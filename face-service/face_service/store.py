"""SQLite is the source of truth; no stale process-local biometric cache."""

from contextlib import contextmanager
import json
import os
from pathlib import Path
import sqlite3
from threading import RLock
from uuid import NAMESPACE_URL, uuid5

import numpy as np

from . import MODEL_VERSION
from .engine import Face, normalize_embedding


class Tombstoned(ValueError):
    pass


class Store:
    def __init__(self, path: Path):
        if path.is_symlink():
            raise ValueError("invalid_database_path")
        path = path.resolve()
        path.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
        fd = os.open(path, os.O_CREAT | os.O_RDWR, 0o600)
        os.close(fd)
        os.chmod(path, 0o600)
        self.path = path
        self.lock = RLock()
        with self.connection() as db:
            db.execute("PRAGMA journal_mode=WAL")
            db.executescript("""
                CREATE TABLE IF NOT EXISTS photos (
                    photo_id TEXT PRIMARY KEY,
                    checksum TEXT NOT NULL,
                    model_version TEXT NOT NULL
                );
                CREATE TABLE IF NOT EXISTS faces (
                    id TEXT PRIMARY KEY,
                    photo_id TEXT NOT NULL REFERENCES photos(photo_id) ON DELETE CASCADE,
                    box TEXT NOT NULL,
                    embedding BLOB NOT NULL
                );
                CREATE INDEX IF NOT EXISTS faces_photo ON faces(photo_id);
                CREATE TABLE IF NOT EXISTS tombstones (
                    photo_id TEXT PRIMARY KEY,
                    deleted_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
                );
            """)

    @contextmanager
    def connection(self):
        # Separate connection per operation, and a process-local lock. BEGIN
        # IMMEDIATE also serializes writers across processes/restarts.
        with self.lock:
            db = sqlite3.connect(self.path, timeout=30, isolation_level=None)
            try:
                db.execute("PRAGMA foreign_keys=ON")
                db.execute("PRAGMA synchronous=FULL")
                db.execute("PRAGMA secure_delete=ON")
                yield db
            finally:
                if db.in_transaction:
                    db.rollback()
                db.close()

    @staticmethod
    def _check_tombstone(db, photo_id):
        if db.execute("SELECT 1 FROM tombstones WHERE photo_id=?", (photo_id,)).fetchone():
            raise Tombstoned("photo_deleted")

    @staticmethod
    def _faces(db, photo_id):
        rows = db.execute("SELECT id, box FROM faces WHERE photo_id=? ORDER BY id", (photo_id,)).fetchall()
        return [{"id": row[0], "box": json.loads(row[1])} for row in rows]

    def cached(self, photo_id: str, checksum: str):
        with self.connection() as db:
            db.execute("BEGIN")
            self._check_tombstone(db, photo_id)
            row = db.execute("SELECT checksum, model_version FROM photos WHERE photo_id=?", (photo_id,)).fetchone()
            if row == (checksum, MODEL_VERSION):
                return self._faces(db, photo_id)
            return None

    def index(self, photo_id: str, checksum: str, faces: list[Face]):
        with self.connection() as db:
            db.execute("BEGIN IMMEDIATE")
            # This check MUST occur after inference, inside the write transaction:
            # a DELETE committed while inference ran must win permanently.
            self._check_tombstone(db, photo_id)
            row = db.execute("SELECT checksum, model_version FROM photos WHERE photo_id=?", (photo_id,)).fetchone()
            if row != (checksum, MODEL_VERSION):
                db.execute("DELETE FROM photos WHERE photo_id=?", (photo_id,))
                db.execute("INSERT INTO photos VALUES (?, ?, ?)", (photo_id, checksum, MODEL_VERSION))
                for i, face in enumerate(faces):
                    face_id = str(uuid5(NAMESPACE_URL, f"{MODEL_VERSION}:{photo_id}:{checksum}:{i}"))
                    vector = normalize_embedding(face.embedding).astype("<f4").tobytes()
                    db.execute("INSERT INTO faces VALUES (?, ?, ?, ?)",
                               (face_id, photo_id, json.dumps(face.box), vector))
            result = self._faces(db, photo_id)
            db.commit()
            return result

    def delete(self, photo_id: str):
        with self.connection() as db:
            db.execute("BEGIN IMMEDIATE")
            db.execute("INSERT OR IGNORE INTO tombstones(photo_id) VALUES (?)", (photo_id,))
            db.execute("DELETE FROM photos WHERE photo_id=?", (photo_id,))
            db.commit()
            # Minimize deleted vectors lingering in WAL. This is not a promise of
            # secure erasure on SSDs, snapshots, backups or active reader pages.
            db.execute("PRAGMA wal_checkpoint(TRUNCATE)")

    def search(self, embedding, photo_ids: list[str], threshold: float):
        query = normalize_embedding(embedding)
        best = {}
        with self.connection() as db:
            # One read transaction gives all batches the same deletion/index view.
            db.execute("BEGIN")
            ids = sorted(set(photo_ids))
            for start in range(0, len(ids), 500):
                chunk = ids[start:start + 500]
                placeholders = ",".join("?" for _ in chunk)
                rows = db.execute(f"""
                    SELECT f.photo_id, f.embedding FROM faces f
                    JOIN photos p ON p.photo_id=f.photo_id
                    WHERE p.model_version=? AND f.photo_id IN ({placeholders})
                    AND NOT EXISTS (SELECT 1 FROM tombstones t WHERE t.photo_id=f.photo_id)
                """, [MODEL_VERSION, *chunk])
                for photo_id, blob in rows:
                    vector = np.frombuffer(blob, dtype="<f4")
                    score = float(np.clip(np.dot(query, vector), -1, 1))
                    if score >= threshold:
                        best[photo_id] = max(best.get(photo_id, -1.0), score)
        return [{"photo_id": photo_id, "score": score}
                for photo_id, score in sorted(best.items(), key=lambda item: (-item[1], item[0]))[:200]]