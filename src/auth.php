<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function currentUser(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    $stmt = db()->prepare(
        'SELECT id, name, email, phone, role, email_verified, phone_verified, avatar_path,
                contact_phone, contact_email, contact_note, is_blocked
         FROM users WHERE id = :id'
    );
    $stmt->execute(['id' => (int) $_SESSION['user_id']]);
    $user = $stmt->fetch();

    return $user ?: null;
}

function requireAuth(): array
{
    $user = currentUser();
    if ($user === null) {
        header('Location: /public/login.php');
        exit;
    }
    if (!empty($user['is_blocked'])) {
        $_SESSION = [];
        session_destroy();
        header('Location: /public/login.php?blocked=1');
        exit;
    }

    return $user;
}

function isAdmin(): bool
{
    $user = currentUser();

    return $user !== null && ($user['role'] ?? 'user') === 'admin';
}

function requireAdmin(): array
{
    $user = requireAuth();
    if (($user['role'] ?? 'user') !== 'admin') {
        header('Location: /public/index.php');
        exit;
    }

    return $user;
}
