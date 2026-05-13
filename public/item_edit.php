<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/helpers.php';

$user = requireAuth();
$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: /public/profile.php');
    exit;
}

$pdo = db();
$stmt = $pdo->prepare('SELECT * FROM items WHERE id = ? AND user_id = ?');
$stmt->execute([$id, (int) $user['id']]);
$item = $stmt->fetch();
if (!$item) {
    http_response_code(404);
    echo 'Не найдено';
    exit;
}

if (($item['status'] ?? '') === 'transferred') {
    header('Location: /public/item.php?id=' . $id);
    exit;
}

$categories = categories();
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? 'save');
    if ($action === 'delete') {
        deleteItemCascade($pdo, $id);
        header('Location: /public/profile.php?tab=listings&deleted=1');
        exit;
    }

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

    if ($error === '') {
        $removeIds = array_map('intval', (array) ($_POST['remove_image'] ?? []));
        $imgStmt = $pdo->prepare('SELECT id, path FROM item_images WHERE item_id = ?');
        $imgStmt->execute([$id]);
        foreach ($imgStmt->fetchAll() as $ex) {
            if (in_array((int) $ex['id'], $removeIds, true)) {
                $pdo->prepare('DELETE FROM item_images WHERE id = ?')->execute([(int) $ex['id']]);
                deleteLocalUpload((string) $ex['path']);
            }
        }

        if (!empty($_POST['remove_legacy'])) {
            $lp = (string) ($item['image_path'] ?? '');
            if ($lp !== '') {
                deleteLocalUpload($lp);
                $pdo->prepare('UPDATE items SET image_path = ? WHERE id = ?')->execute(['', $id]);
                $item['image_path'] = '';
            }
        }

        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM item_images WHERE item_id = ?');
        $countStmt->execute([$id]);
        $currentCount = (int) $countStmt->fetchColumn();
        $pathStmt = $pdo->prepare('SELECT image_path FROM items WHERE id = ?');
        $pathStmt->execute([$id]);
        $ipNow = (string) ($pathStmt->fetchColumn() ?: '');
        $legacyLeft = ($ipNow !== '' && $currentCount === 0) ? 1 : 0;
        $totalSlots = $currentCount + $legacyLeft;
        $allowedNew = max(0, 5 - $totalSlots);

        $newPaths = [];
        if ($allowedNew > 0 && isset($_FILES['photos'])) {
            $newPaths = saveUploadedImages($_FILES['photos'], $allowedNew);
        }
        if ($totalSlots + count($newPaths) > 5) {
            $error = 'Не более 5 фотографий всего.';
            foreach ($newPaths as $p) {
                deleteLocalUpload($p);
            }
        } else {
            $mx = $pdo->prepare('SELECT COALESCE(MAX(sort_order), -1) FROM item_images WHERE item_id = ?');
            $mx->execute([$id]);
            $maxSort = (int) $mx->fetchColumn();
            $sort = $maxSort + 1;
            foreach ($newPaths as $p) {
                $pdo->prepare('INSERT INTO item_images (item_id, path, sort_order) VALUES (?, ?, ?)')->execute([$id, $p, $sort++]);
            }

            $pdo->prepare(
                'UPDATE items SET title = ?, description = ?, category = ?, subcategory = ?, address = ?, latitude = ?, longitude = ? WHERE id = ?'
            )->execute([
                $title,
                $description,
                $category,
                $subcategory,
                $address,
                $coords['lat'],
                $coords['lon'],
                $id,
            ]);
            syncItemFts($pdo, $id);
            $success = 'Сохранено.';
            $stmt->execute([$id, (int) $user['id']]);
            $item = $stmt->fetch();
        }
    }
}

$imgStmt = $pdo->prepare('SELECT id, path FROM item_images WHERE item_id = ? ORDER BY sort_order, id');
$imgStmt->execute([$id]);
$dbImages = $imgStmt->fetchAll();
$legacyPath = (string) ($item['image_path'] ?? '');
$legacyStandalone = $legacyPath !== '' && $dbImages === [];
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Редактирование — Забери.РФ</title>
    <link rel="stylesheet" href="/public/assets/app.css">
</head>
<body>
<?php include __DIR__ . '/partials/header.php'; ?>
<div class="wrap">
    <div class="card" style="max-width:640px;">
        <h2>Редактировать объявление</h2>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="save">
            <label>Название</label>
            <input type="text" name="title" required value="<?= h((string) $item['title']) ?>" style="width:100%;padding:10px;margin:6px 0 12px;border-radius:8px;border:1px solid var(--border);">
            <label>Описание</label>
            <textarea name="description" rows="4" style="width:100%;padding:10px;margin:6px 0 12px;border-radius:8px;border:1px solid var(--border);"><?= h((string) ($item['description'] ?? '')) ?></textarea>
            <label>Категория</label>
            <select name="category" id="category" required style="width:100%;padding:10px;margin:6px 0 12px;">
                <?php foreach ($categories as $key => $cat): ?>
                    <option value="<?= h($key) ?>" <?= $item['category'] === $key ? 'selected' : '' ?>><?= h($cat['label']) ?></option>
                <?php endforeach; ?>
            </select>
            <label>Подкатегория</label>
            <select name="subcategory" id="subcategory" required style="width:100%;padding:10px;margin:6px 0 12px;"></select>
            <label>Адрес</label>
            <input type="text" name="address" required value="<?= h((string) ($item['address'] ?? '')) ?>" style="width:100%;padding:10px;margin:6px 0 12px;border-radius:8px;border:1px solid var(--border);">

            <p class="small muted">До 5 фотографий. Отметьте «убрать», чтобы удалить.</p>
            <?php foreach ($dbImages as $im): ?>
                <label style="display:block;margin:8px 0;">
                    <img src="<?= h((string) $im['path']) ?>" alt="" style="max-height:80px;vertical-align:middle;margin-right:8px;">
                    <input type="checkbox" name="remove_image[]" value="<?= (int) $im['id'] ?>"> убрать
                </label>
            <?php endforeach; ?>
            <?php if ($legacyStandalone): ?>
                <label style="display:block;margin:8px 0;">
                    <img src="<?= h($legacyPath) ?>" alt="" style="max-height:80px;vertical-align:middle;margin-right:8px;">
                    <input type="checkbox" name="remove_legacy" value="1"> убрать старое фото
                </label>
            <?php endif; ?>
            <input type="file" name="photos[]" accept="image/*" multiple>

            <button type="submit" class="btn btn-primary btn-lg" style="margin-top:12px;">Сохранить</button>
        </form>
        <?php if ($error !== ''): ?><div class="error"><?= h($error) ?></div><?php endif; ?>
        <?php if ($success !== ''): ?><div class="ok"><?= h($success) ?></div><?php endif; ?>

        <form method="post" style="margin-top:24px;" onsubmit="return confirm('Удалить объявление безвозвратно?');">
            <input type="hidden" name="action" value="delete">
            <button type="submit" class="btn btn-danger">Удалить объявление</button>
        </form>
        <p><a href="/public/item.php?id=<?= (int) $id ?>">← К объявлению</a></p>
    </div>
</div>
<script>
    const categories = <?= json_encode($categories, JSON_UNESCAPED_UNICODE) ?>;
    const categoryEl = document.getElementById('category');
    const subcategoryEl = document.getElementById('subcategory');
    let currentSub = <?= json_encode((string) $item['subcategory'], JSON_UNESCAPED_UNICODE) ?>;

    function refill() {
        const key = categoryEl.value;
        const subs = categories[key].subs;
        subcategoryEl.innerHTML = '';
        Object.entries(subs).forEach(([value, label]) => {
            const o = document.createElement('option');
            o.value = value;
            o.textContent = label;
            if (value === currentSub) o.selected = true;
            subcategoryEl.appendChild(o);
        });
        currentSub = subcategoryEl.value;
    }
    categoryEl.addEventListener('change', () => { currentSub = ''; refill(); });
    refill();
</script>
</body>
</html>
