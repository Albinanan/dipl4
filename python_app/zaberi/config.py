import os
from pathlib import Path

PACKAGE_DIR = Path(__file__).resolve().parent
PYTHON_APP_DIR = PACKAGE_DIR.parent
PROJECT_ROOT = PYTHON_APP_DIR.parent  # repo root (dipl/)

DB_PATH = Path(os.environ.get("ZABERI_DB_PATH", str(PROJECT_ROOT / "data" / "app.sqlite")))
UPLOAD_DIR = Path(os.environ.get("ZABERI_UPLOAD_DIR", str(PROJECT_ROOT / "public" / "uploads")))
ASSETS_DIR = PROJECT_ROOT / "public" / "assets"


def public_upload_path(filename: str) -> str:
    return "/public/uploads/" + filename


SECRET_KEY = os.environ.get("SECRET_KEY", "dev-change-me-in-production")
