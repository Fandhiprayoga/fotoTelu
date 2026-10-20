from hashlib import sha256
import hmac
from io import BytesIO
import os
from pathlib import Path
import re
import sqlite3
from threading import RLock
from typing import Annotated
from uuid import UUID

import cv2
from fastapi import FastAPI, File, Form, HTTPException, UploadFile
from fastapi.exceptions import RequestValidationError
from fastapi.responses import JSONResponse
from pydantic import BaseModel, ConfigDict, Field, StrictFloat, StrictInt, field_validator
from PIL import Image

from . import MODEL_VERSION
from .engine import (ALLOWED_TYPES, FaceQualityError, InvalidImage, OpenCVEngine, decode_image,
                     normalize_embedding)
from .store import Store, Tombstoned

MAX_BODY_BYTES = 12 * 1024 * 1024
ROOT = Path(__file__).resolve().parents[1]
UUID_PATTERN = re.compile(r"^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$")


class PrivateRequestBoundary:
    """Authenticate before reading; bound chunked bodies before multipart parsing.

    Do not use BaseHTTPMiddleware: the complete ASGI body must be checked before
    the downstream parser receives even the first multipart byte.
    """

    def __init__(self, app, token: str):
        self.app = app
        self.token_digest = sha256(token.encode("utf-8")).digest()

    async def __call__(self, scope, receive, send):
        if scope["type"] != "http":
            return await self.app(scope, receive, send)

        async def reject(code, detail):
            await JSONResponse({"detail": detail}, status_code=code,
                               headers={"Cache-Control": "no-store"})(scope, receive, send)

        headers = scope.get("headers", [])
        tokens = [value for name, value in headers if name.lower() == b"x-service-token"]
        if len(tokens) != 1 or not hmac.compare_digest(sha256(tokens[0]).digest(), self.token_digest):
            return await reject(401, "unauthorized")
        lengths = [value for name, value in headers if name.lower() == b"content-length"]
        expected = None
        if lengths:
            if len(lengths) != 1 or not re.fullmatch(rb"[0-9]{1,12}", lengths[0]):
                return await reject(400, "invalid_content_length")
            expected = int(lengths[0])
            if expected > MAX_BODY_BYTES:
                return await reject(413, "request_too_large")
        if any(name.lower() == b"content-encoding" and value.lower() != b"identity"
               for name, value in headers):
            return await reject(415, "content_encoding_not_supported")
        content = bytearray()
        while True:
            message = await receive()
            if message["type"] == "http.disconnect":
                return
            chunk = message.get("body", b"")
            if len(content) + len(chunk) > MAX_BODY_BYTES:
                return await reject(413, "request_too_large")
            content.extend(chunk)
            if not message.get("more_body", False):
                break
        if expected is not None and expected != len(content):
            return await reject(400, "invalid_content_length")
        delivered = False

        async def replay():
            nonlocal delivered
            if not delivered:
                delivered = True
                return {"type": "http.request", "body": bytes(content), "more_body": False}
            return await receive()

        async def private_send(message):
            if message["type"] == "http.response.start":
                message["headers"] = [*message.get("headers", []),
                                      (b"cache-control", b"no-store"),
                                      (b"x-content-type-options", b"nosniff")]
            await send(message)

        await self.app(scope, replay, private_send)


class SearchRequest(BaseModel):
    model_config = ConfigDict(extra="forbid", allow_inf_nan=False)
    embedding: Annotated[list[StrictFloat | StrictInt], Field(min_length=128, max_length=128)]
    model_version: str
    photo_ids: Annotated[list[str], Field(max_length=20_000)]
    threshold: Annotated[StrictFloat | StrictInt, Field(ge=-1, le=1)] = 0.55

    @field_validator("photo_ids")
    @classmethod
    def valid_photo_ids(cls, values):
        if not all(UUID_PATTERN.fullmatch(value) for value in values):
            raise ValueError("invalid_photo_ids")
        return values

    @field_validator("embedding")
    @classmethod
    def valid_embedding(cls, values):
        normalize_embedding(values)
        return values


def create_app(*, engine=None, db_path: Path | None = None, token: str | None = None) -> FastAPI:
    """Uvicorn factory; inject a deterministic engine/database only in tests."""
    secret = token if token is not None else os.environ.get("FACE_SERVICE_TOKEN", "")
    if not secret or not secret.strip():
        raise RuntimeError("FACE_SERVICE_TOKEN is required; refusing to start")
    os.umask(0o077)
    store = Store(db_path if db_path is not None else Path(os.environ.get("FACE_DB_PATH", ROOT / "data/index.sqlite3")))
    engine = engine if engine is not None else OpenCVEngine(Path(os.environ.get("FACE_MODEL_DIR", ROOT / "models")))
    processing_lock = RLock()
    app = FastAPI(title="Internal photo discovery", docs_url=None, redoc_url=None, openapi_url=None)
    app.add_middleware(PrivateRequestBoundary, token=secret)
    app.state.store = store

    @app.exception_handler(RequestValidationError)
    async def invalid_request(_request, _exc):
        # Default FastAPI validation errors echo the input (including embeddings).
        return JSONResponse({"detail": "invalid_request"}, status_code=422)

    @app.exception_handler(sqlite3.Error)
    async def unavailable_index(_request, _exc):
        return JSONResponse({"detail": "index_unavailable"}, status_code=503)

    def require_ready():
        if not engine.ready:
            raise HTTPException(503, "models_not_ready")

    def extract(file: UploadFile, *, selfie: bool):
        require_ready()
        # Serializes full image decoding as well as shared-model inference, to
        # avoid parallel 40MP decodes exhausting RAM in the thread pool.
        with processing_lock:
            try:
                image = decode_image(file.file.read(), file.content_type or "")
                return engine.extract(image, selfie=selfie)
            except InvalidImage:
                raise HTTPException(415, "invalid_image") from None
            except FaceQualityError as exc:
                raise HTTPException(422, str(exc)) from None
            except cv2.error:
                raise HTTPException(422, "image_processing_failed") from None

    @app.get("/health")
    def health():
        ready = bool(engine.ready)
        try:
            with store.connection() as db:
                db.execute("SELECT 1 FROM photos LIMIT 1")
        except sqlite3.Error:
            ready = False
        return {"model_version": MODEL_VERSION, "ready": ready}

    @app.post("/selfie")
    def selfie(file: Annotated[UploadFile, File()]):
        faces = extract(file, selfie=True)
        if len(faces) == 0:
            raise HTTPException(422, "selfie_no_face_detected")
        if len(faces) > 1:
            raise HTTPException(422, "selfie_multiple_faces_detected")
        return {"embedding": normalize_embedding(faces[0].embedding).tolist(), "model_version": MODEL_VERSION}

    @app.post("/photos/{photo_id}")
    def index_photo(photo_id: UUID, file: Annotated[UploadFile, File()],
                    checksum: Annotated[str, Form(min_length=64, max_length=64, pattern="^[0-9a-f]{64}$")]):
        photo_id = str(photo_id)
        if file.content_type not in ALLOWED_TYPES.values():
            raise HTTPException(415, "invalid_image")
        content_digest = sha256(file.file.read()).hexdigest()
        file.file.seek(0)
        if not hmac.compare_digest(content_digest, checksum):
            raise HTTPException(422, "checksum_mismatch")
        try:
            faces = store.cached(photo_id, checksum)
            if faces is None:
                faces = store.index(photo_id, checksum, extract(file, selfie=False))
            else:
                # Matching bytes were fully verified on first indexing, but a
                # retry's declared MIME must still agree with their actual format.
                with Image.open(BytesIO(file.file.read())) as image:
                    if ALLOWED_TYPES.get(image.format) != file.content_type:
                        raise HTTPException(415, "invalid_image")
            return {"faces": faces, "model_version": MODEL_VERSION}
        except Tombstoned:
            raise HTTPException(410, "photo_deleted") from None

    @app.delete("/photos/{photo_id}")
    def delete_photo(photo_id: UUID):
        store.delete(str(photo_id))
        return {"success": True}

    @app.post("/search")
    def search(request: SearchRequest):
        if request.model_version != MODEL_VERSION:
            raise HTTPException(409, "model_version_mismatch")
        require_ready()
        return {"matches": store.search(request.embedding, request.photo_ids, request.threshold),
                "model_version": MODEL_VERSION}

    return app