# Third-party model notices

No ONNX weights are included in the repository. Model source: OpenCV Zoo.
Git LFS pointers fetched from the URLs below on 2026-10-20 independently of the
weight downloads. These committed SHA256 identities, not a downloaded checksum,
are the verification trust anchors. Upstream main is mutable: a changed model
will fail verification until an explicit reviewed pin/model-version update.

## YuNet face detection (2023mar)

- [Git LFS pointer](https://raw.githubusercontent.com/opencv/opencv_zoo/main/models/face_detection_yunet/face_detection_yunet_2023mar.onnx)
- SHA256: `8f2383e4dd3cfbb4553ea8718107fc0423210dc964f9f4280604804ed2552fa4`
- Size: 232589 bytes
- [Upstream license](https://raw.githubusercontent.com/opencv/opencv_zoo/main/models/face_detection_yunet/LICENSE)
- MIT; Copyright (c) 2020 Shiqi Yu <shiqi.yu@gmail.com>.
- License text reproduced in licenses/YUNET-MIT.txt.

## SFace face recognition (2021dec)

- [Git LFS pointer](https://raw.githubusercontent.com/opencv/opencv_zoo/main/models/face_recognition_sface/face_recognition_sface_2021dec.onnx)
- SHA256: `0ba9fbfa01b5270c96627c4ef784da859931e02f04419c829e83484087c34e79`
- Size: 38696353 bytes
- [Upstream license](https://raw.githubusercontent.com/opencv/opencv_zoo/main/models/face_recognition_sface/LICENSE)
- Apache License 2.0; retain the upstream license with model redistribution.
- License text reproduced in licenses/SFACE-APACHE-2.0.txt.
- Full license: https://www.apache.org/licenses/LICENSE-2.0.txt

The downloader fetches the original unmodified weights. Runtime libraries retain
their respective licenses (FastAPI MIT, Starlette BSD-3-Clause, Uvicorn BSD-3-Clause,
Pydantic MIT, NumPy BSD-3-Clause, OpenCV Apache-2.0, Pillow HPND, python-multipart
Apache-2.0); consult installed package metadata for complete bundled notices.
Model/code licenses are not a substitute for legal permission, consent, privacy
review or dataset/bias evaluation for biometric processing.