<?php
declare(strict_types=1);

$dbPath = __DIR__ . '/data/app.sqlite';
$schemaPath = __DIR__ . '/data/schema.sql';

if (!file_exists($schemaPath)) {
    exit("Не найден файл схемы: data/schema.sql\n");
}

if (!is_dir(__DIR__ . '/data')) {
    mkdir(__DIR__ . '/data', 0777, true);
}

$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$schema = file_get_contents($schemaPath);

if ($schema === false) {
    exit("Ошибка чтения schema.sql\n");
}

$pdo->exec($schema);
$stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email');
$stmt->execute(['email' => 'admin@zaberi.rf']);
if (!$stmt->fetch()) {
    $insert = $pdo->prepare(
        'INSERT INTO users (name, email, password_hash, role, created_at)
         VALUES (:name, :email, :password_hash, :role, :created_at)'
    );
    $insert->execute([
        'name' => 'Администратор',
        'email' => 'admin@zaberi.rf',
        'password_hash' => password_hash('admin123', PASSWORD_DEFAULT),
        'role' => 'admin',
        'created_at' => date('c'),
    ]);
}

echo "База данных создана: {$dbPath}\n";
echo "Администратор: admin@zaberi.rf / admin123\n";

