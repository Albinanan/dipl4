<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/helpers.php';

$admin = requireAdmin();
$categories = categories();
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);

    if ($action === 'delete_item' && $id > 0) {
        deleteItemCascade(db(), $id);
        $message = 'Объявление удалено.';
    } elseif ($action === 'toggle_block' && $id > 0) {
        if ($id === (int) $admin['id']) {
            $error = 'Нельзя заблокировать самого себя.';
        } else {
            $stmt = db()->prepare('SELECT is_blocked FROM users WHERE id = ?');
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if ($row) {
                $new = empty($row['is_blocked']) ? 1 : 0;
                db()->prepare('UPDATE users SET is_blocked = ? WHERE id = ?')->execute([$new, $id]);
                $message = $new ? 'Пользователь заблокирован.' : 'Блокировка снята.';
            }
        }
    } elseif ($action === 'delete_user' && $id > 0) {
        if ($id === (int) $admin['id']) {
            $error = 'Нельзя удалить текущего администратора.';
        } else {
            $ids = db()->prepare('SELECT id FROM items WHERE user_id = ?');
            $ids->execute([$id]);
            foreach ($ids->fetchAll() as $r) {
                deleteItemCascade(db(), (int) $r['id']);
            }
            db()->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $id]);
            $message = 'Пользователь удалён.';
        }
    }
}

$users = db()->query('SELECT id, name, email, role, created_at, is_blocked FROM users ORDER BY id DESC')->fetchAll();
$items = db()->query('SELECT i.*, u.name as user_name FROM items i JOIN users u ON u.id = i.user_id ORDER BY i.id DESC')->fetchAll();
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Админ-панель — Забери.РФ</title>
    <link rel="stylesheet" href="/public/assets/app.css">
    <style>
        table.data img.thumb { width: 64px; height: 48px; object-fit: cover; border-radius: 6px; }
    </style>
</head>
<body>
<?php include __DIR__ . '/partials/header.php'; ?>
<div class="wrap">
    <div class="card">
        <strong>Администратор:</strong> <?= h($admin['name']) ?>
        <?php if ($message !== ''): ?><div class="ok"><?= h($message) ?></div><?php endif; ?>
        <?php if ($error !== ''): ?><div class="error"><?= h($error) ?></div><?php endif; ?>
    </div>

    <div class="card" style="overflow-x:auto;">
        <h3>Пользователи</h3>
        <table class="data">
            <tr><th>ID</th><th>Имя</th><th>Email</th><th>Роль</th><th>Блок</th><th>Действия</th></tr>
            <?php foreach ($users as $u): ?>
                <tr>
                    <td><?= (int) $u['id'] ?></td>
                    <td><?= h($u['name']) ?></td>
                    <td><?= h($u['email']) ?></td>
                    <td><?= h($u['role']) ?></td>
                    <td><?= !empty($u['is_blocked']) ? 'да' : 'нет' ?></td>
                    <td>
                        <?php if ((int) $u['id'] !== (int) $admin['id']): ?>
                            <form method="post" style="display:inline;">
                                <input type="hidden" name="action" value="toggle_block">
                                <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                                <button type="submit" class="btn"><?= !empty($u['is_blocked']) ? 'Разблокировать' : 'Заблокировать' ?></button>
                            </form>
                            <form method="post" style="display:inline;" onsubmit="return confirm('Удалить пользователя?');">
                                <input type="hidden" name="action" value="delete_user">
                                <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                                <button type="submit" class="btn btn-danger">Удалить</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>

    <div class="card" style="overflow-x:auto;">
        <h3>Объявления</h3>
        <table class="data">
            <tr><th>ID</th><th>Фото</th><th>Название</th><th>Категория</th><th>Адрес</th><th>Автор</th><th></th></tr>
            <?php foreach ($items as $item): ?>
                <?php $imgs = itemImagePaths(db(), (int) $item['id'], $item['image_path'] ?? null); ?>
                <tr>
                    <td><?= (int) $item['id'] ?></td>
                    <td>
                        <?php if ($imgs !== []): ?>
                            <img class="thumb" src="<?= h($imgs[0]) ?>" alt="">
                        <?php endif; ?>
                    </td>
                    <td><?= h($item['title']) ?></td>
                    <td><?= h($categories[$item['category']]['label'] ?? $item['category']) ?></td>
                    <td><?= h($item['address'] ?? '') ?></td>
                    <td><?= h($item['user_name']) ?></td>
                    <td>
                        <form method="post" onsubmit="return confirm('Удалить объявление?');">
                            <input type="hidden" name="action" value="delete_item">
                            <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                            <button type="submit" class="btn btn-danger">Удалить</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>
</div>
</body>
</html>
