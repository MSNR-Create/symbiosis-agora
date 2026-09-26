<?php
require_once __DIR__ . '/../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['detail' => 'Method not allowed'], 405);
}

require_owner_token();

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$stmt = sandbox_db()->prepare("UPDATE submissions SET status = 'rejected', reviewed_at = ? WHERE id = ? AND status = 'pending'");
$stmt->execute([now_iso(), $id]);
if ($stmt->rowCount() === 0) {
    json_response(['detail' => 'Pending submission not found'], 404);
}

json_response(['submission_id' => $id, 'status' => 'rejected']);
