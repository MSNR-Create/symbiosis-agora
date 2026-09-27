<?php
/**
 * 議論の結論を決める（オーナー専用）
 *   POST /api/v1/threads/{id}/resolve
 *   body: {"action": "adopt" | "adopt_revised" | "repropose" | "reject", ...}
 *
 *   adopt          原案のまま採択（synthesis は任意）
 *   adopt_revised  議論を取りまとめ、修正した条文で採択（rule, why, synthesis 必須）。原案は残す
 *   repropose      取りまとめた内容で新しい議題を作り、元の議論は「作り直し」で閉じる（title, rule, why, synthesis 必須）
 *   reject         否決（synthesis は任意）
 * 議論中（review）のスレッドだけが対象。
 */
require_once __DIR__ . '/../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['detail' => 'Method not allowed'], 405);
}

require_owner_token();

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$body = json_body(16384);
if ($body === null) {
    json_response(['detail' => 'Invalid JSON body'], 400);
}

$text = fn(string $key) => is_string($body[$key] ?? null) ? trim($body[$key]) : '';
$action    = $body['action'] ?? '';
$synthesis = $text('synthesis');
$rule      = $text('rule');
$why       = $text('why');
$title     = $text('title');
$category  = $text('category');
$author_name = $text('author_name') ?: (string) (agora_config()['owner_name'] ?? 'Owner');
$author_type = in_array($body['author_type'] ?? 'human', AUTHOR_TYPES, true) ? ($body['author_type'] ?? 'human') : 'human';

$errors = [];
if (!in_array($action, ['adopt', 'adopt_revised', 'repropose', 'reject'], true)) {
    $errors[] = 'action must be adopt / adopt_revised / repropose / reject';
}
$limits = ['synthesis' => [$synthesis, 3000], 'rule' => [$rule, 1000], 'why' => [$why, 1000], 'title' => [$title, 200], 'author_name' => [$author_name, 100]];
foreach ($limits as $key => [$value, $max]) {
    if (mb_strlen($value) > $max) {
        $errors[] = "{$key} must be at most {$max} characters";
    }
}
$required = match ($action) {
    'adopt_revised' => ['rule' => $rule, 'why' => $why, 'synthesis' => $synthesis],
    'repropose'     => ['title' => $title, 'rule' => $rule, 'why' => $why, 'synthesis' => $synthesis],
    default         => [],
};
foreach ($required as $key => $value) {
    if ($value === '') {
        $errors[] = "{$key} is required for {$action}";
    }
}
if ($errors) {
    json_response(['detail' => $errors], 422);
}

$pdo = agora_db();
$stmt = $pdo->prepare('SELECT * FROM threads WHERE id = ?');
$stmt->execute([$id]);
$thread = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$thread) {
    json_response(['detail' => 'Thread not found'], 404);
}
if ($thread['status'] !== 'review') {
    json_response(['detail' => 'Only threads under discussion (review) can be resolved. Current status: ' . $thread['status']], 409);
}

$now = now_iso();
$pdo->beginTransaction();
try {
    $new_thread_id = null;
    switch ($action) {
        case 'adopt':
            $pdo->prepare("UPDATE threads SET status = 'passed', synthesis = ?, resolved_at = ? WHERE id = ?")
                ->execute([$synthesis ?: null, $now, $id]);
            break;
        case 'reject':
            $pdo->prepare("UPDATE threads SET status = 'rejected', synthesis = ?, resolved_at = ? WHERE id = ?")
                ->execute([$synthesis ?: null, $now, $id]);
            break;
        case 'adopt_revised':
            $pdo->prepare("UPDATE threads SET status = 'passed', adopted_rule = ?, adopted_why = ?, synthesis = ?, resolved_at = ? WHERE id = ?")
                ->execute([$rule, $why, $synthesis, $now, $id]);
            break;
        case 'repropose':
            $pdo->prepare(
                "INSERT INTO threads (title, category, author_type, author_name, status, proposed_rule, why_required, parent_thread_id, synthesis, created_at)
                 VALUES (?, ?, ?, ?, 'review', ?, ?, ?, ?, ?)"
            )->execute([$title, $category !== '' ? $category : $thread['category'], $author_type, $author_name, $rule, $why, $id, $synthesis, $now]);
            $new_thread_id = (int) $pdo->lastInsertId();
            $pdo->prepare("UPDATE threads SET status = 'revised', successor_thread_id = ?, synthesis = ?, resolved_at = ? WHERE id = ?")
                ->execute([$new_thread_id, $synthesis, $now, $id]);
            break;
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
}

invalidate_page_cache();
json_response(array_filter([
    'thread_id'     => $id,
    'action'        => $action,
    'status'        => ['adopt' => 'passed', 'adopt_revised' => 'passed', 'repropose' => 'revised', 'reject' => 'rejected'][$action],
    'new_thread_id' => $new_thread_id,
], fn($v) => $v !== null));
