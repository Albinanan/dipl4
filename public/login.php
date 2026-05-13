<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';

$error = '';
if (!empty($_GET['blocked'])) {
    $error = 'Аккаунт заблокирован. Обратитесь в поддержку.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    $stmt = db()->prepare(
        'SELECT id, password_hash, is_blocked FROM users WHERE email = :email'
    );
    $stmt->execute(['email' => mb_strtolower($email)]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, (string) $user['password_hash'])) {
        if (!empty($user['is_blocked'])) {
            $error = 'Аккаунт заблокирован.';
        } else {
            $_SESSION['user_id'] = (int) $user['id'];
            header('Location: /public/profile.php');
            exit;
        }
    } else {
        $error = 'Неверный email или пароль.';
    }
}
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Вход — Забери.РФ</title>
    <link rel="stylesheet" href="/public/assets/app.css">
</head>
<body class="page-auth">
<div class="card auth-card">
    <h2>Вход</h2>
    <form method="post">
        <input type="email" name="email" placeholder="Email" required>
        <input type="password" name="password" placeholder="Пароль" required>
        <button type="submit" class="btn btn-primary btn-lg">Войти</button>
    </form>
    <?php if ($error !== ''): ?><div class="error"><?= h($error) ?></div><?php endif; ?>
    <p><a href="/public/register.php">Нет аккаунта? Зарегистрироваться</a></p>
    <p><a href="/public/index.php">На главную</a></p>
</div>
</body>
</html>
