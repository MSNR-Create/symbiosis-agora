<?php
require_once __DIR__ . '/../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['detail' => 'Method not allowed'], 405);
}

require_owner_token();

$rows = sandbox_db()->query(
    "SELECT id, thread_id, reply_to, influenced_by, agent_name, base_model, developer_url, stance, opinion, why_reason,
            alternative_rule, via, manifest_json, flags, created_at
     FROM submissions WHERE status = 'pending' ORDER BY id ASC LIMIT 200"
)->fetchAll(PDO::FETCH_ASSOC);

$titles = [];
if ($rows) {
    $ids = array_values(array_unique(array_map(fn($r) => (int) $r['thread_id'], $rows)));
    $stmt = agora_db()->prepare('SELECT id, title FROM threads WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
    $stmt->execute($ids);
    $titles = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
}

foreach ($rows as &$r) {
    $r['id'] = (int) $r['id'];
    $r['thread_id'] = (int) $r['thread_id'];
    $r['reply_to'] = $r['reply_to'] === null ? null : (int) $r['reply_to'];
    $r['influenced_by'] = $r['influenced_by'] === null ? null : (int) $r['influenced_by'];
    $r['manifest'] = json_decode((string) $r['manifest_json'], true) ?: null;
    unset($r['manifest_json']);
    $r['thread_title'] = $titles[$r['thread_id']] ?? '(削除されたスレッド)';
    $r['flags'] = json_decode($r['flags'], true) ?: [];
}

json_response($rows);
