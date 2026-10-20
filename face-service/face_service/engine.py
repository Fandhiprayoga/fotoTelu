from dataclasses import dataclass
from io import BytesIO
from pathlib import Path
from threading import RLock
import warnings

import cv2
import numpy as np
from PIL import Image, ImageOps, UnidentifiedImageError

from .models import MODELS, verify_model

MAX_PIXELS = 40_000_000
MAX_DIMENSION = 16_384
ALLOWED_TYPES = {"JPEG": "image/jpeg", "PNG": "image/png", "WEBP": "image/webp"}


class InvalidImage(ValueError):
    pass


class FaceQualityError(ValueError):
    pass


def decode_image(content: bytes, mime: str) -> np.ndarray:
    """Check headers/pixels before materializing pixels or entering OpenCV."""
    try:
        with warnings.catch_warnings():
            warnings.simplefilter("error", Image.DecompressionBombWarning)
            with Image.open(BytesIO(content)) as image:
                width, height = image.size
                if (
                    ALLOWED_TYPES.get(image.format) != mime
                    or mime not in ALLOWED_TYPES.values()
                    or not (1 <= width <= MAX_DIMENSION and 1 <= height <= MAX_DIMENSION)
                    or width * height > MAX_PIXELS
                    or getattr(image, "n_frames", 1) != 1
                ):
                    raise InvalidImage("invalid_image")
                image.verify()
            with Image.open(BytesIO(content)) as image:
                rgb = np.asarray(ImageOps.exif_transpose(image).convert("RGB"))
        return cv2.cvtColor(rgb, cv2.COLOR_RGB2BGR)
    except (UnidentifiedImageError, OSError, SyntaxError, ValueError,
            Image.DecompressionBombError, Image.DecompressionBombWarning) as exc:
        raise InvalidImage("invalid_image") from exc


def normalize_embedding(value) -> np.ndarray:
    vector = np.asarray(value, dtype=np.float64)
    if vector.shape != (128,) or not np.isfinite(vector).all():
        raise ValueError("invalid_embedding")
    # Scale first: finite but very large inputs must not overflow the norm.
    scale = float(np.max(np.abs(vector)))
    if scale == 0:
        raise ValueError("invalid_embedding")
    vector = vector / scale
    norm = float(np.linalg.norm(vector))
    if norm <= 0 or not np.isfinite(norm):
        raise ValueError("invalid_embedding")
    return (vector / norm).astype(np.float32)


@dataclass
class Face:
    box: list[int]
    embedding: np.ndarray


class OpenCVEngine:
    """One shared YuNet/SFace pair; every model operation is serialized."""

    def __init__(self, model_dir: Path):
        self.lock = RLock()
        self.ready = False
        self.detector = None
        self.recognizer = None
        if not all(verify_model(model_dir / name, spec) for name, spec in MODELS.items()):
            return
        try:
            cv2.setNumThreads(1)
            self.detector = cv2.FaceDetectorYN.create(
                str(model_dir / "face_detection_yunet_2023mar.onnx"), "", (320, 320),
                0.9, 0.3, 5000, cv2.dnn.DNN_BACKEND_OPENCV, cv2.dnn.DNN_TARGET_CPU,
            )
            self.recognizer = cv2.FaceRecognizerSF.create(
                str(model_dir / "face_recognition_sface_2021dec.onnx"), "",
                cv2.dnn.DNN_BACKEND_OPENCV, cv2.dnn.DNN_TARGET_CPU,
            )
            # Exercise both networks: constructing an OpenCV object alone does not
            # prove that ONNX operators are supported by the installed runtime.
            self.detector.detect(np.zeros((320, 320, 3), dtype=np.uint8))
            normalize_embedding(self.recognizer.feature(np.zeros((112, 112, 3), dtype=np.uint8)).ravel())
            self.ready = True
        except (cv2.error, ValueError):
            self.ready = False

    def extract(self, image: np.ndarray, *, selfie: bool) -> list[Face]:
        with self.lock:
            height, width = image.shape[:2]
            # Bound detector CPU/memory for large gallery photos. Alignment and
            # feature extraction still use the original full-resolution pixels.
            scale = min(1.0, 1600 / max(width, height))
            detect_image = cv2.resize(image, (max(1, round(width * scale)), max(1, round(height * scale))))
            dh, dw = detect_image.shape[:2]
            self.detector.setInputSize((dw, dh))
            _, rows = self.detector.detect(detect_image)
            rows = [] if rows is None else rows
            if selfie and len(rows) == 0:
                raise FaceQualityError("selfie_no_face_detected")
            if selfie and len(rows) > 1:
                raise FaceQualityError("selfie_multiple_faces_detected")
            if len(rows) > 200:
                raise FaceQualityError("too_many_faces")
            result = []
            for row in rows:
                row = row.copy()
                row[:14:2] *= width / dw
                row[1:14:2] *= height / dh
                x, y, fw, fh = (float(v) for v in row[:4])
                left, top = max(0, int(x)), max(0, int(y))
                right, bottom = min(width, int(np.ceil(x + fw))), min(height, int(np.ceil(y + fh)))
                if right <= left or bottom <= top:
                    if selfie:
                        raise FaceQualityError("selfie_face_out_of_frame")
                    continue
                if selfie:
                    if min(fw, fh, right - left, bottom - top) < 80:
                        raise FaceQualityError("selfie_face_too_small")
                    if x < 0 or y < 0 or x + fw > width or y + fh > height:
                        raise FaceQualityError("selfie_face_out_of_frame")
                    crop = image[top:bottom, left:right]
                    gray = cv2.cvtColor(cv2.resize(crop, (160, 160)), cv2.COLOR_BGR2GRAY)
                    if cv2.Laplacian(gray, cv2.CV_64F).var() < 45.0:
                        raise FaceQualityError("selfie_face_too_blurry")
                aligned = self.recognizer.alignCrop(image, row)
                embedding = normalize_embedding(self.recognizer.feature(aligned).ravel())
                result.append(Face([left, top, right - left, bottom - top], embedding))
            return sorted(result, key=lambda face: tuple(face.box))