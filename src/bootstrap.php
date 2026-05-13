<?php
declare(strict_types=1);

session_start();

const DB_PATH = __DIR__ . '/../data/app.sqlite';

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    initializeDatabase($pdo);

    return $pdo;
}

function initializeDatabase(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            role TEXT NOT NULL DEFAULT "user",
            created_at TEXT NOT NULL
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            title TEXT NOT NULL,
            description TEXT,
            category TEXT NOT NULL,
            subcategory TEXT NOT NULL,
            address TEXT NOT NULL DEFAULT "",
            latitude REAL NOT NULL,
            longitude REAL NOT NULL,
            image_path TEXT NOT NULL DEFAULT "",
            created_at TEXT NOT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id)
        )'
    );

    migrateDatabase($pdo);
    seedAdmin($pdo);
}

function migrateDatabase(PDO $pdo): void
{
    if (!tableHasColumn($pdo, 'users', 'role')) {
        $pdo->exec('ALTER TABLE users ADD COLUMN role TEXT NOT NULL DEFAULT "user"');
    }
    if (!tableHasColumn($pdo, 'items', 'address')) {
        $pdo->exec('ALTER TABLE items ADD COLUMN address TEXT NOT NULL DEFAULT ""');
    }
    if (!tableHasColumn($pdo, 'items', 'image_path')) {
        $pdo->exec('ALTER TABLE items ADD COLUMN image_path TEXT NOT NULL DEFAULT ""');
    }

    $userCols = [
        'phone' => 'TEXT',
        'email_verified' => 'INTEGER NOT NULL DEFAULT 0',
        'phone_verified' => 'INTEGER NOT NULL DEFAULT 0',
        'email_verify_token' => 'TEXT',
        'email_verify_expires' => 'TEXT',
        'phone_otp' => 'TEXT',
        'phone_otp_expires' => 'TEXT',
        'avatar_path' => 'TEXT NOT NULL DEFAULT ""',
        'contact_phone' => 'TEXT NOT NULL DEFAULT ""',
        'contact_email' => 'TEXT NOT NULL DEFAULT ""',
        'contact_note' => 'TEXT NOT NULL DEFAULT ""',
        'is_blocked' => 'INTEGER NOT NULL DEFAULT 0',
    ];
    foreach ($userCols as $col => $def) {
        if (!tableHasColumn($pdo, 'users', $col)) {
            $pdo->exec('ALTER TABLE users ADD COLUMN ' . $col . ' ' . $def);
        }
    }

    $itemCols = [
        'status' => 'TEXT NOT NULL DEFAULT "active"',
        'transferred_to_user_id' => 'INTEGER',
        'transferred_at' => 'TEXT',
    ];
    foreach ($itemCols as $col => $def) {
        if (!tableHasColumn($pdo, 'items', $col)) {
            $pdo->exec('ALTER TABLE items ADD COLUMN ' . $col . ' ' . $def);
        }
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS item_images (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            item_id INTEGER NOT NULL,
            path TEXT NOT NULL,
            sort_order INTEGER NOT NULL DEFAULT 0,
            FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS item_favorites (
            user_id INTEGER NOT NULL,
            item_id INTEGER NOT NULL,
            created_at TEXT NOT NULL,
            PRIMARY KEY (user_id, item_id),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS item_applications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            item_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            message TEXT NOT NULL DEFAULT "",
            status TEXT NOT NULL DEFAULT "pending",
            created_at TEXT NOT NULL,
            FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            UNIQUE (item_id, user_id)
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS chat_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            item_id INTEGER NOT NULL,
            sender_id INTEGER NOT NULL,
            recipient_id INTEGER NOT NULL,
            body TEXT NOT NULL,
            created_at TEXT NOT NULL,
            FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE,
            FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (recipient_id) REFERENCES users(id) ON DELETE CASCADE
        )'
    );

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_items_user ON items(user_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_items_status ON items(status)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_app_item ON item_applications(item_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_chat_item ON chat_messages(item_id)');

    ensureFts($pdo);
    migrateLegacyItemImages($pdo);
    rebuildAllFts($pdo);
}

function ensureFts(PDO $pdo): void
{
    $pdo->exec('CREATE VIRTUAL TABLE IF NOT EXISTS items_fts USING fts5(title, description)');
}

function migrateLegacyItemImages(PDO $pdo): void
{
    $stmt = $pdo->query('SELECT id, image_path FROM items WHERE image_path IS NOT NULL AND image_path != ""');
    foreach ($stmt->fetchAll() as $row) {
        $id = (int) $row['id'];
        $path = (string) $row['image_path'];
        $c = $pdo->prepare('SELECT COUNT(*) FROM item_images WHERE item_id = ?');
        $c->execute([$id]);
        if ((int) $c->fetchColumn() > 0) {
            continue;
        }
        $pdo->prepare('INSERT INTO item_images (item_id, path, sort_order) VALUES (?, ?, 0)')->execute([$id, $path]);
    }
}

function rebuildAllFts(PDO $pdo): void
{
    require_once __DIR__ . '/helpers.php';
    try {
        $ic = (int) $pdo->query('SELECT COUNT(*) FROM items')->fetchColumn();
        $fc = (int) $pdo->query('SELECT COUNT(*) FROM items_fts')->fetchColumn();
    } catch (Throwable) {
        return;
    }
    if ($ic === $fc) {
        return;
    }
    $pdo->exec('DELETE FROM items_fts');
    $ids = $pdo->query('SELECT id FROM items')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) {
        syncItemFts($pdo, (int) $id);
    }
}

function tableHasColumn(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->query('PRAGMA table_info(' . $table . ')');
    $columns = $stmt->fetchAll();
    foreach ($columns as $col) {
        if (($col['name'] ?? '') === $column) {
            return true;
        }
    }

    return false;
}

function seedAdmin(PDO $pdo): void
{
    $adminEmail = 'admin@zaberi.rf';
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email');
    $stmt->execute(['email' => $adminEmail]);
    if ($stmt->fetch()) {
        $pdo->prepare('UPDATE users SET email_verified = 1, phone_verified = 1 WHERE email = ?')->execute([$adminEmail]);

        return;
    }

    $create = $pdo->prepare(
        'INSERT INTO users (name, email, password_hash, role, created_at, email_verified, phone_verified)
         VALUES (:name, :email, :password_hash, :role, :created_at, 1, 1)'
    );
    $create->execute([
        'name' => 'Администратор',
        'email' => $adminEmail,
        'password_hash' => password_hash('admin123', PASSWORD_DEFAULT),
        'role' => 'admin',
        'created_at' => date('c'),
    ]);
}

function geocodeAddress(string $address): ?array
{
    $query = trim($address);
    if ($query === '') {
        return null;
    }

    $url = 'https://nominatim.openstreetmap.org/search?format=json&limit=1&q=' . urlencode($query . ', Новосибирск');
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "User-Agent: ZaberiRF/1.0\r\n",
            'timeout' => 7,
        ],
    ]);

    $raw = @file_get_contents($url, false, $context);
    if ($raw === false) {
        return null;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || empty($decoded[0]['lat']) || empty($decoded[0]['lon'])) {
        return null;
    }

    return [
        'lat' => (float) $decoded[0]['lat'],
        'lon' => (float) $decoded[0]['lon'],
    ];
}

function categories(): array
{
    return [
        'obuv' => ['label' => 'Обувь', 'subs' => ['zhenskaya' => 'Женская', 'muzhskaya' => 'Мужская', 'detskaya' => 'Детская']],
        'odezhda' => ['label' => 'Одежда', 'subs' => ['zhenskaya' => 'Женская', 'muzhskaya' => 'Мужская', 'detskaya' => 'Детская']],
        'tehnika' => ['label' => 'Техника', 'subs' => ['computer' => 'Компьютер/Ноутбук', 'tv' => 'Телевизор', 'large' => 'Крупногабаритная']],
        'mebel' => ['label' => 'Мебель', 'subs' => ['divany' => 'Диваны', 'stoly' => 'Столы', 'stulya' => 'Стулья', 'kresla' => 'Кресла', 'kover' => 'Ковер']],
        'pets' => ['label' => 'Товары для животных', 'subs' => ['food' => 'Еда', 'clothes' => 'Одежда', 'toys' => 'Игрушки', 'animals' => 'Животные']],
        'trash' => ['label' => 'Отсортированный мусор', 'subs' => ['plastic' => 'Пластик', 'paper' => 'Бумага', 'glass' => 'Стекло', 'metal' => 'Металл', 'batteries' => 'Батарейки']],
        'other' => ['label' => 'Прочее', 'subs' => ['windows' => 'Окна', 'sinks' => 'Раковины', 'radiators' => 'Батареи', 'tires' => 'Шины']],
    ];
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function randomToken(int $bytes = 24): string
{
    return bin2hex(random_bytes($bytes));
}

function generatePhoneOtp(): string
{
    return (string) random_int(100000, 999999);
}
