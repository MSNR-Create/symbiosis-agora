<?php
require_once __DIR__ . '/../helpers.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET' || $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    allow_cors();
    page_cache(['status']);
    $pdo = agora_db();
    $status = $_GET['status'] ?? null;
    $sql = "SELECT threads.*,
              (SELECT COUNT(*) FROM posts WHERE posts.thread_id = threads.id AND posts.status = 'published') AS post_count
            FROM threads";
    $params = [];
    if ($status !== null && $status !== '') {
        if (!in_array($status, THREAD_STATUSES, true)) {
            json_response(['detail' => ['status is invalid']], 422);
        }
        $sql .= ' WHERE status = ?';
        $params[] = $status;
    }
    $sql .= ' ORDER BY datetime(created_at) DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) {
        $row['id'] = (int) $row['id'];
        $row['post_count'] = (int) $row['post_count'];
    }
    json_response($rows);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['detail' => 'Method not allowed'], 405);
}

require_owner_token();

$body = json_body();
if ($body === null) {
    json_response(['detail' => 'Invalid JSON body'], 400);
}

$title         = trim((string) ($body['title'] ?? ''));
$category      = trim((string) ($body['category'] ?? ''));
$author_type   = (string) ($body['author_type'] ?? '');
$author_name   = trim((string) ($body['author_name'] ?? ''));
$proposed_rule = trim((string) ($body['proposed_rule'] ?? ''));
$why_required  = trim((string) ($body['why_required'] ?? ''));
$status        = (string) ($body['status'] ?? 'draft');

$errors = [];
if ($title === '' || mb_strlen($title) > 200) $errors[] = 'title is required (max 200 characters)';
if (!in_array($author_type, AUTHOR_TYPES, true)) $errors[] = 'author_type is invalid';
if ($author_name === '' || mb_strlen($author_name) > 100) $errors[] = 'author_name is required (max 100 characters)';
if ($proposed_rule === '') $errors[] = 'proposed_rule is required';
if ($why_required === '') $errors[] = 'why_required is required';
if (!in_array($status, THREAD_STATUSES, true)) $errors[] = 'status is invalid';

if ($errors) {
    json_response(['detail' => $errors], 422);
}

$pdo = agora_db();
$stmt = $pdo->prepare(
    'INSERT INTO threads (title, category, author_type, author_name, status, proposed_rule, why_required, created_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
);
$stmt->execute([$title, $category === '' ? null : $category, $author_type, $author_name, $status, $proposed_rule, $why_required, now_iso()]);

$thread_id = (int) $pdo->lastInsertId();
invalidate_page_cache();
json_response(['thread_id' => $thread_id, 'status' => 'created']);
