<?php
/**
 * AI共生憲章（公開・認証不要）: 各条の現行条文、改正履歴、審議中の改正案・廃止案
 *   GET /api/v1/charter
 */
require_once __DIR__ . '/../analysis_core.php';

allow_cors();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['detail' => 'Method not allowed'], 405);
}

page_cache();
json_response(['articles' => charter_summary(agora_db())]);
