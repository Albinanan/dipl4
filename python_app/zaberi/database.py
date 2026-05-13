"""SQLite init/migrations (ported from PHP bootstrap)."""
from __future__ import annotations

import secrets
from datetime import datetime, timezone
from typing import Any

from werkzeug.security import generate_password_hash

from .config import DB_PATH


def _iso_now() -> str:
    return datetime.now(timezone.utc).replace(microsecond=0).isoformat()


def table_has_column(cur, table: str, column: str) -> bool:
    cur.execute(f"PRAGMA table_info({table})")
    for row in cur.fetchall():
        if row[1] == column:
            return True
    return False


def sync_item_fts(cur, item_id: int) -> None:
    cur.execute("SELECT title, description FROM items WHERE id = ?", (item_id,))
    row = cur.fetchone()
    cur.execute("DELETE FROM items_fts WHERE rowid = ?", (item_id,))
    if not row:
        return
    title, desc = str(row[0] or ""), str(row[1] or "")
    cur.execute(
        "INSERT INTO items_fts (rowid, title, description) VALUES (?, ?, ?)",
        (item_id, title, desc),
    )


def migrate_legacy_item_images(cur) -> None:
    cur.execute('SELECT id, image_path FROM items WHERE image_path IS NOT NULL AND image_path != ""')
    for row in cur.fetchall():
        iid = int(row[0])
        path = str(row[1])
        cur.execute("SELECT COUNT(*) FROM item_images WHERE item_id = ?", (iid,))
        if int(cur.fetchone()[0]) > 0:
            continue
        cur.execute(
            "INSERT INTO item_images (item_id, path, sort_order) VALUES (?, ?, 0)",
            (iid, path),
        )


def rebuild_all_fts(cur) -> None:
    try:
        cur.execute("SELECT COUNT(*) FROM items")
        ic = int(cur.fetchone()[0])
        cur.execute("SELECT COUNT(*) FROM items_fts")
        fc = int(cur.fetchone()[0])
    except Exception:
        return
    if ic == fc:
        return
    cur.execute("DELETE FROM items_fts")
    cur.execute("SELECT id FROM items")
    for (iid,) in cur.fetchall():
        sync_item_fts(cur, int(iid))


def seed_admin(cur) -> None:
    admin_email = "admin@zaberi.rf"
    cur.execute("SELECT id FROM users WHERE email = ?", (admin_email,))
    if cur.fetchone():
        cur.execute("UPDATE users SET email_verified = 1, phone_verified = 1 WHERE email = ?", (admin_email,))
        return
    cur.execute(
        """INSERT INTO users (name, email, password_hash, role, created_at, email_verified, phone_verified)
           VALUES (?, ?, ?, ?, ?, 1, 1)""",
        (
            "Администратор",
            admin_email,
            generate_password_hash("admin123"),
            "admin",
            _iso_now(),
        ),
    )


def initialize_database(conn) -> None:
    cur = conn.cursor()
    cur.executescript(
        """
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            role TEXT NOT NULL DEFAULT 'user',
            created_at TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            title TEXT NOT NULL,
            description TEXT,
            category TEXT NOT NULL,
            subcategory TEXT NOT NULL,
            address TEXT NOT NULL DEFAULT '',
            latitude REAL NOT NULL,
            longitude REAL NOT NULL,
            image_path TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id)
        );
        """
    )

    if not table_has_column(cur, "users", "role"):
        cur.execute('ALTER TABLE users ADD COLUMN role TEXT NOT NULL DEFAULT "user"')
    if not table_has_column(cur, "items", "address"):
        cur.execute('ALTER TABLE items ADD COLUMN address TEXT NOT NULL DEFAULT ""')
    if not table_has_column(cur, "items", "image_path"):
        cur.execute('ALTER TABLE items ADD COLUMN image_path TEXT NOT NULL DEFAULT ""')

    user_cols = {
        "phone": "TEXT",
        "email_verified": "INTEGER NOT NULL DEFAULT 0",
        "phone_verified": "INTEGER NOT NULL DEFAULT 0",
        "email_verify_token": "TEXT",
        "email_verify_expires": "TEXT",
        "phone_otp": "TEXT",
        "phone_otp_expires": "TEXT",
        'avatar_path': 'TEXT NOT NULL DEFAULT ""',
        'contact_phone': 'TEXT NOT NULL DEFAULT ""',
        'contact_email': 'TEXT NOT NULL DEFAULT ""',
        'contact_note': 'TEXT NOT NULL DEFAULT ""',
        "is_blocked": "INTEGER NOT NULL DEFAULT 0",
    }
    for col, definition in user_cols.items():
        if not table_has_column(cur, "users", col):
            cur.execute(f"ALTER TABLE users ADD COLUMN {col} {definition}")

    item_cols = {
        "status": "TEXT NOT NULL DEFAULT 'active'",
        "transferred_to_user_id": "INTEGER",
        "transferred_at": "TEXT",
    }
    for col, definition in item_cols.items():
        if not table_has_column(cur, "items", col):
            cur.execute(f"ALTER TABLE items ADD COLUMN {col} {definition}")

    cur.executescript(
        """
        CREATE TABLE IF NOT EXISTS item_images (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            item_id INTEGER NOT NULL,
            path TEXT NOT NULL,
            sort_order INTEGER NOT NULL DEFAULT 0,
            FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE
        );
        CREATE TABLE IF NOT EXISTS item_favorites (
            user_id INTEGER NOT NULL,
            item_id INTEGER NOT NULL,
            created_at TEXT NOT NULL,
            PRIMARY KEY (user_id, item_id),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE
        );
        CREATE TABLE IF NOT EXISTS item_applications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            item_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            message TEXT NOT NULL DEFAULT '',
            status TEXT NOT NULL DEFAULT 'pending',
            created_at TEXT NOT NULL,
            FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            UNIQUE (item_id, user_id)
        );
        CREATE TABLE IF NOT EXISTS chat_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            item_id INTEGER NOT NULL,
            sender_id INTEGER NOT NULL,
            recipient_id INTEGER NOT NULL,
            body TEXT NOT NULL,
            created_at TEXT NOT NULL,
            FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE,
            FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (recipient_id) REFERENCES users(id) ON DELETE CASCADE
        );
        CREATE INDEX IF NOT EXISTS idx_items_user ON items(user_id);
        CREATE INDEX IF NOT EXISTS idx_items_status ON items(status);
        CREATE INDEX IF NOT EXISTS idx_app_item ON item_applications(item_id);
        CREATE INDEX IF NOT EXISTS idx_chat_item ON chat_messages(item_id);
        """
    )

    cur.execute("CREATE VIRTUAL TABLE IF NOT EXISTS items_fts USING fts5(title, description)")
    migrate_legacy_item_images(cur)
    rebuild_all_fts(cur)
    seed_admin(cur)
    conn.commit()


def ensure_db_file() -> None:
    DB_PATH.parent.mkdir(parents=True, exist_ok=True)


def get_connection():
    import sqlite3

    ensure_db_file()
    conn = sqlite3.connect(DB_PATH, detect_types=sqlite3.PARSE_DECLTYPES)
    conn.row_factory = sqlite3.Row
    conn.execute("PRAGMA foreign_keys = ON")
    initialize_database(conn)
    return conn


def row_to_dict(row) -> dict[str, Any] | None:
    if row is None:
        return None
    return {k: row[k] for k in row.keys()}


def random_token(nbytes: int = 24) -> str:
    return secrets.token_hex(nbytes)


def generate_phone_otp() -> str:
    return str(secrets.randbelow(900000) + 100000)
