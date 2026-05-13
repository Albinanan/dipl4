<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/auth.php';

$categories = categories();
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Карта — Забери.РФ</title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="">
    <link rel="stylesheet" href="/public/assets/app.css">
    <style>
        html, body, #map { height: 100%; margin: 0; }
        .map-toolbar {
            position: absolute; z-index: 1000; top: 12px; left: 12px; right: 12px;
            background: rgba(255,255,255,0.96); border-radius: 12px; padding: 10px;
            box-shadow: var(--shadow); display: flex; flex-wrap: wrap; gap: 8px; align-items: center;
        }
        .map-toolbar select {
            padding: 8px 10px; border: 1px solid var(--border); border-radius: 8px; background: #fff;
            font-size: 14px;
        }
        .map-toolbar button {
            padding: 8px 14px; border-radius: 8px; font-size: 14px; cursor: pointer;
        }
        .map-toolbar .btn:not(.btn-primary) {
            background: #fff;
            border: 1px solid var(--border);
            color: var(--text);
        }
        .map-toolbar input[type="search"] { flex: 1; min-width: 160px; padding: 8px 10px; border-radius: 8px; border: 1px solid var(--border); background: #fff; }
    </style>
</head>
<body>
<?php include __DIR__ . '/partials/header.php'; ?>
<div style="position:relative;height:calc(100vh - 56px);min-height:400px;">
<div class="map-toolbar">
    <select id="category">
        <option value="">Все категории</option>
        <?php foreach ($categories as $key => $cat): ?>
            <option value="<?= h($key) ?>"><?= h($cat['label']) ?></option>
        <?php endforeach; ?>
    </select>
    <select id="subcategory">
        <option value="">Все подкатегории</option>
    </select>
    <input type="search" id="q" placeholder="Поиск по названию и описанию">
    <button type="button" id="applyFilters" class="btn btn-primary">Показать</button>
    <button type="button" id="clearFilters" class="btn">Сбросить</button>
</div>
<div id="map"></div>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
<script>
    const categories = <?= json_encode($categories, JSON_UNESCAPED_UNICODE) ?>;
    const categoryEl = document.getElementById('category');
    const subcategoryEl = document.getElementById('subcategory');
    const map = L.map('map', { attributionControl: false }).setView([55.0302, 82.9204], 11);
    L.control.attribution({ prefix: false }).addTo(map);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap contributors'
    }).addTo(map);

    let markersLayer = L.layerGroup().addTo(map);

    function refillSubcategories() {
        const selected = categoryEl.value;
        subcategoryEl.innerHTML = '<option value="">Все подкатегории</option>';
        if (!selected || !categories[selected]) return;
        Object.entries(categories[selected].subs).forEach(([value, label]) => {
            const opt = document.createElement('option');
            opt.value = value;
            opt.textContent = label;
            subcategoryEl.appendChild(opt);
        });
    }

    async function loadItems() {
        const params = new URLSearchParams();
        if (categoryEl.value) params.set('category', categoryEl.value);
        if (subcategoryEl.value) params.set('subcategory', subcategoryEl.value);
        const q = document.getElementById('q').value.trim();
        if (q) params.set('q', q);
        const res = await fetch('/public/api_items.php?' + params.toString());
        const data = await res.json();

        markersLayer.clearLayers();
        data.items.forEach(item => {
            const categoryName = categories[item.category]?.label || item.category;
            const subName = categories[item.category]?.subs?.[item.subcategory] || item.subcategory;
            const imgs = item.images && item.images.length ? item.images : (item.image_path ? [item.image_path] : []);
            const img = imgs[0];
            const imageHtml = img ? `<a href="/public/item.php?id=${item.id}"><img src="${img}" alt="" style="max-width:240px;width:100%;display:block;margin-bottom:8px;border-radius:8px;"></a>` : '';
            const popup = `${imageHtml}<b><a href="/public/item.php?id=${item.id}">${item.title}</a></b><br>${(item.description || '').slice(0, 180)}${(item.description || '').length > 180 ? '…' : ''}<br><small>${categoryName} / ${subName}</small><br><small>${item.address || ''}</small><br><small>Даритель: ${item.user_name}</small>`;
            L.marker([item.latitude, item.longitude]).bindPopup(popup).addTo(markersLayer);
        });
    }

    categoryEl.addEventListener('change', refillSubcategories);
    document.getElementById('applyFilters').addEventListener('click', loadItems);
    document.getElementById('clearFilters').addEventListener('click', () => {
        categoryEl.value = '';
        document.getElementById('q').value = '';
        refillSubcategories();
        loadItems();
    });

    refillSubcategories();
    loadItems();
</script>
</body>
</html>
