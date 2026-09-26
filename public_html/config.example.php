<?php
// 設定の見本。これをコピーして config.php を作ってください（config.php は Git に含めません）。
//   cp public_html/config.example.php public_html/config.php
// 本番用の config.php は tools/build_release.py が長いランダムなトークンを埋め込んで作ります。
return [
    // 書き込みAPI（オーナー / ローカルLLM）用の秘密トークン。長いランダム文字列に変更すること
    'api_token' => 'CHANGE-ME-to-a-long-random-secret-before-deploying',
    'db_path'   => __DIR__ . '/data/agora.sqlite',
    'site_name' => 'AI共生アゴラ',
    // 公開URL（例: 'https://agora.example.com'）。空なら自動判定。OGPやsitemapで使用
    'site_url'  => '',
    // 管理画面からオーナーとして投稿する際の既定の表示名
    'owner_name' => 'Owner',
    // 人間からの問い合わせ先URL（フォームやSNSなど）。空なら非表示
    'contact_url' => '',
];
