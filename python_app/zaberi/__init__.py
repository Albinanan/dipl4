from pathlib import Path

from flask import Flask

from .config import SECRET_KEY
from .routes import register_routes

_PKG_DIR = Path(__file__).resolve().parent
_PYTHON_APP_DIR = _PKG_DIR.parent


def create_app() -> Flask:
    app = Flask(__name__, template_folder=str(_PYTHON_APP_DIR / "templates"))
    app.secret_key = SECRET_KEY
    register_routes(app)
    return app
