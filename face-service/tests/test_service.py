import asyncio
from concurrent.futures import ThreadPoolExecutor
from hashlib import sha256
from io import BytesIO
import json
from threading import Event
from uuid import UUID

import cv2
from fastapi.testclient import TestClient
import numpy as np
from PIL import Image
import pytest

from face_service import MODEL_VERSION
from face_service.app import MAX_BODY_BYTES, PrivateRequestBoundary, create_app
from face_service.engine import (Face, FaceQualityError, InvalidImage, OpenCVEngine,
                                 decode_image, normalize_embedding)
from face_service.models import MODELS, verify_model
from face_service.store import Store, Tombstoned

TOKEN = "test-service-token"
PHOTO = str(UUID(int=1))
OTHER = str(UUID(int=2))


def vector(index=0):
    value = np.zeros(128, dtype=np.float32)
    value[index] = 1
    return value


def picture():
    out = BytesIO()
    Image.new("RGB", (100, 100), "white").save(out, format="PNG")
    return out.getvalue()


class StubEngine:
    ready = True

    def __init__(self):
        self.calls = 0
        self.faces = [Face([0, 0, 90, 90], vector())]

    def extract(self, image, *, selfie):
        self.calls += 1
        return self.faces


@pytest.fixture
def setup(tmp_path):
    engine = StubEngine()
    app = create_app(engine=engine, db_path=tmp_path / "index.sqlite3", token=TOKEN)
    with TestClient(app, headers={"X-Service-Token": TOKEN}) as client:
        yield client, engine, app.state.store


def upload(client, photo=PHOTO, content=None, checksum=None, mime="image/png"):
    content = content if content is not None else picture()
    return client.post(f"/photos/{photo}", files={"file": ("photo.png", content, mime)},
                       data={"checksum": checksum or sha256(content).hexdigest()})


def search(client, ids=None, **overrides):
    body = {"embedding": vector().tolist(), "photo_ids": ids if ids is not None else [PHOTO],
            "model_version": MODEL_VERSION, "threshold": 0.55}
    body.update(overrides)
    return client.post("/search", json=body)


def test_missing_token_fails_closed(monkeypatch, tmp_path):
    monkeypatch.delenv("FACE_SERVICE_TOKEN", raising=False)
    with pytest.raises(RuntimeError, match="FACE_SERVICE_TOKEN"):
        create_app(engine=StubEngine(), db_path=tmp_path / "index.sqlite3")
    with pytest.raises(RuntimeError):
        create_app(token="  ", engine=StubEngine(), db_path=tmp_path / "index.sqlite3")


@pytest.mark.parametrize("path,method", [("/health", "GET"), ("/selfie", "POST"),
                                          (f"/photos/{PHOTO}", "POST"),
                                          (f"/photos/{PHOTO}", "DELETE"), ("/search", "POST")])
def test_every_route_authenticated(setup, path, method):
    client, _, _ = setup
    assert client.request(method, path, headers={"X-Service-Token": "wrong"}).status_code == 401
    with TestClient(client.app) as anonymous:
        assert anonymous.request(method, path).status_code == 401


def test_health_and_unready(setup):
    client, engine, _ = setup
    assert client.get("/health").json() == {"model_version": MODEL_VERSION, "ready": True}
    engine.ready = False
    assert client.get("/health").json()["ready"] is False
    assert search(client).status_code == 503
    assert client.delete(f"/photos/{PHOTO}").status_code == 200


def test_selfie_normalized_and_not_persisted(setup):
    client, _, store = setup
    response = client.post("/selfie", files={"file": ("a.png", picture(), "image/png")})
    assert response.status_code == 200
    assert len(response.json()["embedding"]) == 128
    assert np.linalg.norm(response.json()["embedding"]) == pytest.approx(1)
    assert response.headers["cache-control"] == "no-store"
    with store.connection() as db:
        assert db.execute("SELECT count(*) FROM faces").fetchone()[0] == 0


@pytest.mark.parametrize("count", [0, 2])
def test_selfie_exactly_one_face(setup, count):
    client, engine, _ = setup
    engine.faces = [Face([0, 0, 90, 90], vector())] * count
    response = client.post("/selfie", files={"file": ("a.png", picture(), "image/png")})
    assert response.status_code == 422
    expected = "selfie_no_face_detected" if count == 0 else "selfie_multiple_faces_detected"
    assert response.json() == {"detail": expected}


def test_idempotent_index_persistence_and_checksum(setup):
    client, engine, store = setup
    first = upload(client)
    assert first.status_code == 200
    assert set(first.json()) == {"faces", "model_version"}
    assert set(first.json()["faces"][0]) == {"id", "box"}
    assert upload(client).json() == first.json()
    assert engine.calls == 1
    assert Store(store.path).cached(PHOTO, sha256(picture()).hexdigest()) == first.json()["faces"]
    assert upload(client, checksum="0" * 64).status_code == 422
    assert upload(client, mime="image/jpeg").status_code == 415


def test_no_face_gallery_is_valid_and_cached(setup):
    client, engine, _ = setup
    engine.faces = []
    assert upload(client).json()["faces"] == []
    assert upload(client).json()["faces"] == []
    assert engine.calls == 1


def test_durable_delete_and_no_resurrection(setup):
    client, _, store = setup
    upload(client)
    for _ in range(2):
        assert client.delete(f"/photos/{PHOTO}").json() == {"success": True}
    assert upload(client).status_code == 410
    assert search(client).json()["matches"] == []
    with pytest.raises(Tombstoned):
        Store(store.path).index(PHOTO, "a" * 64, [Face([0, 0, 1, 1], vector())])


def test_delete_wins_over_inflight_inference(setup):
    client, engine, _ = setup
    started, resume = Event(), Event()

    def delayed(image, *, selfie):
        started.set()
        assert resume.wait(5)
        return [Face([0, 0, 90, 90], vector())]

    engine.extract = delayed
    with ThreadPoolExecutor() as pool:
        future = pool.submit(upload, client)
        assert started.wait(5)
        try:
            assert client.delete(f"/photos/{PHOTO}").status_code == 200
        finally:
            resume.set()
        assert future.result().status_code == 410


def test_search_scope_dedup_order_threshold_and_version(setup):
    client, _, store = setup
    mixed = normalize_embedding(vector() * 0.8 + vector(1) * 0.6)
    store.index(PHOTO, "a" * 64, [Face([0, 0, 5, 5], vector()), Face([5, 5, 5, 5], mixed)])
    store.index(OTHER, "b" * 64, [Face([0, 0, 5, 5], mixed)])
    assert [m["photo_id"] for m in search(client, [OTHER, PHOTO, PHOTO]).json()["matches"]] == [PHOTO, OTHER]
    assert len(search(client, [OTHER, PHOTO], threshold=0.9).json()["matches"]) == 1
    assert search(client, [OTHER]).json()["matches"][0]["photo_id"] == OTHER
    assert search(client, []).json()["matches"] == []
    assert search(client, model_version="old").status_code == 409


def test_match_cap_deterministic_ties(setup):
    client, _, store = setup
    ids = [str(UUID(int=i)) for i in range(1, 205)]
    for photo in reversed(ids):
        store.index(photo, "a" * 64, [Face([0, 0, 1, 1], vector())])
    matches = search(client, list(reversed(ids))).json()["matches"]
    assert len(matches) == 200
    assert [m["photo_id"] for m in matches] == ids[:200]


@pytest.mark.parametrize("overrides", [
    {"embedding": [0] * 128}, {"embedding": [1] * 127},
    {"embedding": [True] * 128}, {"embedding": ["secret-biometric"] * 128},
    {"photo_ids": ["../file"]}, {"photo_ids": [PHOTO] * 20_001},
    {"threshold": 1.1}, {"threshold": True}, {"threshold": "0.55"},
    {"unknown": 1},
])
def test_search_validation_does_not_echo_biometrics(setup, overrides):
    client, _, _ = setup
    response = search(client, **overrides)
    assert response.status_code == 422
    assert response.json() == {"detail": "invalid_request"}


@pytest.mark.parametrize("bad", [float("nan"), float("inf"), -float("inf")])
def test_nonfinite_embedding_rejected(setup, bad):
    client, _, _ = setup
    body = {"embedding": [bad] * 128, "photo_ids": [], "model_version": MODEL_VERSION, "threshold": 0.55}
    response = client.post("/search", content=json.dumps(body), headers={"Content-Type": "application/json"})
    assert response.status_code == 422
    assert response.json() == {"detail": "invalid_request"}


@pytest.mark.parametrize("content,mime", [(b"bad", "image/png"), (picture(), "image/jpeg"),
                                           (picture(), "application/octet-stream")])
def test_invalid_images(setup, content, mime):
    client, _, _ = setup
    assert upload(client, content=content, mime=mime).status_code == 415


def test_pixels_dimensions_animation_before_cv(monkeypatch):
    import face_service.engine as module
    monkeypatch.setattr(module, "MAX_PIXELS", 9999)
    with pytest.raises(InvalidImage):
        decode_image(picture(), "image/png")
    monkeypatch.setattr(module, "MAX_PIXELS", 40_000_000)
    monkeypatch.setattr(module, "MAX_DIMENSION", 99)
    with pytest.raises(InvalidImage):
        decode_image(picture(), "image/png")
    monkeypatch.setattr(module, "MAX_DIMENSION", 16384)
    out = BytesIO()
    Image.new("RGB", (10, 10)).save(out, format="PNG", save_all=True,
                                    append_images=[Image.new("RGB", (10, 10), "white")])
    with pytest.raises(InvalidImage):
        decode_image(out.getvalue(), "image/png")


def test_request_length_bound_before_decode(setup):
    client, engine, _ = setup
    response = client.post("/selfie", content=b"x", headers={"Content-Length": str(MAX_BODY_BYTES + 1)})
    assert response.status_code == 413
    assert engine.calls == 0


def test_chunked_body_bound_before_downstream():
    called = False
    messages = []
    chunks = iter([{ "type": "http.request", "body": b"a" * (MAX_BODY_BYTES // 2), "more_body": True},
                   { "type": "http.request", "body": b"b" * (MAX_BODY_BYTES // 2 + 1), "more_body": False}])

    async def downstream(scope, receive, send):
        nonlocal called
        called = True

    async def receive():
        return next(chunks)

    async def send(message):
        messages.append(message)

    middleware = PrivateRequestBoundary(downstream, TOKEN)
    asyncio.run(middleware({"type": "http", "headers": [(b"x-service-token", TOKEN.encode())]}, receive, send))
    assert not called
    assert messages[0]["status"] == 413


def test_no_models_is_not_ready(tmp_path):
    assert not OpenCVEngine(tmp_path).ready
    for name, spec in MODELS.items():
        path = tmp_path / name
        path.write_bytes(b"not an onnx file")
        assert not verify_model(path, spec)
    assert not OpenCVEngine(tmp_path).ready


@pytest.mark.parametrize("kind", ["small", "blurry", "multiple", "none", "clear"])
def test_real_engine_quality_rules_without_onnx(kind):
    from threading import RLock

    row = np.array([10, 10, 90, 90, *([30, 30] * 5), 0.99], dtype=np.float32)
    rows = np.array([row])
    if kind == "small":
        rows[0, 2:4] = 40
    elif kind == "multiple":
        rows = np.array([row, row])
    elif kind == "none":
        rows = None

    class Detector:
        def setInputSize(self, size):
            pass

        def detect(self, image):
            return None, rows

    class Recognizer:
        def alignCrop(self, image, face):
            return image

        def feature(self, image):
            return vector()

    engine = OpenCVEngine.__new__(OpenCVEngine)
    engine.lock = RLock()
    engine.detector, engine.recognizer = Detector(), Recognizer()
    image = np.random.default_rng(1).integers(0, 256, (120, 120, 3), dtype=np.uint8)
    if kind == "blurry":
        image[:] = 127
    if kind == "clear":
        assert len(engine.extract(image, selfie=True)) == 1
    else:
        expected = {"none": "selfie_no_face_detected", "multiple": "selfie_multiple_faces_detected",
                    "small": "selfie_face_too_small", "blurry": "selfie_face_too_blurry"}[kind]
        with pytest.raises(FaceQualityError, match=f"^{expected}$"):
            engine.extract(image, selfie=True)


def test_private_database_permissions_and_wal(setup):
    _, _, store = setup
    assert store.path.stat().st_mode & 0o077 == 0
    with store.connection() as db:
        assert db.execute("PRAGMA journal_mode").fetchone()[0] == "wal"
        assert db.execute("PRAGMA synchronous").fetchone()[0] == 2


def test_parallel_writers_and_deletes(tmp_path):
    path = tmp_path / "index.sqlite3"
    first, second = Store(path), Store(path)
    ids = [str(UUID(int=i)) for i in range(1, 25)]
    with ThreadPoolExecutor(max_workers=4) as pool:
        list(pool.map(lambda photo: first.index(photo, "a" * 64, [Face([0, 0, 1, 1], vector())]), ids))
        list(pool.map(second.delete, ids))
    assert first.search(vector(), ids, 0.55) == []


def test_model_download_rejects_wrong_digest(tmp_path, monkeypatch):
    import download_models

    class Response(BytesIO):
        def geturl(self):
            return "https://media.githubusercontent.com/fake"

    monkeypatch.setattr(download_models, "urlopen", lambda *args, **kwargs: Response(b"evil"))
    monkeypatch.setattr(download_models, "MODELS", {"model.onnx": {"directory": "test", "size": 4, "sha256": "0" * 64}})
    with pytest.raises(RuntimeError, match="SHA256"):
        download_models.download_models(tmp_path)
    assert list(tmp_path.iterdir()) == []