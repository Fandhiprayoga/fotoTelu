"""Download only the two committed model identities, never user-provided URLs."""

import argparse
import hashlib
import os
from pathlib import Path
import tempfile
from urllib.request import urlopen

from face_service.models import MODELS, verify_model


def download_models(destination: Path):
    destination.mkdir(parents=True, mode=0o700, exist_ok=True)
    for name, spec in MODELS.items():
        target = destination / name
        if verify_model(target, spec):
            print(f"Verified {name}")
            continue
        url = f"https://media.githubusercontent.com/media/opencv/opencv_zoo/main/models/{spec['directory']}/{name}"
        temporary = None
        try:
            with tempfile.NamedTemporaryFile(dir=destination, prefix=".download-", delete=False) as out:
                temporary = Path(out.name)
                digest = hashlib.sha256()
                size = 0
                with urlopen(url, timeout=60) as response:
                    if not response.geturl().startswith("https://"):
                        raise RuntimeError("Refusing non-HTTPS download")
                    while chunk := response.read(1024 * 1024):
                        size += len(chunk)
                        if size > spec["size"]:
                            raise RuntimeError(f"Model exceeds committed size: {name}")
                        digest.update(chunk)
                        out.write(chunk)
                if size != spec["size"] or digest.hexdigest() != spec["sha256"]:
                    raise RuntimeError(f"Model SHA256/size mismatch: {name}")
                out.flush()
                os.fsync(out.fileno())
            os.replace(temporary, target)
            print(f"Downloaded and verified {name}")
        finally:
            if temporary is not None:
                temporary.unlink(missing_ok=True)


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--directory", type=Path, default=Path(__file__).resolve().parent / "models")
    download_models(parser.parse_args().directory)