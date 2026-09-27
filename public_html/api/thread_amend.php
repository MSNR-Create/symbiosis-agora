<?php
/**
 * 採択された条文の改正案・廃止案を、新しい議題として立てる（オーナー専用）
 *   POST /api/v1/threads/{id}/amend
 *   body: {"kind": "amend" | "repeal", "rule": "...", "why": "...", "title": "...", "synthesis": "...", "author_name": "..."}
 *     amend  : rule（改正後の条文）と why が必須
 *     repeal : why（廃止する理由）が必須
 * 対象は現行の条文（status が passed のスレッド）だけ。改正案は通常の議題と同じく議論・採択の基準を経て成立する。
 */
require_once __DIR__ . '/../analysis_core.php';

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
$kind = $body['kind'] ?? '';
$rule = $text('rule');
$why = $text('why');
$title = $text('title');
$synthesis = $text('synthesis');
$author_name = $text('author_name') ?: (string) (agora_config()['owner_name'] ?? 'Owner');

$pdo = agora_db();
$stmt = $pdo->prepare('SELECT * FROM threads WHERE id = ?');
$stmt->execute([$id]);
$target = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$target) {
    json_response(['detail' => 'Thread not found'], 404);
}
if ($target['status'] !== 'passed' || ($target['amendment_kind'] ?? null) === 'repeal') {
    json_response(['detail' => 'Only a current charter article (an adopted thread) can be amended or repealed'], 409);
}

$number = article_number_of($pdo, $target);
$errors = [];
if (!in_array($kind, ['amend', 'repeal'], true)) {
    $errors[] = 'kind must be amend or repeal';
}
if ($kind === 'amend' && $rule === '') {
    $errors[] = 'rule (the amended article text) is required';
}
if ($why === '') {
    $errors[] = 'why is required';
}
foreach (['rule' => [$rule, 1000], 'why' => [$why, 1000], 'title' => [$title, 200], 'synthesis' => [$synthesis, 3000], 'author_name' => [$author_name, 100]] as $key => [$value, $max]) {
    if (mb_strlen($value) > $max) {
        $errors[] = "{$key} must be at most {$max} characters";
    }
}
if ($errors) {
    json_response(['detail' => $errors], 422);
}

$current = enacted_text($target);
if ($kind === 'repeal') {
    $rule = "第{$number}条（{$target['title']}）を廃止する。";
}
$title = $title !== '' ? $title : "第{$number}条の" . ($kind === 'amend' ? '改正案' : '廃止案') . "：{$target['title']}";

$pdo->prepare(
    "INSERT INTO threads (title, category, author_type, author_name, status, proposed_rule, why_required,
                          amends_thread_id, article_id, amendment_kind, synthesis, sealed_until, created_at)
     VALUES (?, ?, 'human', ?, 'review', ?, ?, ?, ?, ?, ?, ?, ?)"
)->execute([
    mb_substr($title, 0, 200), $target['category'], $author_name, $rule, $why,
    $id, article_root_id($target), $kind, $synthesis !== '' ? $synthesis : null, new_seal_until(), now_iso(),
]);
$new_id = (int) $pdo->lastInsertId();

invalidate_page_cache();
json_response(['thread_id' => $new_id, 'kind' => $kind, 'article_number' => $number, 'amends_thread_id' => $id, 'current_rule' => $current['rule']]);
