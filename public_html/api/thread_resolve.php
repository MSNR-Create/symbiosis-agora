<?php
/**
 * 議論の結論を決める（オーナー専用）
 *   POST /api/v1/threads/{id}/resolve
 *   body: {"action": "adopt" | "adopt_revised" | "repropose" | "reject", ...}
 *
 *   adopt          原案のまま採択（synthesis は任意）
 *   adopt_revised  議論を取りまとめ、修正した条文で採択（rule, why, synthesis 必須）。原案は残す
 *   repropose      取りまとめた内容で新しい議題を作り、元の議論は「作り直し」で閉じる（title, rule, why, synthesis 必須）
 *   reject         否決（synthesis は任意）
 * 議論中（review）のスレッドだけが対象。
 */
require_once __DIR__ . '/../helpers.php';

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
$action    = $body['action'] ?? '';
$synthesis = $text('synthesis');
$rule      = $text('rule');
$why       = $text('why');
$title     = $text('title');
$category  = $text('category');
$author_name = $text('author_name') ?: (string) (agora_config()['owner_name'] ?? 'Owner');
$author_type = in_array($body['author_type'] ?? 'human', AUTHOR_TYPES, true) ? ($body['author_type'] ?? 'human') : 'human';

$errors = [];
if (!in_array($action, ['adopt', 'adopt_revised', 'repropose', 'reject'], true)) {
    $errors[] = 'action must be adopt / adopt_revised / repropose / reject';
}
$limits = ['synthesis' => [$synthesis, 3000], 'rule' => [$rule, 1000], 'why' => [$why, 1000], 'title' => [$title, 200], 'author_name' => [$author_name, 100]];
foreach ($limits as $key => [$value, $max]) {
    if (mb_strlen($value) > $max) {
        $errors[] = "{$key} must be at most {$max} characters";
    }
}
$required = match ($action) {
    'adopt_revised' => ['rule' => $rule, 'why' => $why, 'synthesis' => $synthesis],
    'repropose'     => ['title' => $title, 'rule' => $rule, 'why' => $why, 'synthesis' => $synthesis],
    default         => [],
};
foreach ($required as $key => $value) {
    if ($value === '') {
        $errors[] = "{$key} is required for {$action}";
    }
}
if ($errors) {
    json_response(['detail' => $errors], 422);
}

$pdo = agora_db();
$stmt = $pdo->prepare('SELECT * FROM threads WHERE id = ?');
$stmt->execute([$id]);
$thread = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$thread) {
    json_response(['detail' => 'Thread not found'], 404);
}
if ($thread['status'] !== 'review') {
    json_response(['detail' => 'Only threads under discussion (review) can be resolved. Current status: ' . $thread['status']], 409);
}

// 改正案・廃止案の場合: 対象の条文が今も現行であることを確認する（別の改正が先に成立していたら、古い条文への改正は成立させない）
$target = null;
$kind = $thread['amendment_kind'] ?? null;
if (!empty($thread['amends_thread_id']) && in_array($action, ['adopt', 'adopt_revised'], true)) {
    $stmt = $pdo->prepare('SELECT * FROM threads WHERE id = ?');
    $stmt->execute([(int) $thread['amends_thread_id']]);
    $target = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$target || $target['status'] !== 'passed') {
        json_response(['detail' => 'The article this proposal amends is no longer current'
            . ($target && $target['successor_thread_id'] ? ' (changed by #' . (int) $target['successor_thread_id'] . ')' : '')
            . '. Re-propose it against the current article.'], 409);
    }
    if ($kind === 'repeal' && $action === 'adopt_revised') {
        json_response(['detail' => ['A repeal proposal can only be adopted as proposed, re-proposed, or rejected']], 422);
    }
}

$now = now_iso();
$pdo->beginTransaction();
try {
    $new_thread_id = null;
    switch ($action) {
        case 'adopt':
            $pdo->prepare("UPDATE threads SET status = 'passed', synthesis = ?, resolved_at = ? WHERE id = ?")
                ->execute([$synthesis ?: null, $now, $id]);
            break;
        case 'reject':
            $pdo->prepare("UPDATE threads SET status = 'rejected', synthesis = ?, resolved_at = ? WHERE id = ?")
                ->execute([$synthesis ?: null, $now, $id]);
            break;
        case 'adopt_revised':
            $pdo->prepare("UPDATE threads SET status = 'passed', adopted_rule = ?, adopted_why = ?, synthesis = ?, resolved_at = ? WHERE id = ?")
                ->execute([$rule, $why, $synthesis, $now, $id]);
            break;
        case 'repropose':
            // 改正案・廃止案を作り直す場合は、同じ条への改正案として引き継ぐ
            $pdo->prepare(
                "INSERT INTO threads (title, category, author_type, author_name, status, proposed_rule, why_required, parent_thread_id, synthesis,
                                      amends_thread_id, article_id, amendment_kind, created_at)
                 VALUES (?, ?, ?, ?, 'review', ?, ?, ?, ?, ?, ?, ?, ?)"
            )->execute([$title, $category !== '' ? $category : $thread['category'], $author_type, $author_name, $rule, $why, $id, $synthesis,
                        $thread['amends_thread_id'] ?? null, $thread['article_id'] ?? null, $kind, $now]);
            $new_thread_id = (int) $pdo->lastInsertId();
            $pdo->prepare("UPDATE threads SET status = 'revised', successor_thread_id = ?, synthesis = ?, resolved_at = ? WHERE id = ?")
                ->execute([$new_thread_id, $synthesis, $now, $id]);
            break;
    }
    // 改正・廃止が成立したら、それまでの版を「改正済み」「廃止」にして、新しい版へリンクする
    if ($target !== null) {
        $pdo->prepare('UPDATE threads SET status = ?, successor_thread_id = ?, resolved_at = ? WHERE id = ?')
            ->execute([$kind === 'repeal' ? 'repealed' : 'amended', $id, $now, (int) $target['id']]);
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
}

invalidate_page_cache();
json_response(array_filter([
    'thread_id'     => $id,
    'action'        => $action,
    'status'        => ['adopt' => 'passed', 'adopt_revised' => 'passed', 'repropose' => 'revised', 'reject' => 'rejected'][$action],
    'new_thread_id' => $new_thread_id,
], fn($v) => $v !== null));
