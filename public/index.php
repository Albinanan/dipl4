<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/helpers.php';

$user = currentUser();
$categories = categories();
$pdo = db();

$q = trim((string) ($_GET['q'] ?? ''));
$cat = trim((string) ($_GET['category'] ?? ''));
$sub = trim((string) ($_GET['subcategory'] ?? ''));

$sql = 'SELECT i.*, u.name as user_name FROM items i JOIN users u ON u.id = i.user_id WHERE i.status = "active"';
$params = [];
$match = ftsMatchExpression($q);
if ($match !== null) {
    $sql .= ' AND i.id IN (SELECT rowid FROM items_fts WHERE items_fts MATCH :match)';
    $params['match'] = $match;
}
if ($cat !== '' && isset($categories[$cat])) {
    $sql .= ' AND i.category = :category';
    $params['category'] = $cat;
}
if ($sub !== '' && $cat !== '' && isset($categories[$cat]['subs'][$sub])) {
    $sql .= ' AND i.subcategory = :subcategory';
    $params['subcategory'] = $sub;
}
$sql .= ' ORDER BY i.id DESC LIMIT 24';
$stmt = $pdo->prepare($sql);
try {
    $stmt->execute($params);
} catch (PDOException) {
    unset($params['match']);
    $sql = 'SELECT i.*, u.name as user_name FROM items i JOIN users u ON u.id = i.user_id WHERE i.status = "active"';
    if ($cat !== '' && isset($categories[$cat])) {
        $sql .= ' AND i.category = :category';
    }
    if ($sub !== '' && $cat !== '' && isset($categories[$cat]['subs'][$sub])) {
        $sql .= ' AND i.subcategory = :subcategory';
    }
    if ($q !== '') {
        $sql .= ' AND (i.title LIKE :like OR i.description LIKE :like)';
        $params['like'] = '%' . $q . '%';
    }
    $sql .= ' ORDER BY i.id DESC LIMIT 24';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
}
$listItems = $stmt->fetchAll();
foreach ($listItems as &$row) {
    $row['images'] = itemImagePaths($pdo, (int) $row['id'], $row['image_path'] ?? null);
}
unset($row);
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Забери.РФ — Новосибирск</title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="">
    <link rel="stylesheet" href="/public/assets/app.css">
    <style>
        #map-home { height: 280px; }
        @media (min-width: 900px) { #map-home { height: 320px; } }
    </style>
</head>
<body>
<?php include __DIR__ . '/partials/header.php'; ?>

<div class="wrap">
    <section class="card" style="margin-bottom:20px;">
        <h1 style="margin-top:0;font-size:1.35rem;">Отдаём вещи соседям — бесплатно и безопасно</h1>
        <p class="muted" style="margin-bottom:12px;">Поиск по названию и описанию, фильтры по категориям и карта.</p>
        <form class="search-row" method="get" action="/public/index.php">
            <input type="search" name="q" value="<?= h($q) ?>" placeholder="Например: детские ботинки, диван…" aria-label="Поиск">
            <select name="category" id="home_cat" style="padding:12px;border-radius:10px;border:1px solid var(--border);">
                <option value="">Все категории</option>
                <?php foreach ($categories as $key => $c): ?>
                    <option value="<?= h($key) ?>" <?= $cat === $key ? 'selected' : '' ?>><?= h($c['label']) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="subcategory" id="home_sub" style="padding:12px;border-radius:10px;border:1px solid var(--border);min-width:160px;">
                <option value="">Подкатегория</option>
            </select>
            <button type="submit" class="btn btn-primary btn-lg">Найти</button>
        </form>
        <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;">
            <a class="btn btn-accent btn-lg" href="/public/profile.php?tab=add">Добавить объявление</a>
            <a class="btn btn-lg" href="/public/map.php">Открыть карту</a>
        </div>
    </section>

    <h2 class="h-section" style="font-size:1.1rem;margin:8px 0 12px;">Популярные категории</h2>
    <div class="cat-pills">
        <?php foreach ($categories as $key => $c): ?>
            <a href="/public/index.php?category=<?= h(urlencode($key)) ?>"><?= h($c['label']) ?></a>
        <?php endforeach; ?>
    </div>

    <h2 style="font-size:1.1rem;margin:8px 0 12px;">Последние объявления</h2>
    <div class="grid">
        <?php if (!$listItems): ?>
            <p class="muted">Ничего не найдено. Попробуйте изменить запрос или <a href="/public/index.php">сбросить фильтры</a>.</p>
        <?php endif; ?>
        <?php foreach ($listItems as $item): ?>
            <?php $imgs = $item['images'] ?? []; ?>
            <article class="item-card-lg">
                <a href="/public/item.php?id=<?= (int) $item['id'] ?>">
                    <img class="hero" src="<?= h($imgs[0] ?? 'https://placehold.co/600x400?text=Нет+фото') ?>" alt="">
                </a>
                <div class="body">
                    <strong><a href="/public/item.php?id=<?= (int) $item['id'] ?>"><?= h($item['title']) ?></a></strong>
                    <p class="small muted"><?= h($categories[$item['category']]['label'] ?? '') ?></p>
                    <a class="btn btn-accent btn-lg" style="width:100%;margin-top:8px;" href="/public/item.php?id=<?= (int) $item['id'] ?>">Хочу забрать</a>
                </div>
            </article>
        <?php endforeach; ?>
    </div>

    <h2 style="font-size:1.1rem;margin:24px 0 12px;">На карте</h2>
    <div id="map-home"></div>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
<script>
    const categories = <?= json_encode($categories, JSON_UNESCAPED_UNICODE) ?>;
    const homeCat = document.getElementById('home_cat');
    const homeSub = document.getElementById('home_sub');
    const selectedCat = <?= json_encode($cat, JSON_UNESCAPED_UNICODE) ?>;
    const selectedSub = <?= json_encode($sub, JSON_UNESCAPED_UNICODE) ?>;

    function refillHomeSub() {
        const key = homeCat.value;
        homeSub.innerHTML = '<option value=\"\">Подкатегория</option>';
        if (!key || !categories[key]) return;
        Object.entries(categories[key].subs).forEach(([value, label]) => {
            const o = document.createElement('option');
            o.value = value;
            o.textContent = label;
            if (value === selectedSub) o.selected = true;
            homeSub.appendChild(o);
        });
    }
    homeCat.addEventListener('change', () => { refillHomeSub(); });
    refillHomeSub();

    const map = L.map('map-home', { attributionControl: false }).setView([55.0302, 82.9204], 11);
    L.control.attribution({ prefix: false }).addTo(map);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '&copy; OSM' }).addTo(map);
    const layer = L.layerGroup().addTo(map);
    const params = new URLSearchParams(window.location.search);
    fetch('/public/api_items.php?' + params.toString()).then(r => r.json()).then(data => {
        data.items.forEach(item => {
            L.marker([item.latitude, item.longitude]).bindPopup(`<a href="/public/item.php?id=${item.id}"><b>${item.title}</b></a>`).addTo(layer);
        });
    });
</script>
</body>
</html>
