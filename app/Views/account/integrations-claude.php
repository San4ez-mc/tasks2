<?php
$extra_head = '<link rel="stylesheet" href="/public/css/integrations.css">';
$extra_scripts = '<script src="/public/js/integrations.js"></script>';
ob_start();
$api_tokens = $api_tokens ?? [];
$new_api_token = $new_api_token ?? null;
$mcp_base_url = 'https://tasks.fineko.space/mcp?token=';
$mcp_token = (string) ($new_api_token['token'] ?? 'YOUR_API_TOKEN');
$mcp_full_url = $mcp_base_url . $mcp_token;
?>

<div class="integration-page">
  <section class="integration-card">
    <h2 class="integration-title">Інтеграція Claude</h2>
    <p class="integration-subtitle">Підключіть Task Tracker як MCP connector у Claude через Bearer token.</p>

    <ol class="steps">
      <li>Згенеруйте API token на цій сторінці.</li>
      <li>У Claude додайте custom MCP connector з URL <strong><?php echo e($mcp_full_url); ?></strong>.</li>
      <li>Advanced settings (OAuth) залиште порожніми.</li>
      <li>Перевірка доступності сервера: <strong>https://tasks.fineko.space/health</strong>.</li>
    </ol>

    <?php if (!empty($new_api_token['token'])): ?>
      <div class="code-box" style="margin-top:12px; border:1px dashed #86efac; background:#062c1a;">
        Новий API token: <?php echo e($new_api_token['token']); ?>
        (діє до <?php echo e($new_api_token['expires_at'] ?? ''); ?>)
      </div>
    <?php endif; ?>

    <div class="actions-row">
      <form method="post" action="/account/api-token">
        <input type="hidden" name="return_to" value="/account/integrations/claude">
        <button type="submit" class="btn">Згенерувати API токен</button>
      </form>

      <form method="post" action="/account/api-token-revoke" onsubmit="return confirm('Відкликати всі API токени?');">
        <input type="hidden" name="return_to" value="/account/integrations/claude">
        <button type="submit" class="btn btn-danger">Відкликати всі токени</button>
      </form>
    </div>

    <div class="api-token-list">
      <?php foreach ($api_tokens as $token_row): ?>
        <div class="api-token-item">
          <div class="api-token-row">
            <strong><?php echo e($token_row['token_masked'] ?? ''); ?></strong>
            <form method="post" action="/account/api-token-revoke"
              onsubmit="return confirm('Відкликати цей API токен?');">
              <input type="hidden" name="return_to" value="/account/integrations/claude">
              <input type="hidden" name="token_id" value="<?php echo (int) ($token_row['id'] ?? 0); ?>">
              <button type="submit" class="btn btn-outline">Відкликати</button>
            </form>
          </div>
          <div class="api-token-meta">
            <span>Компанія:
              <?php echo !empty($token_row['company_id']) ? (int) $token_row['company_id'] : 'не зафіксована'; ?></span>
            <span>Створено: <?php echo e($token_row['created_at'] ?? ''); ?></span>
            <span>Діє до: <?php echo e($token_row['expires_at'] ?? ''); ?></span>
          </div>
        </div>
      <?php endforeach; ?>
      <?php if (empty($api_tokens)): ?>
        <div class="api-token-item">Активних API токенів немає.</div>
      <?php endif; ?>
    </div>

    <div class="copy-wrap">
      <div id="mcp-link-value" class="code-box"><?php echo e($mcp_full_url); ?></div>
      <button id="copy-mcp-link" type="button" class="copy-btn" title="Скопіювати посилання"
        aria-label="Скопіювати посилання">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"
          aria-hidden="true">
          <rect x="9" y="9" width="11" height="11" rx="2" stroke="currentColor" stroke-width="2" />
          <path d="M6 15H5C3.89543 15 3 14.1046 3 13V5C3 3.89543 3.89543 3 5 3H13C14.1046 3 15 3.89543 15 5V6"
            stroke="currentColor" stroke-width="2" />
        </svg>
      </button>
    </div>

    <a class="btn" href="/account/settings">Назад до налаштувань</a>
  </section>
</div>

<?php
$title = 'Інтеграція Claude';
$content = ob_get_clean();
include APP_PATH . '/Views/layouts/main.php';
