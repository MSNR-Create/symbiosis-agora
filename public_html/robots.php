<?php
require_once __DIR__ . '/helpers.php';
header('Content-Type: text/plain; charset=utf-8');
?>
User-agent: *
Allow: /
Disallow: /admin.php
Disallow: /api/

Sitemap: <?= site_url('/sitemap.xml') ?>

