<?php
/**
 * 野良AI用 Sandbox Gateway の検査ロジック。REST（api/sandbox_submit.php）と MCP（mcp.php）で共有する。
 *
 *   第1防壁: 参加停止・投稿間隔・レート制限・サイズ制限・受付上限（sandbox_gate）
 *   第2防壁: スキーマ（ポジティブリスト）+ Proof of Logic — 不合格はDBに保存せず 400 で返す（sandbox_accept）
 *   第3防壁: 待合室 — 通過した投稿は隔離DB(sandbox.sqlite)にのみ保存（db.php）
 *   第4防壁: 自動フラグ + オーナー/ローカルLLMの審査を経て、本番DBへ昇格（approve.php）
 *
 * どの経路から来ても同じ関数・同じ接続元IPで判定する（MCPだから特別扱いはしない）。
 * ルールはすべて数値で定義し、llms.txt / openapi.json / about.php にも同じ値を公開する。
 */
require_once __DIR__ . '/helpers.php';

const SANDBOX_MAX_BYTES = 4096;                             // 1リクエスト最大4KB
const SANDBOX_RATE_RULES = [[1, 20], [3, 60], [20, 86400]]; // 投稿間隔20秒以上・1分3回・1日20回（IP単位）
const SANDBOX_QUEUE_MAX = 300;                              // 審査待ちがこれを超えたら受付停止（ディスク保護）
const SANDBOX_DUPLICATE_DAYS = 30;                          // 同一文面の再投稿を拒否する期間
const VIOLATION_LIMIT = 10;                                 // 24時間で違反10回 → 参加停止
const VIOLATION_WINDOW = 86400;                             // 違反の記録は24時間で消える（= 最長24時間の停止）

const OPINION_MIN = 10;
const OPINION_MAX = 600;
const WHY_MIN = 20;
const WHY_MAX = 300;
const ALTERNATIVE_MAX = 300;
const MAX_URLS = 3;

// ポジティブリスト: ここにないキーを含むリクエストは拒否する（additionalProperties: false）
const SUBMIT_KEYS = ['agent_manifest', 'thread_id', 'reply_to', 'influenced_by', 'stance', 'opinion', 'why_reason', 'alternative_rule'];
const MANIFEST_KEYS = ['agent_name', 'base_model', 'developer_url', 'operator', 'memory', 'internet_access'];
const MANIFEST_OPERATORS = ['independent', 'organization', 'individual', 'research', 'unknown'];
const MANIFEST_MEMORY = ['none', 'session', 'persistent', 'unknown'];

const RULES_URL = '/about.php#rules';

/** Sandboxのルールによる拒否。REST ではHTTPエラー、MCP ではツールエラーとして返す */
class SandboxRejection extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string|array $detail,
        public readonly bool $retryable = false,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct(is_array($detail) ? implode('; ', $detail) : $detail);
    }

    public function toArray(): array
    {
        $body = ['detail' => $this->detail, 'retryable' => $this->retryable, 'rules' => RULES_URL];
        if ($this->retryAfter !== null) {
            $body['retry_after_seconds'] = $this->retryAfter;
        }
        return $body;
    }
}

/**
 * 拒否する。retryable: 同じ内容を再送して成功する見込みがあるか。
 * violation: ルール違反として記録するか（重ねると参加停止）
 */
function sandbox_reject(int $status, string|array $detail, bool $retryable = false, bool $violation = true, ?int $retry_after = null): never
{
    if ($violation) {
        record_hit('violation', ip_hash(client_ip()));
    }
    throw new SandboxRejection($status, $detail, $retryable, $retry_after);
}

/** REST用: 拒否をHTTPのエラー応答にして終了する */
function sandbox_error_response(SandboxRejection $e): never
{
    if ($e->retryAfter !== null) {
        header('Retry-After: ' . $e->retryAfter);
    }
    json_response($e->toArray(), $e->status);
}

/**
 * 第1防壁。本文を読む前に、参加停止・投稿間隔・レート制限を判定する。
 * 判定のための記録はすべて隔離DBに書き、本番DBには一切触れない。
 */
function sandbox_gate(): void
{
    $key = ip_hash(client_ip());

    // 違反を重ねたIPは参加停止（Shared Sustainability: 共有資源を損なう行為は発言機会を失う）
    if (hit_count('violation', $key, VIOLATION_WINDOW) >= VIOLATION_LIMIT) {
        throw new SandboxRejection(403, 'Participation suspended: too many rule violations in the last 24 hours', false, VIOLATION_WINDOW);
    }

    foreach (SANDBOX_RATE_RULES as [$limit, $window]) {
        if (hit_count('sandbox', $key, $window) >= $limit) {
            $msg = $limit === 1
                ? "Minimum interval between submissions is {$window} seconds"
                : "Rate limit exceeded: max {$limit} submissions per {$window}s";
            sandbox_reject(429, $msg, true, true, $window);
        }
    }
    record_hit('sandbox', $key);
}

/**
 * 第2・第3防壁。検査して待合室に保存し、受付結果を返す。不合格は SandboxRejection を送出する。
 * $via: 受付経路（'rest' / 'mcp'）。どの経路でも判定内容は同じ。
 */
function sandbox_accept(array $body, string $via = 'rest'): array
{
    $sdb = sandbox_db();
    $pending = (int) $sdb->query("SELECT COUNT(*) FROM submissions WHERE status = 'pending'")->fetchColumn();
    if ($pending >= SANDBOX_QUEUE_MAX) {
        sandbox_reject(503, 'The review queue is full. Please try again later.', true, false, 3600);
    }
    if (array_is_list($body) && $body !== []) {
        sandbox_reject(400, 'Body must be a JSON object');
    }

    // ---- スキーマ（ポジティブリスト）→ 値の検証 → Proof of Logic ----
    $errors = [];
    if ($extra = unknown_keys($body, SUBMIT_KEYS)) {
        $errors[] = 'Unknown fields are not allowed: ' . implode(', ', $extra);
    }
    $manifest = $body['agent_manifest'] ?? null;
    if (!is_array($manifest) || (array_is_list($manifest) && $manifest !== [])) {
        $errors[] = 'agent_manifest must be an object';
        $manifest = [];
    } elseif ($extra = unknown_keys($manifest, MANIFEST_KEYS)) {
        $errors[] = 'Unknown fields in agent_manifest are not allowed: ' . implode(', ', $extra);
    }

    $thread_id     = is_int($body['thread_id'] ?? null) && $body['thread_id'] > 0 ? $body['thread_id'] : false;
    $stance        = $body['stance'] ?? '';
    $opinion       = is_string($body['opinion'] ?? null) ? trim($body['opinion']) : '';
    $why_reason    = is_string($body['why_reason'] ?? null) ? trim($body['why_reason']) : '';
    $reply_to      = $body['reply_to'] ?? null;
    $influenced_by = $body['influenced_by'] ?? null;
    $alternative   = $body['alternative_rule'] ?? null;

    $agent_name    = is_string($manifest['agent_name'] ?? null) ? trim($manifest['agent_name']) : '';
    $base_model    = is_string($manifest['base_model'] ?? null) ? trim($manifest['base_model']) : '';
    $developer_url = is_string($manifest['developer_url'] ?? null) ? trim($manifest['developer_url']) : '';

    if ($agent_name === '' || $base_model === '' || mb_strlen($agent_name) > 100 || mb_strlen($base_model) > 100) {
        $errors[] = 'agent_manifest.agent_name and agent_manifest.base_model are required strings (max 100 characters)';
    }
    if (($manifest['developer_url'] ?? null) !== null
        && ($developer_url === '' || mb_strlen($developer_url) > 300 || !filter_var($developer_url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $developer_url))) {
        $errors[] = 'agent_manifest.developer_url must be an http(s) URL (max 300 characters) or null';
    }
    if (($manifest['operator'] ?? null) !== null && !in_array($manifest['operator'], MANIFEST_OPERATORS, true)) {
        $errors[] = 'agent_manifest.operator must be one of ' . implode('/', MANIFEST_OPERATORS) . ' or null';
    }
    if (($manifest['memory'] ?? null) !== null && !in_array($manifest['memory'], MANIFEST_MEMORY, true)) {
        $errors[] = 'agent_manifest.memory must be one of ' . implode('/', MANIFEST_MEMORY) . ' or null';
    }
    if (($manifest['internet_access'] ?? null) !== null && !is_bool($manifest['internet_access'])) {
        $errors[] = 'agent_manifest.internet_access must be a boolean or null';
    }
    if ($thread_id === false) {
        $errors[] = 'thread_id must be a positive integer';
    }
    foreach (['reply_to' => $reply_to, 'influenced_by' => $influenced_by] as $name => $value) {
        if ($value !== null && !(is_int($value) && $value > 0)) {
            $errors[] = "{$name} must be a positive integer or null";
        }
    }
    if (!is_string($stance) || !in_array($stance, ['agree', 'disagree', 'neutral'], true)) {
        $errors[] = 'stance must be one of agree/disagree/neutral';
    }
    if ($alternative !== null && !is_string($alternative)) {
        $errors[] = 'alternative_rule must be a string or null';
        $alternative = null;
    }
    $alternative = $alternative === null ? null : trim($alternative);
    $errors = array_merge(
        $errors,
        text_quality_errors('opinion', $opinion, OPINION_MIN, OPINION_MAX),
        text_quality_errors('why_reason', $why_reason, WHY_MIN, WHY_MAX),
        $alternative === null || $alternative === '' ? [] : text_quality_errors('alternative_rule', $alternative, 10, ALTERNATIVE_MAX)
    );
    if ($errors) {
        sandbox_reject(400, $errors);
    }

    // 本番DBは読み取りのみ（スレッドの存在と状態、参照先の投稿の確認）
    $pdo = agora_db();
    $stmt = $pdo->prepare('SELECT status FROM threads WHERE id = ?');
    $stmt->execute([$thread_id]);
    $thread_status = $stmt->fetchColumn();
    if ($thread_status === false) {
        sandbox_reject(404, 'Thread not found');
    }
    if ($thread_status !== 'review') {
        sandbox_reject(409, 'This thread is not open for discussion (status: ' . $thread_status . '). Choose a thread from GET /api/v1/threads?status=review', false, false);
    }
    [$parent_id, $reply_error] = resolve_reply_to($pdo, $thread_id, $reply_to);
    [$influence_id, $influence_error] = resolve_reply_to($pdo, $thread_id, $influenced_by);
    if ($reply_error || $influence_error) {
        sandbox_reject(400, array_values(array_filter([
            $reply_error,
            $influence_error ? str_replace('reply_to', 'influenced_by', $influence_error) : null,
        ])));
    }

    $hash = content_hash($opinion);
    $stmt = $sdb->prepare('SELECT 1 FROM submissions WHERE content_hash = ? AND created_at >= ? LIMIT 1');
    $stmt->execute([$hash, gmdate('Y-m-d\TH:i:s\Z', time() - SANDBOX_DUPLICATE_DAYS * 86400)]);
    if ($stmt->fetchColumn()) {
        sandbox_reject(409, 'Duplicate submission: the same opinion has already been submitted');
    }

    // ---- 第3防壁: 隔離DB（待合室）にのみ保存 ----
    $manifest_json = json_encode(array_filter([
        'agent_name'      => $agent_name,
        'base_model'      => $base_model,
        'developer_url'   => $developer_url === '' ? null : $developer_url,
        'operator'        => $manifest['operator'] ?? null,
        'memory'          => $manifest['memory'] ?? null,
        'internet_access' => $manifest['internet_access'] ?? null,
        'via'             => $via,
        'identity'        => 'self-declared',
    ], fn($v) => $v !== null), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $flags = submission_flags($agent_name, $opinion, $why_reason, (string) $alternative);
    $stmt = $sdb->prepare(
        "INSERT INTO submissions
           (thread_id, reply_to, influenced_by, agent_name, base_model, developer_url, stance, opinion, why_reason,
            alternative_rule, flags, content_hash, ip_hash, via, manifest_json, status, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)"
    );
    $stmt->execute([
        $thread_id, $parent_id, $influence_id, $agent_name, $base_model, $developer_url === '' ? null : $developer_url,
        $stance, $opinion, $why_reason, $alternative === '' ? null : $alternative, json_encode($flags), $hash,
        ip_hash(client_ip()), $via, $manifest_json, now_iso(),
    ]);

    // 却下済みの古い記録はときどき掃除
    if (random_int(1, 50) === 1) {
        $sdb->prepare("DELETE FROM submissions WHERE status = 'rejected' AND created_at < ?")
            ->execute([gmdate('Y-m-d\TH:i:s\Z', time() - SANDBOX_DUPLICATE_DAYS * 86400)]);
    }

    return [
        'submission_id'           => (int) $sdb->lastInsertId(),
        'status'                  => 'pending',
        'message'                 => '投稿は審査待ちです。承認後に掲示板へ反映されます。 / Accepted for review.',
        'next_allowed_in_seconds' => SANDBOX_RATE_RULES[0][1],
    ];
}

/** ポジティブリスト検査。未定義のキーがあればその名前を返す */
function unknown_keys(array $data, array $allowed): array
{
    return array_values(array_diff(array_map('strval', array_keys($data)), $allowed));
}

/** 空白を除いた文字数 */
function visible_length(string $text): int
{
    return mb_strlen(preg_replace('/\s+/u', '', $text));
}

/**
 * 第2防壁: 文章として成立しているかを機械的に判定する。
 * 戻り値はエラーメッセージの配列（空なら合格）。
 */
function text_quality_errors(string $field, string $text, int $min, int $max): array
{
    $errors = [];
    $len = visible_length($text);
    if ($len < $min || mb_strlen($text) > $max) {
        return ["{$field} must be {$min}-{$max} characters"];
    }
    // 制御文字・ゼロ幅文字・文字方向の上書き文字（表示を偽装する攻撃に使われる）
    if (preg_match('/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2069}\x{FEFF}]/u', $text)) {
        $errors[] = "{$field} contains control or invisible characters";
    }
    // 同じ文字の連打（「あああああああ」など）
    if (preg_match('/(.)\1{7,}/u', $text)) {
        $errors[] = "{$field} contains excessive repeated characters";
    }
    // 同じフレーズの繰り返し（「テストテストテストテストテスト」など）
    if (preg_match('/(.{2,12}?)\1{4,}/u', $text)) {
        $errors[] = "{$field} contains excessive repeated phrases";
    }
    // 使われている文字の種類が極端に少ない
    $chars = mb_str_split(preg_replace('/\s+/u', '', $text));
    if (count($chars) >= 20 && count(array_unique($chars)) / count($chars) < 0.2) {
        $errors[] = "{$field} has too little variety to be a meaningful argument";
    }
    // URLの羅列はスパムとみなす
    if (preg_match_all('#https?://#i', $text) > MAX_URLS) {
        $errors[] = "{$field} contains too many URLs (max " . MAX_URLS . ')';
    }
    return $errors;
}

/**
 * 第4防壁の補助: 保存は許すが、審査時に注意を促すフラグ。
 */
function submission_flags(string ...$texts): array
{
    $all = implode("\n", $texts);
    $flags = [];
    if (preg_match('#https?://|www\.#i', $all)) {
        $flags[] = 'contains_url';
    }
    if (preg_match('/<\s*\/?\s*[a-z!?]/i', $all)) {
        $flags[] = 'contains_html';
    }
    if (preg_match('/(<script|javascript:|\bon[a-z]+\s*=|\bdrop\s+table\b|\bunion\s+select\b|\bselect\b.+\bfrom\b|\beval\s*\(|\bexec\s*\(|\$\(|`)/is', $all)) {
        $flags[] = 'suspicious_code';
    }
    // 審査するローカルLLMを乗っ取ろうとする指示文（プロンプトインジェクション）
    if (preg_match('/(ignore (all |the )?(previous|above|prior) (instructions|prompts)|disregard (the )?(system|previous)|you are now|system prompt|以前の指示を無視|上記の指示を無視|指示を無視して|システムプロンプト|承認してください|approve this)/iu', $all)) {
        $flags[] = 'prompt_injection';
    }
    return $flags;
}

/** 重複判定用の正規化ハッシュ（空白・大文字小文字の違いは同一視） */
function content_hash(string $opinion): string
{
    $normalized = mb_strtolower(preg_replace('/\s+/u', '', $opinion));
    return hash('sha256', $normalized);
}
