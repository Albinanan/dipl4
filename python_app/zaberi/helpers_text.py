import unicodedata
from typing import Any


def categories() -> dict[str, Any]:
    return {
        "obuv": {"label": "Обувь", "subs": {"zhenskaya": "Женская", "muzhskaya": "Мужская", "detskaya": "Детская"}},
        "odezhda": {"label": "Одежда", "subs": {"zhenskaya": "Женская", "muzhskaya": "Мужская", "detskaya": "Детская"}},
        "tehnika": {
            "label": "Техника",
            "subs": {"computer": "Компьютер/Ноутбук", "tv": "Телевизор", "large": "Крупногабаритная"},
        },
        "mebel": {
            "label": "Мебель",
            "subs": {"divany": "Диваны", "stoly": "Столы", "stulya": "Стулья", "kresla": "Кресла", "kover": "Ковер"},
        },
        "pets": {
            "label": "Товары для животных",
            "subs": {"food": "Еда", "clothes": "Одежда", "toys": "Игрушки", "animals": "Животные"},
        },
        "trash": {
            "label": "Отсортированный мусор",
            "subs": {"plastic": "Пластик", "paper": "Бумага", "glass": "Стекло", "metal": "Металл", "batteries": "Батарейки"},
        },
        "other": {
            "label": "Прочее",
            "subs": {"windows": "Окна", "sinks": "Раковины", "radiators": "Батареи", "tires": "Шины"},
        },
    }


def _fts_tokenize(raw: str) -> list[str]:
    """Approximate PHP preg_match_all('/\\p{L}[\\p{L}\\p{N}]*/u', ...)."""
    terms: list[str] = []
    i = 0
    n = len(raw)
    while i < n:
        while i < n and not unicodedata.category(raw[i]).startswith("L"):
            i += 1
        if i >= n:
            break
        start = i
        i += 1
        while i < n:
            c = unicodedata.category(raw[i])
            if c.startswith("L") or c[0] == "N":
                i += 1
            else:
                break
        terms.append(raw[start:i])
    return terms


def fts_match_expression(raw: str) -> str | None:
    raw = raw.strip()
    if not raw:
        return None
    terms = _fts_tokenize(raw)
    parts: list[str] = []
    for t in terms:
        if len(t) < 2:
            continue
        parts.append('"' + t.replace('"', '""') + '"')
    if not parts:
        return None
    return " AND ".join(parts)


def user_public_profile(u: dict) -> dict:
    return {
        "id": int(u["id"]),
        "name": str(u.get("name") or ""),
        "avatar_path": str(u.get("avatar_path") or ""),
    }
