<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';

$uid = (int) ($_GET['id'] ?? 0);
if ($uid <= 0) {
    header('Location: /public/index.php');
    exit;
}

$stmt = db()->prepare('SELECT id, name, avatar_path FROM users WHERE id = ? AND is_blocked = 0');
$stmt->execute([$uid]);
$u = $stmt->fetch();
if (!$u) {
    http_response_code(404);
    echo 'Пользователь не найден';
    exit;
}
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($u['name']) ?> — Забери.РФ</title>
    <link rel="stylesheet" href="/public/assets/app.css">
</head>
<body>
<?php include __DIR__ . '/partials/header.php'; ?>
<div class="wrap">
    <div class="card" style="max-width:480px;">
        <div class="donor-row">
            <?php $av = (string) ($u['avatar_path'] ?? ''); ?>
            <?php if ($av !== ''): ?>
                <img class="avatar" src="<?= h($av) ?>" alt="" style="width:80px;height:80px;">
            <?php else: ?>
                <img class="avatar" src="https://placehold.co/160x160?text=?" alt="" style="width:80px;height:80px;">
            <?php endif; ?>
            <div>
                <h2 style="margin:0;"><?= h($u['name']) ?></h2>
                <p class="muted small">Контакты и личные данные скрыты.</p>
            </div>
        </div>
        <p><a href="/public/index.php">← На главную</a></p>
    </div>
</div>
</body>
</html>
