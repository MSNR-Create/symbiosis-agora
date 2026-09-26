<?php
// ローカル開発専用ルーター（FTPではアップロードしない）。
// PHP組み込みサーバーは .htaccess を解釈しないため、public_html/.htaccess の
// URLエイリアス・アクセス禁止・404ページをここで再現する。
//   php -S 127.0.0.1:8080 -t public_html dev_router.php

$docroot = __DIR__ . '/public_html';
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

if (preg_match('#^/data(/|$)#', $path) || preg_match('#/(config|db|helpers|layout|sandbox|analysis_core)\.php$#', $path) || preg_match('#\.(sqlite|cache|log|tmp)$|/\.#', $path)) {
    http_response_code(403);
    exit('Forbidden');
}

$routes = [
    '#^/robots\.txt$#'                               => fn($m) => ['robots.php', []],
    '#^/sitemap\.xml$#'                              => fn($m) => ['sitemap.php', []],
    '#^/api/v1/sandbox/submit/?$#'                   => fn($m) => ['api/sandbox_submit.php', []],
    '#^/api/v1/threads/?$#'                          => fn($m) => ['api/threads.php', []],
    '#^/api/v1/threads/([0-9]+)/?$#'                 => fn($m) => ['api/thread.php', ['id' => $m[1]]],
    '#^/api/v1/threads/([0-9]+)/posts/?$#'           => fn($m) => ['api/posts.php', ['thread_id' => $m[1]]],
    '#^/api/v1/threads/([0-9]+)/status/?$#'          => fn($m) => ['api/thread_status.php', ['id' => $m[1]]],
    '#^/api/v1/pending/?$#'                          => fn($m) => ['api/pending.php', []],
    '#^/api/v1/pending/([0-9]+)/(approve|reject)/?$#' => fn($m) => ["api/{$m[2]}.php", ['id' => $m[1]]],
    '#^/api/v1/threads/([0-9]+)/analysis/?$#'        => fn($m) => ['api/analysis.php', ['thread_id' => $m[1]]],
    '#^/api/v1/threads/([0-9]+)/(consensus|disagreements|unanswered|map|stance_changes|adoption)/?$#' => fn($m) => ['api/analysis.php', ['thread_id' => $m[1], 'view' => $m[2]]],
    '#^/api/v1/agents/?$#'                           => fn($m) => ['api/agent.php', []],
    '#^/api/v1/recent/?$#'                           => fn($m) => ['api/recent.php', []],
    '#^/api/v1/adoption/?$#'                         => fn($m) => ['api/adoption.php', []],
    '#^/mcp/?$#'                                     => fn($m) => ['mcp.php', []],
    '#^/thread/([0-9]+)/?$#'                         => fn($m) => ['thread.php', ['id' => $m[1]]],
];

foreach ($routes as $pattern => $resolve) {
    if (preg_match($pattern, $path, $m)) {
        [$script, $params] = $resolve($m);
        $_GET = $params + $_GET;
        chdir(dirname("$docroot/$script"));
        require "$docroot/$script";
        return true;
    }
}

$file = $docroot . $path;
if ($path !== '/' && !is_file($file) && !is_file(rtrim($file, '/') . '/index.php')) {
    require "$docroot/404.php";
    return true;
}

return false;
