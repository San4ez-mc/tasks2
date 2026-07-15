<?php
/**
 * Дашборд
 */
$title = 'Дашборд';
$extra_head = '<link rel="stylesheet" href="/public/css/dashboard.css">';
$extra_scripts = '<script src="/public/js/dashboard.js"></script>';

ob_start();
?>
<section class="dashboard-page">

    <?php
    $result_total = max(1, (int) $total_results);
    $result_done_pct = (int) round(((int) $result_status_counts['completed'] / $result_total) * 100);
    $result_progress_pct = (int) round(((int) $result_status_counts['in-progress'] / $result_total) * 100);
    $result_hold_pct = (int) round(((int) $result_status_counts['on-hold'] / $result_total) * 100);
    $result_other_pct = max(0, 100 - $result_done_pct - $result_progress_pct - $result_hold_pct);

    $template_total = max(1, (int) $total_templates);
    $template_daily_pct = (int) round(((int) $template_repeat_counts['daily'] / $template_total) * 100);
    $template_weekly_pct = (int) round(((int) $template_repeat_counts['weekly'] / $template_total) * 100);
    $template_monthly_pct = (int) round(((int) $template_repeat_counts['monthly'] / $template_total) * 100);
    $template_none_pct = max(0, 100 - $template_daily_pct - $template_weekly_pct - $template_monthly_pct);
    ?>

    <div class="surface">
        <header class="headline">
            <h2>Дашборд</h2>
            <div class="subtitle">Зріз по задачах, план-фактах, цілях і шаблонах</div>
        </header>

        <div class="stats-grid">
            <article class="stat-card">
                <div class="stat-label">Всього задач</div>
                <div class="stat-value"><?php echo (int) $total_tasks; ?></div>
                <div class="stat-sub">Виконання: <?php echo (int) $tasks_completion_rate; ?>%</div>
            </article>
            <article class="stat-card">
                <div class="stat-label">План-факт планів</div>
                <div class="stat-value"><?php echo (int) count($weekly_plans); ?></div>
                <div class="stat-sub">Пункти виконано: <?php echo (int) $weekly_plan_items_done; ?> /
                    <?php echo (int) $weekly_plan_items_total; ?>
                </div>
            </article>
            <article class="stat-card">
                <div class="stat-label">Цілі</div>
                <div class="stat-value"><?php echo (int) $total_results; ?></div>
                <div class="stat-sub">Завершено: <?php echo (int) $completed_results; ?>
                    (<?php echo (int) $results_completion_rate; ?>%)</div>
            </article>
            <article class="stat-card">
                <div class="stat-label">Шаблони</div>
                <div class="stat-value"><?php echo (int) $total_templates; ?></div>
                <div class="stat-sub">Daily: <?php echo (int) $template_repeat_counts['daily']; ?> | Weekly:
                    <?php echo (int) $template_repeat_counts['weekly']; ?>
                </div>
            </article>
        </div>

        <div class="charts-grid">
            <article class="chart-card">
                <div class="chart-title">План-факт</div>
                <div class="chart-subtitle">Завершені пункти у ваших тижневих планах</div>
                <div class="ring-wrap">
                    <div class="ring"><span><?php echo (int) $plan_fact_completion_rate; ?>%</span></div>
                </div>
                <div class="legend">
                    <div class="legend-item">
                        <span>Виконано</span>
                        <small><?php echo (int) $weekly_plan_items_done; ?></small>
                    </div>
                    <div class="legend-item">
                        <span>Всього пунктів</span>
                        <small><?php echo (int) $weekly_plan_items_total; ?></small>
                    </div>
                    <div class="legend-item">
                        <span>Активних планів</span>
                        <small><?php echo (int) count($weekly_plans); ?></small>
                    </div>
                </div>
            </article>

            <article class="chart-card">
                <div class="chart-title">Цілі</div>
                <div class="chart-subtitle">Структура статусів по ваших цілях</div>
                <div class="stacked" aria-hidden="true">
                    <div class="seg done" data-pct="<?php echo $result_done_pct; ?>"></div>
                    <div class="seg progress" data-pct="<?php echo $result_progress_pct; ?>"></div>
                    <div class="seg hold" data-pct="<?php echo $result_hold_pct; ?>"></div>
                    <div class="seg other" data-pct="<?php echo $result_other_pct; ?>"></div>
                </div>
                <div class="legend">
                    <div class="legend-item">
                        <span>Завершені</span><small><?php echo (int) $result_status_counts['completed']; ?></small>
                    </div>
                    <div class="legend-item"><span>В
                            процесі</span><small><?php echo (int) $result_status_counts['in-progress']; ?></small></div>
                    <div class="legend-item"><span>На
                            паузі</span><small><?php echo (int) $result_status_counts['on-hold']; ?></small></div>
                    <div class="legend-item">
                        <span>Інші</span><small><?php echo (int) $result_status_counts['other']; ?></small>
                    </div>
                </div>
            </article>

            <article class="chart-card">
                <div class="chart-title">Шаблони</div>
                <div class="chart-subtitle">Розподіл шаблонів за періодичністю</div>
                <div class="bars">
                    <div class="bar-row">
                        <div class="bar-label">Daily</div>
                        <div class="bar-track">
                            <div class="bar-fill" data-pct="<?php echo $template_daily_pct; ?>"></div>
                        </div>
                        <div class="bar-value"><?php echo (int) $template_repeat_counts['daily']; ?></div>
                    </div>
                    <div class="bar-row">
                        <div class="bar-label">Weekly</div>
                        <div class="bar-track">
                            <div class="bar-fill" data-pct="<?php echo $template_weekly_pct; ?>"></div>
                        </div>
                        <div class="bar-value"><?php echo (int) $template_repeat_counts['weekly']; ?></div>
                    </div>
                    <div class="bar-row">
                        <div class="bar-label">Monthly</div>
                        <div class="bar-track">
                            <div class="bar-fill" data-pct="<?php echo $template_monthly_pct; ?>"></div>
                        </div>
                        <div class="bar-value"><?php echo (int) $template_repeat_counts['monthly']; ?></div>
                    </div>
                    <div class="bar-row">
                        <div class="bar-label">None</div>
                        <div class="bar-track">
                            <div class="bar-fill" data-pct="<?php echo $template_none_pct; ?>"></div>
                        </div>
                        <div class="bar-value"><?php echo (int) $template_repeat_counts['none']; ?></div>
                    </div>
                </div>
            </article>
        </div>
    </div>

</section>
<?php
$content = ob_get_clean();
require APP_PATH . '/Views/layouts/main.php';
