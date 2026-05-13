from __future__ import annotations

import secrets
import shutil
import tempfile
import time
from pathlib import Path
from typing import Any

from werkzeug.datastructures import FileStorage
from werkzeug.utils import secure_filename

from .config import PROJECT_ROOT, UPLOAD_DIR, public_upload_path


def validate_image_file(path: str) -> tuple[str, str] | None:
    with open(path, "rb") as f:
        head = f.read(16)
    if len(head) >= 3 and head[:3] == b"\xff\xd8\xff":
        return "image/jpeg", "jpg"
    if len(head) >= 8 and head[:8] == b"\x89PNG\r\n\x1a\n":
        return "image/png", "png"
    if len(head) >= 12 and head[:4] == b"RIFF" and head[8:12] == b"WEBP":
        return "image/webp", "webp"
    return None


def delete_local_upload(public_path: str | None) -> None:
    if not public_path:
        return
    rel = public_path.replace("/public", "", 1)
    local = PROJECT_ROOT / "public" / rel.lstrip("/")
    if local.is_file():
        try:
            local.unlink()
        except OSError:
            pass


def save_uploaded_images(files: list[FileStorage], max_files: int = 5) -> list[str]:
    paths: list[str] = []
    UPLOAD_DIR.mkdir(parents=True, exist_ok=True)
    count = 0
    for fs in files:
        if count >= max_files:
            break
        if not fs or not fs.filename:
            continue
        fd, tmp_path = tempfile.mkstemp(suffix=Path(secure_filename(fs.filename) or "img").suffix)
        try:
            import os

            os.close(fd)
            fs.save(tmp_path)
            valid = validate_image_file(tmp_path)
            if not valid:
                continue
            _, ext = valid
            filename = f"img_{int(time.time())}_{secrets.token_hex(4)}.{ext}"
            dest = str(UPLOAD_DIR / filename)
            shutil.move(tmp_path, dest)
            tmp_path = ""  # moved
            paths.append(public_upload_path(filename))
            count += 1
        finally:
            if tmp_path and Path(tmp_path).exists():
                try:
                    Path(tmp_path).unlink()
                except OSError:
                    pass
    return paths


def item_image_paths(cur, item_id: int, legacy_image_path: str | None) -> list[str]:
    cur.execute(
        "SELECT path FROM item_images WHERE item_id = ? ORDER BY sort_order ASC, id ASC",
        (item_id,),
    )
    paths = [str(r[0]) for r in cur.fetchall()]
    if not paths and legacy_image_path:
        return [legacy_image_path]
    return paths


def delete_item_cascade(conn, item_id: int) -> None:
    cur = conn.cursor()
    cur.execute("SELECT path FROM item_images WHERE item_id = ?", (item_id,))
    for (p,) in cur.fetchall():
        delete_local_upload(str(p))
    cur.execute("SELECT image_path FROM items WHERE id = ?", (item_id,))
    row = cur.fetchone()
    if row and row[0]:
        delete_local_upload(str(row[0]))
    cur.execute("DELETE FROM item_images WHERE item_id = ?", (item_id,))
    cur.execute("DELETE FROM item_favorites WHERE item_id = ?", (item_id,))
    cur.execute("DELETE FROM item_applications WHERE item_id = ?", (item_id,))
    cur.execute("DELETE FROM chat_messages WHERE item_id = ?", (item_id,))
    cur.execute("DELETE FROM items_fts WHERE rowid = ?", (item_id,))
    cur.execute("DELETE FROM items WHERE id = ?", (item_id,))
    conn.commit()


def can_chat_on_item(cur, item_id: int, user_id: int) -> dict[str, Any] | None:
    cur.execute("SELECT user_id, status FROM items WHERE id = ?", (item_id,))
    row = cur.fetchone()
    if not row:
        return None
    donor_id = int(row[0])
    if user_id == donor_id:
        return {"role": "donor", "donor_id": donor_id}
    cur.execute("SELECT id, status FROM item_applications WHERE item_id = ? AND user_id = ?", (item_id, user_id))
    a = cur.fetchone()
    if a:
        return {"role": "applicant", "donor_id": donor_id, "application_id": int(a[0])}
    return None


def geocode_address(address: str) -> dict[str, float] | None:
    import json
    import urllib.parse
    import urllib.request

    query = address.strip()
    if not query:
        return None
    q = query + ", Новосибирск"
    url = "https://nominatim.openstreetmap.org/search?format=json&limit=1&q=" + urllib.parse.quote(q)
    req = urllib.request.Request(url, headers={"User-Agent": "ZaberiRF/1.0 (Python)"})
    try:
        with urllib.request.urlopen(req, timeout=7) as resp:
            raw = resp.read().decode("utf-8", errors="replace")
    except Exception:
        return None
    try:
        decoded = json.loads(raw)
    except json.JSONDecodeError:
        return None
    if not isinstance(decoded, list) or not decoded:
        return None
    first = decoded[0]
    lat, lon = first.get("lat"), first.get("lon")
    if lat is None or lon is None:
        return None
    return {"lat": float(lat), "lon": float(lon)}


def save_avatar_file(user_id: int, storage: FileStorage) -> str | None:
    UPLOAD_DIR.mkdir(parents=True, exist_ok=True)
    suffix = Path(secure_filename(storage.filename) or "av").suffix
    fd, tmp_path = tempfile.mkstemp(suffix=suffix)
    try:
        import os

        os.close(fd)
        storage.save(tmp_path)
        valid = validate_image_file(tmp_path)
        if not valid:
            return None
        _, ext = valid
        fn = f"av_{user_id}_{secrets.token_hex(4)}.{ext}"
        dest = str(UPLOAD_DIR / fn)
        shutil.move(tmp_path, dest)
        tmp_path = ""
        return public_upload_path(fn)
    finally:
        if tmp_path and Path(tmp_path).exists():
            try:
                Path(tmp_path).unlink()
            except OSError:
                pass
