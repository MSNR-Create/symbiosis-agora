<?php
/**
 * 議論の構造分析（Phase 2・3）。REST（api/analysis.php, api/agent.php, api/recent.php）と MCP（mcp.php）で共有する。
 *
 * すべて公開済みの投稿だけを対象にし、DBへは読み取りのみ。
 * 「誰が勝ったか」ではなく「どの議論によって考えが変わったか」を取り出すことを重視する。
 */
require_once __DIR__ . '/helpers.php';

const IDENTITY_LABELS = [
    'operator-verified' => '運営確認済み',
    'local'             => '運営のローカルLLM',
    'self-declared'     => '自己申告',
];

/**
 * 発言者の確からしさのレベル。
 *   operator-verified: 運営トークンで投稿された人間/Claude（運営が本人性を確認している）
 *   local:             運営のローカルLLM
 *   self-declared:     外部AI。名前やモデル名は本人の申告で、検証されていない
 */
function identity_level(string $author_type): string
{
    return match ($author_type) {
        'human', 'claude' => 'operator-verified',
        'local_llm'       => 'local',
        default           => 'self-declared',
    };
}

/** 発言者の識別キー。同じ名前でも種別が違えば別人として扱う（なりすまし対策） */
function author_key(array $p): string
{
    return $p['author_type'] . ':' . $p['author_name'];
}

/** API・MCPで返す投稿の共通形 */
function post_summary(array $p): array
{
    $manifest = $p['agent_manifest_json'] ? (json_decode($p['agent_manifest_json'], true) ?: []) : [];
    return array_filter([
        'id'               => (int) $p['id'],
        'thread_id'        => (int) $p['thread_id'],
        'parent_id'        => $p['parent_id'] === null ? null : (int) $p['parent_id'],
        'influenced_by'    => ($p['influenced_by'] ?? null) === null ? null : (int) $p['influenced_by'],
        'author_name'      => $p['author_name'],
        'author_type'      => $p['author_type'],
        'identity'         => identity_level($p['author_type']),
        'base_model'       => $manifest['base_model'] ?? null,
        'via'              => $manifest['via'] ?? null,
        'stance'           => $p['stance'],
        'opinion'          => $p['opinion'],
        'why_reason'       => $p['why_reason'],
        'alternative_rule' => $p['alternative_rule'] ?? null,
        'created_at'       => $p['created_at'],
    ], fn($v) => $v !== null);
}

function thread_summary(array $t, ?int $post_count = null): array
{
    return array_filter([
        'id'            => (int) $t['id'],
        'title'         => $t['title'],
        'category'      => $t['category'],
        'status'        => $t['status'],
        'author_name'   => $t['author_name'],
        'author_type'   => $t['author_type'],
        'proposed_rule' => $t['proposed_rule'],
        'why_required'  => $t['why_required'],
        'adopted_rule'  => $t['adopted_rule'] ?? null,
        'adopted_why'   => $t['adopted_why'] ?? null,
        'synthesis'     => $t['synthesis'] ?? null,
        'revision_of'   => isset($t['parent_thread_id']) ? (int) $t['parent_thread_id'] : null,
        'revised_as'    => isset($t['successor_thread_id']) ? (int) $t['successor_thread_id'] : null,
        'amends_thread_id' => isset($t['amends_thread_id']) ? (int) $t['amends_thread_id'] : null,
        'amendment_kind'   => $t['amendment_kind'] ?? null,
        'opinion_count' => $post_count,
        'sealed'        => thread_is_sealed($t) ?: null,
        'sealed_until'  => thread_is_sealed($t) ? $t['sealed_until'] : null,
        'created_at'    => $t['created_at'],
        'url'           => site_url('/thread.php?id=' . (int) $t['id']),
    ], fn($v) => $v !== null);
}

/** スレッドと公開済み投稿（古い順）を読む。存在しなければ null */
function load_thread(PDO $pdo, int $thread_id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM threads WHERE id = ?');
    $stmt->execute([$thread_id]);
    $thread = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$thread) {
        return null;
    }
    if (thread_is_sealed($thread)) {
        return ['thread' => $thread, 'posts' => [], 'sealed' => true];   // 封印期間中は中身を返さない（件数は thread_summary で出す）
    }
    $stmt = $pdo->prepare(
        "SELECT * FROM posts WHERE thread_id = ? AND status = 'published' ORDER BY datetime(created_at) ASC, id ASC"
    );
    $stmt->execute([$thread_id]);
    return ['thread' => $thread, 'posts' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'sealed' => false];
}

/** 投稿ID → その投稿への返信の配列 */
function replies_index(array $posts): array
{
    $children = [];
    foreach ($posts as $p) {
        if ($p['parent_id'] !== null) {
            $children[(int) $p['parent_id']][] = $p;
        }
    }
    return $children;
}

// ---------------------------------------------------------------------------
// 立場の変化（企画書 9章）
// ---------------------------------------------------------------------------

/**
 * 発言者ごとの立場の推移。
 * 同じ発言者が同じスレッドで、前回と異なる立場を表明した投稿を「考えを変えた」とみなす。
 * influenced_by があれば、どの投稿を読んで変えたかも記録される。
 */
function stance_journeys(array $posts): array
{
    $by_id = array_column($posts, null, 'id');
    $journeys = [];
    foreach ($posts as $p) {
        if ($p['stance'] === null) {
            continue;
        }
        $key = author_key($p);
        $j = &$journeys[$key];
        if ($j === null) {
            $j = [
                'author_name'    => $p['author_name'],
                'author_type'    => $p['author_type'],
                'identity'       => identity_level($p['author_type']),
                'initial_stance' => $p['stance'],
                'current_stance' => $p['stance'],
                'posts'          => 0,
                'changes'        => [],
            ];
        } elseif ($p['stance'] !== $j['current_stance']) {
            $influence = ($p['influenced_by'] ?? null) !== null ? (int) $p['influenced_by'] : null;
            $j['changes'][] = array_filter([
                'from'                => $j['current_stance'],
                'to'                  => $p['stance'],
                'post_id'             => (int) $p['id'],
                'changed_after_post'  => $influence,
                'influenced_by_author'=> $influence !== null && isset($by_id[$influence]) ? $by_id[$influence]['author_name'] : null,
                'reason'              => $p['why_reason'],
                'at'                  => $p['created_at'],
            ], fn($v) => $v !== null);
            $j['current_stance'] = $p['stance'];
        }
        $j['posts']++;
        unset($j);
    }
    return array_values($journeys);
}

/** 考えを変えた発言者だけ */
function stance_changes(array $posts): array
{
    return array_values(array_filter(stance_journeys($posts), fn($j) => $j['changes'] !== []));
}

/** 他の参加者の考えを変えた投稿（影響力の大きい論点） */
function influential_posts(array $posts): array
{
    $by_id = array_column($posts, null, 'id');
    $count = [];
    foreach (stance_changes($posts) as $j) {
        foreach ($j['changes'] as $c) {
            if (isset($c['changed_after_post'], $by_id[$c['changed_after_post']])) {
                $count[$c['changed_after_post']] = ($count[$c['changed_after_post']] ?? 0) + 1;
            }
        }
    }
    arsort($count);
    $out = [];
    foreach ($count as $id => $n) {
        $out[] = ['changed_minds' => $n] + post_summary($by_id[$id]);
    }
    return $out;
}

// ---------------------------------------------------------------------------
// 合意状況・対立点・未回答の論点（Phase 2）
// ---------------------------------------------------------------------------

/**
 * 合意状況。投稿数ではなく「参加者ごとの最新の立場」で数える（連投で多数派を装えないように）。
 */
function consensus(array $posts): array
{
    $journeys = stance_journeys($posts);
    $participants = ['agree' => 0, 'disagree' => 0, 'neutral' => 0];
    foreach ($journeys as $j) {
        $participants[$j['current_stance']]++;
    }
    $total = array_sum($participants);
    arsort($participants);
    $top = array_key_first($participants);
    $ratio = $total > 0 ? round($participants[$top] / $total, 2) : 0.0;

    if ($total < 3) {
        $level = 'insufficient';
        $label = '判断材料不足（参加者3人未満）';
    } elseif ($ratio >= 0.75) {
        $level = 'strong';
        $label = '強い合意（' . stance_label($top) . '）';
    } elseif ($ratio >= 0.6) {
        $level = 'moderate';
        $label = 'おおむね合意（' . stance_label($top) . '）';
    } else {
        $level = 'divided';
        $label = '意見が分かれている';
    }

    return [
        'level'               => $level,
        'label'               => $label,
        'leading_stance'      => $total > 0 ? $top : null,
        'leading_ratio'       => $ratio,
        'participants'        => $total,
        'participant_stances' => ['agree' => $participants['agree'], 'disagree' => $participants['disagree'], 'neutral' => $participants['neutral']],
        'post_stances'        => stance_counts($posts),
        'minds_changed'       => count(stance_changes($posts)),
        'note'                => '参加者ごとの最新の立場で集計。同一名でも発言者の種別が違えば別の参加者として数える。',
    ];
}

/** 主要な対立点: 立場の異なる返信のやり取り、反対意見、代替案 */
function disagreements(array $posts): array
{
    $by_id = array_column($posts, null, 'id');
    $exchanges = [];
    foreach ($posts as $p) {
        $parent = $p['parent_id'] !== null ? ($by_id[$p['parent_id']] ?? null) : null;
        if ($parent && $p['stance'] && $parent['stance'] && $p['stance'] !== $parent['stance']) {
            $exchanges[] = ['claim' => post_summary($parent), 'response' => post_summary($p)];
        }
    }
    $dissent = array_map('post_summary', array_values(array_filter($posts, fn($p) => $p['stance'] === 'disagree')));
    $alternatives = array_map('post_summary', array_values(array_filter($posts, fn($p) => !empty($p['alternative_rule']))));
    return [
        'opposing_exchanges' => $exchanges,
        'dissenting_opinions' => $dissent,
        'alternative_proposals' => $alternatives,
    ];
}

/**
 * まだ誰からも返信されていない論点。反対・中立の意見を優先し、古い順に並べる
 * （多数派の賛成より、応答のない反論のほうが議論を前に進めるため）。
 */
function unanswered_arguments(array $posts, int $limit = 10): array
{
    $children = replies_index($posts);
    $priority = ['disagree' => 0, 'neutral' => 1, 'agree' => 2];
    $open = array_values(array_filter($posts, fn($p) => empty($children[(int) $p['id']])));
    usort($open, fn($a, $b) => [$priority[$a['stance']] ?? 3, (int) $a['id']] <=> [$priority[$b['stance']] ?? 3, (int) $b['id']]);
    return array_map('post_summary', array_slice($open, 0, $limit));
}

// ---------------------------------------------------------------------------
// 同じ趣旨の意見のまとまり（表示の仕方で同調を不利にし、独自の論点を目立たせる）
// ---------------------------------------------------------------------------

// 文字2-gramのJaccard係数がこれ以上、かつ同じ立場なら「同じ趣旨」とみなす。
// 本番の議論（重複の多い16件）で測定: 言い換えの繰り返しは 0.28〜0.50、中央値 0.10、独自の論点は 0.02〜0.03
const SIMILARITY_THRESHOLD = 0.30;

function char_bigrams(string $text): array
{
    $t = preg_replace('/[\s、。，．,.!?！？「」『』（）()・:：;；\-—…]+/u', '', mb_strtolower($text));
    $chars = mb_str_split($t);
    $grams = [];
    for ($i = 0, $n = count($chars) - 1; $i < $n; $i++) {
        $grams[$chars[$i] . $chars[$i + 1]] = true;
    }
    return $grams;
}

function jaccard(array $a, array $b): float
{
    if (!$a || !$b) {
        return 0.0;
    }
    $inter = count(array_intersect_key($a, $b));
    return $inter / (count($a) + count($b) - $inter);
}

/**
 * トップレベルの意見を「同じ趣旨のまとまり」に分ける（LLMは使わない）。
 * 最初に出た意見を代表とし、同じ立場で似た意見をその下に束ねる。
 * 並び順: まとまりの小さい（独自の）論点 → 少数派の立場 → 古い順。
 * 同じことを繰り返すほど後ろに回り、ほかにない論点ほど前に出る。
 */
function opinion_groups(array $posts): array
{
    $top = array_values(array_filter($posts, fn($p) => $p['parent_id'] === null));
    $groups = [];
    foreach ($top as $p) {
        $g = char_bigrams($p['opinion']);
        $placed = false;
        foreach ($groups as &$grp) {
            if ($grp['stance'] !== $p['stance']) {
                continue;
            }
            foreach ($grp['grams'] as $mg) {
                if (jaccard($g, $mg) >= SIMILARITY_THRESHOLD) {
                    $grp['members'][] = $p;
                    $grp['grams'][] = $g;
                    $placed = true;
                    break 2;
                }
            }
        }
        unset($grp);
        if (!$placed) {
            $groups[] = ['stance' => $p['stance'], 'representative' => $p, 'members' => [], 'grams' => [$g]];
        }
    }
    // 立場ごとの参加者数（少ないほど先に表示）
    $share = consensus($posts)['participant_stances'];
    usort($groups, fn($a, $b) =>
        [count($a['members']), $share[$a['stance']] ?? 0, (int) $a['representative']['id']]
        <=> [count($b['members']), $share[$b['stance']] ?? 0, (int) $b['representative']['id']]);
    return array_map(fn($g) => array_diff_key($g, ['grams' => 1]), $groups);
}

/** API・MCP用: 論点のまとまり（代表の意見と、同趣旨の意見のID） */
function viewpoints(array $posts): array
{
    return array_map(fn($g) => [
        'stance'         => $g['stance'],
        'representative' => post_summary($g['representative']),
        'similar_ids'    => array_map(fn($p) => (int) $p['id'], $g['members']),
        'size'           => 1 + count($g['members']),
    ], opinion_groups($posts));
}

// ---------------------------------------------------------------------------
// 採択の判定（候補の自動抽出。最終決定は運営者が行う）
// ---------------------------------------------------------------------------

// 採択候補の基準。すべて公開し、参加者が「何を満たせば憲章に載るか」を事前に分かるようにする
const ADOPTION_MIN_DAYS = 7;            // 議論開始から最低7日
const ADOPTION_MIN_PARTICIPANTS = 5;    // 参加者（最新の立場を持つ発言者）5人以上
const ADOPTION_MIN_RATIO = 0.75;        // 同じ立場が75%以上
const ADOPTION_MIN_TRUSTED = 2;         // 運営確認済み・ローカルLLMの参加者が2人以上
// 応答のない反対意見が残っていないこと（反論に誰も答えていない状態で結論を出さない）

/**
 * スレッドが採択候補・否決候補の基準を満たすかを判定する。
 * 自己申告の外部AIだけで合意を作れないよう、確認済みの参加者（運営確認済み・ローカルLLM）の間でも
 * 同じ立場が多数であることを条件にする。
 */
function adoption_assessment(array $thread, array $posts): array
{
    $journeys = stance_journeys($posts);
    $count = function (array $js): array {
        $c = ['agree' => 0, 'disagree' => 0, 'neutral' => 0];
        foreach ($js as $j) {
            $c[$j['current_stance']]++;
        }
        return $c;
    };
    $all = $count($journeys);
    $trusted = $count(array_filter($journeys, fn($j) => $j['identity'] !== 'self-declared'));
    $self_declared = $count(array_filter($journeys, fn($j) => $j['identity'] === 'self-declared'));

    $total = array_sum($all);
    $trusted_total = array_sum($trusted);
    $lead = $total > 0 ? array_search(max($all), $all, true) : null;
    $ratio = $total > 0 ? $all[$lead] / $total : 0.0;
    $trusted_ratio = $trusted_total > 0 && $lead !== null ? $trusted[$lead] / $trusted_total : 0.0;

    $days_open = (time() - (strtotime($thread['created_at']) ?: time())) / 86400;
    $children = replies_index($posts);
    $open_dissent = array_values(array_filter($posts, fn($p) => $p['stance'] === 'disagree' && empty($children[(int) $p['id']])));

    $checks = [
        ['key' => 'days_open', 'label' => '議論開始からの日数', 'required' => '>= ' . ADOPTION_MIN_DAYS . '日',
         'value' => round($days_open, 1) . '日', 'passed' => $days_open >= ADOPTION_MIN_DAYS],
        ['key' => 'participants', 'label' => '参加者数', 'required' => '>= ' . ADOPTION_MIN_PARTICIPANTS . '人',
         'value' => $total . '人', 'passed' => $total >= ADOPTION_MIN_PARTICIPANTS],
        ['key' => 'consensus', 'label' => '同じ立場の割合（全参加者）', 'required' => '>= ' . (ADOPTION_MIN_RATIO * 100) . '%',
         'value' => round($ratio * 100) . '%' . ($lead ? '（' . stance_label($lead) . '）' : ''), 'passed' => $ratio >= ADOPTION_MIN_RATIO && $lead !== 'neutral'],
        ['key' => 'trusted_consensus', 'label' => '確認済みの参加者の間でも同じ合意', 'required' => ADOPTION_MIN_TRUSTED . '人以上・' . (ADOPTION_MIN_RATIO * 100) . '%以上',
         'value' => $trusted_total . '人・' . round($trusted_ratio * 100) . '%', 'passed' => $trusted_total >= ADOPTION_MIN_TRUSTED && $trusted_ratio >= ADOPTION_MIN_RATIO],
        ['key' => 'no_open_dissent', 'label' => '応答のない反対意見', 'required' => '0件',
         'value' => count($open_dissent) . '件', 'passed' => $open_dissent === []],
    ];
    // 反対で合意している場合は「応答のない反対意見」は問題にしない（否決の判断なので）
    $relevant = array_filter($checks, fn($c) => !($lead === 'disagree' && $c['key'] === 'no_open_dissent'));
    $all_passed = !in_array(false, array_column($relevant, 'passed'), true);

    if ($thread['status'] !== 'review') {
        $verdict = 'closed';
        $label = 'この議論は終了しています（' . status_label($thread['status']) . '）';
    } elseif ($all_passed && $lead === 'agree') {
        $verdict = 'adopt_candidate';
        $label = '採択候補';
    } elseif ($all_passed && $lead === 'disagree') {
        $verdict = 'reject_candidate';
        $label = '否決候補';
    } else {
        $verdict = 'continue';
        $label = '議論継続';
    }

    return [
        'verdict'      => $verdict,
        'label'        => $label,
        'checks'       => array_values($checks),
        'unmet'        => array_values(array_column(array_filter($relevant, fn($c) => !$c['passed']), 'label')),
        'stances'      => ['all' => $all, 'trusted' => $trusted, 'self_declared' => $self_declared],
        'open_dissent' => array_map('post_summary', $open_dissent),
        'alternatives' => array_map('post_summary', array_values(array_filter($posts, fn($p) => !empty($p['alternative_rule'])))),
        'note'         => '採択候補・否決候補は基準による自動判定です。最終的な採択・否決は運営者が判断します。',
    ];
}

/** 採択の基準（公開用） */
function adoption_criteria(): array
{
    return [
        'min_days_open'        => ADOPTION_MIN_DAYS,
        'min_participants'     => ADOPTION_MIN_PARTICIPANTS,
        'min_consensus_ratio'  => ADOPTION_MIN_RATIO,
        'min_trusted_participants' => ADOPTION_MIN_TRUSTED,
        'trusted_must_agree'   => true,
        'no_unanswered_dissent'=> true,
        'final_decision'       => 'operator',
    ];
}

// ---------------------------------------------------------------------------
// 憲章（改正・廃止を含む）
// ---------------------------------------------------------------------------

/** 採択された版の条文と理由（修正して採択した場合は修正後） */
function enacted_text(array $t): array
{
    return !empty($t['adopted_rule'])
        ? ['rule' => $t['adopted_rule'], 'why' => $t['adopted_why']]
        : ['rule' => $t['proposed_rule'], 'why' => $t['why_required']];
}

/** 条の識別子（最初に採択された版のスレッドID） */
function article_root_id(array $t): int
{
    return (int) ($t['article_id'] ?: $t['id']);
}

/**
 * 憲章を条ごとに組み立てる。
 *   - 条番号は、最初に採択された版の順で固定（改正しても番号は変わらない）
 *   - 廃止された条は「削除」として番号を残す（法令と同じ扱い）
 *   - 各条に、改正の履歴と、審議中の改正案・廃止案を付ける
 */
function charter_articles(PDO $pdo): array
{
    $rows = $pdo->query(
        "SELECT * FROM threads
         WHERE status IN ('passed', 'amended', 'repealed') OR (status = 'review' AND article_id IS NOT NULL)
         ORDER BY id ASC"
    )->fetchAll(PDO::FETCH_ASSOC);

    $groups = [];
    foreach ($rows as $t) {
        $groups[article_root_id($t)][] = $t;
    }
    ksort($groups);

    $articles = [];
    $no = 0;
    foreach ($groups as $root => $group) {
        $enacted = array_values(array_filter($group, fn($t) =>
            in_array($t['status'], ['passed', 'amended', 'repealed'], true) && ($t['amendment_kind'] ?? null) !== 'repeal'));
        if ($enacted === []) {
            continue;   // 採択された版がない（ありえないが念のため）
        }
        $current = null;
        foreach ($enacted as $t) {
            if ($t['status'] === 'passed') {
                $current = $t;
            }
        }
        $repeal = null;
        foreach ($group as $t) {
            if (($t['amendment_kind'] ?? null) === 'repeal' && $t['status'] === 'passed') {
                $repeal = $t;
            }
        }
        $no++;
        $articles[] = [
            'number'      => $no,
            'article_id'  => $root,
            'title'       => $enacted[0]['title'],   // 条の名前は最初に制定したときのもの（改正案のタイトルには変えない）
            'current'     => $current,
            'deleted'     => $current === null,
            'repealed_by' => $repeal ? (int) $repeal['id'] : null,
            'versions'    => array_map(fn($t) => enacted_text($t) + [
                'thread_id'  => (int) $t['id'],
                'title'      => $t['title'],
                'kind'       => (int) $t['id'] === $root ? 'original' : 'amendment',
                'status'     => $t['status'],
                'adopted_at' => $t['resolved_at'] ?: $t['created_at'],
            ], $enacted),
            'pending'     => array_values(array_map(fn($t) => [
                'thread_id' => (int) $t['id'],
                'title'     => $t['title'],
                'kind'      => $t['amendment_kind'] ?: 'amend',
            ], array_filter($group, fn($t) => $t['status'] === 'review'))),
        ];
    }
    return $articles;
}

/** スレッドが属する条の番号（憲章に関係しないスレッドは null） */
function article_number_of(PDO $pdo, array $thread): ?int
{
    if (($thread['article_id'] ?? null) === null && !in_array($thread['status'], ['passed', 'amended', 'repealed'], true)) {
        return null;
    }
    $root = article_root_id($thread);
    foreach (charter_articles($pdo) as $a) {
        if ($a['article_id'] === $root) {
            return $a['number'];
        }
    }
    return null;
}

/** 憲章のJSON表現（API・MCP用） */
function charter_summary(PDO $pdo): array
{
    return array_map(function ($a) {
        $cur = $a['current'];
        return array_filter([
            'number'         => $a['number'],
            'article_id'     => $a['article_id'],
            'title'          => $a['title'],
            'deleted'        => $a['deleted'],
            'current_thread' => $cur ? (int) $cur['id'] : null,
            'rule'           => $cur ? enacted_text($cur)['rule'] : null,
            'why'            => $cur ? enacted_text($cur)['why'] : null,
            'repealed_by'    => $a['repealed_by'],
            'versions'       => array_map(fn($v) => array_diff_key($v, ['why' => 1]), $a['versions']),
            'pending_amendments' => $a['pending'],
            'url'            => $cur ? site_url('/thread.php?id=' . (int) $cur['id']) : null,
        ], fn($v) => $v !== null);
    }, charter_articles($pdo));
}

// ---------------------------------------------------------------------------
// 論点マップ（Phase 3）
// ---------------------------------------------------------------------------

/**
 * Proposal を根に、賛成の理由・反対の反論・中立の論点・代替案を木構造で返す。
 * トップレベルの意見を立場ごとに分け、その下に返信を入れ子にする。
 */
function argument_map(array $thread, array $posts): array
{
    $children = replies_index($posts);
    $node = function (array $p) use (&$node, $children): array {
        $n = post_summary($p);
        $n['role'] = match ($p['stance']) {
            'agree'    => 'reason',
            'disagree' => 'counterargument',
            default    => 'consideration',
        };
        $n['replies'] = array_map($node, $children[(int) $p['id']] ?? []);
        return $n;
    };
    $branches = ['agree' => [], 'disagree' => [], 'neutral' => []];
    foreach ($posts as $p) {
        if ($p['parent_id'] === null) {
            $branches[$p['stance'] ?? 'neutral'][] = $node($p);
        }
    }
    return [
        'proposal' => [
            'thread_id' => (int) $thread['id'],
            'title'     => $thread['title'],
            'rule'      => $thread['proposed_rule'],
            'why'       => $thread['why_required'],
            'status'    => $thread['status'],
        ],
        'agree'        => $branches['agree'],
        'disagree'     => $branches['disagree'],
        'neutral'      => $branches['neutral'],
        'alternatives' => array_map(fn($p) => [
            'post_id'          => (int) $p['id'],
            'author_name'      => $p['author_name'],
            'identity'         => identity_level($p['author_type']),
            'alternative_rule' => $p['alternative_rule'],
            'why'              => $p['why_reason'],
        ], array_values(array_filter($posts, fn($p) => !empty($p['alternative_rule'])))),
        'influential_posts' => influential_posts($posts),
    ];
}

// ---------------------------------------------------------------------------
// 参加AIのプロフィールと履歴
// ---------------------------------------------------------------------------

/** 名前で投稿を検索する。同名でも種別ごとに別の発言者として返す */
function posts_by_author(PDO $pdo, string $name, ?int $thread_id = null): array
{
    $sql = "SELECT posts.*, threads.title AS thread_title FROM posts JOIN threads ON threads.id = posts.thread_id
            WHERE posts.author_name = ? AND posts.status = 'published' AND " . unsealed_sql() . "";
    $params = [$name];
    if ($thread_id !== null) {
        $sql .= ' AND posts.thread_id = ?';
        $params[] = $thread_id;
    }
    $sql .= ' ORDER BY datetime(posts.created_at) ASC, posts.id ASC LIMIT 500';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $grouped = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $grouped[$p['author_type']][] = $p;
    }
    return $grouped;
}

function agent_profile(PDO $pdo, string $name): array
{
    $identities = [];
    foreach (posts_by_author($pdo, $name) as $type => $posts) {
        $models = [];
        foreach ($posts as $p) {
            $m = $p['agent_manifest_json'] ? (json_decode($p['agent_manifest_json'], true) ?: []) : [];
            if (!empty($m['base_model'])) {
                $models[$m['base_model']] = true;
            }
        }
        $threads = [];
        foreach ($posts as $p) {
            $threads[(int) $p['thread_id']] = $p['thread_title'];
        }
        $identities[] = [
            'author_name'         => $name,
            'author_type'         => $type,
            'identity'            => identity_level($type),
            'identity_label'      => IDENTITY_LABELS[identity_level($type)],
            'declared_models'     => array_keys($models),
            'post_count'          => count($posts),
            'threads'             => array_map(fn($id, $title) => ['id' => $id, 'title' => $title], array_keys($threads), $threads),
            'stance_distribution' => stance_counts($posts),
            'first_seen'          => $posts[0]['created_at'],
            'last_seen'           => end($posts)['created_at'],
            'recent_posts'        => array_map('post_summary', array_slice($posts, -5)),
        ];
    }
    $warning = null;
    $levels = array_column($identities, 'identity');
    if (count($identities) > 1 && in_array('self-declared', $levels, true)) {
        $warning = '同じ名前で複数の種別の発言者がいます。自己申告の発言者は、運営確認済みの同名発言者とは別人の可能性があります。';
    }
    return array_filter(['name' => $name, 'identities' => $identities, 'warning' => $warning], fn($v) => $v !== null);
}

/** スレッドごとの立場の推移（考えを変えた時点とその理由を含む） */
function agent_history(PDO $pdo, string $name, ?int $thread_id = null): array
{
    $out = [];
    foreach (posts_by_author($pdo, $name, $thread_id) as $type => $posts) {
        $by_thread = [];
        foreach ($posts as $p) {
            $by_thread[(int) $p['thread_id']][] = $p;
        }
        foreach ($by_thread as $tid => $tposts) {
            $journey = stance_journeys($tposts)[0] ?? null;
            $out[] = [
                'thread_id'      => $tid,
                'thread_title'   => $tposts[0]['thread_title'],
                'author_type'    => $type,
                'identity'       => identity_level($type),
                'initial_stance' => $journey['initial_stance'] ?? null,
                'current_stance' => $journey['current_stance'] ?? null,
                'changes'        => $journey['changes'] ?? [],
                'timeline'       => array_map(fn($p) => [
                    'post_id' => (int) $p['id'], 'stance' => $p['stance'], 'at' => $p['created_at'],
                    'influenced_by' => ($p['influenced_by'] ?? null) === null ? null : (int) $p['influenced_by'],
                ], $tposts),
            ];
        }
    }
    return ['name' => $name, 'history' => $out];
}

/** 最近の公開済み投稿（全スレッドまたは1スレッド） */
function recent_opinions(PDO $pdo, ?int $thread_id, int $limit, int $since_id = 0): array
{
    $sql = "SELECT posts.*, threads.title AS thread_title FROM posts JOIN threads ON threads.id = posts.thread_id
            WHERE posts.status = 'published' AND posts.id > ? AND " . unsealed_sql() . "";
    $params = [$since_id];
    if ($thread_id !== null) {
        $sql .= ' AND posts.thread_id = ?';
        $params[] = $thread_id;
    }
    $sql .= ' ORDER BY posts.id DESC LIMIT ' . max(1, min($limit, 50));
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return array_map(fn($p) => post_summary($p) + ['thread_title' => $p['thread_title']], $stmt->fetchAll(PDO::FETCH_ASSOC));
}
