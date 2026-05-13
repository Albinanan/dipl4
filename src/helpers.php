<?php
declare(strict_types=1);

function syncItemFts(PDO $pdo, int $itemId): void
{
    $stmt = $pdo->prepare('SELECT title, description FROM items WHERE id = ?');
    $stmt->execute([$itemId]);
    $row = $stmt->fetch();
    $del = $pdo->prepare('DELETE FROM items_fts WHERE rowid = ?');
    $del->execute([$itemId]);
    if (!$row) {
        return;
    }
    $ins = $pdo->prepare('INSERT INTO items_fts (rowid, title, description) VALUES (?, ?, ?)');
    $ins->execute([$itemId, (string) ($row['title'] ?? ''), (string) ($row['description'] ?? '')]);
}

function ftsMatchExpression(string $raw): ?string
{
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }
    preg_match_all('/\p{L}[\p{L}\p{N}]*/u', $raw, $m);
    $terms = $m[0] ?? [];
    if ($terms === []) {
        return null;
    }
    $parts = [];
    foreach ($terms as $t) {
        if (mb_strlen($t) < 2) {
            continue;
        }
        $parts[] = '"' . str_replace('"', '""', $t) . '"';
    }
    if ($parts === []) {
        return null;
    }

    return implode(' AND ', $parts);
}

/** Публичные поля пользователя для карточек (без контактов). */
function userPublicProfile(array $u): array
{
    return [
        'id' => (int) $u['id'],
        'name' => (string) ($u['name'] ?? ''),
        'avatar_path' => (string) ($u['avatar_path'] ?? ''),
    ];
}

function uploadsDir(): string
{
    return __DIR__ . '/../public/uploads';
}

function publicUploadPath(string $filename): string
{
    return '/public/uploads/' . $filename;
}

/**
 * @return array{0: string, 1: string}|null [mime, extension]
 */
function validateImageUpload(string $tmpPath): ?array
{
    $info = @getimagesize($tmpPath);
    if ($info === false) {
        return null;
    }
    $mime = (string) ($info['mime'] ?? '');
    $ext = match ($mime) {
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        default => '',
    };

    return $ext !== '' ? [$mime, $ext] : null;
}

/**
 * Сохраняет до $maxFiles из $_FILES[$fieldName] (multiple или одиночный).
 * @return list<string> публичные пути /public/uploads/...
 */
function saveUploadedImages(array $filesField, int $maxFiles = 5): array
{
    $paths = [];
    if (!isset($filesField['name'])) {
        return $paths;
    }

    $names = $filesField['name'];
    $tmps = $filesField['tmp_name'];
    $errors = $filesField['error'];

    if (!is_array($names)) {
        $names = [$names];
        $tmps = [$tmps];
        $errors = [$errors];
    }

    $uploadDir = uploadsDir();
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    $count = min(count($names), $maxFiles);
    for ($i = 0; $i < $count; $i++) {
        if (count($paths) >= $maxFiles) {
            break;
        }
        if ((int) ($errors[$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            continue;
        }
        $tmp = (string) ($tmps[$i] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            continue;
        }
        $valid = validateImageUpload($tmp);
        if ($valid === null) {
            continue;
        }
        [, $ext] = $valid;
        $filename = 'img_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $full = $uploadDir . '/' . $filename;
        if (!move_uploaded_file($tmp, $full)) {
            continue;
        }
        $paths[] = publicUploadPath($filename);
    }

    return $paths;
}

function deleteLocalUpload(?string $publicPath): void
{
    if ($publicPath === null || $publicPath === '') {
        return;
    }
    $rel = str_replace('/public', '', $publicPath);
    $local = __DIR__ . '/../public' . $rel;
    if (is_file($local)) {
        @unlink($local);
    }
}

function deleteItemCascade(PDO $pdo, int $itemId): void
{
    $imgStmt = $pdo->prepare('SELECT path FROM item_images WHERE item_id = ?');
    $imgStmt->execute([$itemId]);
    foreach ($imgStmt->fetchAll() as $r) {
        deleteLocalUpload((string) ($r['path'] ?? ''));
    }

    $legacy = $pdo->prepare('SELECT image_path FROM items WHERE id = ?');
    $legacy->execute([$itemId]);
    $row = $legacy->fetch();
    if ($row && !empty($row['image_path'])) {
        deleteLocalUpload((string) $row['image_path']);
    }

    $pdo->prepare('DELETE FROM item_images WHERE item_id = ?')->execute([$itemId]);
    $pdo->prepare('DELETE FROM item_favorites WHERE item_id = ?')->execute([$itemId]);
    $pdo->prepare('DELETE FROM item_applications WHERE item_id = ?')->execute([$itemId]);
    $pdo->prepare('DELETE FROM chat_messages WHERE item_id = ?')->execute([$itemId]);
    $pdo->prepare('DELETE FROM items_fts WHERE rowid = ?')->execute([$itemId]);
    $pdo->prepare('DELETE FROM items WHERE id = ?')->execute([$itemId]);
}

function itemImagePaths(PDO $pdo, int $itemId, ?string $legacyImagePath): array
{
    $stmt = $pdo->prepare('SELECT path FROM item_images WHERE item_id = ? ORDER BY sort_order ASC, id ASC');
    $stmt->execute([$itemId]);
    $paths = array_column($stmt->fetchAll(), 'path');
    if ($paths === [] && $legacyImagePath !== null && $legacyImagePath !== '') {
        return [$legacyImagePath];
    }

    return $paths;
}

function canChatOnItem(PDO $pdo, int $itemId, int $userId): ?array
{
    $stmt = $pdo->prepare('SELECT user_id, status FROM items WHERE id = ?');
    $stmt->execute([$itemId]);
    $item = $stmt->fetch();
    if (!$item) {
        return null;
    }
    $donorId = (int) $item['user_id'];
    if ($userId === $donorId) {
        return ['role' => 'donor', 'donor_id' => $donorId];
    }
    $app = $pdo->prepare('SELECT id, status FROM item_applications WHERE item_id = ? AND user_id = ?');
    $app->execute([$itemId, $userId]);
    $a = $app->fetch();
    if ($a) {
        return ['role' => 'applicant', 'donor_id' => $donorId, 'application_id' => (int) $a['id']];
    }

    return null;
}
