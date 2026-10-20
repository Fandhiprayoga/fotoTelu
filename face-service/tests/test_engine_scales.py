"""Camera-scale regression tests; no personal images or ONNX required."""
from threading import RLock

import numpy as np
import pytest

from face_service.engine import FaceQualityError, OpenCVEngine


def make_engine(found_at, *, count=1, box=(200, 200, 300, 400)):
    class Detector:
        def __init__(self):
            self.sizes = []

        def setInputSize(self, size):
            self.sizes.append(size)

        def detect(self, image):
            height, width = image.shape[:2]
            assert self.sizes[-1] == (width, height)
            if max(width, height) != found_at:
                return None, None
            scale = found_at / 1280
            row = np.array([*box, *([300, 350] * 5), 0.99], dtype=np.float32)
            row[:14] *= scale
            return None, np.array([row] * count)

    class Recognizer:
        def __init__(self):
            self.rows = []

        def alignCrop(self, image, row):
            self.rows.append(row.copy())
            assert image.shape == (1280, 960, 3)
            return image

        def feature(self, image):
            value = np.zeros(128, dtype=np.float32)
            value[0] = 1
            return value

    engine = OpenCVEngine.__new__(OpenCVEngine)
    engine.lock = RLock()
    engine.detector = Detector()
    engine.recognizer = Recognizer()
    return engine


@pytest.fixture
def camera_frame():
    return np.random.default_rng(7).integers(0, 256, (1280, 960, 3), dtype=np.uint8)


@pytest.mark.parametrize("found_at,expected_sizes", [
    (1280, [(960, 1280)]),
    (640, [(960, 1280), (480, 640)]),
    (320, [(960, 1280), (480, 640), (240, 320)]),
])
def test_camera_retry_restores_box_and_landmarks(camera_frame, found_at, expected_sizes):
    engine = make_engine(found_at)
    faces = engine.extract(camera_frame, selfie=True)
    assert engine.detector.sizes == expected_sizes
    assert len(faces) == 1
    assert faces[0].box == [200, 200, 300, 400]
    np.testing.assert_allclose(engine.recognizer.rows[0][:14],
                               [200, 200, 300, 400, *([300, 350] * 5)])


def test_empty_camera_frame_still_rejected(camera_frame):
    engine = make_engine(None)
    with pytest.raises(FaceQualityError, match="^selfie_no_face_detected$"):
        engine.extract(camera_frame, selfie=True)
    assert len(engine.detector.sizes) == 3
    assert engine.recognizer.rows == []


@pytest.mark.parametrize("found_at", [1280, 640, 320])
def test_multiple_faces_never_retried_or_accepted(camera_frame, found_at):
    engine = make_engine(found_at, count=2)
    with pytest.raises(FaceQualityError, match="^selfie_multiple_faces_detected$"):
        engine.extract(camera_frame, selfie=True)
    assert max(engine.detector.sizes[-1]) == found_at
    assert engine.recognizer.rows == []


@pytest.mark.parametrize("box,blurry,error", [
    ((200, 200, 60, 70), False, "selfie_face_too_small"),
    ((-10, 200, 300, 400), False, "selfie_face_out_of_frame"),
    ((200, 200, 300, 400), True, "selfie_face_too_blurry"),
])
def test_retry_preserves_quality_checks(camera_frame, box, blurry, error):
    engine = make_engine(640, box=box)
    if blurry:
        camera_frame[:] = 127
    with pytest.raises(FaceQualityError, match=f"^{error}$"):
        engine.extract(camera_frame, selfie=True)
    assert engine.recognizer.rows == []


def test_gallery_keeps_single_scale_behavior(camera_frame):
    engine = make_engine(640)
    assert engine.extract(camera_frame, selfie=False) == []
    assert engine.detector.sizes == [(960, 1280)]