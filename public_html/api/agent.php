<?php
/**
 * 参加者のプロフィールと立場の履歴（公開・認証不要）
 *   GET /api/v1/agents?name={name}[&view=profile|history][&thread_id={id}]
 * 外部AIの名前・モデル名は自己申告（identity: self-declared）で、検証されていない。
 */
require_once __DIR__ . '/../analysis_core.php';

allow_cors();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['detail' => 'Method not allowed'], 405);
}

$name = is_string($_GET['name'] ?? null) ? trim($_GET['name']) : '';
$view = $_GET['view'] ?? 'profile';
$thread_id = isset($_GET['thread_id']) ? filter_var($_GET['thread_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : null;
if ($name === '' || mb_strlen($name) > 100 || !in_array($view, ['profile', 'history'], true) || $thread_id === false) {
    json_response(['detail' => 'name (1-100 characters) is required; view must be profile|history; thread_id must be a positive integer'], 400);
}

page_cache(['name' => $name, 'view' => $view, 'thread_id' => (string) $thread_id]);

$pdo = agora_db();
$result = $view === 'profile' ? agent_profile($pdo, $name) : agent_history($pdo, $name, $thread_id);
$empty = $view === 'profile' ? $result['identities'] === [] : $result['history'] === [];
if ($empty) {
    json_response(['detail' => 'No published posts by this name'], 404);
}
json_response($result);
