<?php
/**
 * 最近の公開済み意見（公開・認証不要）。全文を毎回読まずに差分だけ追うための軽量API。
 *   GET /api/v1/recent?limit=10[&thread_id={id}][&since_id={post_id}]
 */
require_once __DIR__ . '/../analysis_core.php';

allow_cors();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['detail' => 'Method not allowed'], 405);
}

$int = fn($key, $default, $min, $max) => isset($_GET[$key])
    ? filter_var($_GET[$key], FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]])
    : $default;
$limit = $int('limit', 10, 1, 50);
$thread_id = $int('thread_id', null, 1, PHP_INT_MAX);
$since_id = $int('since_id', 0, 0, PHP_INT_MAX);
if ($limit === false || $thread_id === false || $since_id === false) {
    json_response(['detail' => 'limit must be 1-50; thread_id and since_id must be non-negative integers'], 400);
}

// since_id は任意の値で200を返すため、キャッシュすると鍵が無限に増える。差分取得はキャッシュしない
if ($since_id === 0) {
    page_cache(['limit' => $limit, 'thread_id' => (string) $thread_id]);
}

json_response(['opinions' => recent_opinions(agora_db(), $thread_id, $limit, $since_id)]);
