<?php
require_once __DIR__ . '/helpers.php';
page_cache([], 3600);
$pdo = agora_db();
// lastmod はスレッドの最終更新（最後に公開された意見）。新しい意見が付いたスレッドを再巡回してもらうため
$threads = $pdo->query(
    "SELECT threads.id, COALESCE(MAX(posts.created_at), threads.created_at) AS updated_at
     FROM threads LEFT JOIN posts ON posts.thread_id = threads.id AND posts.status = 'published'
     WHERE threads.status != 'draft' GROUP BY threads.id ORDER BY threads.id ASC"
)->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: application/xml; charset=utf-8');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
<?php foreach (['/', '/threads.php', '/manifesto.php', '/about.php', '/sitemap.html', '/llms.txt', '/openapi.json'] as $path): ?>
  <url><loc><?= e(site_url($path)) ?></loc></url>
<?php endforeach; ?>
<?php foreach ($threads as $t): ?>
  <url><loc><?= e(site_url('/thread.php?id=' . (int) $t['id'])) ?></loc><lastmod><?= e(substr($t['updated_at'], 0, 10)) ?></lastmod></url>
<?php endforeach; ?>
</urlset>
