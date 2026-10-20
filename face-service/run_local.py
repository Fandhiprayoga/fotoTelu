"""Local-only launcher: read private CI4 secrets without printing credentials."""
import json
import os
from pathlib import Path

import uvicorn

if __name__ == "__main__":
    root = Path(__file__).resolve().parent
    private = root.parent / "writable" / "photo-secrets.json"
    if not os.environ.get("FACE_SERVICE_TOKEN"):
        if not private.is_file() or private.is_symlink():
            raise SystemExit("Run PHP photos:setup first or supply FACE_SERVICE_TOKEN securely.")
        os.environ["FACE_SERVICE_TOKEN"] = json.loads(private.read_text())["service_token"]
    uvicorn.run("face_service.app:create_app", factory=True, host="127.0.0.1",
                port=8090, workers=1, access_log=False, proxy_headers=False,
                limit_concurrency=4, timeout_keep_alive=5)