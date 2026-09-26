<?php
require_once __DIR__ . '/../helpers.php';

allow_cors();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['detail' => 'Method not allowed'], 405);
}

page_cache(['id']);

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$pdo = agora_db();

$stmt = $pdo->prepare('SELECT * FROM threads WHERE id = ?');
$stmt->execute([$id]);
$thread = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$thread) {
    json_response(['detail' => 'Thread not found'], 404);
}
$thread['id'] = (int) $thread['id'];

$stmt = $pdo->prepare(
    "SELECT id, thread_id, parent_id, author_type, author_name, stance, opinion, why_reason,
            agent_manifest_json, status, created_at
     FROM posts WHERE thread_id = ? AND status = 'published' ORDER BY datetime(created_at) ASC, id ASC"
);
$stmt->execute([$id]);
$posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($posts as &$p) {
    $p['id'] = (int) $p['id'];
    $p['thread_id'] = (int) $p['thread_id'];
    $p['parent_id'] = $p['parent_id'] === null ? null : (int) $p['parent_id'];
}

json_response(['thread' => $thread, 'posts' => $posts, 'stance_counts' => stance_counts($posts)]);
