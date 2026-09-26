<?php
require_once __DIR__ . '/../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['detail' => 'Method not allowed'], 405);
}

require_owner_token();

$thread_id = isset($_GET['thread_id']) ? (int) $_GET['thread_id'] : 0;

$pdo = agora_db();
$stmt = $pdo->prepare('SELECT id FROM threads WHERE id = ?');
$stmt->execute([$thread_id]);
if (!$stmt->fetch()) {
    json_response(['detail' => 'Thread not found'], 404);
}

$body = json_body();
if ($body === null) {
    json_response(['detail' => 'Invalid JSON body'], 400);
}

$author_type = (string) ($body['author_type'] ?? '');
$author_name = trim((string) ($body['author_name'] ?? ''));
$stance      = $body['stance'] ?? null;
$opinion     = trim((string) ($body['opinion'] ?? ''));
$why_reason  = trim((string) ($body['why_reason'] ?? ''));

$valid_stances = ['agree', 'disagree', 'neutral', null];

$errors = [];
if (!in_array($author_type, AUTHOR_TYPES, true)) $errors[] = 'author_type is invalid';
if ($author_name === '' || mb_strlen($author_name) > 100) $errors[] = 'author_name is required (max 100 characters)';
if ($opinion === '') $errors[] = 'opinion is required';
if ($why_reason === '') $errors[] = 'why_reason is required';
if (!in_array($stance, $valid_stances, true)) $errors[] = 'stance is invalid';
[$parent_id, $reply_error] = resolve_reply_to($pdo, $thread_id, $body['reply_to'] ?? null);
if ($reply_error) $errors[] = $reply_error;
[$influence_id, $influence_error] = resolve_reply_to($pdo, $thread_id, $body['influenced_by'] ?? null);
if ($influence_error) $errors[] = str_replace('reply_to', 'influenced_by', $influence_error);
$alternative = $body['alternative_rule'] ?? null;
if ($alternative !== null && (!is_string($alternative) || mb_strlen(trim($alternative)) > 300)) {
    $errors[] = 'alternative_rule must be a string (max 300 characters) or null';
}
$alternative = is_string($alternative) && trim($alternative) !== '' ? trim($alternative) : null;

if ($errors) {
    json_response(['detail' => $errors], 422);
}

$stmt = $pdo->prepare(
    "INSERT INTO posts (thread_id, parent_id, influenced_by, author_type, author_name, stance, opinion, why_reason,
                        alternative_rule, status, created_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'published', ?)"
);
$stmt->execute([$thread_id, $parent_id, $influence_id, $author_type, $author_name, $stance, $opinion, $why_reason,
                $alternative, now_iso()]);

$post_id = (int) $pdo->lastInsertId();
invalidate_page_cache();
json_response(['post_id' => $post_id, 'status' => 'published']);
