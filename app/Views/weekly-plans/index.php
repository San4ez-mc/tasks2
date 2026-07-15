<?php
$title = 'План-факт';
$layout_container_class = 'container-wide';

$current_user = get_user() ?? [];

$format_minutes = static function ($minutes): string {
    $minutes = (int) $minutes;
    if ($minutes <= 0) {
        return '0 хв';
    }

    $hours = intdiv($minutes, 60);
    $rest = $minutes % 60;

    if ($hours > 0 && $rest > 0) {
        return $hours . 'г ' . $rest . 'хв';
    }

    if ($hours > 0) {
        return $hours . 'г';
    }

    return $rest . 'хв';
};

$show_grouped_employee_sections = in_array((string) ($scope ?? ''), ['all', 'company'], true);

ob_start();
?>
<style>
    .planfact-topbar {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 16px;
        flex-wrap: wrap;
        margin-bottom: 20px;
    }

    .planfact-title h1 {
        font-size: 28px;
        margin-bottom: 6px;
        color: #102034;
    }

    .planfact-title p {
        color: #617085;
        max-width: 760px;
    }

    .planfact-scope-switch {
        display: inline-flex;
        gap: 8px;
        flex-wrap: wrap;
        margin-top: 12px;
    }

    .planfact-scope-link {
        text-decoration: none;
        color: #17324d;
        background: #eef4fb;
        border: 1px solid #d7e2ef;
        border-radius: 999px;
        padding: 10px 14px;
        font-weight: 700;
    }

    .planfact-scope-link.is-active {
        background: #102034;
        color: #fff;
        border-color: #102034;
    }

    .planfact-summary-grid {
        display: grid;
        grid-template-columns: 1.2fr .8fr;
        gap: 16px;
        margin-bottom: 18px;
    }

    .planfact-hero,
    .planfact-note {
        background: linear-gradient(135deg, #ffffff 0%, #f6f9fc 100%);
        border: 1px solid #d7e2ef;
        border-radius: 18px;
        padding: 20px;
        box-shadow: 0 16px 32px rgba(16, 32, 52, .06);
    }

    .planfact-note {
        background: linear-gradient(135deg, #f2fbf6 0%, #fcfffd 100%);
    }

    .planfact-note h2 {
        font-size: 18px;
        margin-bottom: 8px;
        color: #0e7a4f;
    }

    .planfact-note p {
        color: #4d5c70;
        line-height: 1.5;
    }

    .planfact-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 16px;
    }

    .planfact-group-stack {
        display: grid;
        gap: 18px;
    }

    .planfact-group {
        display: grid;
        gap: 12px;
    }

    .planfact-group-header {
        display: flex;
        align-items: baseline;
        gap: 10px;
        flex-wrap: wrap;
    }

    .planfact-group-header h2 {
        margin: 0;
        font-size: 24px;
        color: #102034;
    }

    .planfact-group-header p {
        margin: 0;
        color: #627388;
        font-size: 14px;
    }

    .plan-card {
        background: #fff;
        border: 1px solid #dbe5ef;
        border-radius: 18px;
        padding: 18px;
        box-shadow: 0 12px 30px rgba(15, 23, 42, .05);
        display: flex;
        flex-direction: column;
        gap: 14px;
    }

    .plan-card-header {
        display: flex;
        justify-content: space-between;
        gap: 14px;
        align-items: flex-start;
    }

    .plan-card-header h3 {
        font-size: 20px;
        color: #12243a;
        margin-bottom: 6px;
    }

    .plan-card-header p {
        color: #627388;
        font-size: 14px;
    }

    .plan-card-actions {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
    }

    .plan-history-list {
        display: flex;
        flex-direction: column;
        gap: 14px;
    }

    .plan-history-item {
        border: 1px solid #dbe5ef;
        background: #f8fbff;
        border-radius: 16px;
        padding: 16px;
    }

    .plan-history-head {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 12px;
        margin-bottom: 14px;
    }

    .plan-history-head p {
        color: #627388;
        font-size: 14px;
        margin-top: 4px;
    }

    /* Ряд діаграм */
    .plan-history-charts-row {
        display: flex;
        gap: 16px;
        align-items: flex-start;
        flex-wrap: wrap;
        margin-bottom: 14px;
    }

    .plan-history-chart-item {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 6px;
        flex: 1;
        min-width: 120px;
    }

    .plan-history-ring-wrap {
        position: relative;
        width: 120px;
        height: 120px;
        display: grid;
        place-items: center;
        flex-shrink: 0;
    }

    .plan-history-ring {
        width: 120px;
        height: 120px;
        border-radius: 50%;
        display: grid;
        place-items: center;
    }

    .plan-history-ring::after {
        content: '';
        width: 84px;
        height: 84px;
        background: #f8fbff;
        border-radius: 50%;
        box-shadow: inset 0 0 0 1px #dbe6ef;
    }

    .plan-history-ring-value {
        position: absolute;
        font-size: 18px;
        font-weight: 800;
        color: #102034;
        text-align: center;
        line-height: 1.1;
    }

    .plan-history-ring-value small {
        display: block;
        font-size: 11px;
        font-weight: 600;
        color: #708197;
    }

    .plan-history-chart-label {
        font-size: 11px;
        font-weight: 700;
        color: #708197;
        text-transform: uppercase;
        letter-spacing: .05em;
        text-align: center;
    }

    .plan-history-chart-sublabel {
        font-size: 11.5px;
        color: #94a3b8;
        text-align: center;
    }

    .btn-primary,
    .btn-secondary,
    .btn-danger {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        text-decoration: none;
        border-radius: 10px;
        padding: 10px 14px;
        font-weight: 700;
        border: 1px solid transparent;
        cursor: pointer;
    }

    .btn-primary {
        background: #102034;
        color: #fff;
    }

    .btn-secondary {
        background: #eef4fb;
        color: #17324d;
        border-color: #d7e2ef;
    }

    .btn-danger {
        background: #fff1f2;
        color: #b42318;
        border-color: #fecdd3;
    }

    .plan-metrics {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 10px;
    }

    .plan-metric {
        background: #f7fafd;
        border: 1px solid #dce6f2;
        border-radius: 14px;
        padding: 12px;
    }

    .plan-metric-label {
        font-size: 12px;
        color: #708197;
        text-transform: uppercase;
        letter-spacing: .04em;
        margin-bottom: 4px;
    }

    .plan-metric-value {
        font-size: 22px;
        font-weight: 800;
        color: #102034;
    }

    .plan-empty {
        color: #64748b;
        background: #f8fafc;
        border: 1px dashed #cdd8e5;
        border-radius: 14px;
        padding: 16px;
    }

    .plan-create-form {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
    }

    .plan-create-form input[type="hidden"] {
        display: none;
    }

    .plan-create-form input[type="date"] {
        border-radius: 12px;
        border: 1px solid #d2dae5;
        padding: 10px 12px;
        font: inherit;
        min-width: 180px;
    }

    .plan-create-form textarea {
        width: 100%;
        min-height: 80px;
        border-radius: 12px;
        border: 1px solid #d2dae5;
        padding: 12px;
        font: inherit;
        resize: vertical;
    }

    .plan-badge {
        display: inline-flex;
        align-items: center;
        border-radius: 999px;
        padding: 6px 10px;
        background: #e8f1ff;
        color: #173b63;
        font-size: 12px;
        font-weight: 700;
    }

    .plan-scope-note {
        margin-top: 8px;
        color: #5f6f84;
        font-size: 14px;
    }

    @media (max-width: 1024px) {

        .planfact-summary-grid,
        .planfact-grid,
        .plan-metrics {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 768px) {
        .planfact-topbar {
            align-items: stretch;
        }

        .plan-card-actions,
        .planfact-scope-switch {
            width: 100%;
        }

        .planfact-scope-link,
        .btn-primary,
        .btn-secondary {
            width: 100%;
        }
    }

    @media (max-width: 480px) {

        .plan-card,
        .planfact-hero,
        .planfact-note {
            padding: 16px;
        }

        .plan-card-header {
            flex-direction: column;
        }
    }
</style>

<div class="planfact-topbar">
    <div class="planfact-title">
        <h1>План-факт по команді</h1>
        <p>У списку відображаються всі plan-fact без фільтра по даті.</p>
        <div class="planfact-scope-switch">
            <a class="planfact-scope-link <?php echo $scope === 'my' ? 'is-active' : ''; ?>"
                href="/weekly-plans?scope=my">Мої</a>
            <a class="planfact-scope-link <?php echo $scope === 'subordinates' ? 'is-active' : ''; ?>"
                href="/weekly-plans?scope=subordinates">Підлеглі</a>
            <a class="planfact-scope-link <?php echo $scope === 'all' ? 'is-active' : ''; ?>"
                href="/weekly-plans?scope=all">Всі</a>
            <?php if (!empty($isOwner)): ?>
                <a class="planfact-scope-link <?php echo $scope === 'company' ? 'is-active' : ''; ?>"
                    href="/weekly-plans?scope=company">Вся компанія</a>
            <?php endif; ?>
        </div>
    </div>
    <a class="btn-primary" href="/weekly-plans/create">Додати план-факт</a>
</div>

<?php if (empty($employees)): ?>
    <div class="plan-empty">Для вашого акаунта не знайдено жодного доступного plan-fact. Ви бачитимете лише себе і
        співробітників, де зазначені керівником.</div>
<?php else: ?>
    <div class="<?php echo $show_grouped_employee_sections ? 'planfact-group-stack' : 'planfact-grid'; ?>">
        <?php foreach ($employees as $employee): ?>
            <?php
            $employee_user_id = (int) ($employee['user_id'] ?? 0);
            $employee_name = trim((string) ($employee['first_name'] ?? '') . ' ' . (string) ($employee['last_name'] ?? '')) ?: ('Користувач #' . $employee_user_id);
            $employee_title = trim((string) ($employee['title'] ?? ''));
            $employeePlans = $plansByUser[$employee_user_id] ?? [];
            ?>
            <?php if ($show_grouped_employee_sections): ?>
                <section class="planfact-group">
                    <div class="planfact-group-header">
                        <h2><?php echo htmlspecialchars($employee_name); ?></h2>
                        <p><?php echo $employee_title !== '' ? htmlspecialchars($employee_title) : 'Учасник команди'; ?></p>
                    </div>
                    <section class="plan-card">
                    <?php else: ?>
                        <section class="plan-card">
                        <?php endif; ?>
                        <div class="plan-card-header">
                            <div>
                                <h3><?php echo htmlspecialchars($employee_name); ?></h3>
                                <p>
                                    <?php echo $employee_title !== '' ? htmlspecialchars($employee_title) : 'Учасник команди'; ?>
                                    <?php if ((int) ($current_user['id'] ?? 0) === $employee_user_id): ?>
                                        • Це ваш план
                                    <?php endif; ?>
                                </p>
                            </div>
                            <?php if (!empty($employeePlans)): ?>
                                <span class="plan-badge">Планів: <?php echo count($employeePlans); ?></span>
                            <?php else: ?>
                                <span class="plan-badge" style="background:#fff4e8;color:#945d08;">Ще не створено</span>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($employeePlans)): ?>
                            <div class="plan-history-list">
                                <?php foreach ($employeePlans as $plan): ?>
                                    <?php $summary = $planSummaries[(int) ($plan['id'] ?? 0)] ?? null; ?>
                                    <div class="plan-history-item">
                                        <div class="plan-history-head">
                                            <div>
                                                <strong>
                                                    <?php echo htmlspecialchars(date('d.m.Y', strtotime((string) $plan['week_start_date']))); ?>
                                                    -
                                                    <?php echo htmlspecialchars(date('d.m.Y', strtotime((string) $plan['week_end_date']))); ?>
                                                </strong>
                                                <p>Створено:
                                                    <?php echo htmlspecialchars(date('d.m.Y H:i', strtotime((string) $plan['created_at']))); ?>
                                                </p>
                                            </div>
                                            <span class="plan-badge">#<?php echo (int) ($plan['id'] ?? 0); ?></span>
                                        </div>
                                        <?php if ($summary): ?>
                                            <?php
                                            $pct = (int) ($summary['completion_rate'] ?? 0);
                                            $totalMin = (int) ($summary['total_minutes'] ?? 0);
                                            $actualMin = (int) ($summary['actual_minutes'] ?? 0);
                                            $totalItems = (int) ($summary['total_items'] ?? 0);
                                            $doneItems = (int) ($summary['completed_items'] ?? 0);
                                            $unplanned = (int) ($summary['unplanned_count'] ?? 0);
                                            $weekTotal = (int) ($summary['week_task_count'] ?? 0);

                                            // Кружок 1: % виконання плану
                                            $ring1 = 'conic-gradient(#1b7f5a 0 ' . $pct . '%, #dbe6ef ' . $pct . '% 100%)';

                                            // Кружок 2: співвідношення факт/план часу
                                            $timePct = $totalMin > 0 ? min(100, (int) round($actualMin / $totalMin * 100)) : 0;
                                            $ring2 = 'conic-gradient(#2563eb 0 ' . $timePct . '%, #dbe6ef ' . $timePct . '% 100%)';

                                            // Кружок 3: позапланові відносно всіх задач тижня
                                            $unplannedPct = $weekTotal > 0 ? min(100, (int) round($unplanned / $weekTotal * 100)) : 0;
                                            $ring3 = 'conic-gradient(#d97706 0 ' . $unplannedPct . '%, #dbe6ef ' . $unplannedPct . '% 100%)';
                                            ?>
                                            <div class="plan-history-charts-row">
                                                <div class="plan-history-chart-item">
                                                    <div class="plan-history-ring-wrap">
                                                        <div class="plan-history-ring" style="background:<?php echo $ring1; ?>;"></div>
                                                        <div class="plan-history-ring-value"><?php echo $pct; ?>%</div>
                                                    </div>
                                                    <div class="plan-history-chart-label">Виконання плану</div>
                                                    <div class="plan-history-chart-sublabel"><?php echo $doneItems; ?> /
                                                        <?php echo $totalItems; ?> задач</div>
                                                </div>
                                                <div class="plan-history-chart-item">
                                                    <div class="plan-history-ring-wrap">
                                                        <div class="plan-history-ring" style="background:<?php echo $ring2; ?>;"></div>
                                                        <div class="plan-history-ring-value">
                                                            <?php echo htmlspecialchars($format_minutes($actualMin)); ?>
                                                            <small>з <?php echo htmlspecialchars($format_minutes($totalMin)); ?></small>
                                                        </div>
                                                    </div>
                                                    <div class="plan-history-chart-label">Факт / план часу</div>
                                                    <div class="plan-history-chart-sublabel"><?php echo $timePct; ?>% від плану</div>
                                                </div>
                                                <div class="plan-history-chart-item">
                                                    <div class="plan-history-ring-wrap">
                                                        <div class="plan-history-ring" style="background:<?php echo $ring3; ?>;"></div>
                                                        <div class="plan-history-ring-value"><?php echo $unplanned; ?><small>поза
                                                                планом</small></div>
                                                    </div>
                                                    <div class="plan-history-chart-label">Позапланові задачі</div>
                                                    <div class="plan-history-chart-sublabel"><?php echo $unplannedPct; ?>% від тижня</div>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                        <div class="plan-card-actions" style="margin-top:12px;">
                                            <a class="btn-primary" href="/weekly-plans/view/<?php echo (int) $plan['id']; ?>">Відкрити
                                                план-факт</a>
                                            <form method="post" action="/weekly-plans/delete/<?php echo (int) $plan['id']; ?>"
                                                onsubmit="return confirm('Видалити цей план-факт повністю разом з елементами плану і пов\'язаними задачами?');">
                                                <button class="btn-danger" type="submit">Видалити</button>
                                            </form>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="plan-empty">Для цього співробітника ще немає жодного plan-fact. Можна створити новий для
                                поточного
                                тижня.</div>
                        <?php endif; ?>
                        <div class="plan-card-actions" style="margin-top:12px;">
                            <a class="btn-secondary"
                                href="/weekly-plans/create?user_id=<?php echo $employee_user_id; ?>&week_start=<?php echo urlencode($weekStart); ?>">Створити
                                новий план</a>
                        </div>
                    </section>
                    <?php if ($show_grouped_employee_sections): ?>
                    </section>
                <?php endif; ?>
            <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php
$content = ob_get_clean();
require APP_PATH . '/Views/layouts/main.php';
?>