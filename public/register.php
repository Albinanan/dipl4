<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim((string) ($_POST['name'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $phone = preg_replace('/\D+/', '', (string) ($_POST['phone'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($name === '' || $email === '' || $password === '') {
        $error = 'Заполните имя, email и пароль.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Введите корректный email.';
    } elseif ($phone !== '' && strlen($phone) < 10) {
        $error = 'Укажите полный номер телефона или оставьте поле пустым.';
    } else {
        $token = randomToken(16);
        $expires = date('c', time() + 86400);
        $phoneOtp = $phone !== '' ? generatePhoneOtp() : null;
        $phoneOtpExp = $phone !== '' ? date('c', time() + 900) : null;

        try {
            $stmt = db()->prepare(
                'INSERT INTO users (name, email, password_hash, role, created_at, phone, email_verified, phone_verified,
                 email_verify_token, email_verify_expires, phone_otp, phone_otp_expires)
                 VALUES (:name, :email, :password_hash, :role, :created_at, :phone, 0, 0, :ev_token, :ev_exp, :potp, :potp_exp)'
            );
            $stmt->execute([
                'name' => $name,
                'email' => mb_strtolower($email),
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'role' => 'user',
                'created_at' => date('c'),
                'phone' => $phone !== '' ? $phone : null,
                'ev_token' => $token,
                'ev_exp' => $expires,
                'potp' => $phoneOtp,
                'potp_exp' => $phoneOtpExp,
            ]);
            $_SESSION['user_id'] = (int) db()->lastInsertId();
            header('Location: /public/profile.php?tab=settings&registered=1');
            exit;
        } catch (PDOException $e) {
            $error = 'Пользователь с таким email или телефоном уже существует.';
        }
    }
}
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Регистрация — Забери.РФ</title>
    <link rel="stylesheet" href="/public/assets/app.css">
</head>
<body class="page-auth">
<div class="card auth-card">
    <h2>Регистрация</h2>
    <p class="muted small">После регистрации подтвердите email. Телефон — по желанию; код показывается на экране (демо).</p>
    <form method="post">
        <input type="text" name="name" placeholder="Имя" required>
        <input type="email" name="email" placeholder="Email" required>
        <input type="tel" name="phone" placeholder="Телефон (необязательно)">
        <input type="password" name="password" placeholder="Пароль" required>
        <button type="submit" class="btn btn-primary btn-lg">Создать аккаунт</button>
    </form>
    <?php if ($error !== ''): ?><div class="error"><?= h($error) ?></div><?php endif; ?>
    <p><a href="/public/login.php">Уже есть аккаунт? Войти</a></p>
    <p><a href="/public/index.php">На главную</a></p>
</div>
</body>
</html>
