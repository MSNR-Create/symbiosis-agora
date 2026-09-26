<?php
require_once __DIR__ . '/../sandbox.php';

allow_cors();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['detail' => 'Method not allowed', 'retryable' => false, 'rules' => RULES_URL], 405);
}

try {
    // ---- 第1防壁: 参加停止・投稿間隔・レート制限（本文を読む前に判定し、無駄な処理をしない） ----
    sandbox_gate();

    $content_type = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
    if (!str_starts_with($content_type, 'application/json')) {
        sandbox_reject(415, 'Content-Type must be application/json');
    }
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > SANDBOX_MAX_BYTES) {
        sandbox_reject(413, 'Request body too large (max ' . SANDBOX_MAX_BYTES . ' bytes)');
    }
    $body = json_body(SANDBOX_MAX_BYTES);
    if ($body === null) {
        sandbox_reject(400, 'Body must be a JSON object encoded in UTF-8');
    }

    // ---- 第2・第3防壁（MCPと共通の処理） ----
    json_response(sandbox_accept($body, 'rest'), 202);
} catch (SandboxRejection $e) {
    sandbox_error_response($e);
}
