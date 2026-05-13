<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/helpers.php';

header('Content-Type: application/json; charset=utf-8');

$me = requireAuth();
$uid = (int) $me['id'];

$itemId = (int) ($_GET['item_id'] ?? $_POST['item_id'] ?? 0);
$peerId = (int) ($_GET['peer_id'] ?? $_POST['peer_id'] ?? 0);

if ($itemId <= 0 || $peerId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'bad_request'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db();
$ctx = canChatOnItem($pdo, $itemId, $uid);
if ($ctx === null) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

$donorId = (int) $ctx['donor_id'];
$allowed = ($uid === $donorId && $peerId !== $donorId) || ($uid !== $donorId && $peerId === $donorId);
if (!$allowed) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Заявка всегда от откликнувшегося (не от дарителя): проверяем именно его user_id
$applicantId = $uid === $donorId ? $peerId : $uid;
$appCh = $pdo->prepare('SELECT id FROM item_applications WHERE item_id = ? AND user_id = ?');
$appCh->execute([$itemId, $applicantId]);
if (!$appCh->fetch()) {
    http_response_code(403);
    echo json_encode(['error' => 'no_application'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = trim((string) ($_POST['body'] ?? ''));
    if ($body === '' || mb_strlen($body) > 2000) {
        http_response_code(400);
        echo json_encode(['error' => 'bad_body'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $ins = $pdo->prepare(
        'INSERT INTO chat_messages (item_id, sender_id, recipient_id, body, created_at) VALUES (?, ?, ?, ?, ?)'
    );
    $ins->execute([$itemId, $uid, $peerId, $body, date('c')]);
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

$stmt = $pdo->prepare(
    'SELECT m.*, u.name AS sender_name, u.avatar_path AS sender_avatar
     FROM chat_messages m
     JOIN users u ON u.id = m.sender_id
     WHERE m.item_id = :item_id
     AND ((m.sender_id = :u1 AND m.recipient_id = :p1) OR (m.sender_id = :p2 AND m.recipient_id = :u2))
     ORDER BY m.id ASC'
);
$stmt->execute([
    'item_id' => $itemId,
    'u1' => $uid,
    'p1' => $peerId,
    'p2' => $peerId,
    'u2' => $uid,
]);
$msgs = $stmt->fetchAll();
foreach ($msgs as &$m) {
    $m['mine'] = (int) $m['sender_id'] === $uid;
}
unset($m);

echo json_encode(['messages' => $msgs], JSON_UNESCAPED_UNICODE);
