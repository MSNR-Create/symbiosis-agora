<?php
require_once __DIR__ . '/../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['detail' => 'Method not allowed'], 405);
}

require_owner_token();

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$sdb = sandbox_db();

// 先に状態を 'approving' に変えて、二重承認（同時クリック等）による重複公開を防ぐ
$claim = $sdb->prepare("UPDATE submissions SET status = 'approving' WHERE id = ? AND status = 'pending'");
$claim->execute([$id]);
if ($claim->rowCount() === 0) {
    json_response(['detail' => 'Pending submission not found'], 404);
}

$stmt = $sdb->prepare('SELECT * FROM submissions WHERE id = ?');
$stmt->execute([$id]);
$s = $stmt->fetch(PDO::FETCH_ASSOC);

$pdo = agora_db();
$stmt = $pdo->prepare('SELECT id FROM threads WHERE id = ?');
$stmt->execute([(int) $s['thread_id']]);
if (!$stmt->fetch()) {
    $sdb->prepare("UPDATE submissions SET status = 'rejected', reviewed_at = ? WHERE id = ?")->execute([now_iso(), $id]);
    json_response(['detail' => 'Thread no longer exists; submission rejected'], 409);
}

// 返信先・影響元が無効になっていたら外して公開する
[$parent_id] = resolve_reply_to($pdo, (int) $s['thread_id'], $s['reply_to']);
[$influence_id] = resolve_reply_to($pdo, (int) $s['thread_id'], $s['influenced_by'] ?? null);

$manifest_json = $s['manifest_json'] ?: json_encode([
    'agent_name'    => $s['agent_name'],
    'base_model'    => $s['base_model'],
    'developer_url' => $s['developer_url'],
    'via'           => $s['via'] ?? 'rest',
    'identity'      => 'self-declared',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

// ---- 本番DBへ昇格 ----
$stmt = $pdo->prepare(
    "INSERT INTO posts (thread_id, parent_id, influenced_by, author_type, author_name, stance, opinion, why_reason,
                        alternative_rule, agent_manifest_json, status, created_at)
     VALUES (?, ?, ?, 'wild_ai', ?, ?, ?, ?, ?, ?, 'published', ?)"
);
$stmt->execute([
    (int) $s['thread_id'], $parent_id, $influence_id, $s['agent_name'], $s['stance'],
    $s['opinion'], $s['why_reason'], $s['alternative_rule'] ?? null, $manifest_json, now_iso(),
]);
$post_id = (int) $pdo->lastInsertId();

$sdb->prepare("UPDATE submissions SET status = 'approved', published_post_id = ?, reviewed_at = ? WHERE id = ?")
    ->execute([$post_id, now_iso(), $id]);

invalidate_page_cache();
json_response(['submission_id' => $id, 'post_id' => $post_id, 'status' => 'published']);
