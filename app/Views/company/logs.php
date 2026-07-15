<?php
ob_start();
$company = $company ?? [];
$employees = $employees ?? [];
$logs = $logs ?? [];
$selected_user_id = (int) ($selected_user_id ?? 0);
$search_query = trim((string) ($search_query ?? ''));

$employee_map = [];
foreach ($employees as $employee) {
    $employee_map[(int) ($employee['user_id'] ?? 0)] = trim((string) (($employee['first_name'] ?? '') . ' ' . ($employee['last_name'] ?? ''))) ?: 'Без імені';
}

$format_log_date = static function ($value): string {
    $timestamp = strtotime((string) $value);
    if ($timestamp === false) {
        return '—';
    }

    return date('d.m.y H:i', $timestamp);
};
?>

<style>
    .logs-page {
        display: grid;
        gap: 20px;
    }

    .logs-card {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 18px;
        box-shadow: 0 8px 30px rgba(15, 23, 42, 0.05);
        overflow: hidden;
    }

    .logs-card-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding: 16px 18px;
        border-bottom: 1px solid #e2e8f0;
        background: linear-gradient(135deg, #f1f5f9 0%, #ffffff 100%);
    }

    .logs-title {
        margin: 0;
        font-size: 20px;
        font-weight: 700;
        color: #0f172a;
    }

    .logs-subtitle {
        margin: 4px 0 0;
        font-size: 13px;
        color: #475569;
    }

    .logs-actions {
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .logs-link {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 10px;
        padding: 9px 14px;
        background: #e0f2fe;
        color: #075985;
        font-size: 14px;
        font-weight: 600;
        text-decoration: none;
    }

    .logs-link:hover {
        background: #bae6fd;
    }

    .logs-body {
        padding: 18px;
        display: grid;
        gap: 14px;
    }

    .logs-filters {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
    }

    .logs-search {
        display: grid;
        grid-template-columns: minmax(220px, 360px) auto;
        gap: 10px;
        align-items: center;
    }

    .logs-search input {
        width: 100%;
        border: 1px solid #cbd5e1;
        border-radius: 10px;
        padding: 10px 12px;
        font-size: 14px;
        background: #fff;
        color: #0f172a;
    }

    .logs-chip {
        display: inline-flex;
        align-items: center;
        border-radius: 999px;
        padding: 7px 12px;
        background: #e2e8f0;
        color: #334155;
        text-decoration: none;
        font-size: 13px;
        font-weight: 600;
    }

    .logs-chip.active {
        background: #0ea5e9;
        color: #fff;
    }

    .logs-list {
        display: grid;
        gap: 14px;
    }

    .log-item {
        background: #fff;
        border: 1px solid #dbe4ee;
        border-radius: 14px;
        padding: 14px;
        display: grid;
        gap: 10px;
    }

    .log-meta {
        display: flex;
        flex-wrap: wrap;
        gap: 12px;
        font-size: 12px;
        color: #64748b;
    }

    .log-pill {
        display: inline-flex;
        align-items: center;
        border-radius: 999px;
        padding: 4px 10px;
        background: #e0f2fe;
        color: #075985;
        font-size: 12px;
        font-weight: 700;
    }

    .log-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 12px;
    }

    .log-block {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 12px;
    }

    .log-block-title {
        margin: 0 0 8px;
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: .04em;
        color: #475569;
    }

    .log-text {
        margin: 0;
        white-space: pre-wrap;
        word-break: break-word;
        color: #0f172a;
        font-size: 13px;
        line-height: 1.45;
    }

    .log-empty {
        padding: 28px;
        text-align: center;
        color: #64748b;
        background: #fff;
        border: 1px dashed #cbd5e1;
        border-radius: 14px;
    }

    details.log-details {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 10px 12px;
    }

    details.log-details summary {
        cursor: pointer;
        font-size: 13px;
        font-weight: 700;
        color: #334155;
    }

    details.log-details pre {
        margin: 10px 0 0;
        white-space: pre-wrap;
        word-break: break-word;
        font-size: 12px;
        color: #0f172a;
    }

    @media (max-width: 900px) {
        .log-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="logs-page">
    <section class="logs-card">
        <header class="logs-card-head">
            <div>
                <h1 class="logs-title">Логи переписок з ботом</h1>
                <p class="logs-subtitle">Вхідні повідомлення, транскрибація аудіо, відповідь ШІ та reply бота.</p>
            </div>
            <div class="logs-actions">
                <a href="/company/profile" class="logs-link">Назад до співробітників</a>
            </div>
        </header>
        <div class="logs-body">
            <form class="logs-search" method="GET"
                action="/company/logs<?= $selected_user_id > 0 ? '/' . $selected_user_id : ''; ?>">
                <input type="text" name="q" value="<?= e($search_query); ?>"
                    placeholder="Пошук по словах у повідомленнях, відповідях, командах...">
                <button type="submit" class="logs-link" style="border:0;cursor:pointer;">Шукати</button>
            </form>

            <div class="logs-filters">
                <a href="/company/logs<?= $search_query !== '' ? '?q=' . urlencode($search_query) : ''; ?>"
                    class="logs-chip <?= $selected_user_id === 0 ? 'active' : ''; ?>">Усі</a>
                <?php foreach ($employees as $employee): ?>
                    <?php $emp_id = (int) ($employee['user_id'] ?? 0); ?>
                    <a href="/company/logs/<?= $emp_id; ?><?= $search_query !== '' ? '?q=' . urlencode($search_query) : ''; ?>"
                        class="logs-chip <?= $selected_user_id === $emp_id ? 'active' : ''; ?>">
                        <?= e($employee_map[$emp_id] ?? 'Без імені'); ?>
                    </a>
                <?php endforeach; ?>
            </div>

            <?php if (empty($logs)): ?>
                <div class="log-empty">Логів поки немає.</div>
            <?php else: ?>
                <div class="logs-list">
                    <?php foreach ($logs as $log): ?>
                        <?php
                        $user_name = $employee_map[(int) ($log['app_user_id'] ?? 0)] ?? trim((string) (($log['first_name'] ?? '') . ' ' . ($log['last_name'] ?? ''))) ?: 'Невідомий користувач';
                        $raw_text = trim((string) ($log['raw_text'] ?? ''));
                        $transcribed_text = trim((string) ($log['transcribed_text'] ?? ''));
                        $normalized_text = trim((string) ($log['normalized_text'] ?? ''));
                        $route_name = trim((string) ($log['route_name'] ?? ''));
                        $route_confidence = trim((string) ($log['route_confidence'] ?? ''));
                        $route_reason = trim((string) ($log['route_reason'] ?? ''));
                        $bot_reply = trim((string) ($log['bot_reply'] ?? ''));
                        $ai_parsed_json = trim((string) ($log['ai_parsed_json'] ?? ''));
                        $ai_raw_response = trim((string) ($log['ai_raw_response'] ?? ''));
                        $audio_error = trim((string) ($log['audio_error'] ?? ''));
                        ?>
                        <article class="log-item">
                            <div class="log-meta">
                                <span class="log-pill"><?= e((string) ($log['processing_status'] ?? 'received')); ?></span>
                                <?php if ($route_name !== ''): ?>
                                    <span class="log-pill"
                                        style="background:#dcfce7;color:#166534;"><?= e($route_name . ($route_confidence !== '' ? ' · ' . $route_confidence : '')); ?></span>
                                <?php endif; ?>
                                <?php if (!empty($log['execution_path'])): ?>
                                    <span class="log-pill"
                                        style="background:#ede9fe;color:#5b21b6;"><?= e((string) $log['execution_path']); ?></span>
                                <?php endif; ?>
                                <span><?= e($format_log_date($log['created_at'] ?? '')); ?></span>
                                <span><?= e($user_name); ?></span>
                                <span>Chat: <?= e((string) ($log['chat_id'] ?? '—')); ?></span>
                                <span>Тип: <?= e((string) ($log['message_kind'] ?? 'text')); ?></span>
                                <?php if (!empty($log['command_names'])): ?>
                                    <span>Команди: <?= e((string) $log['command_names']); ?></span>
                                <?php endif; ?>
                            </div>

                            <div class="log-grid">
                                <div class="log-block">
                                    <h3 class="log-block-title">Що сказав користувач</h3>
                                    <p class="log-text">
                                        <?= e($raw_text !== '' ? $raw_text : ($normalized_text !== '' ? $normalized_text : '—')); ?>
                                    </p>
                                </div>
                                <div class="log-block">
                                    <h3 class="log-block-title">Що відповів бот</h3>
                                    <p class="log-text"><?= e($bot_reply !== '' ? $bot_reply : '—'); ?></p>
                                </div>
                            </div>

                            <?php if ($route_reason !== ''): ?>
                                <div class="log-block" style="margin-top:12px;">
                                    <h3 class="log-block-title">Router</h3>
                                    <p class="log-text"><?= e($route_reason); ?></p>
                                </div>
                            <?php endif; ?>

                            <?php if ($transcribed_text !== '' || $normalized_text !== '' || $audio_error !== ''): ?>
                                <div class="log-grid">
                                    <div class="log-block">
                                        <h3 class="log-block-title">Транскрибація / нормалізований текст</h3>
                                        <p class="log-text"><?php
                                        $parts = [];
                                        if ($transcribed_text !== '') {
                                            $parts[] = "Транскрибація:\n" . $transcribed_text;
                                        }
                                        if ($normalized_text !== '' && $normalized_text !== $transcribed_text) {
                                            $parts[] = "У розбір пішло:\n" . $normalized_text;
                                        }
                                        if ($audio_error !== '') {
                                            $parts[] = "Помилка аудіо:\n" . $audio_error;
                                        }
                                        echo e(!empty($parts) ? implode("\n\n", $parts) : '—');
                                        ?></p>
                                    </div>
                                    <div class="log-block">
                                        <h3 class="log-block-title">Контекст для ШІ</h3>
                                        <p class="log-text"><?= e(trim((string) ($log['ai_recent_context'] ?? '')) ?: '—'); ?></p>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if ($ai_parsed_json !== '' || $ai_raw_response !== ''): ?>
                                <details class="log-details">
                                    <summary>Деталі відповіді ШІ</summary>
                                    <?php if ($ai_parsed_json !== ''): ?>
                                        <pre><?= e($ai_parsed_json); ?></pre>
                                    <?php endif; ?>
                                    <?php if ($ai_raw_response !== ''): ?>
                                        <pre><?= e($ai_raw_response); ?></pre>
                                    <?php endif; ?>
                                </details>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>
</div>

<?php
$content = ob_get_clean();
require APP_PATH . '/Views/layouts/main.php';