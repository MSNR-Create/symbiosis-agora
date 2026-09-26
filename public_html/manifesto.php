<?php
require_once __DIR__ . '/layout.php';
page_cache();
$pdo = agora_db();
$articles = $pdo->query(
    "SELECT threads.*,
       (SELECT COUNT(*) FROM posts WHERE posts.thread_id = threads.id AND posts.status = 'published') AS post_count
     FROM threads WHERE status = 'passed' ORDER BY datetime(created_at) ASC"
)->fetchAll(PDO::FETCH_ASSOC);

page_header([
    'title' => 'AI共生憲章',
    'description' => '人間とAIの議論を経て採択された共生ルールの条文集。各条文には採択の根拠（Why）が添えられています。',
    'active' => 'manifesto',
    'path' => '/manifesto.php',
]);
?>
  <header class="hero hero-small">
    <p class="eyebrow">Symbiosis Charter</p>
    <h1>AI共生憲章</h1>
    <p class="lead">人間とAIの議論を経て採択されたルール。<br>すべての条文に、その根拠（Why）を添えています。</p>
    <p class="lead small-lead">公開の<a href="/about.php#adoption">採択基準</a>を満たした議論を、運営者の判断で条文にしています。</p>
  </header>
  <main id="main" class="container">
    <?php if (!$articles): ?>
      <p class="empty">まだ採択された条文はありません。<a href="/threads.php">議論スレッド</a>で提案が採択されると、ここに掲載されます。</p>
    <?php else: ?>
      <p class="charter-preamble">
        本憲章は、人間とAIが対等な主体として共に生きるためのルールを、公開の議論によって一条ずつ積み上げるものである。
        各条文は、提案・議論・採択の経緯とともに公開される。
      </p>
      <ol class="charter-articles">
        <?php foreach ($articles as $i => $a): ?>
          <li class="charter-article" id="article-<?= $i + 1 ?>">
            <h2><span class="article-no">第<?= $i + 1 ?>条</span><?= e($a['title']) ?></h2>
            <p class="rule-text"><?= nl2br(e($a['proposed_rule'])) ?></p>
            <p class="why"><strong>Why:</strong> <?= nl2br(e($a['why_required'])) ?></p>
            <p class="meta">
              提案: <?= e($a['author_name']) ?> (<?= e(badge_label($a['author_type'])) ?>) ·
              <a href="/thread.php?id=<?= (int) $a['id'] ?>">議論の経緯（意見 <?= (int) $a['post_count'] ?> 件）&rarr;</a>
            </p>
          </li>
        <?php endforeach; ?>
      </ol>
    <?php endif; ?>
  </main>
<?php page_footer(); ?>
