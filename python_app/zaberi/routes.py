from __future__ import annotations

import re
from datetime import datetime, timedelta, timezone

from flask import Flask, g, jsonify, redirect, render_template, request, send_from_directory, session, url_for
from werkzeug.security import generate_password_hash

from .passwords import verify_password

from .config import ASSETS_DIR, UPLOAD_DIR
from .database import generate_phone_otp, get_connection, random_token, sync_item_fts
from .helpers_media import (
    can_chat_on_item,
    delete_item_cascade,
    delete_local_upload,
    geocode_address,
    item_image_paths,
    save_avatar_file,
    save_uploaded_images,
)
from .helpers_text import categories, fts_match_expression


def _iso_now() -> str:
    return datetime.now(timezone.utc).replace(microsecond=0).isoformat()


def get_db():
    if "db" not in g:
        g.db = get_connection()
    return g.db


def _row(d) -> dict | None:
    if d is None:
        return None
    return {k: d[k] for k in d.keys()}


def current_user():
    uid = session.get("user_id")
    if not uid:
        return None
    conn = get_db()
    cur = conn.cursor()
    cur.execute(
        """SELECT id, name, email, phone, role, email_verified, phone_verified, avatar_path,
                  contact_phone, contact_email, contact_note, is_blocked
           FROM users WHERE id = ?""",
        (int(uid),),
    )
    return _row(cur.fetchone())


def require_auth():
    u = current_user()
    if u is None:
        return redirect(url_for("login"))
    if u.get("is_blocked"):
        session.clear()
        return redirect(url_for("login", blocked=1))
    return u


def require_admin():
    u = require_auth()
    if hasattr(u, "headers"):  # redirect response
        return u
    if (u.get("role") or "user") != "admin":
        return redirect(url_for("index"))
    return u


def register_routes(app: Flask) -> None:
    @app.teardown_appcontext
    def close_db(_exc):
        db = g.pop("db", None)
        if db is not None:
            db.close()

    @app.route("/public/assets/<path:filename>")
    def public_assets(filename):
        return send_from_directory(ASSETS_DIR, filename)

    @app.route("/public/uploads/<path:filename>")
    def public_uploads(filename):
        return send_from_directory(UPLOAD_DIR, filename)

    @app.route("/")
    def index():
        conn = get_db()
        cur = conn.cursor()
        cats = categories()
        q = (request.args.get("q") or "").strip()
        cat = (request.args.get("category") or "").strip()
        sub = (request.args.get("subcategory") or "").strip()

        match = fts_match_expression(q)
        plist: list = []
        sql = """SELECT i.*, u.name as user_name FROM items i
                 JOIN users u ON u.id = i.user_id WHERE i.status = 'active'"""
        if match is not None:
            sql += " AND i.id IN (SELECT rowid FROM items_fts WHERE items_fts MATCH ?)"
            plist.append(match)
        if cat and cat in cats:
            sql += " AND i.category = ?"
            plist.append(cat)
        if sub and cat and cat in cats and sub in cats[cat]["subs"]:
            sql += " AND i.subcategory = ?"
            plist.append(sub)
        sql += " ORDER BY i.id DESC LIMIT 24"
        try:
            cur.execute(sql, plist)
            list_items = [_row(r) for r in cur.fetchall()]
        except Exception:
            plist2: list = []
            sql2 = """SELECT i.*, u.name as user_name FROM items i
                      JOIN users u ON u.id = i.user_id WHERE i.status = 'active'"""
            if cat and cat in cats:
                sql2 += " AND i.category = ?"
                plist2.append(cat)
            if sub and cat and cat in cats and sub in cats[cat]["subs"]:
                sql2 += " AND i.subcategory = ?"
                plist2.append(sub)
            if q:
                sql2 += " AND (i.title LIKE ? OR i.description LIKE ?)"
                plist2.extend([f"%{q}%", f"%{q}%"])
            sql2 += " ORDER BY i.id DESC LIMIT 24"
            cur.execute(sql2, plist2)
            list_items = [_row(r) for r in cur.fetchall()]

        for row in list_items:
            row["images"] = item_image_paths(cur, int(row["id"]), row.get("image_path"))
        u = current_user()
        return render_template(
            "index.html",
            user=u,
            categories=cats,
            q=q,
            cat=cat,
            sub=sub,
            list_items=list_items,
        )

    @app.route("/login", methods=["GET", "POST"])
    def login():
        err = ""
        if request.args.get("blocked"):
            err = "Аккаунт заблокирован. Обратитесь в поддержку."
        if request.method == "POST":
            email = (request.form.get("email") or "").strip().lower()
            password = request.form.get("password") or ""
            conn = get_db()
            cur = conn.cursor()
            cur.execute("SELECT id, password_hash, is_blocked FROM users WHERE email = ?", (email,))
            row = cur.fetchone()
            user = _row(row) if row else None
            if user and verify_password(user.get("password_hash"), password):
                if user.get("is_blocked"):
                    err = "Аккаунт заблокирован."
                else:
                    session["user_id"] = int(user["id"])
                    return redirect(url_for("profile"))
            else:
                err = "Неверный email или пароль."
        return render_template("login.html", user=current_user(), error=err)

    @app.route("/register", methods=["GET", "POST"])
    def register():
        err = ""
        if request.method == "POST":
            name = (request.form.get("name") or "").strip()
            email = (request.form.get("email") or "").strip()
            phone = re.sub(r"\D+", "", request.form.get("phone") or "")
            password = request.form.get("password") or ""
            if not name or not email or not password:
                err = "Заполните имя, email и пароль."
            elif "@" not in email or "." not in email.split("@")[-1]:
                err = "Введите корректный email."
            elif phone and len(phone) < 10:
                err = "Укажите полный номер телефона или оставьте поле пустым."
            else:
                token = random_token(16)
                exp = (datetime.now(timezone.utc) + timedelta(days=1)).replace(microsecond=0).isoformat()
                phone_otp = generate_phone_otp() if phone else None
                potp_exp = (datetime.now(timezone.utc) + timedelta(minutes=15)).replace(microsecond=0).isoformat() if phone else None
                conn = get_db()
                cur = conn.cursor()
                try:
                    cur.execute(
                        """INSERT INTO users (name, email, password_hash, role, created_at, phone, email_verified, phone_verified,
                           email_verify_token, email_verify_expires, phone_otp, phone_otp_expires)
                           VALUES (?, ?, ?, 'user', ?, ?, 0, 0, ?, ?, ?, ?)""",
                        (
                            name,
                            email.lower(),
                            generate_password_hash(password),
                            _iso_now(),
                            phone or None,
                            token,
                            exp,
                            phone_otp,
                            potp_exp,
                        ),
                    )
                    conn.commit()
                    session["user_id"] = int(cur.lastrowid)
                    return redirect(url_for("profile", tab="settings", registered=1))
                except Exception:
                    err = "Пользователь с таким email или телефоном уже существует."
        return render_template("register.html", user=current_user(), error=err)

    @app.route("/logout")
    def logout():
        session.clear()
        return redirect(url_for("index"))

    @app.route("/verify-email")
    def verify_email():
        token = (request.args.get("token") or "").strip()
        ok = False
        if token:
            conn = get_db()
            cur = conn.cursor()
            cur.execute(
                """UPDATE users SET email_verified = 1, email_verify_token = NULL, email_verify_expires = NULL
                   WHERE email_verify_token = ? AND (email_verify_expires IS NULL OR email_verify_expires > ?)""",
                (token, _iso_now()),
            )
            conn.commit()
            ok = cur.rowcount > 0
        return render_template("verify_email.html", user=current_user(), ok=ok)

    @app.route("/profile", methods=["GET", "POST"])
    def profile():
        auth = require_auth()
        if hasattr(auth, "headers"):
            return auth
        user = auth
        conn = get_db()
        cur = conn.cursor()
        cats = categories()
        tab = request.args.get("tab") or "listings"
        if tab not in ("listings", "add", "responses", "favorites", "settings"):
            tab = "listings"
        error = ""
        success = ""
        post_tab = ""

        if request.method == "POST":
            post_tab = request.form.get("form_tab") or ""

            if post_tab == "settings":
                name = (request.form.get("name") or "").strip()
                contact_phone = (request.form.get("contact_phone") or "").strip()
                contact_email = (request.form.get("contact_email") or "").strip()
                contact_note = (request.form.get("contact_note") or "").strip()
                phone_digits = re.sub(r"\D+", "", request.form.get("phone") or "")
                tab = "settings"
                if not name:
                    error = "Укажите имя."
                else:
                    cur.execute(
                        "UPDATE users SET name = ?, contact_phone = ?, contact_email = ?, contact_note = ? WHERE id = ?",
                        (name, contact_phone, contact_email, contact_note, int(user["id"])),
                    )
                    if phone_digits:
                        cur.execute("UPDATE users SET phone = ?, phone_verified = 0 WHERE id = ?", (phone_digits, int(user["id"])))
                    f = request.files.get("avatar")
                    if f and f.filename:
                        pub = save_avatar_file(int(user["id"]), f)
                        if pub:
                            old = str(user.get("avatar_path") or "")
                            delete_local_upload(old)
                            cur.execute("UPDATE users SET avatar_path = ? WHERE id = ?", (pub, int(user["id"])))
                    conn.commit()
                    success = "Профиль обновлён."
                    tab = "settings"

            elif post_tab == "resend_email":
                token = random_token(16)
                exp = (datetime.now(timezone.utc) + timedelta(days=1)).replace(microsecond=0).isoformat()
                cur.execute(
                    "UPDATE users SET email_verify_token = ?, email_verify_expires = ? WHERE id = ?",
                    (token, exp, int(user["id"])),
                )
                conn.commit()
                success = "Ссылка для подтверждения: " + url_for("verify_email", token=token, _external=True)
                tab = "settings"

            elif post_tab == "send_phone_otp":
                digits = re.sub(r"\D+", "", str(user.get("phone") or ""))
                if not digits:
                    error = "Сначала укажите телефон в настройках и сохраните профиль."
                else:
                    otp = generate_phone_otp()
                    exp = (datetime.now(timezone.utc) + timedelta(minutes=15)).replace(microsecond=0).isoformat()
                    cur.execute(
                        "UPDATE users SET phone_otp = ?, phone_otp_expires = ? WHERE id = ?",
                        (otp, exp, int(user["id"])),
                    )
                    conn.commit()
                    success = "Код подтверждения телефона (демо): " + otp
                tab = "settings"

            elif post_tab == "verify_phone":
                code = (request.form.get("phone_code") or "").strip()
                cur.execute("SELECT phone_otp, phone_otp_expires FROM users WHERE id = ?", (int(user["id"]),))
                row = cur.fetchone()
                if row and code == str(row[0] or "") and str(row[1] or "") > _iso_now():
                    cur.execute(
                        "UPDATE users SET phone_verified = 1, phone_otp = NULL, phone_otp_expires = NULL WHERE id = ?",
                        (int(user["id"]),),
                    )
                    conn.commit()
                    success = "Телефон подтверждён."
                else:
                    error = "Неверный или просроченный код."
                tab = "settings"

            elif post_tab == "add_item":
                title = (request.form.get("title") or "").strip()
                description = (request.form.get("description") or "").strip()
                category = request.form.get("category") or ""
                subcategory = request.form.get("subcategory") or ""
                address = (request.form.get("address") or "").strip()
                coords = None
                if not title or category not in cats or subcategory not in cats[category]["subs"]:
                    error = "Проверьте название и категорию."
                elif not address:
                    error = "Укажите адрес."
                else:
                    coords = geocode_address(address)
                    if coords is None:
                        error = "Адрес не найден. Укажите более точный адрес в Новосибирске."
                paths: list = []
                if not error:
                    files = request.files.getlist("photos")
                    paths = save_uploaded_images([f for f in files if f and f.filename], 5)
                if not error:
                    cur.execute(
                        """INSERT INTO items (user_id, title, description, category, subcategory, address, latitude, longitude, image_path, created_at, status)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')""",
                        (
                            int(user["id"]),
                            title,
                            description,
                            category,
                            subcategory,
                            address,
                            coords["lat"],
                            coords["lon"],
                            paths[0] if paths else "",
                            _iso_now(),
                        ),
                    )
                    conn.commit()
                    new_id = int(cur.lastrowid)
                    for i, p in enumerate(paths):
                        cur.execute(
                            "INSERT INTO item_images (item_id, path, sort_order) VALUES (?, ?, ?)",
                            (new_id, p, i),
                        )
                    conn.commit()
                    sync_item_fts(cur, new_id)
                    conn.commit()
                    return redirect(url_for("profile", tab="listings", created=1))
                tab = "add"

        cur.execute(
            """SELECT id, name, email, phone, role, email_verified, phone_verified, avatar_path, contact_phone, contact_email, contact_note
               FROM users WHERE id = ?""",
            (int(user["id"]),),
        )
        user = _row(cur.fetchone()) or user

        cur.execute("SELECT * FROM items WHERE user_id = ? ORDER BY id DESC", (int(user["id"]),))
        my_items = [_row(r) for r in cur.fetchall()]
        for it in my_items:
            it["images"] = item_image_paths(cur, int(it["id"]), it.get("image_path"))

        cur.execute(
            """SELECT a.*, i.title as item_title, i.id as item_id, i.status as item_status
               FROM item_applications a JOIN items i ON i.id = a.item_id
               WHERE a.user_id = ? ORDER BY a.id DESC""",
            (int(user["id"]),),
        )
        responses = [_row(r) for r in cur.fetchall()]

        cur.execute(
            """SELECT i.*, f.created_at as fav_at FROM item_favorites f JOIN items i ON i.id = f.item_id
               WHERE f.user_id = ? ORDER BY f.created_at DESC""",
            (int(user["id"]),),
        )
        favorites = [_row(r) for r in cur.fetchall()]
        for it in favorites:
            it["images"] = item_image_paths(cur, int(it["id"]), it.get("image_path"))

        return render_template(
            "profile.html",
            user=user,
            categories=cats,
            tab=tab,
            error=error,
            success=success,
            post_tab=post_tab,
            my_items=my_items,
            responses=responses,
            favorites=favorites,
        )

    @app.route("/item/<int:iid>", methods=["GET", "POST"])
    def item_page(iid):
        conn = get_db()
        cur = conn.cursor()
        cur.execute(
            """SELECT i.*, u.name as donor_name, u.avatar_path as donor_avatar FROM items i
               JOIN users u ON u.id = i.user_id WHERE i.id = ?""",
            (iid,),
        )
        item = _row(cur.fetchone())
        if not item:
            return "Объявление не найдено", 404
        u = current_user()
        is_owner = u and int(u["id"]) == int(item["user_id"])
        images = item_image_paths(cur, iid, item.get("image_path"))
        cats = categories()

        if request.method == "POST" and u:
            action = request.form.get("action") or ""
            if action == "apply" and not is_owner and int(item["user_id"]) != int(u["id"]):
                if (item.get("status") or "") != "active":
                    return redirect(url_for("item_page", iid=iid))
                msg = (request.form.get("message") or "").strip() or "Здравствуйте! Хочу забрать вещь."
                try:
                    cur.execute(
                        'INSERT INTO item_applications (item_id, user_id, message, status, created_at) VALUES (?, ?, ?, "pending", ?)',
                        (iid, int(u["id"]), msg, _iso_now()),
                    )
                    conn.commit()
                except Exception:
                    pass
                return redirect(url_for("item_page", iid=iid, applied=1))
            if action == "favorite":
                cur.execute("SELECT 1 FROM item_favorites WHERE user_id = ? AND item_id = ?", (int(u["id"]), iid))
                if cur.fetchone():
                    cur.execute("DELETE FROM item_favorites WHERE user_id = ? AND item_id = ?", (int(u["id"]), iid))
                else:
                    cur.execute(
                        "INSERT INTO item_favorites (user_id, item_id, created_at) VALUES (?, ?, ?)",
                        (int(u["id"]), iid, _iso_now()),
                    )
                conn.commit()
                return redirect(url_for("item_page", iid=iid))
            if is_owner:
                if action == "accept" and (item.get("status") or "") == "active":
                    app_user = int(request.form.get("user_id") or 0)
                    cur.execute('UPDATE item_applications SET status = "accepted" WHERE item_id = ? AND user_id = ?', (iid, app_user))
                    cur.execute(
                        'UPDATE item_applications SET status = "rejected" WHERE item_id = ? AND user_id != ? AND status = "pending"',
                        (iid, app_user),
                    )
                    conn.commit()
                    return redirect(url_for("item_page", iid=iid))
                if action == "reject":
                    app_user = int(request.form.get("user_id") or 0)
                    cur.execute('UPDATE item_applications SET status = "rejected" WHERE item_id = ? AND user_id = ?', (iid, app_user))
                    conn.commit()
                    return redirect(url_for("item_page", iid=iid))
                if action == "transfer" and (item.get("status") or "") == "active":
                    to = int(request.form.get("user_id") or 0)
                    cur.execute(
                        'SELECT id FROM item_applications WHERE item_id = ? AND user_id = ? AND status = "accepted"',
                        (iid, to),
                    )
                    if cur.fetchone():
                        cur.execute(
                            'UPDATE items SET status = "transferred", transferred_to_user_id = ?, transferred_at = ? WHERE id = ?',
                            (to, _iso_now(), iid),
                        )
                        conn.commit()
                        sync_item_fts(cur, iid)
                        conn.commit()
                    return redirect(url_for("item_page", iid=iid))

        fav = False
        if u:
            cur.execute("SELECT 1 FROM item_favorites WHERE user_id = ? AND item_id = ?", (int(u["id"]), iid))
            fav = cur.fetchone() is not None

        applications = []
        if is_owner:
            cur.execute(
                """SELECT a.*, u.name as applicant_name, u.avatar_path as applicant_avatar
                   FROM item_applications a JOIN users u ON u.id = a.user_id
                   WHERE a.item_id = ? ORDER BY a.id DESC""",
                (iid,),
            )
            applications = [_row(r) for r in cur.fetchall()]

        my_app = None
        if u and not is_owner:
            cur.execute("SELECT * FROM item_applications WHERE item_id = ? AND user_id = ?", (iid, int(u["id"])))
            my_app = _row(cur.fetchone())

        chat_peer = int(request.args.get("chat_with") or 0)
        can_chat = can_chat_on_item(cur, iid, int(u["id"])) if u else None
        donor_id = int(item["user_id"])
        peer_for_chat = 0
        show_chat = False
        if u and can_chat and (item.get("status") or "") != "transferred":
            if can_chat["role"] == "applicant":
                peer_for_chat = donor_id
                show_chat = True
            elif can_chat["role"] == "donor" and chat_peer > 0:
                peer_for_chat = chat_peer
                show_chat = True

        accepted_apps = [a for a in applications if a.get("status") == "accepted"]
        first_accepted = accepted_apps[0] if accepted_apps else None

        return render_template(
            "item.html",
            user=u,
            item=item,
            item_id=iid,
            images=images,
            categories=cats,
            is_owner=is_owner,
            fav=fav,
            applications=applications,
            my_app=my_app,
            peer_for_chat=peer_for_chat,
            show_chat=show_chat,
            first_accepted=first_accepted,
        )

    @app.route("/item/<int:iid>/edit", methods=["GET", "POST"])
    def item_edit(iid):
        auth = require_auth()
        if hasattr(auth, "headers"):
            return auth
        user = auth
        conn = get_db()
        cur = conn.cursor()
        cur.execute("SELECT * FROM items WHERE id = ? AND user_id = ?", (iid, int(user["id"])))
        item = _row(cur.fetchone())
        if not item:
            return "Не найдено", 404
        if (item.get("status") or "") == "transferred":
            return redirect(url_for("item_page", iid=iid))
        cats = categories()
        error = ""
        success = ""

        if request.method == "POST":
            action = request.form.get("action") or "save"
            if action == "delete":
                delete_item_cascade(conn, iid)
                return redirect(url_for("profile", tab="listings", deleted=1))
            title = (request.form.get("title") or "").strip()
            description = (request.form.get("description") or "").strip()
            category = request.form.get("category") or ""
            subcategory = request.form.get("subcategory") or ""
            address = (request.form.get("address") or "").strip()
            coords = None
            if not title or category not in cats or subcategory not in cats[category]["subs"]:
                error = "Проверьте название и категорию."
            elif not address:
                error = "Укажите адрес."
            else:
                coords = geocode_address(address)
                if coords is None:
                    error = "Адрес не найден. Укажите более точный адрес в Новосибирске."
            if not error:
                remove_ids = [int(x) for x in request.form.getlist("remove_image") if str(x).isdigit()]
                cur.execute("SELECT id, path FROM item_images WHERE item_id = ?", (iid,))
                for ex in cur.fetchall():
                    if int(ex[0]) in remove_ids:
                        cur.execute("DELETE FROM item_images WHERE id = ?", (int(ex[0]),))
                        delete_local_upload(str(ex[1]))
                if request.form.get("remove_legacy"):
                    lp = str(item.get("image_path") or "")
                    if lp:
                        delete_local_upload(lp)
                        cur.execute("UPDATE items SET image_path = ? WHERE id = ?", ("", iid))
                        item["image_path"] = ""
                cur.execute("SELECT COUNT(*) FROM item_images WHERE item_id = ?", (iid,))
                current_count = int(cur.fetchone()[0])
                cur.execute("SELECT image_path FROM items WHERE id = ?", (iid,))
                ip_now = str(cur.fetchone()[0] or "")
                legacy_left = 1 if ip_now and current_count == 0 else 0
                total_slots = current_count + legacy_left
                allowed_new = max(0, 5 - total_slots)
                new_paths: list = []
                if allowed_new > 0:
                    files = request.files.getlist("photos")
                    new_paths = save_uploaded_images([f for f in files if f and f.filename], allowed_new)
                if total_slots + len(new_paths) > 5:
                    error = "Не более 5 фотографий всего."
                    for p in new_paths:
                        delete_local_upload(p)
                else:
                    cur.execute("SELECT COALESCE(MAX(sort_order), -1) FROM item_images WHERE item_id = ?", (iid,))
                    max_sort = int(cur.fetchone()[0])
                    sort = max_sort + 1
                    for p in new_paths:
                        cur.execute(
                            "INSERT INTO item_images (item_id, path, sort_order) VALUES (?, ?, ?)",
                            (iid, p, sort),
                        )
                        sort += 1
                    cur.execute(
                        """UPDATE items SET title = ?, description = ?, category = ?, subcategory = ?, address = ?, latitude = ?, longitude = ? WHERE id = ?""",
                        (title, description, category, subcategory, address, coords["lat"], coords["lon"], iid),
                    )
                    conn.commit()
                    sync_item_fts(cur, iid)
                    conn.commit()
                    success = "Сохранено."
                    cur.execute("SELECT * FROM items WHERE id = ? AND user_id = ?", (iid, int(user["id"])))
                    item = _row(cur.fetchone()) or item

        cur.execute("SELECT id, path FROM item_images WHERE item_id = ? ORDER BY sort_order, id", (iid,))
        db_images = [{"id": r[0], "path": r[1]} for r in cur.fetchall()]
        legacy_path = str(item.get("image_path") or "")
        legacy_standalone = bool(legacy_path and not db_images)

        return render_template(
            "item_edit.html",
            user=user,
            item=item,
            item_id=iid,
            categories=cats,
            error=error,
            success=success,
            db_images=db_images,
            legacy_path=legacy_path,
            legacy_standalone=legacy_standalone,
        )

    @app.route("/map")
    def map_page():
        return render_template("map.html", user=current_user(), categories=categories())

    @app.route("/api/items")
    def api_items():
        conn = get_db()
        cur = conn.cursor()
        cats = categories()
        category = (request.args.get("category") or "").strip()
        subcategory = (request.args.get("subcategory") or "").strip()
        q = (request.args.get("q") or "").strip()
        match = fts_match_expression(q)
        plist: list = []
        sql = """SELECT i.*, u.name as user_name FROM items i JOIN users u ON u.id = i.user_id WHERE i.status = 'active'"""
        if match is not None:
            sql += " AND i.id IN (SELECT rowid FROM items_fts WHERE items_fts MATCH ?)"
            plist.append(match)
        if category:
            sql += " AND i.category = ?"
            plist.append(category)
        if subcategory:
            sql += " AND i.subcategory = ?"
            plist.append(subcategory)
        sql += " ORDER BY i.id DESC LIMIT 200"
        try:
            cur.execute(sql, plist)
            items = [_row(r) for r in cur.fetchall()]
        except Exception:
            plist2: list = []
            sql2 = """SELECT i.*, u.name as user_name FROM items i JOIN users u ON u.id = i.user_id WHERE i.status = 'active'"""
            if category:
                sql2 += " AND i.category = ?"
                plist2.append(category)
            if subcategory:
                sql2 += " AND i.subcategory = ?"
                plist2.append(subcategory)
            if q:
                sql2 += " AND (i.title LIKE ? OR i.description LIKE ?)"
                plist2.extend([f"%{q}%", f"%{q}%"])
            sql2 += " ORDER BY i.id DESC LIMIT 200"
            cur.execute(sql2, plist2)
            items = [_row(r) for r in cur.fetchall()]
        for row in items:
            row["images"] = item_image_paths(cur, int(row["id"]), row.get("image_path"))
        return jsonify({"items": items})

    @app.route("/api/chat", methods=["GET", "POST"])
    def api_chat():
        if not session.get("user_id"):
            return jsonify({"error": "unauthorized"}), 401
        me = current_user()
        if not me or me.get("is_blocked"):
            return jsonify({"error": "unauthorized"}), 401
        uid = int(me["id"])
        item_id = int(request.values.get("item_id") or 0)
        peer_id = int(request.values.get("peer_id") or 0)
        if item_id <= 0 or peer_id <= 0:
            return jsonify({"error": "bad_request"}), 400
        conn = get_db()
        cur = conn.cursor()
        ctx = can_chat_on_item(cur, item_id, uid)
        if ctx is None:
            return jsonify({"error": "forbidden"}), 403
        donor_id = int(ctx["donor_id"])
        allowed = (uid == donor_id and peer_id != donor_id) or (uid != donor_id and peer_id == donor_id)
        if not allowed:
            return jsonify({"error": "forbidden"}), 403
        applicant_id = peer_id if uid == donor_id else uid
        cur.execute("SELECT id FROM item_applications WHERE item_id = ? AND user_id = ?", (item_id, applicant_id))
        if not cur.fetchone():
            return jsonify({"error": "no_application"}), 403

        if request.method == "POST":
            body = (request.form.get("body") or "").strip()
            if not body or len(body) > 2000:
                return jsonify({"error": "bad_body"}), 400
            cur.execute(
                "INSERT INTO chat_messages (item_id, sender_id, recipient_id, body, created_at) VALUES (?, ?, ?, ?, ?)",
                (item_id, uid, peer_id, body, _iso_now()),
            )
            conn.commit()
            return jsonify({"ok": True})

        cur.execute(
            """SELECT m.*, u.name AS sender_name, u.avatar_path AS sender_avatar
               FROM chat_messages m JOIN users u ON u.id = m.sender_id
               WHERE m.item_id = ?
               AND ((m.sender_id = ? AND m.recipient_id = ?) OR (m.sender_id = ? AND m.recipient_id = ?))
               ORDER BY m.id ASC""",
            (item_id, uid, peer_id, peer_id, uid),
        )
        msgs = []
        for r in cur.fetchall():
            m = _row(r)
            m["mine"] = int(m["sender_id"]) == uid
            msgs.append(m)
        return jsonify({"messages": msgs})

    @app.route("/admin", methods=["GET", "POST"])
    def admin():
        auth = require_admin()
        if hasattr(auth, "headers"):
            return auth
        admin_user = auth
        conn = get_db()
        cur = conn.cursor()
        cats = categories()
        message = ""
        err = ""
        if request.method == "POST":
            action = request.form.get("action") or ""
            pid = int(request.form.get("id") or 0)
            if action == "delete_item" and pid > 0:
                delete_item_cascade(conn, pid)
                message = "Объявление удалено."
            elif action == "toggle_block" and pid > 0:
                if pid == int(admin_user["id"]):
                    err = "Нельзя заблокировать самого себя."
                else:
                    cur.execute("SELECT is_blocked FROM users WHERE id = ?", (pid,))
                    row = cur.fetchone()
                    if row:
                        new_val = 0 if row[0] else 1
                        cur.execute("UPDATE users SET is_blocked = ? WHERE id = ?", (new_val, pid))
                        conn.commit()
                        message = "Пользователь заблокирован." if new_val else "Блокировка снята."
            elif action == "delete_user" and pid > 0:
                if pid == int(admin_user["id"]):
                    err = "Нельзя удалить текущего администратора."
                else:
                    cur.execute("SELECT id FROM items WHERE user_id = ?", (pid,))
                    for (iid,) in cur.fetchall():
                        delete_item_cascade(conn, int(iid))
                    cur.execute("DELETE FROM users WHERE id = ?", (pid,))
                    conn.commit()
                    message = "Пользователь удалён."

        cur.execute("SELECT id, name, email, role, created_at, is_blocked FROM users ORDER BY id DESC")
        users = [_row(r) for r in cur.fetchall()]
        cur.execute("SELECT i.*, u.name as user_name FROM items i JOIN users u ON u.id = i.user_id ORDER BY i.id DESC")
        items = [_row(r) for r in cur.fetchall()]
        for it in items:
            imgs = item_image_paths(cur, int(it["id"]), it.get("image_path"))
            it["thumb"] = imgs[0] if imgs else None

        return render_template(
            "admin.html",
            user=admin_user,
            categories=cats,
            admin_user=admin_user,
            message=message,
            error=err,
            users=users,
            items=items,
        )

    @app.route("/user/<int:uid>")
    def user_public(uid):
        conn = get_db()
        cur = conn.cursor()
        cur.execute("SELECT id, name, avatar_path FROM users WHERE id = ? AND is_blocked = 0", (uid,))
        u = _row(cur.fetchone())
        if not u:
            return "Пользователь не найден", 404
        return render_template("user.html", user=current_user(), profile=u)
