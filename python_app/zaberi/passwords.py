"""Verify passwords from PHP (bcrypt $2y$) and from Werkzeug (pbkdf2 / scrypt)."""
from __future__ import annotations

import bcrypt
from werkzeug.security import check_password_hash


def verify_password(stored_hash: str | None, password: str) -> bool:
    if not password or stored_hash is None:
        return False
    stored = str(stored_hash).strip()
    if not stored:
        return False
    # PHP password_hash(..., PASSWORD_DEFAULT) → bcrypt, usually $2y$...
    if stored.startswith("$2"):
        try:
            h = stored.encode("utf-8")
            if h.startswith(b"$2y$"):
                h = b"$2b$" + h[4:]
            return bcrypt.checkpw(password.encode("utf-8"), h)
        except (ValueError, TypeError):
            return False
    try:
        return check_password_hash(stored, password)
    except ValueError:
        return False
