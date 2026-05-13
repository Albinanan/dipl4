<?php
declare(strict_types=1);
if (!function_exists('currentUser')) {
    require_once __DIR__ . '/../../src/auth.php';
}
$headerUser = currentUser();
?>
<header class="site-header">
    <a class="brand" href="/public/index.php">Забери.РФ</a>
    <nav>
        <a href="/public/index.php">Главная</a>
        <a href="/public/map.php">Карта</a>
        <?php if ($headerUser): ?>
            <a href="/public/profile.php">Личный кабинет</a>
            <?php if (($headerUser['role'] ?? 'user') === 'admin'): ?>
                <a href="/public/admin.php">Админ</a>
            <?php endif; ?>
            <a href="/public/logout.php">Выход</a>
        <?php else: ?>
            <a href="/public/login.php">Вход</a>
            <a href="/public/register.php">Регистрация</a>
        <?php endif; ?>
    </nav>
    <span class="spacer"></span>
</header>
