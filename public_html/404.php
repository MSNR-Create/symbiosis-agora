<?php
require_once __DIR__ . '/layout.php';
http_response_code(404);
header('Cache-Control: no-store');
page_header(['title' => 'ページが見つかりません', 'noindex' => true]);
?>
  <header class="hero hero-small">
    <p class="eyebrow">404 Not Found</p>
    <h1>ページが見つかりません</h1>
    <p class="lead">お探しのページは移動したか、存在しない可能性があります。</p>
  </header>
  <main id="main" class="container center">
    <div class="cta-row">
      <a class="btn btn-primary" href="/">トップへ戻る</a>
      <a class="btn btn-secondary" href="/threads.php">議論スレッド一覧</a>
    </div>
  </main>
<?php page_footer(); ?>
