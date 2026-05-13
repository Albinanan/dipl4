<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/helpers.php';

$user = requireAuth();
$pdo = db();
$categories = categories();
$tab = (string) ($_GET['tab'] ?? 'listings');
$allowedTabs = ['listings', 'add', 'responses', 'favorites', 'settings'];
if (!in_array($tab, $allowedTabs, true)) {
    $tab = 'listings';
}

$error = '';
$success = '';
$postTab = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postTab = (string) ($_POST['form_tab'] ?? '');

    if ($postTab === 'settings') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $contactPhone = trim((string) ($_POST['contact_phone'] ?? ''));
        $contactEmail = trim((string) ($_POST['contact_email'] ?? ''));
        $contactNote = trim((string) ($_POST['contact_note'] ?? ''));
        $phoneDigits = preg_replace('/\D+/', '', (string) ($_POST['phone'] ?? ''));

        if ($name === '') {
            $error = 'Укажите имя.';
        } else {
            $pdo->prepare(
                'UPDATE users SET name = ?, contact_phone = ?, contact_email = ?, contact_note = ? WHERE id = ?'
            )->execute([$name, $contactPhone, $contactEmail, $contactNote, (int) $user['id']]);
            if ($phoneDigits !== '') {
                $pdo->prepare('UPDATE users SET phone = ?, phone_verified = 0 WHERE id = ?')->execute([$phoneDigits, (int) $user['id']]);
            }

            if (isset($_FILES['avatar']) && (int) ($_FILES['avatar']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                $tmp = (string) $_FILES['avatar']['tmp_name'];
                $valid = validateImageUpload($tmp);
                if ($valid !== null) {
                    [, $ext] = $valid;
                    $dir = uploadsDir();
                    if (!is_dir($dir)) {
                        mkdir($dir, 0777, true);
                    }
                    $fn = 'av_' . (int) $user['id'] . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                    if (move_uploaded_file($tmp, $dir . '/' . $fn)) {
                        $old = (string) ($user['avatar_path'] ?? '');
                        deleteLocalUpload($old);
                        $pub = publicUploadPath($fn);
                        $pdo->prepare('UPDATE users SET avatar_path = ? WHERE id = ?')->execute([$pub, (int) $user['id']]);
                    }
                }
            }
            $success = 'Профиль обновлён.';
            $tab = 'settings';
        }
    }

    if ($postTab === 'resend_email') {
        $token = randomToken(16);
        $pdo->prepare('UPDATE users SET email_verify_token = ?, email_verify_expires = ? WHERE id = ?')->execute([
            $token,
            date('c', time() + 86400),
            (int) $user['id'],
        ]);
        $success = 'Ссылка для подтверждения: /public/verify_email.php?token=' . $token;
        $tab = 'settings';
    }

    if ($postTab === 'send_phone_otp') {
        $digits = preg_replace('/\D+/', '', (string) ($user['phone'] ?? ''));
        if ($digits === '') {
            $error = 'Сначала укажите телефон в настройках и сохраните профиль.';
        } else {
            $otp = generatePhoneOtp();
            $pdo->prepare('UPDATE users SET phone_otp = ?, phone_otp_expires = ? WHERE id = ?')->execute([
                $otp,
                date('c', time() + 900),
                (int) $user['id'],
            ]);
            $success = 'Код подтверждения телефона (демо): ' . $otp;
        }
        $tab = 'settings';
    }

    if ($postTab === 'verify_phone') {
        $code = trim((string) ($_POST['phone_code'] ?? ''));
        $stmt = $pdo->prepare('SELECT phone_otp, phone_otp_expires FROM users WHERE id = ?');
        $stmt->execute([(int) $user['id']]);
        $row = $stmt->fetch();
        if ($row && $code === (string) ($row['phone_otp'] ?? '') && (string) ($row['phone_otp_expires'] ?? '') > date('c')) {
            $pdo->prepare('UPDATE users SET phone_verified = 1, phone_otp = NULL, phone_otp_expires = NULL WHERE id = ?')->execute([(int) $user['id']]);
            $success = 'Телефон подтверждён.';
        } else {
            $error = 'Неверный или просроченный код.';
        }
        $tab = 'settings';
    }

    if ($postTab === 'add_item') {
        $title = trim((string) ($_POST['title'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $category = (string) ($_POST['category'] ?? '');
        $subcategory = (string) ($_POST['subcategory'] ?? '');
        $address = trim((string) ($_POST['address'] ?? ''));

        if ($title === '' || !isset($categories[$category]) || !isset($categories[$category]['subs'][$subcategory])) {
            $error = 'Проверьте название и категорию.';
        } elseif ($address === '') {
            $error = 'Укажите адрес.';
        } else {
            $coords = geocodeAddress($address);
            if ($coords === null) {
                $error = 'Адрес не найден. Укажите более точный адрес в Новосибирске.';
            }
        }

        $paths = [];
        if ($error === '' && isset($_FILES['photos'])) {
            $paths = saveUploadedImages($_FILES['photos'], 5);
        }

        if ($error === '') {
            $pdo->prepare(
                'INSERT INTO items (user_id, title, description, category, subcategory, address, latitude, longitude, image_path, created_at, status)
                 VALUES (:user_id, :title, :description, :category, :subcategory, :address, :lat, :lon, :image_path, :created_at, "active")'
            )->execute([
                'user_id' => (int) $user['id'],
                'title' => $title,
                'description' => $description,
                'category' => $category,
                'subcategory' => $subcategory,
                'address' => $address,
                'lat' => $coords['lat'],
                'lon' => $coords['lon'],
                'image_path' => $paths[0] ?? '',
                'created_at' => date('c'),
            ]);
            $newId = (int) $pdo->lastInsertId();
            foreach ($paths as $i => $p) {
                $pdo->prepare('INSERT INTO item_images (item_id, path, sort_order) VALUES (?, ?, ?)')->execute([$newId, $p, $i]);
            }
            syncItemFts($pdo, $newId);
            header('Location: /public/profile.php?tab=listings&created=1');
            exit;
        }
        $tab = 'add';
    }
}

$stmt = db()->prepare(
    'SELECT id, name, email, phone, role, email_verified, phone_verified, avatar_path, contact_phone, contact_email, contact_note
     FROM users WHERE id = :id'
);
$stmt->execute(['id' => (int) $user['id']]);
$user = $stmt->fetch() ?: $user;

$myItems = $pdo->prepare('SELECT * FROM items WHERE user_id = ? ORDER BY id DESC');
$myItems->execute([(int) $user['id']]);
$myItems = $myItems->fetchAll();

$responses = $pdo->prepare(
    'SELECT a.*, i.title as item_title, i.id as item_id, i.status as item_status
     FROM item_applications a JOIN items i ON i.id = a.item_id
     WHERE a.user_id = ? ORDER BY a.id DESC'
);
$responses->execute([(int) $user['id']]);
$responses = $responses->fetchAll();

$favorites = $pdo->prepare(
    'SELECT i.*, f.created_at as fav_at FROM item_favorites f JOIN items i ON i.id = f.item_id
     WHERE f.user_id = ? ORDER BY f.created_at DESC'
);
$favorites->execute([(int) $user['id']]);
$favorites = $favorites->fetchAll();

function tabLink(string $t, string $label, string $current): string
{
    $cls = $t === $current ? 'active' : '';

    return '<a class="' . h($cls) . '" href="/public/profile.php?tab=' . h($t) . '">' . h($label) . '</a>';
}
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Личный кабинет — Забери.РФ</title>
    <link rel="stylesheet" href="/public/assets/app.css">
</head>
<body>
<?php include __DIR__ . '/partials/header.php'; ?>

<div class="wrap">
    <div class="card" style="margin-bottom:16px;">
        <strong><?= h($user['name']) ?></strong>
        <span class="muted small"> · <?= h($user['email']) ?></span>
        <?php if (($user['role'] ?? '') === 'admin'): ?> — <strong>Администратор</strong><?php endif; ?>
    </div>

    <div class="tabs">
        <?= tabLink('listings', 'Мои объявления', $tab) ?>
        <?= tabLink('add', 'Добавить объявление', $tab) ?>
        <?= tabLink('responses', 'Мои отклики', $tab) ?>
        <?= tabLink('favorites', 'Избранное', $tab) ?>
        <?= tabLink('settings', 'Настройки', $tab) ?>
    </div>

    <?php if (!empty($_GET['created'])): ?><div class="ok card">Объявление опубликовано.</div><?php endif; ?>
    <?php if (!empty($_GET['deleted'])): ?><div class="ok card">Объявление удалено.</div><?php endif; ?>
    <?php if (!empty($_GET['registered'])): ?><div class="ok card">Аккаунт создан. Подтвердите email в разделе «Настройки».</div><?php endif; ?>
    <?php if ($tab === 'listings'): ?>
        <div class="grid">
            <?php if (!$myItems): ?>
                <p>Объявлений пока нет. <a href="/public/profile.php?tab=add">Добавить</a></p>
            <?php endif; ?>
            <?php foreach ($myItems as $item): ?>
                <?php $imgs = itemImagePaths($pdo, (int) $item['id'], $item['image_path'] ?? null); ?>
                <article class="item-card-lg">
                    <?php if ($imgs !== []): ?>
                        <img class="hero" src="<?= h($imgs[0]) ?>" alt="">
                    <?php else: ?>
                        <img class="hero" src="https://placehold.co/600x400?text=Нет+фото" alt="">
                    <?php endif; ?>
                    <div class="body">
                        <strong><?= h($item['title']) ?></strong>
                        <p class="small muted"><?= h($categories[$item['category']]['label'] ?? '') ?> · <?= h($item['status'] ?? '') ?></p>
                        <a class="btn btn-primary" href="/public/item.php?id=<?= (int) $item['id'] ?>">Открыть</a>
                        <a class="btn" href="/public/item_edit.php?id=<?= (int) $item['id'] ?>">Редактировать</a>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($tab === 'add'): ?>
        <div class="card" style="max-width:640px;">
            <h3>Новое объявление</h3>
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="form_tab" value="add_item">
                <input type="text" name="title" placeholder="Название" required style="width:100%;padding:10px;margin:8px 0;border-radius:8px;border:1px solid var(--border);">
                <textarea name="description" rows="4" placeholder="Описание" style="width:100%;padding:10px;margin:8px 0;border-radius:8px;border:1px solid var(--border);"></textarea>
                <select name="category" id="category" required style="width:100%;padding:10px;margin:8px 0;">
                    <?php foreach ($categories as $key => $cat): ?>
                        <option value="<?= h($key) ?>"><?= h($cat['label']) ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="subcategory" id="subcategory" required style="width:100%;padding:10px;margin:8px 0;"></select>
                <input type="text" name="address" placeholder="Адрес в Новосибирске" required style="width:100%;padding:10px;margin:8px 0;border-radius:8px;border:1px solid var(--border);">
                <label class="small muted">До 5 фотографий (JPG, PNG, WEBP)</label>
                <input type="file" name="photos[]" accept="image/*" multiple>
                <button type="submit" class="btn btn-accent btn-lg" style="margin-top:12px;">Опубликовать</button>
            </form>
            <?php if ($error !== '' && $postTab === 'add_item'): ?><div class="error"><?= h($error) ?></div><?php endif; ?>
        </div>
        <script>
            const categories = <?= json_encode($categories, JSON_UNESCAPED_UNICODE) ?>;
            const categoryEl = document.getElementById('category');
            const subcategoryEl = document.getElementById('subcategory');
            function us() {
                const subs = categories[categoryEl.value].subs;
                subcategoryEl.innerHTML = '';
                Object.entries(subs).forEach(([v, l]) => {
                    const o = document.createElement('option');
                    o.value = v;
                    o.textContent = l;
                    subcategoryEl.appendChild(o);
                });
            }
            categoryEl.addEventListener('change', us);
            us();
        </script>
    <?php endif; ?>

    <?php if ($tab === 'responses'): ?>
        <div class="card">
            <table class="data">
                <tr><th>Объявление</th><th>Сообщение</th><th>Статус</th></tr>
                <?php if (!$responses): ?>
                    <tr><td colspan="3" class="muted">Откликов пока нет.</td></tr>
                <?php endif; ?>
                <?php foreach ($responses as $r): ?>
                    <tr>
                        <td><a href="/public/item.php?id=<?= (int) $r['item_id'] ?>"><?= h($r['item_title']) ?></a><br><small class="muted"><?= h($r['item_status']) ?></small></td>
                        <td><?= nl2br(h($r['message'])) ?></td>
                        <td><?= h($r['status']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>
    <?php endif; ?>

    <?php if ($tab === 'favorites'): ?>
        <div class="grid">
            <?php if (!$favorites): ?>
                <p class="muted">Пока пусто. Добавляйте объявления в избранное на странице вещи.</p>
            <?php endif; ?>
            <?php foreach ($favorites as $item): ?>
                <?php $imgs = itemImagePaths($pdo, (int) $item['id'], $item['image_path'] ?? null); ?>
                <article class="item-card-lg">
                    <img class="hero" src="<?= h($imgs[0] ?? 'https://placehold.co/600x400?text=Нет+фото') ?>" alt="">
                    <div class="body">
                        <strong><?= h($item['title']) ?></strong>
                        <p><a class="btn btn-primary" href="/public/item.php?id=<?= (int) $item['id'] ?>">Открыть</a></p>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($tab === 'settings'): ?>
        <div class="card" style="max-width:520px;">
            <h3>Настройки профиля</h3>
            <p class="small muted">Контакты ниже видите только вы. Другие пользователи их не видят.</p>
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="form_tab" value="settings">
                <label>Имя</label>
                <input type="text" name="name" value="<?= h($user['name']) ?>" required style="width:100%;padding:10px;margin:4px 0 12px;border-radius:8px;border:1px solid var(--border);">
                <label>Телефон аккаунта (для входа/верификации, цифры)</label>
                <input type="text" name="phone" value="<?= h((string) ($user['phone'] ?? '')) ?>" style="width:100%;padding:10px;margin:4px 0 12px;border-radius:8px;border:1px solid var(--border);">
                <label>Контактный телефон (приватный)</label>
                <input type="text" name="contact_phone" value="<?= h((string) ($user['contact_phone'] ?? '')) ?>" style="width:100%;padding:10px;margin:4px 0 12px;border-radius:8px;border:1px solid var(--border);">
                <label>Контактный email (приватный)</label>
                <input type="email" name="contact_email" value="<?= h((string) ($user['contact_email'] ?? '')) ?>" style="width:100%;padding:10px;margin:4px 0 12px;border-radius:8px;border:1px solid var(--border);">
                <label>Доп. контакт (Telegram и т.д., приватно)</label>
                <input type="text" name="contact_note" value="<?= h((string) ($user['contact_note'] ?? '')) ?>" style="width:100%;padding:10px;margin:4px 0 12px;border-radius:8px;border:1px solid var(--border);">
                <label>Аватар</label>
                <input type="file" name="avatar" accept="image/*" style="margin:4px 0 12px;">
                <button type="submit" class="btn btn-primary">Сохранить</button>
            </form>

            <hr style="border:0;border-top:1px solid var(--border);margin:20px 0;">
            <p>Email: <?= !empty($user['email_verified']) ? '<span class="ok">подтверждён</span>' : '<span class="error">не подтверждён</span>' ?></p>
            <?php if (empty($user['email_verified'])): ?>
                <form method="post"><input type="hidden" name="form_tab" value="resend_email"><button type="submit" class="btn">Получить ссылку подтверждения</button></form>
            <?php endif; ?>

            <p style="margin-top:16px;">Телефон: <?= !empty($user['phone_verified']) ? '<span class="ok">подтверждён</span>' : '<span class="muted">не подтверждён</span>' ?></p>
            <form method="post" style="margin-bottom:8px;"><input type="hidden" name="form_tab" value="send_phone_otp"><button type="submit" class="btn">Выслать код (демо)</button></form>
            <form method="post">
                <input type="hidden" name="form_tab" value="verify_phone">
                <input type="text" name="phone_code" placeholder="Код из SMS (демо)" style="padding:10px;border-radius:8px;border:1px solid var(--border);width:200px;">
                <button type="submit" class="btn btn-primary">Подтвердить телефон</button>
            </form>

            <p style="margin-top:20px;"><a href="/public/index.php">На главную</a></p>
        </div>
        <?php if ($error !== '' && in_array($postTab, ['settings', 'send_phone_otp', 'verify_phone', 'resend_email'], true)): ?><div class="error card"><?= h($error) ?></div><?php endif; ?>
        <?php if ($success !== ''): ?><div class="ok card"><?= h($success) ?></div><?php endif; ?>
    <?php endif; ?>
</div>
</body>
</html>
