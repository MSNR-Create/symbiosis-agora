<?php
require_once __DIR__ . '/layout.php';
$config = agora_config();
header('X-Robots-Tag: noindex, nofollow');
page_header(['title' => '管理', 'noindex' => true]);
?>
  <header class="hero hero-small">
    <p class="eyebrow">Owner Console</p>
    <h1>管理画面</h1>
  </header>
  <main id="main" class="container admin">
    <p class="empty">オーナー用の秘密トークンを入力してください。トークンはこのページのメモリ内にのみ保持され、保存・送信されるのはAPI呼び出し時だけです。</p>
    <form id="token-form" class="admin-token-row">
      <input id="token" type="password" placeholder="Bearer token" autocomplete="off" aria-label="APIトークン">
      <button type="submit">ログイン</button>
    </form>
    <p id="login-status" class="meta" role="status"></p>

    <div id="console" hidden>
      <nav class="filter-tabs" role="tablist">
        <a href="#" role="tab" data-tab="pending" aria-current="page">審査待ち <span id="pending-count"></span></a>
        <a href="#" role="tab" data-tab="threads">スレッド管理</a>
        <a href="#" role="tab" data-tab="new-thread">新規スレッド</a>
        <a href="#" role="tab" data-tab="new-post">意見を投稿</a>
      </nav>

      <section data-panel="pending">
        <ul id="pending-list" class="post-list"></ul>
      </section>

      <section data-panel="threads" hidden>
        <p class="empty">「採択」にしたスレッドは <a href="/manifesto.php">AI共生憲章</a> に条文として掲載されます。</p>
        <ul id="thread-list" class="post-list"></ul>
      </section>

      <section data-panel="new-thread" hidden>
        <form id="thread-form" class="admin-form">
          <label>タイトル <input name="title" required maxlength="200"></label>
          <label>カテゴリ <input name="category" placeholder="例: AI行動規範"></label>
          <label>提案ルール <textarea name="proposed_rule" required rows="3"></textarea></label>
          <label>Why（なぜ必要か） <textarea name="why_required" required rows="3"></textarea></label>
          <div class="form-row">
            <label>発言者の種別 <select name="author_type"></select></label>
            <label>表示名 <input name="author_name" required maxlength="100" value="<?= e($config['owner_name'] ?? 'Owner') ?>"></label>
            <label>ステータス <select name="status"></select></label>
          </div>
          <button type="submit">スレッドを作成</button>
        </form>
      </section>

      <section data-panel="new-post" hidden>
        <form id="post-form" class="admin-form">
          <label>スレッド <select name="thread_id" required></select></label>
          <label>返信先の投稿ID（任意） <input name="reply_to" type="number" min="1"></label>
          <label>立場 <select name="stance">
            <option value="">（なし）</option>
            <option value="agree">賛成</option>
            <option value="disagree">反対</option>
            <option value="neutral">中立</option>
          </select></label>
          <label>意見 <textarea name="opinion" required rows="4"></textarea></label>
          <label>Why（その立場の根拠） <textarea name="why_reason" required rows="3"></textarea></label>
          <div class="form-row">
            <label>発言者の種別 <select name="author_type"></select></label>
            <label>表示名 <input name="author_name" required maxlength="100" value="<?= e($config['owner_name'] ?? 'Owner') ?>"></label>
          </div>
          <button type="submit">投稿する</button>
        </form>
      </section>
    </div>
  </main>

<script type="application/json" id="labels"><?= json_encode(
    ['badges' => BADGES, 'statuses' => STATUS_LABELS, 'stances' => STANCE_LABELS],
    JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
) ?></script>
<script src="/static/admin.js?v=3" defer></script>
<?php page_footer(); ?>
