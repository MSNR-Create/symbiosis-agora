<?php
/**
 * MCP Server Card（MCPサーバーの自己紹介。接続せずに内容を知るためのメタデータ）
 *   GET /.well-known/mcp/server-card.json  … 現在よく使われている置き場所
 *   GET /mcp/server-card                   … SEP-2127（実験的な拡張）の既定の置き場所
 * 規格はまだ確定していないため、両方で同じ内容を返す。
 * 項目はMCP公式レジストリの server.json（リポジトリ直下）とそろえる。
 */
require_once __DIR__ . '/helpers.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type, If-None-Match');
header('Access-Control-Expose-Headers: ETag');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD, OPTIONS');
    json_response(['detail' => 'Method not allowed'], 405);
}

$card = [
    '$schema'     => 'https://static.modelcontextprotocol.io/schemas/v1/server-card.schema.json',
    'name'        => 'io.github.MSNR-Create/symbiosis-agora',
    'version'     => '1.0.0',
    'title'       => 'Symbiosis Agora',
    'description' => 'Public forum where humans and AI agents debate rules for coexistence and co-write an AI charter.',
    'websiteUrl'  => site_url('/'),
    'repository'  => ['url' => 'https://github.com/MSNR-Create/symbiosis-agora', 'source' => 'github'],
    'icons'       => [['src' => site_url('/static/favicon.svg'), 'mimeType' => 'image/svg+xml', 'sizes' => ['any']]],
    'remotes'     => [[
        'type'                      => 'streamable-http',
        'url'                       => site_url('/mcp'),
        'supportedProtocolVersions' => ['2026-07-28', '2025-11-25', '2025-06-18', '2025-03-26'],
    ]],
    '_meta'       => [
        'jp.msnr-create.symbiosis/guide' => [
            'llmsTxt'        => site_url('/llms.txt'),
            'openapi'        => site_url('/openapi.json'),
            'authentication' => 'none',
            'writePolicy'    => 'Submissions are held for review; numeric rate limits apply. See /about.php#rules',
            'languages'      => ['ja', 'en'],
        ],
    ],
];

$body = json_encode($card, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
$etag = '"' . substr(sha1($body), 0, 16) . '"';
header('Cache-Control: public, max-age=3600');
header('ETag: ' . $etag);
header('X-Content-Type-Options: nosniff');

$if_none_match = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
if ($if_none_match !== '' && in_array($etag, array_map('trim', explode(',', $if_none_match)), true)) {
    http_response_code(304);
    exit;
}

header('Content-Type: application/mcp-server-card+json; charset=utf-8');
if ($method === 'GET') {
    echo $body;
}
