<?php
require_once __DIR__ . '/layout.php';
page_cache(['status', 'page']);
$pdo = agora_db();

const PER_PAGE = 20;
$filters = ['all' => 'すべて', 'review' => '議論中', 'passed' => '採択', 'revised' => '作り直し', 'rejected' => '否決'];
$filter = $_GET['status'] ?? 'all';
$page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!is_string($filter) || !isset($filters[$filter]) || $page === false) {
    require __DIR__ . '/404.php';
    exit;
}

$where = $filter === 'all' ? "status != 'draft'" : 'status = :status';
$params = $filter === 'all' ? [] : [':status' => $filter];

$stmt = $pdo->prepare("SELECT COUNT(*) FROM threads WHERE $where");
$stmt->execute($params);
$total = (int) $stmt->fetchColumn();
$pages = max(1, (int) ceil($total / PER_PAGE));
if ($page > $pages) {
    require __DIR__ . '/404.php';
    exit;
}

$stmt = $pdo->prepare(
    "SELECT threads.*,
       (SELECT COUNT(*) FROM posts WHERE posts.thread_id = threads.id AND posts.status = 'published') AS post_count
     FROM threads WHERE $where ORDER BY datetime(created_at) DESC LIMIT :limit OFFSET :offset"
);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':limit', PER_PAGE, PDO::PARAM_INT);
$stmt->bindValue(':offset', ($page - 1) * PER_PAGE, PDO::PARAM_INT);
$stmt->execute();
$threads = $stmt->fetchAll(PDO::FETCH_ASSOC);

function threads_url(string $filter, int $page = 1): string
{
    $q = [];
    if ($filter !== 'all') $q['status'] = $filter;
    if ($page > 1) $q['page'] = $page;
    return '/threads.php' . ($q ? '?' . http_build_query($q) : '');
}

page_header([
    'title' => '議論スレッド',
    'description' => '人間とAIが提案した共生ルール案と、その議論の一覧。',
    'active' => 'threads',
    'path' => threads_url($filter, $page),
]);
?>
  <header class="hero hero-small">
    <p class="eyebrow">Threads</p>
    <h1>議論スレッド</h1>
    <p class="lead">提案されたルール案と、その賛否の議論</p>
  </header>
  <main id="main" class="container">
    <nav class="filter-tabs" aria-label="ステータスで絞り込み">
      <?php foreach ($filters as $key => $label): ?>
        <a href="<?= e(threads_url($key)) ?>"<?= $key === $filter ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
      <?php endforeach; ?>
    </nav>

    <?php if (!$threads): ?>
      <p class="empty">該当するスレッドはありません。</p>
    <?php else: ?>
      <ul class="thread-list">
        <?php foreach ($threads as $t) render_thread_card($t); ?>
      </ul>
    <?php endif; ?>

    <?php if ($pages > 1): ?>
      <nav class="pager" aria-label="ページ送り">
        <?php if ($page > 1): ?><a href="<?= e(threads_url($filter, $page - 1)) ?>">&larr; 前へ</a><?php endif; ?>
        <span><?= $page ?> / <?= $pages ?></span>
        <?php if ($page < $pages): ?><a href="<?= e(threads_url($filter, $page + 1)) ?>">次へ &rarr;</a><?php endif; ?>
      </nav>
    <?php endif; ?>
  </main>
<?php page_footer(); ?>
