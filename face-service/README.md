# Private photo-discovery service

FastAPI + CPU OpenCV YuNet 2023mar / SFace 2021dec. Internal PHP-to-Python API only;
never expose it to browsers or the public Internet. Fixed model version:
`sface-2021dec-yunet-2023mar-v1`. No cloud service, external image URLs, client paths,
original-photo persistence, selfie persistence, or biometric application logging.

## Local setup

Run from this directory using Python 3.11 or newer. Pins target available CPython
3.14 wheels as well; actual platform wheel availability must be checked at install.

1. Create a private virtual environment with `python3 -m venv .venv`.
2. Install `requirements-dev.txt` with the environment's pip (production:
   `requirements.txt` only).
3. Run `python download_models.py` using the environment's Python. Two downloads
   total about 39 MB. The utility checks committed upstream Git LFS SHA256 and size;
   the runtime rechecks both on startup. Models are ignored by Git.
  If Python reports `CERTIFICATE_VERIFY_FAILED` (notably python.org macOS builds),
  configure its trusted CA certificates or set `SSL_CERT_FILE` to a trusted CA
  bundle before downloading. Never disable TLS verification or checksum checking.
4. Set `FACE_SERVICE_TOKEN` in the process environment to a cryptographically random
   secret (at least 32 random bytes recommended). Use the same secret only in PHP's
   private server configuration. Never put it in browser code, URLs or shell history.
   An absent/blank token refuses startup, including health. No `.env` auto-loading.
5. Start the virtual environment's Uvicorn with import target
  `face_service.app:create_app`, `--factory`, `--host 127.0.0.1`, `--port 8090`,
   `--workers 1`, `--no-access-log`, `--no-proxy-headers`, `--limit-concurrency 4`,
   `--timeout-keep-alive 5`. Do not use reload outside development.
6. Run the environment's `python -m pytest`. Tests use temporary SQLite and stub
   models: no ONNX download, network, or real biometric fixtures required.

Optional trusted-process settings: `FACE_MODEL_DIR` (default local models directory),
`FACE_DB_PATH` (default local data/index.sqlite3). These are never HTTP parameters.
Keep this directory outside PHP's public web root. Missing, corrupt, or incompatible
models produce authenticated health `ready: false`; processing/search return 503.
Deletion remains available without models. Health is readiness, not a substitute
for operational process supervision.

## Exact HTTP contract

Every request, **including health**, requires `X-Service-Token`.

| Method | Route | Request | Response |
|---|---|---|---|
| GET | /health | none | `{model_version, ready}` |
| POST | /selfie | multipart `file` | `{embedding: [128 floats], model_version}` |
| POST | /photos/{photo_id} | multipart `file`, `checksum` | `{faces: [{id, box: [x,y,w,h]}], model_version}` |
| DELETE | /photos/{photo_id} | none | `{success: true}` |
| POST | /search | JSON `{embedding, model_version, photo_ids, threshold}` | `{matches: [{photo_id, score}], model_version}` |

Photo IDs are UUIDs; PHP should supply canonical lowercase hyphenated UUIDs.
Checksum is the actual uploaded byte stream's lowercase 64-character SHA256.
Embeddings must be finite, nonzero, 128-component numeric vectors; normalized on
ingress. Version mismatch is 409. Invalid input is 422; invalid image/MIME is 415;
oversize is 413; unauthorized is 401; deleted photo upload is 410. Errors use
`{detail: safe_error_code}` and never echo embeddings or uploaded bytes.

JPEG, PNG and single-frame WebP only, detected format must equal multipart MIME.
12 MiB bounds the **entire** HTTP request, multipart overhead included, even when
chunked, before multipart parsing. Compressed request bodies are unsupported.
Pillow verifies headers, at most 40,000,000 pixels and 16,384 pixels per dimension
before OpenCV sees decoded pixels. EXIF orientation is applied before bounding boxes
and alignment. Boxes refer to this oriented image. No uploaded filenames are used.

Selfie requires exactly one detected face (confidence >= 0.9), at least 80 pixels
in both dimensions, fully in frame, with Laplacian variance >= 45 after a 160px
quality resize. Guidance: well-lit, in focus, front-facing, move closer. These
heuristics are provisional, not proof of face ownership/identity. Gallery indexing
supports multiple faces or zero faces; more than 200 detected faces is rejected.
Detection is downscaled to at most 1600px on the longest side; tiny/background faces
can be missed. Alignment/embedding use original decoded pixels.

## Scope, deletion and scoring

PHP must authorize every request and send **only explicitly eligible photo UUIDs**
in `photo_ids` (at most 20,000 entries, including duplicates). There is deliberately
no unscoped search/list/read-embedding endpoint. Empty scope returns no matches.
Exact normalized cosine search deduplicates per photo using maximum face score;
descending score then ascending photo UUID breaks ties deterministically. At most
200 matches are returned. No pagination; partition eligible sets in PHP if needed.

`threshold` defaults to PHP's provisional 0.55, range [-1, 1]. **A score is not a
probability.** Calibrate false positives/negatives on consented representative data
before production; no accuracy guarantee is implied. No liveness, anti-spoofing,
identity verification or authentication use is implemented. PHP must enforce
consent, selfie ownership/revocation, album publication, current grants, and download
authorization. Results are candidates, never durable access grants by themselves.

SQLite stores gallery embeddings, boxes, checksums and model version, not selfies.
Same photo/checksum/version returns stable face IDs without repeat inference.
Reindex with changed bytes atomically replaces faces. DELETE is idempotent and
always persists a tombstone, including for unknown IDs; a post-inference transaction
rechecks it to prevent late upload resurrection. **Never reuse deleted UUIDs or
prune tombstones.** Tombstones persist across restarts/backups. A search already in
flight can reflect its earlier snapshot; PHP must revalidate authorization/deletion
at delivery. WAL, FULL synchronous transactions, foreign keys, per-operation
connections and locks protect index consistency. Shared inference and image decode
are serialized. Deploy one worker; do not remove locking or share models unsafely.

## VPS / supervised security baseline

- Dedicated unprivileged OS account, private directory mode 0700, umask 0077,
  SQLite mode 0600. Use a local disk, not NFS. Restrict models to trusted writers.
- Supervisor/systemd: private working directory, virtual-environment Uvicorn argv
  from setup above, one worker, loopback bind, restart on failure, private temporary
  directory (`PrivateTmp=true`), `UMask=0077`, `NoNewPrivileges=true`, resource limits,
  protected filesystem with only data/temp writable. Supply the token through a
  root-readable environment/credential configuration, not command-line arguments.
- Firewall port 8090 from all external access. Same-host PHP uses loopback. For
  separate hosts, use an authenticated encrypted tunnel/mTLS and private firewall
  rules; a header token alone does not protect plaintext network traffic.
- No public proxy route, CORS, debug mode, tracing/APM request capture, body logging,
  or access logs with secret headers. Rate-limit PHP selfie/search/upload flows.
  Bound proxy request bytes/timeouts/concurrency as defense in depth; budget RAM
  for 40MP image decoding. Request body buffers and multipart temporary files may
  contain sensitive pixels: private temp storage and encrypted disk/swap required.
- SQLite is **not encrypted**. Encrypt disk/backups, restrict OS access, back up
  via SQLite online backup or stop service/checkpoint before copying. Never copy
  only the main DB while WAL is active. Restores must preserve newer deletion
  tombstones/reconcile PHP's current deletions before enabling service.
- Secure-delete + WAL checkpoint reduce residual SQLite bytes but do not guarantee
  forensic erasure from SSDs, active snapshots, swap or backups. Define retention,
  backup expiry and consent/deletion procedures outside this service.
- Benchmark CPU, memory, 1k/10k-photo latency and realistic concurrent PHP traffic;
  exact search has no scale/SLA guarantee. Test real-model compatibility and
  consented photos on the target machine before enabling PHP integration.
- Model upgrades require a new explicit version, complete gallery reindex and
  refreshed saved selfie embeddings. Never mix models silently.

See THIRD_PARTY_NOTICES.md for model origins, pinned SHA256 and licenses.