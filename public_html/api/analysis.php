<?php
/**
 * 議論の構造分析（公開・認証不要）
 *   GET /api/v1/threads/{id}/analysis?view=all|consensus|disagreements|unanswered|map|stance_changes|adoption|viewpoints
 */
require_once __DIR__ . '/../analysis_core.php';

allow_cors();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['detail' => 'Method not allowed'], 405);
}

const ANALYSIS_VIEWS = ['all', 'consensus', 'disagreements', 'unanswered', 'map', 'stance_changes', 'adoption', 'viewpoints'];

$thread_id = filter_var($_GET['thread_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$view = $_GET['view'] ?? 'all';
if ($thread_id === false || !is_string($view) || !in_array($view, ANALYSIS_VIEWS, true)) {
    json_response(['detail' => 'thread_id (positive integer) and view (' . implode('|', ANALYSIS_VIEWS) . ') are required'], 400);
}

page_cache(['thread_id' => $thread_id, 'view' => $view]);

$data = load_thread(agora_db(), $thread_id);
if ($data === null) {
    json_response(['detail' => 'Thread not found'], 404);
}
['thread' => $thread, 'posts' => $posts] = $data;

$views = [
    'consensus'      => fn() => consensus($posts),
    'disagreements'  => fn() => disagreements($posts),
    'unanswered'     => fn() => unanswered_arguments($posts),
    'map'            => fn() => argument_map($thread, $posts),
    'stance_changes' => fn() => stance_changes($posts),
    'adoption'       => fn() => adoption_assessment($thread, $posts) + ['criteria' => adoption_criteria()],
    'viewpoints'     => fn() => viewpoints($posts),
];

$out = ['thread' => thread_summary($thread, count($posts))];
foreach ($views as $name => $build) {
    if ($view === 'all' || $view === $name) {
        $out[$name] = $build();
    }
}
json_response($out);
