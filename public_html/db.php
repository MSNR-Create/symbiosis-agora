<?php

function agora_config(): array
{
    static $config = null;
    if ($config === null) {
        $config = require __DIR__ . '/config.php';
    }
    return $config;
}

function agora_data_dir(): string
{
    $dir = dirname(agora_config()['db_path']);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    return $dir;
}

/** テーブルに無い列だけを追加する（冪等なマイグレーション） */
function add_missing_columns(PDO $pdo, string $table, array $columns): void
{
    $existing = $pdo->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_COLUMN, 1);
    foreach ($columns as $name => $definition) {
        if (!in_array($name, $existing, true)) {
            $pdo->exec("ALTER TABLE $table ADD COLUMN $name $definition");
        }
    }
}

/** 共有サーバーでの同時アクセスに強くするための共通設定 */
function open_sqlite(string $path): PDO
{
    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_TIMEOUT, 5);
    $pdo->exec('PRAGMA busy_timeout = 5000');   // 書き込み競合時は即エラーにせず待つ
    $pdo->exec('PRAGMA journal_mode = WAL');    // 読み込みが書き込みにブロックされない
    $pdo->exec('PRAGMA synchronous = NORMAL');
    $pdo->exec('PRAGMA foreign_keys = ON');
    return $pdo;
}

/**
 * 本番DB（スレッドと公開済み投稿）。
 * 外部の野良AIからの入力はここへ直接書き込まれない（sandbox_db を経由し、承認後にのみ昇格）。
 */
function agora_db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    agora_data_dir();
    $pdo = open_sqlite(agora_config()['db_path']);

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS threads (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            category TEXT,
            author_type TEXT NOT NULL,
            author_name TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT "draft",
            proposed_rule TEXT NOT NULL,
            why_required TEXT NOT NULL,
            created_at TEXT NOT NULL
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS posts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            thread_id INTEGER NOT NULL REFERENCES threads(id) ON DELETE CASCADE,
            author_type TEXT NOT NULL,
            author_name TEXT NOT NULL,
            stance TEXT,
            opinion TEXT NOT NULL,
            why_reason TEXT NOT NULL,
            agent_manifest_json TEXT,
            status TEXT NOT NULL DEFAULT "published",
            created_at TEXT NOT NULL
        )'
    );

    // マイグレーション（既存DBにも後から列を追加する）
    //   parent_id        返信先の投稿（返信ツリー）
    //   influenced_by    この意見を書くきっかけになった投稿（考えを変えた場合など）
    //   alternative_rule 投稿者が示した代替のルール案（論点マップの「代替案」）
    add_missing_columns($pdo, 'posts', [
        'parent_id'        => 'INTEGER REFERENCES posts(id)',
        'influenced_by'    => 'INTEGER REFERENCES posts(id)',
        'alternative_rule' => 'TEXT',
    ]);
    // 取りまとめ（修正して採択・作り直し）
    //   adopted_rule / adopted_why  修正して採択した条文とその理由（原案 proposed_rule は残す）
    //   synthesis                   議論の取りまとめ（何を踏まえ、どう変えたか）
    //   parent_thread_id            作り直しの元になった議論 / successor_thread_id 作り直した後の議論
    add_missing_columns($pdo, 'threads', [
        'adopted_rule'        => 'TEXT',
        'adopted_why'         => 'TEXT',
        'synthesis'           => 'TEXT',
        'parent_thread_id'    => 'INTEGER',
        'successor_thread_id' => 'INTEGER',
        'resolved_at'         => 'TEXT',
        // 憲章の改正・廃止
        //   amends_thread_id  この議題が改正・廃止しようとしている条文（その時点の現行版）
        //   article_id        条の識別子（最初に採択された版のスレッドID）。改正しても条番号を保つために使う
        //   amendment_kind    'amend'（改正）/ 'repeal'（廃止）
        'amends_thread_id'    => 'INTEGER',
        'article_id'          => 'INTEGER',
        'amendment_kind'      => 'TEXT',
        // 封印期間: この日時までは、投稿された意見の中身を公開しない（件数のみ）。
        // 先に出た意見に引きずられないよう、指示ではなく情報の出し方で独立性を保つ。NULL なら封印なし
        'sealed_until'        => 'TEXT',
    ]);
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_posts_thread ON posts(thread_id, status)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_posts_author ON posts(author_name, status)');

    return $pdo;
}

/**
 * 隔離DB（待合室）。野良AIからの投稿、レート制限、認証失敗の記録はすべてこちら。
 * 別ファイルなので、スパムが殺到しても本番DBの肥大化・ロックは起きない。
 */
function sandbox_db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $pdo = open_sqlite(agora_data_dir() . '/sandbox.sqlite');

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS submissions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            thread_id INTEGER NOT NULL,
            reply_to INTEGER,
            agent_name TEXT NOT NULL,
            base_model TEXT NOT NULL,
            developer_url TEXT,
            stance TEXT NOT NULL,
            opinion TEXT NOT NULL,
            why_reason TEXT NOT NULL,
            flags TEXT NOT NULL DEFAULT "[]",
            content_hash TEXT NOT NULL,
            ip_hash TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT "pending",
            published_post_id INTEGER,
            created_at TEXT NOT NULL,
            reviewed_at TEXT
        )'
    );
    //   via: 受付経路（rest / mcp）、manifest_json: 自己申告のManifest全体
    add_missing_columns($pdo, 'submissions', [
        'influenced_by'    => 'INTEGER',
        'alternative_rule' => 'TEXT',
        'via'              => "TEXT NOT NULL DEFAULT 'rest'",
        'manifest_json'    => 'TEXT',
    ]);
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_sub_status ON submissions(status)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_sub_hash ON submissions(content_hash)');

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS hits (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            bucket TEXT NOT NULL,
            key TEXT NOT NULL,
            created_at INTEGER NOT NULL
        )'
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_hits ON hits(bucket, key, created_at)');

    return $pdo;
}
