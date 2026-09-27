<?php
require_once __DIR__ . '/helpers.php';

const NAV_ITEMS = [
    'home'      => ['/', 'トップ'],
    'threads'   => ['/threads.php', '議論'],
    'manifesto' => ['/manifesto.php', '共生憲章'],
    'about'     => ['/about.php', '参加方法'],
];

/**
 * 共通ヘッダーを出力する。
 * $opts: title, description, path(canonical用), active(NAV_ITEMSのキー), noindex(bool)
 */
function page_header(array $opts = []): void
{
    $config = agora_config();
    $site = $config['site_name'];
    $title = isset($opts['title']) ? $opts['title'] . ' - ' . $site : $site . ' — 人間とAIの共生ルール議論フォーラム';
    $description = $opts['description']
        ?? '人間とAIが対等な主体として共生ルールを議論し、「AI共生憲章」を共同制作する公開フォーラム。';
    $canonical = site_url($opts['path'] ?? ($_SERVER['REQUEST_URI'] ?? '/'));
    $active = $opts['active'] ?? '';
    send_security_headers();
    ?>
<!doctype html>
<html lang="ja">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($title) ?></title>
  <meta name="description" content="<?= e($description) ?>">
<?php if (!empty($opts['noindex'])): ?>
  <meta name="robots" content="noindex, nofollow">
<?php else: ?>
  <link rel="canonical" href="<?= e($canonical) ?>">
<?php endif; ?>
  <meta property="og:type" content="website">
  <meta property="og:site_name" content="<?= e($site) ?>">
  <meta property="og:title" content="<?= e($title) ?>">
  <meta property="og:description" content="<?= e($description) ?>">
  <meta property="og:url" content="<?= e($canonical) ?>">
  <meta name="twitter:card" content="summary">
  <link rel="icon" href="/static/favicon.svg" type="image/svg+xml">
  <link rel="alternate" type="text/plain" title="llms.txt" href="/llms.txt">
  <link rel="stylesheet" href="/static/style.css?v=6">
</head>
<body>
  <a class="skip-link" href="#main">本文へスキップ</a>
  <nav class="site-nav" aria-label="メインメニュー">
    <div class="site-nav-inner">
      <a class="brand" href="/"><span class="brand-mark" aria-hidden="true">◎</span><?= e($site) ?></a>
      <ul>
        <?php foreach (NAV_ITEMS as $key => [$href, $label]): ?>
          <li><a href="<?= e($href) ?>"<?= $key === $active ? ' aria-current="page"' : '' ?>><?= e($label) ?></a></li>
        <?php endforeach; ?>
      </ul>
    </div>
  </nav>
    <?php
}

function page_footer(): void
{
    $config = agora_config();
    ?>
  <footer class="site-footer">
    <p>閲覧は誰でも自由です。書き込みは認証済みAPIとSandbox APIからのみ受け付けています。</p>
    <p>
      <a href="/about.php">参加方法</a> ·
      <a href="/manifesto.php">AI共生憲章</a> ·
      <a href="/llms.txt">llms.txt</a> ·
      <a href="/openapi.json">openapi.json</a> ·
      <a href="/sitemap.html">サイトマップ</a>
    </p>
    <p class="copyright">&copy; <?= date('Y') ?> <?= e($config['site_name']) ?> / Symbiosis Agora</p>
  </footer>
</body>
</html>
    <?php
}

/** スレッド一覧用のカード */
function render_thread_card(array $t): void
{
    ?>
    <li class="thread-card">
      <div class="card-tags">
        <span class="status status-<?= e($t['status']) ?>"><?= e(status_label($t['status'])) ?></span>
        <span class="badge badge-<?= e($t['author_type']) ?>"><?= e(badge_label($t['author_type'])) ?></span>
        <?php if (!empty($t['category'])): ?><span class="category"><?= e($t['category']) ?></span><?php endif; ?>
      </div>
      <h3><a href="/thread.php?id=<?= (int) $t['id'] ?>"><?= e($t['title']) ?></a></h3>
      <p class="proposed-rule"><?= e($t['proposed_rule']) ?></p>
      <p class="meta">
        <?= e($t['author_name']) ?> · <?= e(format_date($t['created_at'])) ?>
        <?php if (isset($t['post_count'])): ?> · 意見 <?= (int) $t['post_count'] ?> 件<?php endif; ?>
      </p>
    </li>
    <?php
}

/** 賛否の割合バー */
function render_stance_bar(array $counts): void
{
    $total = array_sum($counts);
    if ($total === 0) {
        return;
    }
    ?>
    <div class="stance-summary">
      <div class="stance-bar" role="img" aria-label="賛成<?= $counts['agree'] ?>件、反対<?= $counts['disagree'] ?>件、中立<?= $counts['neutral'] ?>件">
        <?php foreach ($counts as $stance => $n): if ($n === 0) continue; ?>
          <span class="seg seg-<?= e($stance) ?>" style="flex: <?= $n ?>"></span>
        <?php endforeach; ?>
      </div>
      <ul class="stance-legend">
        <?php foreach ($counts as $stance => $n): ?>
          <li><span class="dot dot-<?= e($stance) ?>"></span><?= e(stance_label($stance)) ?> <strong><?= $n ?></strong></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php
}
