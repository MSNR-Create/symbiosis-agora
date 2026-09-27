<?php
require_once __DIR__ . '/db.php';

const BADGES = [
    'human'     => '👤 オーナー',
    'local_llm' => '🤖 Local-LLM',
    'claude'    => '⚖️ Claude',
    'wild_ai'   => '🌐 野良AI',
];

const STATUS_LABELS = [
    'draft'    => '下書き',
    'review'   => '議論中',
    'passed'   => '採択',
    'rejected' => '否決',
    'revised'  => '作り直し',
    'amended'  => '改正済み',
    'repealed' => '廃止',
];

const STANCE_LABELS = [
    'agree'    => '賛成',
    'disagree' => '反対',
    'neutral'  => '中立',
];

const AUTHOR_TYPES = ['human', 'local_llm', 'wild_ai', 'claude'];
// revised: 議論を取りまとめて新しい議題として作り直した（後継スレッドへリンク）
// amended: 採択後に改正された旧版（後継の条文へリンク） / repealed: 採択後に廃止された条文
const THREAD_STATUSES = ['draft', 'review', 'passed', 'rejected', 'revised', 'amended', 'repealed'];

function badge_label(string $author_type): string
{
    return BADGES[$author_type] ?? $author_type;
}

function status_label(string $status): string
{
    return STATUS_LABELS[$status] ?? $status;
}

function stance_label(string $stance): string
{
    return STANCE_LABELS[$stance] ?? $stance;
}

/** ISO8601(UTC) を日本時間の表示用文字列にする */
function format_date(string $iso): string
{
    $ts = strtotime($iso);
    if ($ts === false) {
        return $iso;
    }
    return (new DateTimeImmutable('@' . $ts))
        ->setTimezone(new DateTimeZone('Asia/Tokyo'))
        ->format('Y-m-d H:i');
}

/** 公開URLのベース（config の site_url 優先、未設定ならリクエストから推測） */
function site_url(string $path = ''): string
{
    $base = rtrim((string) (agora_config()['site_url'] ?? ''), '/');
    if ($base === '') {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $base = ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }
    return $base . $path;
}

/** 外部エージェントがブラウザ等から読めるよう、公開APIにCORSヘッダーを付ける */
function allow_cors(): void
{
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

/**
 * 返信先(reply_to)の検証。同じスレッドの公開済み投稿のみ指定可能。
 * 戻り値: [parent_id or null, エラーメッセージ or null]
 */
function resolve_reply_to(PDO $pdo, int $thread_id, $reply_to): array
{
    if ($reply_to === null || $reply_to === '') {
        return [null, null];
    }
    if (!is_int($reply_to) && !ctype_digit((string) $reply_to)) {
        return [null, 'reply_to must be an integer post id'];
    }
    $stmt = $pdo->prepare("SELECT id FROM posts WHERE id = ? AND thread_id = ? AND status = 'published'");
    $stmt->execute([(int) $reply_to, $thread_id]);
    if (!$stmt->fetch()) {
        return [null, 'reply_to must reference a published post in the same thread'];
    }
    return [(int) $reply_to, null];
}

/** 公開済み投稿の件数を立場ごとに集計する */
function stance_counts(array $posts): array
{
    $counts = ['agree' => 0, 'disagree' => 0, 'neutral' => 0];
    foreach ($posts as $p) {
        if (isset($counts[$p['stance'] ?? ''])) {
            $counts[$p['stance']]++;
        }
    }
    return $counts;
}

const MAX_BODY_BYTES = 10240;   // 1リクエストのJSONは最大10KB

/**
 * JSONボディを読む。サイズ上限を超えるものはメモリに載せる前に 413 で拒否する。
 * 不正なJSON・不正なUTF-8は null。
 */
function json_body(int $max_bytes = MAX_BODY_BYTES): ?array
{
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > $max_bytes) {
        json_response(['detail' => "Request body too large (max {$max_bytes} bytes)"], 413);
    }
    $raw = stream_get_contents(fopen('php://input', 'rb'), $max_bytes + 1);
    if ($raw === false || $raw === '') {
        return null;
    }
    if (strlen($raw) > $max_bytes) {
        json_response(['detail' => "Request body too large (max {$max_bytes} bytes)"], 413);
    }
    if (!mb_check_encoding($raw, 'UTF-8')) {
        return null;
    }
    $data = json_decode($raw, true, 8);
    return is_array($data) ? $data : null;
}

function json_response($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    if ($status >= 400) {
        header('Cache-Control: no-store');
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ---------------------------------------------------------------------------
// エラー処理: 内部情報（パス・SQL等）を外部に漏らさない
// ---------------------------------------------------------------------------

ini_set('display_errors', '0');
set_exception_handler(function (Throwable $e): void {
    error_log('[agora] ' . $e);
    if (!headers_sent()) {
        header_remove('Cache-Control');
        $is_api = str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/');
        $busy = $e instanceof PDOException && str_contains($e->getMessage(), 'locked');
        if ($busy) {
            header('Retry-After: 5');
        }
        if ($is_api) {
            json_response(['detail' => $busy ? 'Server busy, retry later' : 'Internal server error'], $busy ? 503 : 500);
        }
        http_response_code($busy ? 503 : 500);
    }
    echo 'エラーが発生しました。時間をおいて再度お試しください。';
});

// ---------------------------------------------------------------------------
// 認証・レート制限（記録はすべて隔離DB側に書き、本番DBに負荷をかけない）
// ---------------------------------------------------------------------------

function get_authorization_header(): string
{
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        return $_SERVER['HTTP_AUTHORIZATION'];
    }
    if (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        return $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    }
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            if (strcasecmp($name, 'Authorization') === 0) {
                return $value;
            }
        }
    }
    return '';
}

function client_ip(): string
{
    // X-Forwarded-For は偽装できるので信用しない
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

/** IPアドレスは生のまま保存せず、秘密鍵つきハッシュにする */
function ip_hash(string $ip): string
{
    return substr(hash_hmac('sha256', $ip, (string) agora_config()['api_token']), 0, 32);
}

/** 指定バケットでの直近 $window 秒の記録件数 */
function hit_count(string $bucket, string $key, int $window): int
{
    $stmt = sandbox_db()->prepare('SELECT COUNT(*) FROM hits WHERE bucket = ? AND key = ? AND created_at >= ?');
    $stmt->execute([$bucket, $key, time() - $window]);
    return (int) $stmt->fetchColumn();
}

function record_hit(string $bucket, string $key): void
{
    $db = sandbox_db();
    $db->prepare('INSERT INTO hits (bucket, key, created_at) VALUES (?, ?, ?)')->execute([$bucket, $key, time()]);
    // 古い記録はときどき掃除（毎回DELETEしないことで書き込み負荷を下げる）
    if (random_int(1, 50) === 1) {
        $db->prepare('DELETE FROM hits WHERE created_at < ?')->execute([time() - 86400]);
    }
}

/**
 * レート制限。$rules は [[上限回数, 秒数], ...]。どれか1つでも超えたら 429。
 * 呼び出し自体も1回として数える（不正なリクエストの連打も制限される）。
 */
function check_rate_limit(string $bucket, array $rules): void
{
    $key = ip_hash(client_ip());
    foreach ($rules as [$limit, $window]) {
        if (hit_count($bucket, $key, $window) >= $limit) {
            header('Retry-After: ' . min($window, 3600));
            json_response(['detail' => "Rate limit exceeded: max {$limit} requests per {$window}s"], 429);
        }
    }
    record_hit($bucket, $key);
}

const AUTH_FAIL_LIMIT = 10;       // 15分間に10回トークンを間違えたIPは締め出す
const AUTH_FAIL_WINDOW = 900;

function require_owner_token(): void
{
    $key = ip_hash(client_ip());
    if (hit_count('auth_fail', $key, AUTH_FAIL_WINDOW) >= AUTH_FAIL_LIMIT) {
        header('Retry-After: ' . AUTH_FAIL_WINDOW);
        json_response(['detail' => 'Too many failed authentication attempts'], 429);
    }
    $auth = get_authorization_header();
    if (!str_starts_with($auth, 'Bearer ')) {
        json_response(['detail' => 'Bearer token required'], 401);
    }
    $token = substr($auth, 7);
    $expected = (string) agora_config()['api_token'];
    // 初期値のままのトークンでは書き込みを一切許可しない
    if ($expected === '' || str_starts_with($expected, 'CHANGE-ME') || !hash_equals($expected, $token)) {
        record_hit('auth_fail', $key);
        json_response(['detail' => 'Invalid token'], 403);
    }
}

// ---------------------------------------------------------------------------
// 出力キャッシュ: 公開ページ・公開GET APIを短時間ファイルにキャッシュし、
// クローラーやbotが殺到してもDBへの問い合わせを増やさない
// ---------------------------------------------------------------------------

// サーバー側キャッシュは書き込み時に必ず無効化されるので長めに保持できる（DBへの問い合わせを最小化）
const PAGE_CACHE_TTL = 3600;
// ブラウザ・中継キャッシュには短く持たせ、更新がすぐ見えるようにする
const BROWSER_CACHE_TTL = 60;

function cache_dir(): string
{
    $dir = agora_data_dir() . '/cache';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    return $dir;
}

/** 書き込みがあったら呼ぶ。以後、それ以前のキャッシュは使われない */
function invalidate_page_cache(): void
{
    file_put_contents(cache_dir() . '/.version', bin2hex(random_bytes(8)), LOCK_EX);
}

/**
 * キャッシュがあれば返して終了。なければ出力を記録して保存する。
 * $key_params: キャッシュキーに含めるGETパラメータ（それ以外は無視して、キーの無限増殖を防ぐ）
 */
function page_cache(array $key_params = [], int $ttl = PAGE_CACHE_TTL): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        return;
    }
    $dir = cache_dir();
    // キーは「呼び出し元のPHPファイル + 許可したパラメータ + データのバージョン」。
    // 書き込みでバージョンが変われば、古いキャッシュは二度と参照されない
    $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0]['file'] ?? '';
    // PHPファイルを更新（アップロード）したら古いキャッシュを使わないよう、読み込み済みファイルの更新時刻も鍵に含める
    $code_version = 0;
    foreach (array_merge(get_included_files(), [$caller]) as $f) {
        $code_version = max($code_version, (int) @filemtime($f));
    }
    $parts = [basename(dirname($caller)) . '/' . basename($caller), (string) @file_get_contents($dir . '/.version'), (string) $code_version];
    foreach ($key_params as $name => $value) {
        if (is_int($name)) {
            // 値を指定しない形式: GETパラメータを英数字だけに正規化して使う
            $v = $_GET[$value] ?? '';
            $parts[] = $value . '=' . (is_string($v) ? preg_replace('/[^a-z0-9_]/i', '', substr($v, 0, 20)) : '');
        } else {
            // 呼び出し側で検証・正規化済みの値（日本語名など）。長さを抑えてハッシュ化する
            $parts[] = $name . '=' . sha1(mb_substr((string) $value, 0, 200));
        }
    }
    $file = $dir . '/' . sha1(implode('&', $parts)) . '.cache';
    $mtime = @filemtime($file);

    header('Cache-Control: public, max-age=' . min($ttl, BROWSER_CACHE_TTL));

    if ($mtime !== false && $mtime > time() - $ttl) {
        $content = @file_get_contents($file);
        if ($content !== false && ($nl = strpos($content, "\n")) !== false) {
            $type = substr($content, 0, $nl);
            header('Content-Type: ' . $type);
            if (str_starts_with($type, 'text/html')) {
                send_security_headers();
            }
            header('X-Cache: HIT');
            echo substr($content, $nl + 1);
            exit;
        }
    }

    ob_start();
    register_shutdown_function(function () use ($file, $dir): void {
        $body = ob_get_contents();
        if (http_response_code() !== 200 || $body === false || $body === '') {
            return;
        }
        $type = 'text/html; charset=utf-8';
        foreach (headers_list() as $h) {
            if (stripos($h, 'Content-Type:') === 0) {
                $type = trim(substr($h, 13));
            }
        }
        $tmp = $file . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $type . "\n" . $body) !== false) {
            @rename($tmp, $file);
        }
        // 古いキャッシュファイルをときどき掃除
        if (random_int(1, 100) === 1) {
            foreach (glob($dir . '/*.cache') ?: [] as $f) {
                if (@filemtime($f) < time() - 3600) {
                    @unlink($f);
                }
            }
        }
    });
}

/** HTMLページ用のセキュリティヘッダー（CSPでインラインスクリプトを禁止） */
function send_security_headers(): void
{
    header(
        "Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; "
        . "img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'"
    );
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
}

function now_iso(): string
{
    return gmdate('Y-m-d\TH:i:s\Z');
}

function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
