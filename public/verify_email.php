<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';

$token = trim((string) ($_GET['token'] ?? ''));
$ok = false;
if ($token !== '') {
    $stmt = db()->prepare(
        'UPDATE users SET email_verified = 1, email_verify_token = NULL, email_verify_expires = NULL
         WHERE email_verify_token = :t AND (email_verify_expires IS NULL OR email_verify_expires > :now)'
    );
    $stmt->execute(['t' => $token, 'now' => date('c')]);
    $ok = $stmt->rowCount() > 0;
}
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Подтверждение email — Забери.РФ</title>
    <link rel="stylesheet" href="/public/assets/app.css">
</head>
<body class="page-auth">
<div class="card auth-card">
    <?php if ($ok): ?>
        <h2>Email подтверждён</h2>
        <p>Можно публиковать объявления и откликаться.</p>
    <?php else: ?>
        <h2>Ссылка недействительна</h2>
        <p class="muted">Запросите новую ссылку в личном кабинете → Настройки.</p>
    <?php endif; ?>
    <p><a class="btn btn-primary" href="/public/profile.php?tab=settings">В кабинет</a></p>
</div>
</body>
</html>
