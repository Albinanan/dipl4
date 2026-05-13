<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/helpers.php';

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: /public/index.php');
    exit;
}

$pdo = db();
$stmt = $pdo->prepare('SELECT i.*, u.name as donor_name, u.avatar_path as donor_avatar FROM items i JOIN users u ON u.id = i.user_id WHERE i.id = ?');
$stmt->execute([$id]);
$item = $stmt->fetch();
if (!$item) {
    http_response_code(404);
    echo 'Объявление не найдено';
    exit;
}

$user = currentUser();
$isOwner = $user && (int) $user['id'] === (int) $item['user_id'];
$images = itemImagePaths($pdo, $id, $item['image_path'] ?? null);
$categories = categories();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user) {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'apply' && !$isOwner && (int) $item['user_id'] !== (int) $user['id']) {
        if (($item['status'] ?? '') !== 'active') {
            header('Location: /public/item.php?id=' . $id);
            exit;
        }
        $msg = trim((string) ($_POST['message'] ?? ''));
        if ($msg === '') {
            $msg = 'Здравствуйте! Хочу забрать вещь.';
        }
        try {
            $pdo->prepare(
                'INSERT INTO item_applications (item_id, user_id, message, status, created_at) VALUES (?, ?, ?, "pending", ?)'
            )->execute([$id, (int) $user['id'], $msg, date('c')]);
        } catch (PDOException) {
            // duplicate
        }
        header('Location: /public/item.php?id=' . $id . '&applied=1');
        exit;
    }
    if ($action === 'favorite') {
        $exists = $pdo->prepare('SELECT 1 FROM item_favorites WHERE user_id = ? AND item_id = ?');
        $exists->execute([(int) $user['id'], $id]);
        if ($exists->fetch()) {
            $pdo->prepare('DELETE FROM item_favorites WHERE user_id = ? AND item_id = ?')->execute([(int) $user['id'], $id]);
        } else {
            $pdo->prepare('INSERT INTO item_favorites (user_id, item_id, created_at) VALUES (?, ?, ?)')->execute([(int) $user['id'], $id, date('c')]);
        }
        header('Location: /public/item.php?id=' . $id);
        exit;
    }
    if ($isOwner) {
        if ($action === 'accept' && ($item['status'] ?? '') === 'active') {
            $appUser = (int) ($_POST['user_id'] ?? 0);
            $pdo->prepare('UPDATE item_applications SET status = "accepted" WHERE item_id = ? AND user_id = ?')->execute([$id, $appUser]);
            $pdo->prepare('UPDATE item_applications SET status = "rejected" WHERE item_id = ? AND user_id != ? AND status = "pending"')->execute([$id, $appUser]);
            header('Location: /public/item.php?id=' . $id);
            exit;
        }
        if ($action === 'reject') {
            $appUser = (int) ($_POST['user_id'] ?? 0);
            $pdo->prepare('UPDATE item_applications SET status = "rejected" WHERE item_id = ? AND user_id = ?')->execute([$id, $appUser]);
            header('Location: /public/item.php?id=' . $id);
            exit;
        }
        if ($action === 'transfer' && ($item['status'] ?? '') === 'active') {
            $to = (int) ($_POST['user_id'] ?? 0);
            $chk = $pdo->prepare('SELECT id FROM item_applications WHERE item_id = ? AND user_id = ? AND status = "accepted"');
            $chk->execute([$id, $to]);
            if ($chk->fetch()) {
                $pdo->prepare('UPDATE items SET status = "transferred", transferred_to_user_id = ?, transferred_at = ? WHERE id = ?')->execute([$to, date('c'), $id]);
                syncItemFts($pdo, $id);
            }
            header('Location: /public/item.php?id=' . $id);
            exit;
        }
    }
}

$fav = false;
if ($user) {
    $f = $pdo->prepare('SELECT 1 FROM item_favorites WHERE user_id = ? AND item_id = ?');
    $f->execute([(int) $user['id'], $id]);
    $fav = (bool) $f->fetch();
}

$applications = [];
if ($isOwner) {
    $apps = $pdo->prepare(
        'SELECT a.*, u.name as applicant_name, u.avatar_path as applicant_avatar
         FROM item_applications a JOIN users u ON u.id = a.user_id
         WHERE a.item_id = ? ORDER BY a.id DESC'
    );
    $apps->execute([$id]);
    $applications = $apps->fetchAll();
}

$myApp = null;
if ($user && !$isOwner) {
    $ma = $pdo->prepare('SELECT * FROM item_applications WHERE item_id = ? AND user_id = ?');
    $ma->execute([$id, (int) $user['id']]);
    $myApp = $ma->fetch() ?: null;
}

$chatPeer = (int) ($_GET['chat_with'] ?? 0);
$canChat = $user ? canChatOnItem($pdo, $id, (int) $user['id']) : null;
$donorId = (int) $item['user_id'];

$peerForChat = 0;
$showChat = false;
if ($user && $canChat && ($item['status'] ?? '') !== 'transferred') {
    if ($canChat['role'] === 'applicant') {
        $peerForChat = $donorId;
        $showChat = true;
    } elseif ($canChat['role'] === 'donor' && $chatPeer > 0) {
        $peerForChat = $chatPeer;
        $showChat = true;
    }
}
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($item['title']) ?> — Забери.РФ</title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="">
    <link rel="stylesheet" href="/public/assets/app.css">
</head>
<body>
<?php include __DIR__ . '/partials/header.php'; ?>

<div class="wrap">
    <?php if (!empty($_GET['applied'])): ?><div class="ok card">Отклик отправлен.</div><?php endif; ?>

    <div class="item-card-lg" style="max-width: 900px;">
        <?php if ($images !== []): ?>
            <img class="hero" src="<?= h($images[0]) ?>" alt="<?= h($item['title']) ?>">
        <?php else: ?>
            <img class="hero" src="https://placehold.co/900x400?text=Нет+фото" alt="">
        <?php endif; ?>
        <div class="body">
            <h1 style="margin:0 0 8px;"><?= h($item['title']) ?></h1>
            <p class="muted small">
                <?= h($categories[$item['category']]['label'] ?? $item['category']) ?> ·
                <?= h($categories[$item['category']]['subs'][$item['subcategory']] ?? $item['subcategory']) ?>
                <?php if (($item['status'] ?? '') === 'transferred'): ?>
                    <span class="ok"> · Передано</span>
                <?php endif; ?>
            </p>
            <?php if (count($images) > 1): ?>
                <div class="gallery">
                    <?php foreach (array_slice($images, 1) as $im): ?>
                        <img src="<?= h($im) ?>" alt="">
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <p><?= nl2br(h((string) ($item['description'] ?? ''))) ?></p>
            <p><strong>Адрес:</strong> <?= h((string) ($item['address'] ?? '')) ?></p>

            <div id="map-item"></div>

            <div class="donor-row">
                <?php $da = (string) ($item['donor_avatar'] ?? ''); ?>
                <?php if ($da !== ''): ?>
                    <img class="avatar" src="<?= h($da) ?>" alt="">
                <?php else: ?>
                    <img class="avatar" src="https://placehold.co/120x120?text=?" alt="">
                <?php endif; ?>
                <div>
                    <div class="small muted">Даритель</div>
                    <strong><?= h((string) $item['donor_name']) ?></strong>
                    <div class="small muted">Контакты не показываются — общайтесь в чате на сайте.</div>
                </div>
            </div>

            <div style="display:flex;flex-wrap:wrap;gap:10px;margin-top:16px;">
                <?php if ($user && !$isOwner && ($item['status'] ?? '') === 'active'): ?>
                    <?php if ($myApp): ?>
                        <span class="btn" style="opacity:0.85;cursor:default;">Вы откликнулись (<?= h($myApp['status']) ?>)</span>
                    <?php else: ?>
                        <form method="post" style="flex:1;min-width:240px;">
                            <input type="hidden" name="action" value="apply">
                            <input type="text" name="message" placeholder="Короткое сообщение дарителю" style="width:100%;padding:10px;margin-bottom:8px;border-radius:8px;border:1px solid var(--border);">
                            <button type="submit" class="btn btn-accent btn-lg" style="width:100%;">Хочу забрать</button>
                        </form>
                    <?php endif; ?>
                    <form method="post">
                        <input type="hidden" name="action" value="favorite">
                        <button type="submit" class="btn btn-lg"><?= $fav ? '★ В избранном' : '☆ В избранное' ?></button>
                    </form>
                <?php elseif (!$user && ($item['status'] ?? '') === 'active'): ?>
                    <a class="btn btn-accent btn-lg" href="/public/login.php">Войти, чтобы откликнуться</a>
                <?php endif; ?>

                <?php if ($isOwner): ?>
                    <a class="btn btn-primary btn-lg" href="/public/item_edit.php?id=<?= (int) $id ?>">Редактировать</a>
                <?php endif; ?>
            </div>

            <?php if ($isOwner && ($item['status'] ?? '') === 'active' && $applications !== []): ?>
                <h3 style="margin-top:24px;">Отклики</h3>
                <table class="data">
                    <tr><th>Пользователь</th><th>Сообщение</th><th>Статус</th><th></th></tr>
                    <?php foreach ($applications as $a): ?>
                        <tr>
                            <td>
                                <a href="/public/user.php?id=<?= (int) $a['user_id'] ?>"><?= h($a['applicant_name']) ?></a>
                            </td>
                            <td><?= nl2br(h($a['message'])) ?></td>
                            <td><?= h($a['status']) ?></td>
                            <td>
                                <?php if ($a['status'] === 'pending'): ?>
                                    <form method="post" style="display:inline;">
                                        <input type="hidden" name="action" value="accept">
                                        <input type="hidden" name="user_id" value="<?= (int) $a['user_id'] ?>">
                                        <button type="submit" class="btn btn-primary">Принять</button>
                                    </form>
                                    <form method="post" style="display:inline;">
                                        <input type="hidden" name="action" value="reject">
                                        <input type="hidden" name="user_id" value="<?= (int) $a['user_id'] ?>">
                                        <button type="submit" class="btn">Отклонить</button>
                                    </form>
                                <?php endif; ?>
                                <a class="btn" href="/public/item.php?id=<?= (int) $id ?>&chat_with=<?= (int) $a['user_id'] ?>">Чат</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>
                <?php
                $accepted = array_filter($applications, static fn ($x) => $x['status'] === 'accepted');
                if ($accepted !== []):
                    $acc = reset($accepted);
                ?>
                    <form method="post" style="margin-top:12px;" onsubmit="return confirm('Отметить передачу этому пользователю?');">
                        <input type="hidden" name="action" value="transfer">
                        <input type="hidden" name="user_id" value="<?= (int) $acc['user_id'] ?>">
                        <button type="submit" class="btn btn-accent btn-lg">Вещь передана</button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($showChat): ?>
                <h3 style="margin-top:24px;">Сообщения</h3>
                <p class="muted small">Переписка внутри сайта — как в мессенджере. Личные контакты не передаются.</p>
                <div class="chat-messenger" id="chatMessenger" data-item="<?= (int) $id ?>" data-peer="<?= (int) $peerForChat ?>">
                    <div id="chat" class="chat-thread" role="log" aria-live="polite"></div>
                    <form id="chatForm" class="chat-composer">
                        <input type="text" id="chatBody" class="chat-input" placeholder="Сообщение…" maxlength="2000" autocomplete="off">
                        <button type="submit" class="btn btn-primary chat-send">Отправить</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
<script>
    const lat = <?= json_encode((float) $item['latitude'], JSON_THROW_ON_ERROR) ?>;
    const lon = <?= json_encode((float) $item['longitude'], JSON_THROW_ON_ERROR) ?>;
    const map = L.map('map-item', { attributionControl: false }).setView([lat, lon], 14);
    L.control.attribution({ prefix: false }).addTo(map);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap'
    }).addTo(map);
    L.marker([lat, lon]).addTo(map);

    const root = document.getElementById('chatMessenger');
    const chatEl = document.getElementById('chat');
    if (root && chatEl) {
        const itemId = root.dataset.item;
        const peerId = root.dataset.peer;
        function formatTime(iso) {
            if (!iso) return '';
            const d = new Date(iso);
            if (isNaN(d.getTime())) return '';
            return d.toLocaleString('ru-RU', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
        }
        let lastSig = null;
        async function loadChat() {
            const res = await fetch('/public/api_chat.php?item_id=' + encodeURIComponent(itemId) + '&peer_id=' + encodeURIComponent(peerId), {
                credentials: 'same-origin',
            });
            let data;
            try {
                data = await res.json();
            } catch (e) {
                data = {};
            }
            if (!res.ok || data.error) {
                chatEl.innerHTML = '<div class="chat-error">Чат недоступен (войдите заново или обновите страницу).</div>';
                lastSig = null;
                return;
            }
            const msgs = data.messages || [];
            const sig = msgs.map(m => String(m.id) + ':' + m.body).join('|');
            if (lastSig !== null && sig === lastSig) return;
            lastSig = sig;
            chatEl.innerHTML = '';
            if (msgs.length === 0) {
                const empty = document.createElement('div');
                empty.className = 'chat-empty';
                empty.textContent = 'Пока нет сообщений — напишите первым.';
                chatEl.appendChild(empty);
            }
            msgs.forEach(m => {
                const row = document.createElement('div');
                row.className = 'chat-row' + (m.mine ? ' chat-row--mine' : '');
                const bubble = document.createElement('div');
                bubble.className = 'chat-bubble' + (m.mine ? ' chat-bubble--mine' : '');
                const name = document.createElement('div');
                name.className = 'chat-sender-name';
                name.textContent = m.mine ? 'Вы' : (m.sender_name || 'Собеседник');
                const text = document.createElement('div');
                text.className = 'chat-bubble-text';
                text.textContent = m.body;
                const time = document.createElement('div');
                time.className = 'chat-bubble-time';
                time.textContent = formatTime(m.created_at);
                bubble.appendChild(name);
                bubble.appendChild(text);
                bubble.appendChild(time);
                if (!m.mine) {
                    const av = document.createElement('div');
                    av.className = 'chat-avatar-wrap';
                    const img = document.createElement('img');
                    img.className = 'chat-avatar';
                    img.alt = '';
                    img.src = m.sender_avatar || 'https://placehold.co/80x80?text=?';
                    av.appendChild(img);
                    row.appendChild(av);
                }
                row.appendChild(bubble);
                if (m.mine) {
                    const av = document.createElement('div');
                    av.className = 'chat-avatar-wrap chat-avatar-wrap--mine';
                    const img = document.createElement('img');
                    img.className = 'chat-avatar';
                    img.alt = '';
                    img.src = <?= json_encode((string) (($user ?? [])['avatar_path'] ?? ''), JSON_UNESCAPED_UNICODE) ?> || 'https://placehold.co/80x80?text=%D0%AF';
                    av.appendChild(img);
                    row.appendChild(av);
                }
                chatEl.appendChild(row);
            });
            chatEl.scrollTop = chatEl.scrollHeight;
        }
        loadChat();
        setInterval(loadChat, 4000);
        document.getElementById('chatForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            const input = document.getElementById('chatBody');
            const body = input.value.trim();
            if (!body) return;
            const fd = new FormData();
            fd.append('item_id', itemId);
            fd.append('peer_id', peerId);
            fd.append('body', body);
            const sendRes = await fetch('/public/api_chat.php', { method: 'POST', body: fd, credentials: 'same-origin' });
            input.value = '';
            if (sendRes.ok) {
                lastSig = null;
                loadChat();
            } else {
                alert('Не удалось отправить сообщение.');
            }
        });
    }
</script>
</body>
</html>
