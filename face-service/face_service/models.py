"""Reviewed upstream Git LFS identities; never derive trust from downloaded bytes."""

from pathlib import Path
import hashlib

MODELS = {
    "face_detection_yunet_2023mar.onnx": {
        "directory": "face_detection_yunet",
        "sha256": "8f2383e4dd3cfbb4553ea8718107fc0423210dc964f9f4280604804ed2552fa4",
        "size": 232589,
    },
    "face_recognition_sface_2021dec.onnx": {
        "directory": "face_recognition_sface",
        "sha256": "0ba9fbfa01b5270c96627c4ef784da859931e02f04419c829e83484087c34e79",
        "size": 38696353,
    },
}


def verify_model(path: Path, spec: dict) -> bool:
    if not path.is_file() or path.stat().st_size != spec["size"]:
        return False
    with path.open("rb") as stream:
        digest = hashlib.file_digest(stream, "sha256").hexdigest()
    return digest == spec["sha256"]