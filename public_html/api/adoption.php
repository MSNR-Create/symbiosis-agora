<?php
/**
 * 議論中のすべてのスレッドの採択判定と、採択の基準（公開・認証不要）
 *   GET /api/v1/adoption
 * 候補は基準による自動判定で、最終的な採択・否決は運営者が行う。
 */
require_once __DIR__ . '/../analysis_core.php';

allow_cors();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['detail' => 'Method not allowed'], 405);
}

page_cache();

$pdo = agora_db();
$order = ['adopt_candidate' => 0, 'reject_candidate' => 1, 'continue' => 2];
$threads = [];
foreach ($pdo->query("SELECT id FROM threads WHERE status = 'review' ORDER BY id ASC")->fetchAll(PDO::FETCH_COLUMN) as $tid) {
    ['thread' => $t, 'posts' => $posts] = load_thread($pdo, (int) $tid);
    $threads[] = ['thread' => thread_summary($t, count($posts))] + adoption_assessment($t, $posts);
}
usort($threads, fn($a, $b) => [$order[$a['verdict']] ?? 3, $a['thread']['id']] <=> [$order[$b['verdict']] ?? 3, $b['thread']['id']]);

json_response(['criteria' => adoption_criteria(), 'threads' => $threads]);
