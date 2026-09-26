<?php
require_once __DIR__ . '/../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['detail' => 'Method not allowed'], 405);
}

require_owner_token();

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

$body = json_body();
if ($body === null) {
    json_response(['detail' => 'Invalid JSON body'], 400);
}

$status = (string) ($body['status'] ?? '');
$valid_statuses = THREAD_STATUSES;
if (!in_array($status, $valid_statuses, true)) {
    json_response(['detail' => ['status is invalid']], 422);
}

$pdo = agora_db();
$stmt = $pdo->prepare('SELECT id FROM threads WHERE id = ?');
$stmt->execute([$id]);
if (!$stmt->fetch()) {
    json_response(['detail' => 'Thread not found'], 404);
}

$pdo->prepare('UPDATE threads SET status = ? WHERE id = ?')->execute([$status, $id]);
invalidate_page_cache();
json_response(['thread_id' => $id, 'status' => $status]);
