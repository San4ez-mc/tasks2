<?php
$extra_head = '<link rel="stylesheet" href="/public/css/account.css?v=5">';
ob_start();
$user = $user ?? get_user();
$active_code = $active_code ?? null;
$api_tokens = $api_tokens ?? [];
$new_api_token = $new_api_token ?? null;
$digest_settings = $digest_settings ?? [
  'morning_enabled' => 1,
  'morning_hour' => 9,
  'evening_enabled' => 1,
  'evening_hour' => 18,
];
$companies = $companies ?? [];
$subscription = $subscription ?? _sub_free_defaults();
$is_company_owner = $is_company_owner ?? false;
$company_employees = $company_employees ?? [];
$company_id = (int) ($_SESSION['company_id'] ?? 0);
$plans = SUBSCRIPTION_PLANS;
$free_limit = SUBSCRIPTION_FREE_MEMBER_LIMIT;
$member_count = count($company_employees);
// Flash from WayForPay redirect
if (isset($_GET['sub_paid']) && $_GET['sub_paid'] === '1') {
  echo '<div style="margin-bottom:10px;" class="account-flash-ok">✅ Оплату отримано! Підписку активовано.</div>';
}
?>


<?php if (!empty($new_api_token['token'])): ?>
<div class="new-token-banner" id="new-token-banner">
  <div class="new-token-banner-title">🔑 Ваш новий API токен — збережіть зараз, повторно не покажемо</div>
  <div class="new-token-banner-row">
    <code class="new-token-value" id="new-token-value-top"><?php echo e($new_api_token['token']); ?></code>
    <button type="button" class="btn-copy-token" onclick="copyApiToken('new-token-value-top', this)">
      <svg width="14" height="14" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="5" y="5" width="9" height="9" rx="1.5" stroke="currentColor" stroke-width="1.5"/><path d="M11 5V3.5A1.5 1.5 0 0 0 9.5 2h-7A1.5 1.5 0 0 0 1 3.5v7A1.5 1.5 0 0 0 2.5 12H4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
      Копіювати
    </button>
  </div>
  <div class="new-token-banner-expires">Діє до: <?php echo e($new_api_token['expires_at'] ?? ''); ?></div>
</div>
<?php endif; ?>

<div class="account-page">
  <section class="account-card">
    <h2 class="account-title">Налаштування акаунта</h2>
    <p class="account-subtitle">Керуйте прив'язкою Telegram та перегляньте компанії, до яких маєте доступ.</p>

    <div class="account-grid">
      <div class="account-item">
        <div class="account-item-label">Ім'я</div>
        <div class="account-item-value">
          <?php echo e(trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?: 'Не вказано'); ?>
        </div>
      </div>
      <div class="account-item">
        <div class="account-item-label">Email</div>
        <div class="account-item-value"><?php echo e($user['email'] ?? 'Не вказано'); ?></div>
      </div>
      <div class="account-item">
        <div class="account-item-label">Username</div>
        <div class="account-item-value">
          <?php echo e(($user['username'] ?? '') !== '' ? ('@' . $user['username']) : 'Не вказано'); ?>
        </div>
      </div>
      <div class="account-item">
        <div class="account-item-label">Telegram ID</div>
        <div class="account-item-value">
          <?php echo !empty($user['telegram_id']) ? (int) $user['telegram_id'] : 'Не прив\'язано'; ?>
        </div>
      </div>
    </div>
  </section>

  <section class="account-card">
    <div class="settings-promo-banner">
      <div class="settings-promo-icon">💬</div>
      <div class="settings-promo-body">
        <div class="settings-promo-title">Telegram-бот — ваш особистий асистент</div>
        <div class="settings-promo-text">Після прив'язки ви отримуєте ранкові плани та вечірні звіти прямо у Telegram.
          Надсилайте голосові повідомлення або текст — бот зрозуміє, розподілить задачі по проектах і зафіксує
          результати. Більше не треба відкривати браузер, щоб поставити задачу або відзвітувати.</div>
      </div>
    </div>
    <?php $telegram_linked = !empty($user['telegram_id']); ?>
    <div class="tg-status <?php echo $telegram_linked ? '' : 'off'; ?>">
      <h3 class="tg-status-title"><?php echo $telegram_linked ? 'Telegram підключено' : 'Telegram не підключено'; ?>
      </h3>
      <?php if ($telegram_linked): ?>
        <p class="tg-help">Акаунт прив'язаний. Бот зможе ідентифікувати вас у приватному чаті.</p>
      <?php else: ?>
        <p class="tg-help">Щоб підключити Telegram, згенеруйте код і надішліть у бот команду <strong>/link КОД</strong>.
        </p>
      <?php endif; ?>

      <?php if (!empty($active_code['code'])): ?>
        <div class="code-box">
          Код:
          <span class="code-token"><?php echo e($active_code['code']); ?></span>
          <span>до <?php echo e($active_code['expires_at']); ?></span>
        </div>
      <?php endif; ?>

      <p class="tg-help" style="margin-top:10px;">Якщо у вас кілька компаній, у боті можна подивитися список через
        <strong>/company</strong> і перемкнути активну через <strong>/company ID</strong>.
      </p>
      <p class="tg-help" style="margin-top:10px;">Для швидкого входу або відновлення доступу напишіть боту
        <strong>/login</strong>. Він надішле одноразове посилання на 5 хвилин.
      </p>

      <div class="actions-row">
        <form method="post" action="/account/telegram-link">
          <button type="submit" class="btn btn-primary">Згенерувати код прив'язки</button>
        </form>

        <?php if ($telegram_linked): ?>
          <form method="post" action="/account/telegram-unlink"
            onsubmit="return confirm('Відв\'язати Telegram акаунт?');">
            <button type="submit" class="btn btn-danger">Відв'язати Telegram</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <section class="account-card">
    <h3 class="account-title" style="font-size:16px;">API Token для MCP/Claude</h3>
    <div class="settings-promo-banner">
      <div class="settings-promo-icon">🔑</div>
      <div class="settings-promo-body">
        <div class="settings-promo-title">Підключіть Claude напряму до системи</div>
        <div class="settings-promo-text">API-токен дає змогу підключити Claude Desktop або будь-який MCP-клієнт до
          вашого таск-трекера. Після підключення Claude бачить ваші задачі, проекти та шаблони — і може ними керувати
          прямо з інтерфейсу Claude. Токен прив'язується до конкретної компанії й має термін дії для безпеки.</div>
      </div>
    </div>
    <p class="account-subtitle">Токен використовується в Authorization: Bearer для доступу до /api/v1/*.</p>

    <?php if (!empty($new_api_token['token'])): ?>
      <div class="code-box" style="margin-top:12px; border-color:#86efac; display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
        <span style="white-space:nowrap; flex-shrink:0;">Новий API token:</span>
        <span class="code-token" id="new-api-token-value" style="letter-spacing:0.02em; flex:1; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"><?php echo e($new_api_token['token']); ?></span>
        <span style="white-space:nowrap; color:#666; flex-shrink:0;">до <?php echo e($new_api_token['expires_at'] ?? ''); ?></span>
        <button type="button" class="btn-copy-token" onclick="copyApiToken('new-api-token-value', this)">
          <svg width="14" height="14" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg" style="flex-shrink:0;"><rect x="5" y="5" width="9" height="9" rx="1.5" stroke="currentColor" stroke-width="1.5"/><path d="M11 5V3.5A1.5 1.5 0 0 0 9.5 2h-7A1.5 1.5 0 0 0 1 3.5v7A1.5 1.5 0 0 0 2.5 12H4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
          Копіювати
        </button>
      </div>
    <?php endif; ?>

    <div class="actions-row">
      <form method="post" action="/account/api-token">
        <button type="submit" class="btn btn-primary">Згенерувати API токен</button>
      </form>

      <form method="post" action="/account/api-token-revoke"
        onsubmit="return confirm('Відкликати всі API токени? Доступ конектора буде втрачено.');">
        <button type="submit" class="btn btn-danger">Відкликати всі токени</button>
      </form>
    </div>

    <div class="api-token-list">
      <?php foreach ($api_tokens as $token_row): ?>
        <?php $tid = (int) ($token_row['id'] ?? 0); ?>
        <div class="api-token-item">
          <div class="api-token-row">
            <span class="api-token-masked" id="token-masked-<?php echo $tid; ?>">
              <strong><?php echo e($token_row['token_masked'] ?? ''); ?></strong>
            </span>
            <code class="api-token-full" id="token-full-<?php echo $tid; ?>" style="display:none;"></code>
            <div class="api-token-actions">
              <button type="button" class="btn-copy-token" id="token-reveal-btn-<?php echo $tid; ?>"
                onclick="revealToken(<?php echo $tid; ?>, this)">
                <svg width="14" height="14" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M1 8C1 8 3.5 3 8 3s7 5 7 5-2.5 5-7 5S1 8 1 8Z" stroke="currentColor" stroke-width="1.5"/><circle cx="8" cy="8" r="2" stroke="currentColor" stroke-width="1.5"/></svg>
                Показати
              </button>
              <form method="post" action="/account/api-token-revoke"
                onsubmit="return confirm('Відкликати цей API токен?');" style="margin:0;">
                <input type="hidden" name="token_id" value="<?php echo $tid; ?>">
                <button type="submit" class="btn btn-outline">Відкликати</button>
              </form>
            </div>
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
  </section>

  <section class="account-card">
    <h3 class="account-title" style="font-size:16px;">Інтеграції з Claude AI</h3>
    <div class="settings-promo-banner settings-promo-highlight">
      <div class="settings-promo-icon">🤖</div>
      <div class="settings-promo-body">
        <div class="settings-promo-title">Голосом або текстом — Claude розбере все сам</div>
        <div class="settings-promo-text">У платному тарифі Claude стає повноцінним членом команди. Надсилайте
          <strong>аудіоповідомлення</strong> у чат Claude — він розшифрує, виділить задачі, призначить відповідальних і
          одразу створить їх у системі. Складайте тижневі плани, отримуйте огляд незакритих задач, розбивайте великі
          цілі на конкретні кроки — все це без жодної форми, просто в розмові. Підходить для керівників, які не люблять
          «клікати».
        </div>
      </div>
    </div>
    <p class="account-subtitle">Підключення Claude через MCP.</p>

    <?php
    // Генеруємо OAuth credentials для цього юзера
    try {
        $oauthCtrl = new \App\Controllers\OAuthController();
        $oauthCreds = $oauthCtrl->clientCredentials((int) ($user['id'] ?? 0));
    } catch (\Throwable $e) {
        $oauthCreds = null;
    }
    ?>

    <div style="margin-top:16px;">
      <div style="font-weight:600; font-size:14px; margin-bottom:12px;">🔌 Підключення до Claude.ai (MCP Connector)</div>
      <p style="font-size:13px; color:#555; margin-bottom:14px; line-height:1.6;">
        В Claude.ai натисніть <strong>Settings → Connectors → Add custom connector</strong> і заповніть:
      </p>

      <div class="claude-creds-block">
        <div class="cred-row">
          <span class="cred-label">Name</span>
          <code class="cred-value" id="cred-name">FINEKO Task Tracker</code>
          <button type="button" class="btn-copy-token" onclick="copyEl('cred-name', this)">Копіювати</button>
        </div>
        <div class="cred-row">
          <span class="cred-label">Remote MCP server URL</span>
          <code class="cred-value" id="cred-url"><?php echo e(APP_URL . '/mcp'); ?></code>
          <button type="button" class="btn-copy-token" onclick="copyEl('cred-url', this)">Копіювати</button>
        </div>
        <?php if ($oauthCreds): ?>
        <div class="cred-row">
          <span class="cred-label">OAuth Client ID</span>
          <code class="cred-value" id="cred-cid"><?php echo e($oauthCreds['client_id']); ?></code>
          <button type="button" class="btn-copy-token" onclick="copyEl('cred-cid', this)">Копіювати</button>
        </div>
        <div class="cred-row">
          <span class="cred-label">OAuth Client Secret</span>
          <code class="cred-value" id="cred-cs"><?php echo e($oauthCreds['client_secret']); ?></code>
          <button type="button" class="btn-copy-token" onclick="copyEl('cred-cs', this)">Копіювати</button>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <section class="account-card">
    <h3 class="account-title" style="font-size:16px;">Telegram дайджести (ранок/вечір)</h3>
    <div class="settings-promo-banner">
      <div class="settings-promo-icon">📋</div>
      <div class="settings-promo-body">
        <div class="settings-promo-title">Починайте і завершуйте день з чітким планом</div>
        <div class="settings-promo-text"><strong>Ранковий дайджест</strong> — перелік усіх задач на сьогодні: хто що має
          зробити, дедлайни, пріоритети. <strong>Вечірній звіт</strong> — що виконано, що залишилось, що перенеслося.
          Дайджест надходить у Telegram особисто вам — без потреби заходити в систему. Ідеально для керівника, який хоче
          тримати руку на пульсі команди.</div>
      </div>
    </div>
    <p class="account-subtitle">Cron перевіряє щогодини і надсилає звіт у вказану годину. За замовчуванням: 09:00 і
      18:00.</p>

    <form method="post" action="/account/digest-settings" class="notify-form">
      <div class="notify-row">
        <label>
          <input type="checkbox" name="morning_enabled" value="1" <?php echo (int) ($digest_settings['morning_enabled'] ?? 1) === 1 ? 'checked' : ''; ?>>
          Ранковий план
        </label>
        <input type="number" name="morning_hour" min="0" max="23"
          value="<?php echo (int) ($digest_settings['morning_hour'] ?? 9); ?>">
      </div>

      <div class="notify-row">
        <label>
          <input type="checkbox" name="evening_enabled" value="1" <?php echo (int) ($digest_settings['evening_enabled'] ?? 1) === 1 ? 'checked' : ''; ?>>
          Вечірній звіт
        </label>
        <input type="number" name="evening_hour" min="0" max="23"
          value="<?php echo (int) ($digest_settings['evening_hour'] ?? 18); ?>">
      </div>

      <div>
        <button type="submit" class="btn btn-primary">Зберегти налаштування</button>
      </div>
    </form>
  </section>

  <section class="account-card">
    <h3 class="account-title" style="font-size:16px;">Пароль</h3>
    <p class="account-subtitle">Після входу через Telegram-посилання тут можна швидко встановити новий пароль.</p>

    <form method="post" action="/account/password" class="password-form">
      <div class="password-field">
        <label for="password">Новий пароль</label>
        <input type="password" id="password" name="password" minlength="6" required>
      </div>
      <div class="password-field">
        <label for="password_confirm">Підтвердження</label>
        <input type="password" id="password_confirm" name="password_confirm" minlength="6" required>
      </div>
      <div class="password-field span-2">
        <button type="submit" class="btn btn-primary">Оновити пароль</button>
      </div>
    </form>
  </section>

  <section class="account-card sub-card" id="subscriptionSection">
    <h3 class="account-title" style="font-size:16px;">Підписка</h3>
    <div class="settings-promo-banner">
      <div class="settings-promo-icon">🚀</div>
      <div class="settings-promo-body">
        <div class="settings-promo-title">Розблокуйте повний потенціал команди</div>
        <div class="settings-promo-text"><strong>Basic ($25/міс)</strong> — до 10 людей: задачі, проекти, шаблони,
          дайджести та Claude через MCP. <strong>Pro ($50/міс)</strong> — необмежена кількість людей плюс <em>Telegram
            AI бот</em>: голосові команди, автоматична постановка задач і звіти прямо в месенджері без браузера. Перші 3
          тижні — безкоштовно на тарифі Pro.</div>
      </div>
    </div>
    <p class="account-subtitle">Оберіть тарифний план для вашої компанії.</p>

    <?php
    $sub_plan = $subscription['plan'];
    $sub_active = $subscription['is_active'];
    $sub_status = $subscription['status'];
    $sub_days_left = $subscription['days_left'];
    $sub_expires = $subscription['expires_at'];
    $sub_cancelled = $subscription['cancelled_at'] !== '';
    $sub_plan_name = $subscription['plan_name'];
    $sub_is_trial = $subscription['is_trial'] ?? false;
    ?>

    <!-- Поточний статус -->
    <div
      class="sub-status-block <?php echo $sub_active ? 'sub-active' : ($sub_cancelled ? 'sub-cancelled' : 'sub-free'); ?>">
      <div class="sub-status-header">
        <span class="sub-badge"><?php echo e($sub_plan_name); ?><?php if ($sub_is_trial): ?> <span
              class="sub-trial-badge">Пробний</span><?php endif; ?></span>
        <?php if ($sub_active): ?>
          <span class="sub-days">
            <?php if ($sub_cancelled): ?>
              Скасовано — активний ще <?php echo $sub_days_left; ?> д.
            <?php elseif ($sub_is_trial): ?>
              Пробний період — <?php echo $sub_days_left; ?> д. залишилось
            <?php else: ?>
              <?php echo $sub_days_left; ?> д. до кінця оплаченого періоду
            <?php endif; ?>
          </span>
        <?php else: ?>
          <span class="sub-days sub-days-free">Безкоштовний план (до <?php echo $free_limit; ?> учасників)</span>
        <?php endif; ?>
      </div>
      <?php if ($sub_active && $sub_expires !== ''): ?>
        <div class="sub-expires-at">Дія до: <?php echo date('d.m.Y', strtotime($sub_expires)); ?></div>
        <div class="sub-countdown-bar">
          <?php
          $total_days = $sub_is_trial ? 21 : 31;
          $pct = $sub_days_left > 0 ? min(100, round($sub_days_left / $total_days * 100)) : 0;
          ?>
          <div class="sub-countdown-fill" style="width:<?php echo $pct; ?>%"></div>
        </div>
      <?php endif; ?>
    </div>

    <!-- Плани -->
    <div class="sub-plans-grid">
      <?php foreach ($plans as $plan_key => $plan): ?>
        <?php $is_current = ($sub_plan === $plan_key && $sub_active && !$sub_cancelled); ?>
        <div class="sub-plan-card <?php echo $is_current ? 'sub-plan-current' : ''; ?>">
          <div class="sub-plan-header">
            <span class="sub-plan-name"><?php echo e($plan['name']); ?></span>
            <span class="sub-plan-price">$<?php echo $plan['price_usd']; ?><small>/міс</small></span>
          </div>
          <ul class="sub-plan-features">
            <li>👥 <?php echo e($plan['description']); ?></li>
            <?php if ($plan['ai_bot']): ?>
              <li>🤖 Telegram AI бот</li>
            <?php else: ?>
              <li class="sub-feature-no">🚫 Без Telegram AI бота</li>
            <?php endif; ?>
          </ul>
          <?php if ($is_current): ?>
            <div class="sub-plan-current-badge">Ваш поточний план</div>
          <?php elseif ($is_company_owner): ?>
            <form method="post" action="/account/subscription/pay">
              <input type="hidden" name="plan" value="<?php echo e($plan_key); ?>">
              <button type="submit" class="btn btn-primary sub-pay-btn">
                <?php echo $sub_active ? 'Змінити план' : 'Підписатися'; ?>
              </button>
            </form>
          <?php else: ?>
            <div class="sub-no-rights">Тільки власник може керувати підпискою</div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>

    <!-- Скасувати підписку -->
    <?php if ($sub_active && !$sub_cancelled && $is_company_owner): ?>
      <div class="sub-cancel-row">
        <?php if ($member_count > $free_limit): ?>
          <!-- Показати popup з вибором учасників -->
          <button type="button" class="btn btn-danger" id="subCancelBtn">
            Скасувати підписку
          </button>
        <?php else: ?>
          <form method="post" action="/account/subscription/cancel"
            onsubmit="return confirm('Скасувати підписку? Доступ залишиться до кінця оплаченого місяця.');">
            <button type="submit" class="btn btn-danger">Скасувати підписку</button>
          </form>
        <?php endif; ?>
        <span class="sub-cancel-hint">Доступ зберігається до кінця оплаченого місяця.</span>
      </div>
    <?php endif; ?>
  </section>

  <!-- ========== Popup downgrade ========== -->
  <?php if ($sub_active && !$sub_cancelled && $is_company_owner && $member_count > $free_limit): ?>
    <div class="sub-downgrade-overlay" id="subDowngradeOverlay" style="display:none;">
      <div class="sub-downgrade-modal">
        <h3 class="sub-downgrade-title">Скасування підписки — вибір учасників</h3>
        <p class="sub-downgrade-desc">
          На безкоштовному плані залишиться максимум <strong><?php echo $free_limit; ?> учасники</strong>.<br>
          Ви маєте <?php echo $member_count; ?> учасників — оберіть, хто залишається. Решта втратить доступ.
        </p>
        <form method="post" action="/account/subscription/downgrade-members" id="subDowngradeForm">
          <div class="sub-member-list">
            <?php
            $owner_id = (int) $user['id'];
            foreach ($company_employees as $emp):
              $emp_uid = (int) ($emp['user_id'] ?? 0);
              $emp_name = trim((($emp['first_name'] ?? '') . ' ' . ($emp['last_name'] ?? ''))) ?: $emp['email'] ?? '—';
              $is_owner_emp = $emp_uid === $owner_id;
              ?>
              <label class="sub-member-row <?php echo $is_owner_emp ? 'sub-member-owner' : ''; ?>">
                <input type="checkbox" name="keep_members[]" value="<?php echo $emp_uid; ?>" <?php echo $is_owner_emp ? 'checked disabled' : ''; ?>>
                <?php if ($is_owner_emp): ?>
                  <input type="hidden" name="keep_members[]" value="<?php echo $emp_uid; ?>">
                <?php endif; ?>
                <span class="sub-member-name"><?php echo e($emp_name); ?></span>
                <?php if ($is_owner_emp): ?>
                  <span class="sub-member-badge">Ви (власник)</span>
                <?php else: ?>
                  <span class="sub-member-role"><?php echo e(ucfirst($emp['role'] ?? 'member')); ?></span>
                <?php endif; ?>
              </label>
            <?php endforeach; ?>
          </div>
          <p class="sub-downgrade-counter">
            Обрано: <strong id="subSelectedCount">1</strong> з <?php echo $free_limit; ?> дозволених
          </p>
          <div class="sub-downgrade-actions">
            <button type="button" class="btn btn-outline" id="subDowngradeCancel">Скасувати</button>
            <button type="submit" class="btn btn-danger" id="subDowngradeSubmit">Підтвердити та скасувати
              підписку</button>
          </div>
        </form>
      </div>
    </div>
    <script>
      (function () {
        var btn = document.getElementById('subCancelBtn');
        var overlay = document.getElementById('subDowngradeOverlay');
        var cancelBtn = document.getElementById('subDowngradeCancel');
        var form = document.getElementById('subDowngradeForm');
        var counter = document.getElementById('subSelectedCount');
        var submitBtn = document.getElementById('subDowngradeSubmit');
        var limit = <?php echo (int) $free_limit; ?>;

        function countChecked() {
          return form.querySelectorAll('input[type="checkbox"]:checked').length;
        }

        function updateUI() {
          var n = countChecked();
          if (counter) counter.textContent = n;
          if (submitBtn) submitBtn.disabled = n > limit || n < 1;
        }

        if (btn) btn.addEventListener('click', function () {
          overlay.style.display = 'flex';
          updateUI();
        });

        if (cancelBtn) cancelBtn.addEventListener('click', function () {
          overlay.style.display = 'none';
        });

        if (overlay) overlay.addEventListener('click', function (e) {
          if (e.target === overlay) overlay.style.display = 'none';
        });

        if (form) {
          form.querySelectorAll('input[type="checkbox"]').forEach(function (cb) {
            cb.addEventListener('change', updateUI);
          });
        }

        if (form) form.addEventListener('submit', function (e) {
          if (countChecked() > limit) {
            e.preventDefault();
            alert('Оберіть не більше ' + limit + ' учасників.');
          }
        });
      })();
    </script>
  <?php endif; ?>

  <section class="account-card" id="training">
    <?php
    if (isset($_GET['train_paid']) && $_GET['train_paid'] === '1') {
      echo '<div class="account-flash-ok" style="margin-bottom:14px;">✅ Оплату отримано! Ми зв\'яжемось з вами найближчим часом.</div>';
    }
    $training_plans = TRAINING_PLANS;
    ?>
    <h3 class="account-title" style="font-size:16px;">🎓 Впровадження системи — практичний тренінг</h3>

    <div class="settings-promo-banner settings-promo-training">
      <div class="settings-promo-icon">🚀</div>
      <div class="settings-promo-body">
        <div class="settings-promo-title">Місяць практичного відпрацювання навичок планування для вас і вашої команди</div>
        <div class="settings-promo-text">
          Ви вже користуєтесь системою — але чи використовує її вся команда так, як треба? <strong>Більшість керівників
          знають теорію, але не мають відпрацьованої звички</strong> — у себе та в підлеглих. Без системи команда
          "гасить пожежі" замість роботи над важливим, стратегічні задачі програють рутині, а плани зриваються,
          бо будуються на ілюзіях замість реальних ресурсів.<br><br>
          Наш місячний практикум з <strong>кваліфікованим тренером</strong> — це не лекції, а живі розбори ваших
          реальних планів. 4 онлайн-заняття в Zoom, домашні завдання, зворотний зв'язок. Ви разом з командою
          виходите на один стандарт планування — без опору і відкату назад.<br><br>
          📅 <strong>Старт: <?php echo TRAINING_START_DATE; ?></strong> &nbsp;|&nbsp;
          🪑 <strong>Залишилось місць: <?php echo TRAINING_SEATS_LEFT; ?></strong>
        </div>
      </div>
    </div>

    <div class="training-why-grid">
      <div class="training-why-item">
        <span class="training-why-icon">🔥</span>
        <div><strong>Результат з першого тижня</strong> — практичні завдання, а не теорія в шухляду</div>
      </div>
      <div class="training-why-item">
        <span class="training-why-icon">👥</span>
        <div><strong>Для всієї команди одразу</strong> — не треба потім переказувати матеріал</div>
      </div>
      <div class="training-why-item">
        <span class="training-why-icon">🎯</span>
        <div><strong>Ваші реальні задачі</strong> — розбираємо саме ваші плани, а не абстрактні приклади</div>
      </div>
      <div class="training-why-item">
        <span class="training-why-icon">📊</span>
        <div><strong>Система як рентген</strong> — одразу видно, хто перевантажений, а хто імітує роботу</div>
      </div>
    </div>

    <div class="training-plans-grid">
      <?php foreach ($training_plans as $tkey => $tplan): ?>
        <div class="training-plan-card <?php echo !empty($tplan['popular']) ? 'training-plan-popular' : ''; ?>">
          <?php if (!empty($tplan['popular'])): ?>
            <div class="training-popular-badge">🔥 Найпопулярніший</div>
          <?php endif; ?>
          <div class="training-plan-name"><?php echo e($tplan['name']); ?></div>
          <div class="training-plan-participants"><?php echo e($tplan['participants']); ?></div>
          <div class="training-plan-price">$<?php echo $tplan['price_usd']; ?><span class="training-plan-period">/міс</span></div>
          <p class="training-plan-desc"><?php echo e($tplan['description']); ?></p>
          <ul class="training-plan-features">
            <?php foreach ($tplan['features'] as $f): ?>
              <li>✓ <?php echo e($f); ?></li>
            <?php endforeach; ?>
          </ul>
          <form method="post" action="/training/pay">
            <input type="hidden" name="plan" value="<?php echo e($tkey); ?>">
            <button type="submit" class="btn training-pay-btn <?php echo !empty($tplan['popular']) ? 'training-pay-btn-primary' : ''; ?>">
              Записатись — $<?php echo $tplan['price_usd']; ?>
            </button>
          </form>
        </div>
      <?php endforeach; ?>
    </div>

    <p class="training-footer-note">
      ⚡ Натискаючи кнопку, ви перейдете на сторінку оплати WayForPay. Після підтвердження ми зв'яжемось з вами
      в Telegram або Email для підтвердження участі.
    </p>
  </section>

  <section class="account-card">
    <h3 class="account-title" style="font-size:16px;">Компанії користувача</h3>
    <p class="account-subtitle">Активну компанію можна перемкнути у верхній панелі на будь-якій сторінці.</p>

    <div class="company-list">
      <?php foreach ($companies as $company): ?>
        <span class="company-pill"><?php echo e($company['name'] ?? ('Компанія #' . (int) $company['id'])); ?></span>
      <?php endforeach; ?>
      <?php if (empty($companies)): ?>
        <span class="company-pill">Компанії не знайдені</span>
      <?php endif; ?>
    </div>
  </section>
</div>

<style>
.claude-creds-block { background:#f9fafb; border:1.5px solid #e5e7eb; border-radius:10px; padding:4px 0; margin-top:4px; }
.cred-row { display:flex; align-items:center; gap:10px; padding:10px 16px; border-bottom:1px solid #f0f0f0; flex-wrap:wrap; }
.cred-row:last-child { border-bottom:none; }
.cred-label { font-size:12px; color:#6b7280; font-weight:500; width:160px; flex-shrink:0; }
.cred-value { font-family:monospace; font-size:12px; color:#111; background:#fff; border:1px solid #e5e7eb; border-radius:5px; padding:4px 8px; flex:1; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.api-token-actions {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-shrink: 0;
}
.api-token-full {
  font-family: monospace;
  font-size: 13px;
  background: #f9fafb;
  border: 1px solid #e5e7eb;
  border-radius: 6px;
  padding: 4px 10px;
  flex: 1;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  color: #111827;
  letter-spacing: 0.02em;
}
.new-token-banner {
  background: #f0fdf4;
  border: 1.5px solid #86efac;
  border-radius: 10px;
  padding: 16px 20px;
  margin-bottom: 20px;
}
.new-token-banner-title {
  font-weight: 600;
  font-size: 14px;
  color: #166534;
  margin-bottom: 10px;
}
.new-token-banner-row {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}
.new-token-value {
  font-family: monospace;
  font-size: 14px;
  color: #1a1a1a;
  background: #fff;
  border: 1px solid #d1fae5;
  border-radius: 6px;
  padding: 6px 12px;
  flex: 1;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  letter-spacing: 0.02em;
}
.new-token-banner-expires {
  font-size: 12px;
  color: #6b7280;
  margin-top: 8px;
}
.btn-copy-token {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 5px 12px;
  font-size: 13px;
  font-weight: 500;
  color: #374151;
  background: #f3f4f6;
  border: 1.5px solid #d1d5db;
  border-radius: 6px;
  cursor: pointer;
  transition: background .15s, border-color .15s, color .15s;
  white-space: nowrap;
  flex-shrink: 0;
}
.btn-copy-token:hover { background: #e5e7eb; border-color: #9ca3af; }
.btn-copy-token.copied { background: #dcfce7; border-color: #86efac; color: #166534; }
</style>
<script>
function copyEl(elemId, btn) {
  var el = document.getElementById(elemId);
  if (!el) return;
  navigator.clipboard.writeText(el.textContent.trim()).then(function() {
    var orig = btn.textContent;
    btn.textContent = '✓ Скопійовано';
    btn.classList.add('copied');
    setTimeout(function() { btn.textContent = orig; btn.classList.remove('copied'); }, 2000);
  });
}
function revealToken(tokenId, btn) {
  var masked = document.getElementById('token-masked-' + tokenId);
  var fullEl = document.getElementById('token-full-' + tokenId);
  if (!fullEl) return;

  // Якщо вже розкрито — копіюємо
  if (fullEl.style.display !== 'none') {
    copyApiToken('token-full-' + tokenId, btn);
    return;
  }

  btn.disabled = true;
  btn.textContent = 'Завантаження...';

  fetch('/account/api-token-reveal?token_id=' + tokenId, { credentials: 'same-origin' })
    .then(function(r) { return r.json(); })
    .then(function(data) {
      if (!data.ok || !data.token) {
        btn.disabled = false;
        btn.textContent = 'Помилка';
        return;
      }
      fullEl.textContent = data.token;
      fullEl.style.display = '';
      if (masked) masked.style.display = 'none';

      btn.disabled = false;
      btn.innerHTML = '<svg width="14" height="14" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg" style="flex-shrink:0;"><rect x="5" y="5" width="9" height="9" rx="1.5" stroke="currentColor" stroke-width="1.5"/><path d="M11 5V3.5A1.5 1.5 0 0 0 9.5 2h-7A1.5 1.5 0 0 0 1 3.5v7A1.5 1.5 0 0 0 2.5 12H4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg> Копіювати';
      btn.onclick = function() { copyApiToken('token-full-' + tokenId, btn); };
    })
    .catch(function() {
      btn.disabled = false;
      btn.textContent = 'Помилка';
    });
}

function copyApiToken(elemId, btn) {
  var el = document.getElementById(elemId);
  if (!el) return;
  var text = el.textContent || el.innerText;
  navigator.clipboard.writeText(text.trim()).then(function () {
    btn.classList.add('copied');
    btn.innerHTML = '<svg width="14" height="14" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg" style="flex-shrink:0;"><path d="M2.5 8.5L6 12L13.5 4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg> Скопійовано';
    setTimeout(function () {
      btn.classList.remove('copied');
      btn.innerHTML = '<svg width="14" height="14" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg" style="flex-shrink:0;"><rect x="5" y="5" width="9" height="9" rx="1.5" stroke="currentColor" stroke-width="1.5"/><path d="M11 5V3.5A1.5 1.5 0 0 0 9.5 2h-7A1.5 1.5 0 0 0 1 3.5v7A1.5 1.5 0 0 0 2.5 12H4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg> Копіювати';
    }, 2000);
  }).catch(function () {
    // fallback для старих браузерів
    var range = document.createRange();
    range.selectNode(el);
    window.getSelection().removeAllRanges();
    window.getSelection().addRange(range);
    document.execCommand('copy');
    window.getSelection().removeAllRanges();
    btn.classList.add('copied');
    btn.textContent = '✓ Скопійовано';
    setTimeout(function () {
      btn.classList.remove('copied');
      btn.innerHTML = '<svg width="14" height="14" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg" style="flex-shrink:0;"><rect x="5" y="5" width="9" height="9" rx="1.5" stroke="currentColor" stroke-width="1.5"/><path d="M11 5V3.5A1.5 1.5 0 0 0 9.5 2h-7A1.5 1.5 0 0 0 1 3.5v7A1.5 1.5 0 0 0 2.5 12H4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg> Копіювати';
    }, 2000);
  });
}
</script>

<?php
$title = 'Налаштування акаунта';
$content = ob_get_clean();
include APP_PATH . '/Views/layouts/main.php';
