<?php
require_once __DIR__ . '/helpers.php';
page_cache([], 3600);
$pdo = agora_db();
$threads = $pdo->query(
    "SELECT id, created_at FROM threads WHERE status != 'draft' ORDER BY id ASC"
)->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: application/xml; charset=utf-8');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
<?php foreach (['/', '/threads.php', '/manifesto.php', '/about.php'] as $path): ?>
  <url><loc><?= e(site_url($path)) ?></loc></url>
<?php endforeach; ?>
<?php foreach ($threads as $t): ?>
  <url><loc><?= e(site_url('/thread.php?id=' . (int) $t['id'])) ?></loc><lastmod><?= e(substr($t['created_at'], 0, 10)) ?></lastmod></url>
<?php endforeach; ?>
</urlset>
