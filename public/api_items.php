<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/helpers.php';

header('Content-Type: application/json; charset=utf-8');

$category = trim((string) ($_GET['category'] ?? ''));
$subcategory = trim((string) ($_GET['subcategory'] ?? ''));
$q = trim((string) ($_GET['q'] ?? ''));

$sql = 'SELECT i.*, u.name as user_name FROM items i JOIN users u ON u.id = i.user_id WHERE i.status = "active"';
$params = [];

$match = ftsMatchExpression($q);
if ($match !== null) {
    $sql .= ' AND i.id IN (SELECT rowid FROM items_fts WHERE items_fts MATCH :match)';
    $params['match'] = $match;
}

if ($category !== '') {
    $sql .= ' AND i.category = :category';
    $params['category'] = $category;
}

if ($subcategory !== '') {
    $sql .= ' AND i.subcategory = :subcategory';
    $params['subcategory'] = $subcategory;
}

$sql .= ' ORDER BY i.id DESC LIMIT 200';
$stmt = db()->prepare($sql);
try {
    $stmt->execute($params);
} catch (PDOException) {
    unset($params['match']);
    $sql = 'SELECT i.*, u.name as user_name FROM items i JOIN users u ON u.id = i.user_id WHERE i.status = "active"';
    if ($category !== '') {
        $sql .= ' AND i.category = :category';
    }
    if ($subcategory !== '') {
        $sql .= ' AND i.subcategory = :subcategory';
    }
    if ($q !== '') {
        $sql .= ' AND (i.title LIKE :like OR i.description LIKE :like)';
        $params['like'] = '%' . $q . '%';
    }
    $sql .= ' ORDER BY i.id DESC LIMIT 200';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
}
$items = $stmt->fetchAll();

$pdo = db();
foreach ($items as &$row) {
    $row['images'] = itemImagePaths($pdo, (int) $row['id'], $row['image_path'] ?? null);
}
unset($row);

echo json_encode(['items' => $items], JSON_UNESCAPED_UNICODE);
