<?php
$format_minutes = static function ($minutes) {
    $hours = floor($minutes / 60);
    $rest = $minutes % 60;
    if ($hours > 0) {
        return $hours . 'г';
    }
    return $rest . 'хв';
};

$extract_time = static function ($value) {
    $rawValue = trim((string) $value);
    if ($rawValue === '' || !preg_match('/\b(?:[01]\d|2[0-3]):[0-5]\d\b/', $rawValue, $matches)) {
        return '';
    }
    return $matches[0];
};

$type_labels = [
    'important-urgent' => 'Важливі термінові',
    'important-not-urgent' => 'Важливі нетермінові',
    'not-important-urgent' => 'Неважливі термінові',
    'not-important-not-urgent' => 'Неважливі нетермінові',
];

$status_labels = [
    'todo' => 'Нова',
    'in-progress' => 'В процесі',
    'done' => 'Виконано',
    'completed' => 'Виконано',
    'postponed' => 'Перенесено',
];

$assignee_options = [];
foreach ($employees as $employee) {
    $employeeId = (int) ($employee['user_id'] ?? 0);
    if ($employeeId <= 0) {
        continue;
    }
    $assignee_options[$employeeId] = trim((string) ($employee['first_name'] ?? '') . ' ' . (string) ($employee['last_name'] ?? ''));
}

$progress = (int) ($planStats['completion_rate'] ?? 0);
$progress_circle = 'conic-gradient(#1b7f5a 0 ' . $progress . '%, #dbe6ef ' . $progress . '% 100%)';
$unplanned_ids = array_map(static fn($task) => (int) ($task['id'] ?? 0), $unplannedTasks);
$flash_success = flash('success');
$flash_error = flash('error');
$googleCalendarEnabled = defined('GOOGLE_CLIENT_ID') && trim((string) GOOGLE_CLIENT_ID) !== '';
$is_weekend = static function (string $date): bool {
    $dayNumber = (int) date('N', strtotime($date));
    return in_array($dayNumber, [6, 7], true);
};

$extra_head = '<link rel="stylesheet" href="/public/css/weekly-plans.css"><link rel="stylesheet" href="/public/css/overdue-popup.css">';
$extra_scripts = '<script src="/public/js/weekly-plans.js"></script><script src="/public/js/overdue-popup.js"></script>';

ob_start();
?>
<script>
    window.WP = {
        updateFactTaskUrl: '/weekly-plans/update-fact-task/<?php echo (int) $plan['id']; ?>',
        googleCalendarEnabled: <?php echo $googleCalendarEnabled ? 'true' : 'false'; ?>,
        googleClientId: <?php echo json_encode($googleCalendarEnabled ? (string) GOOGLE_CLIENT_ID : ''); ?>,
        weekStart: <?php echo json_encode((string) $plan['week_start_date']); ?>,
        weekEnd: <?php echo json_encode((string) $plan['week_end_date']); ?>,
        typeLabels: <?php echo json_encode($type_labels, JSON_UNESCAPED_UNICODE); ?>,
        templates: <?php echo json_encode(array_values(array_map(static function ($t) {
            return [
                'id'              => (int) ($t['id'] ?? 0),
                'name'            => (string) ($t['name'] ?? ''),
                'type'            => (string) ($t['type'] ?? ''),
                'description'     => (string) ($t['description'] ?? ''),
                'expected_result' => (string) ($t['expected_result'] ?? ''),
                'expected_time'   => isset($t['expected_time']) ? (int) $t['expected_time'] : null,
                'start_time'      => (string) ($t['start_time'] ?? ''),
            ];
        }, $templates ?? [])), JSON_UNESCAPED_UNICODE); ?>,
        results: <?php echo json_encode(array_values(array_map(static function ($r) {
            return [
                'id'     => (int) ($r['id'] ?? 0),
                'title'  => (string) ($r['title'] ?? ''),
                'indent' => !empty($r['_indent']),
            ];
        }, $results_flat ?? [])), JSON_UNESCAPED_UNICODE); ?>
    };
    window.OVERDUE_TASKS = <?php echo json_encode(array_values(array_map(static function ($t) {
        return [
            'id' => (int) ($t['id'] ?? 0),
            'title' => (string) ($t['title'] ?? ''),
            'due_date' => (string) ($t['due_date'] ?? ''),
            'result_id' => isset($t['result_id']) ? (int) $t['result_id'] : null,
            'result_title' => (string) ($t['result_title'] ?? ''),
        ];
    }, $overdue_tasks ?? [])), JSON_UNESCAPED_UNICODE); ?>;
</script>

<div class="pf-shell">
    <?php if ($flash_success || $flash_error): ?>
        <div class="pf-flash-stack">
            <?php if ($flash_success): ?>
                <div class="pf-flash success"><?php echo htmlspecialchars($flash_success); ?></div>
            <?php endif; ?>
            <?php if ($flash_error): ?>
                <div class="pf-flash error"><?php echo htmlspecialchars($flash_error); ?></div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="pf-header">
        <div class="pf-header-main">
            <div>
                <h1>План-факт:
                    <?php echo htmlspecialchars(trim((string) ($plan['first_name'] ?? '') . ' ' . (string) ($plan['last_name'] ?? ''))); ?>
                </h1>
                <p>Тиждень <?php echo htmlspecialchars(date('d.m.Y', strtotime((string) $plan['week_start_date']))); ?>
                    -
                    <?php echo htmlspecialchars(date('d.m.Y', strtotime((string) $plan['week_end_date']))); ?>. Ліворуч
                    збережений первинний план, праворуч факт і позапланові задачі за цей самий тиждень.
                </p>
            </div>
        </div>

        <div class="pf-toolbar">
            <div class="pf-tabbar">
                <span class="pf-tab is-active">План-факт</span>
                <a class="pf-tab"
                    href="/tasks?tab=my&date=<?php echo urlencode((string) $plan['week_start_date']); ?>">Щоденні
                    задачі</a>
            </div>

            <div class="pf-actions-top">
                <a class="pf-btn-secondary" href="/weekly-plans">До списку
                    планів</a>
                <button type="button" class="pf-btn-secondary" id="pfGoogleImportBtn" <?php echo $googleCalendarEnabled ? '' : 'disabled'; ?>>Імпорт з Google Calendar Beta</button>
                <label class="pf-settings-toggle" for="pfHideWeekendsToggle">
                    <input type="checkbox" id="pfHideWeekendsToggle">
                    <span>Приховати вихідні</span>
                </label>
                <form method="post" action="/weekly-plans/delete/<?php echo (int) $plan['id']; ?>"
                    onsubmit="return confirm('Видалити цей план-факт повністю разом з елементами плану і пов\'язаними задачами?');"
                    style="display:inline-flex;">
                    <button type="submit" class="pf-btn-danger">Видалити план-факт</button>
                </form>
            </div>
        </div>
    </div>

    <div class="pf-overview">
        <section class="pf-panel">
            <h2>Виконання початкового плану</h2>
            <div class="pf-progress-grid">
                <div class="pf-progress-wrap">
                    <div class="pf-progress-ring" style="background: <?php echo $progress_circle; ?>;"></div>
                    <div class="pf-progress-value"><?php echo $progress; ?>%</div>
                </div>
                <div>
                    <p>Круг і ключові графіки вище рахуються тільки по задачах, які були у початковому плані. Нові
                        задачі теж показуються у факті, але не спотворюють відсоток виконання плану.</p>
                    <div class="pf-metric-grid">
                        <div class="pf-metric">
                            <div class="pf-metric-label">Планових задач</div>
                            <div class="pf-metric-value"><?php echo (int) ($planStats['total_items'] ?? 0); ?></div>
                        </div>
                        <div class="pf-metric">
                            <div class="pf-metric-label">Виконано</div>
                            <div class="pf-metric-value"><?php echo (int) ($planStats['completed_items'] ?? 0); ?></div>
                        </div>
                        <div class="pf-metric">
                            <div class="pf-metric-label">Плановий час</div>
                            <div class="pf-metric-value" style="font-size:18px;">
                                <?php echo htmlspecialchars($format_minutes($planStats['total_minutes'] ?? 0)); ?>
                            </div>
                        </div>
                        <div class="pf-metric">
                            <div class="pf-metric-label">Факт часу</div>
                            <div class="pf-metric-value" style="font-size:18px;">
                                <?php echo htmlspecialchars($format_minutes($factStats['actual_minutes'] ?? 0)); ?>
                            </div>
                        </div>
                        <div class="pf-metric">
                            <div class="pf-metric-label">Позапланових</div>
                            <div class="pf-metric-value"><?php echo (int) ($factStats['unplanned_count'] ?? 0); ?></div>
                        </div>
                        <div class="pf-metric">
                            <div class="pf-metric-label">Перенесено</div>
                            <div class="pf-metric-value"><?php echo (int) ($factStats['moved_planned_tasks'] ?? 0); ?>
                            </div>
                        </div>
                        <div class="pf-metric">
                            <div class="pf-metric-label">Плановий факт-час</div>
                            <div class="pf-metric-value" style="font-size:18px;">
                                <?php echo htmlspecialchars($format_minutes($factStats['planned_actual_minutes'] ?? 0)); ?>
                            </div>
                        </div>
                        <div class="pf-metric">
                            <div class="pf-metric-label">Відхилення</div>
                            <div class="pf-metric-value" style="font-size:18px;">
                                <?php echo htmlspecialchars(($factStats['variance_minutes'] ?? 0) >= 0 ? '+' : '') . htmlspecialchars($format_minutes(abs((int) ($factStats['variance_minutes'] ?? 0)))); ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="pf-panel">
            <h2>Підказки</h2>
            <div class="pf-alerts">
                <?php foreach ($planAlerts as $alert): ?>
                    <div class="pf-alert pf-alert-<?php echo htmlspecialchars((string) ($alert['level'] ?? 'info')); ?>">
                        <?php echo htmlspecialchars((string) ($alert['text'] ?? '')); ?>
                    </div>
                <?php endforeach; ?>
                <?php foreach ($factAlerts as $alert): ?>
                    <div class="pf-alert pf-alert-<?php echo htmlspecialchars((string) ($alert['level'] ?? 'info')); ?>">
                        <?php echo htmlspecialchars((string) ($alert['text'] ?? '')); ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    </div>

    <div class="pf-forms">
        <section class="pf-form-card is-collapsed">
            <div class="pf-form-card-header" onclick="this.closest('.pf-form-card').classList.toggle('is-collapsed')">
                <h3>Додати задачу в план</h3>
                <span class="pf-form-card-toggle">&#9660;</span>
            </div>
            <div class="pf-form-card-body">
                <p>Задача одразу потрапить і в тижневий план, і в щоденні задачі користувача.</p>
                <form class="js-pf-plan-add-form" method="post"
                    action="/weekly-plans/add-item/<?php echo (int) $plan['id']; ?>" novalidate>
                    <input type="date" name="planned_date"
                        value="<?php echo htmlspecialchars((string) $plan['week_start_date']); ?>"
                        min="<?php echo htmlspecialchars((string) $plan['week_start_date']); ?>"
                        max="<?php echo htmlspecialchars((string) $plan['week_end_date']); ?>" required>
                    <input type="time" name="start_time" step="60" placeholder="Час старту">
                    <input type="text" name="title" placeholder="Назва задачі *" required pattern=".*\S.*">
                    <textarea name="expected_result" placeholder="Очікуваний результат *"></textarea>
                    <span class="pf-field-error" style="display:none;"></span>
                    <small class="pf-field-hint">Коли заповнене — звужує задачу, не дає робити зайвого. Якщо делегована
                        —
                        виконавець розуміє, що саме очікується, і переробок менше.</small>
                    <input type="number" name="expected_time" min="1" step="5" placeholder="Плановий час, хв *">
                    <span class="pf-field-error" style="display:none;"></span>
                    <small class="pf-field-hint">Дозволяє розуміти, скільки і яких реально задач можна поставити в
                        день.</small>
                    <select name="type">
                        <?php foreach ($type_labels as $type_value => $type_label): ?>
                            <option value="<?php echo htmlspecialchars($type_value); ?>" <?php echo $type_value === 'important-not-urgent' ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($type_label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <select name="result_id">
                        <option value="">Без цілі</option>
                        <?php foreach ($results as $result): ?>
                            <option value="<?php echo (int) ($result['id'] ?? 0); ?>">
                                <?php echo htmlspecialchars((string) ($result['title'] ?? 'Ціль')); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <select name="project_id">
                        <option value="">Без проекту</option>
                        <?php foreach ($projects as $project): ?>
                            <option value="<?php echo (int) ($project['id'] ?? 0); ?>">
                                <?php echo htmlspecialchars((string) ($project['name'] ?? '')); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <select name="assignee_ids[]" multiple
                        size="<?php echo min(5, max(2, count($assignee_options))); ?>">
                        <?php foreach ($assignee_options as $assignee_id => $assignee_name): ?>
                            <option value="<?php echo (int) $assignee_id; ?>" <?php echo (int) $plan['user_id'] === (int) $assignee_id ? 'selected' : ''; ?>><?php echo htmlspecialchars($assignee_name); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small class="pf-field-hint">Утримуйте Ctrl (або Cmd) для вибору кількох виконавців.</small>
                    <textarea name="description" placeholder="Опис / контекст"></textarea>
                    <button type="submit">Додати задачу</button>
                </form>
            </div>
        </section>

        <section class="pf-form-card is-collapsed">
            <div class="pf-form-card-header" onclick="this.closest('.pf-form-card').classList.toggle('is-collapsed')">
                <h3>Заповнити з шаблонів</h3>
                <span class="pf-form-card-toggle">&#9660;</span>
            </div>
            <div class="pf-form-card-body">
                <p>Обрані шаблони будуть додані в конкретний день тижня і створять щоденні задачі.</p>
                <form method="post" action="/weekly-plans/add-templates/<?php echo (int) $plan['id']; ?>">
                    <input type="date" name="planned_date"
                        value="<?php echo htmlspecialchars((string) $plan['week_start_date']); ?>"
                        min="<?php echo htmlspecialchars((string) $plan['week_start_date']); ?>"
                        max="<?php echo htmlspecialchars((string) $plan['week_end_date']); ?>" required>
                    <select name="template_ids[]" multiple size="6" required>
                        <?php foreach ($templates as $template): ?>
                            <option value="<?php echo (int) ($template['id'] ?? 0); ?>">
                                <?php echo htmlspecialchars((string) ($template['name'] ?? 'Шаблон')); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit">Додати шаблони</button>
                </form>
            </div>
        </section>

        <section class="pf-form-card is-collapsed">
            <div class="pf-form-card-header" onclick="this.closest('.pf-form-card').classList.toggle('is-collapsed')">
                <h3>Копіювати між днями</h3>
                <span class="pf-form-card-toggle">&#9660;</span>
            </div>
            <div class="pf-form-card-body">
                <p>Оберіть день-джерело, конкретні задачі (або всі) і день призначення.</p>
                <form method="post" action="/weekly-plans/copy-day/<?php echo (int) $plan['id']; ?>">
                    <select name="source_date" id="pf-copy-source" required>
                        <option value="">З якого дня</option>
                        <?php foreach ($days as $day): ?>
                            <?php $dayDate = (string) $day['date']; ?>
                            <option value="<?php echo htmlspecialchars($dayDate); ?>"
                                data-is-weekend="<?php echo $is_weekend($dayDate) ? '1' : '0'; ?>">
                                <?php echo htmlspecialchars((string) $day['label'] . ' ' . (string) $day['display']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <select name="item_ids[]" id="pf-copy-items" multiple size="5">
                        <?php foreach ($days as $day): ?>
                            <?php $dayDate = (string) $day['date']; ?>
                            <?php foreach ($itemsByDay[$dayDate] ?? [] as $dayItem): ?>
                                <?php
                                $copyItemTitle = trim((string) ($dayItem['title'] ?? ''));
                                if ($copyItemTitle === '') {
                                    $copyItemTitle = trim((string) ($dayItem['task_title'] ?? ''));
                                }
                                if ($copyItemTitle === '') {
                                    $copyItemTitle = trim((string) ($dayItem['template_name'] ?? ''));
                                }
                                if ($copyItemTitle === '') {
                                    $copyItemTitle = 'Задача #' . (int) ($dayItem['id'] ?? 0);
                                }
                                ?>
                                <option value="<?php echo (int) ($dayItem['id'] ?? 0); ?>"
                                    data-day="<?php echo htmlspecialchars($dayDate); ?>"
                                    data-is-weekend="<?php echo $is_weekend($dayDate) ? '1' : '0'; ?>" style="display:none;">
                                    <?php echo htmlspecialchars($copyItemTitle); ?>
                                </option>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </select>
                    <small style="color:#607084;">Якщо нічого не обрано — копіюються всі задачі дня</small>
                    <select name="target_date" required>
                        <option value="">На який день</option>
                        <?php foreach ($days as $day): ?>
                            <?php $dayDate = (string) $day['date']; ?>
                            <option value="<?php echo htmlspecialchars($dayDate); ?>"
                                data-is-weekend="<?php echo $is_weekend($dayDate) ? '1' : '0'; ?>">
                                <?php echo htmlspecialchars((string) $day['label'] . ' ' . (string) $day['display']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit">Скопіювати</button>
                </form>
            </div>
        </section>
    </div>

    <div class="pf-compare-board">
        <div class="pf-compare-head">
            <section class="pf-column-header">
                <h2>План <span style="font-size:14px;font-weight:400;color:#607084;">загалом
                        <?php echo htmlspecialchars($format_minutes($totalPlanMinutes)); ?></span></h2>
                <span>Знімок на момент створення та наповнення плану</span>
            </section>
            <section class="pf-column-header" style="position:relative;">
                <h2 style="display:inline-block;vertical-align:middle;">Факт</h2>
                <span>Усі реальні задачі користувача за цей тиждень</span>
            </section>
        </div>

        <!-- Модалка швидкого додавання задачі у План -->
        <div class="pf-complete-overlay" id="pfPlanQuickAddOverlay"></div>
        <div class="pf-qa-modal" id="pfPlanQuickAddModal" aria-hidden="true">
            <div class="pf-qa-header">
                <h3>Додати задачу в План</h3>
                <button type="button" class="pf-qa-close" id="pfPlanQuickAddCancel" aria-label="Закрити">×</button>
            </div>
            <form id="pfPlanQuickAddForm" method="post" action="/weekly-plans/add-item/<?php echo (int) $plan['id']; ?>"
                novalidate>
                <div class="pf-qa-body">
                    <div class="pf-qa-row">
                        <div class="pf-qa-field">
                            <label>Дата</label>
                            <input type="date" name="planned_date" id="pfPlanQaDate"
                                value="<?php echo htmlspecialchars(date('Y-m-d')); ?>"
                                min="<?php echo htmlspecialchars((string) $plan['week_start_date']); ?>"
                                max="<?php echo htmlspecialchars((string) $plan['week_end_date']); ?>" required>
                        </div>
                        <div class="pf-qa-field">
                            <label>Час старту</label>
                            <input type="time" name="start_time" step="60" placeholder="09:00">
                        </div>
                    </div>
                    <div class="pf-qa-field">
                        <label>Назва задачі <span class="pf-qa-req">*</span></label>
                        <input type="text" name="title" placeholder="Назва задачі" required pattern=".*\S.*">
                    </div>
                    <div class="pf-qa-field">
                        <label>Очікуваний результат <span class="pf-qa-req">*</span></label>
                        <textarea name="expected_result" placeholder="Що має бути зроблено…" rows="2"></textarea>
                        <span class="pf-field-error" style="display:none;"></span>
                        <span class="pf-field-hint">Коли заповнене — звужує задачу, не дає робити зайвого.</span>
                    </div>
                    <div class="pf-qa-row">
                        <div class="pf-qa-field">
                            <label>Плановий час, хв <span class="pf-qa-req">*</span></label>
                            <input type="number" name="expected_time" min="1" step="5" placeholder="0">
                            <span class="pf-field-error" style="display:none;"></span>
                        </div>
                        <div class="pf-qa-field">
                            <label>Тип</label>
                            <select name="type">
                                <?php foreach ($type_labels as $type_value => $type_label): ?>
                                    <option value="<?php echo htmlspecialchars($type_value); ?>" <?php echo $type_value === 'important-not-urgent' ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($type_label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="pf-qa-field">
                        <label>Відповідальні</label>
                        <select name="assignee_ids[]" multiple
                            size="<?php echo min(5, max(2, count($assignee_options))); ?>">
                            <?php foreach ($assignee_options as $assignee_id => $assignee_name): ?>
                                <option value="<?php echo (int) $assignee_id; ?>" <?php echo (int) $plan['user_id'] === (int) $assignee_id ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($assignee_name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <span class="pf-field-hint">Ctrl (або Cmd) — вибір кількох виконавців.</span>
                    </div>
                    <div class="pf-qa-field">
                        <label>Проект</label>
                        <select name="project_id">
                            <option value="">Без проекту</option>
                            <?php foreach ($projects as $project): ?>
                                <option value="<?php echo (int) ($project['id'] ?? 0); ?>">
                                    <?php echo htmlspecialchars((string) ($project['name'] ?? '')); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="pf-qa-field">
                        <label>Ціль</label>
                        <select name="result_id">
                            <option value="">Без цілі</option>
                            <?php foreach ($results_flat as $rf): ?>
                                <option value="<?php echo (int) ($rf['id'] ?? 0); ?>">
                                    <?php echo ($rf['_indent'] ? '— ' : '') . htmlspecialchars((string) ($rf['title'] ?? 'Ціль')); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="pf-qa-field">
                        <label>Опис</label>
                        <textarea name="description" placeholder="Контекст / примітки…" rows="2"></textarea>
                    </div>
                    <?php if (!empty($templates)): ?>
                    <div class="pf-qa-section">
                        <div class="pf-qa-section-label">Шаблони — клік заповнює поля</div>
                        <div class="pf-qa-chips" id="pfPlanTemplateChips">
                            <?php foreach ($templates as $tpl): ?>
                            <button type="button" class="pf-qa-chip pf-qa-chip--template"
                                data-modal="plan"
                                data-name="<?php echo htmlspecialchars((string)($tpl['name'] ?? '')); ?>"
                                data-type="<?php echo htmlspecialchars((string)($tpl['type'] ?? '')); ?>"
                                data-description="<?php echo htmlspecialchars((string)($tpl['description'] ?? '')); ?>"
                                data-expected-result="<?php echo htmlspecialchars((string)($tpl['expected_result'] ?? '')); ?>"
                                data-expected-time="<?php echo (int)($tpl['expected_time'] ?? 0); ?>"
                                data-start-time="<?php echo htmlspecialchars((string)($tpl['start_time'] ?? '')); ?>">
                                <?php echo htmlspecialchars((string)($tpl['name'] ?? 'Шаблон')); ?>
                            </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($results_flat)): ?>
                    <div class="pf-qa-section">
                        <div class="pf-qa-section-label">Цілі — клік встановлює ціль</div>
                        <div class="pf-qa-chips" id="pfPlanResultChips">
                            <?php foreach ($results_flat as $rf): ?>
                            <button type="button" class="pf-qa-chip pf-qa-chip--result<?php echo !empty($rf['_indent']) ? ' pf-qa-chip--indent' : ''; ?>"
                                data-modal="plan"
                                data-result-id="<?php echo (int)($rf['id'] ?? 0); ?>"
                                data-result-title="<?php echo htmlspecialchars((string)($rf['title'] ?? '')); ?>">
                                <?php echo (!empty($rf['_indent']) ? '— ' : '') . htmlspecialchars((string)($rf['title'] ?? 'Ціль')); ?>
                            </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="pf-qa-footer">
                    <button type="button" class="pf-btn-secondary" id="pfPlanQuickAddCancelFooter">Скасувати</button>
                    <button type="submit" class="pf-btn">Додати в план</button>
                </div>
            </form>
        </div>

        <!-- Модалка швидкого додавання задачі у Факт -->
        <div class="pf-complete-overlay" id="pfFactQuickAddOverlay"></div>
        <div class="pf-qa-modal" id="pfFactQuickAddModal" aria-hidden="true">
            <div class="pf-qa-header">
                <h3>Додати задачу у Факт</h3>
                <button type="button" class="pf-qa-close" id="pfFactQuickAddCancel" aria-label="Закрити">×</button>
            </div>
            <form id="pfFactQuickAddForm" method="post" action="/weekly-plans/add-item/<?php echo (int) $plan['id']; ?>"
                novalidate>
                <div class="pf-qa-body">
                    <div class="pf-qa-row">
                        <div class="pf-qa-field">
                            <label>Дата</label>
                            <input type="date" name="planned_date" id="pfQaDate"
                                value="<?php echo htmlspecialchars(date('Y-m-d')); ?>"
                                min="<?php echo htmlspecialchars((string) $plan['week_start_date']); ?>"
                                max="<?php echo htmlspecialchars((string) $plan['week_end_date']); ?>" required>
                        </div>
                        <div class="pf-qa-field">
                            <label>Час старту</label>
                            <input type="time" name="start_time" step="60" placeholder="09:00">
                        </div>
                    </div>
                    <div class="pf-qa-field">
                        <label>Назва задачі <span class="pf-qa-req">*</span></label>
                        <input type="text" name="title" placeholder="Назва задачі" required pattern=".*\S.*">
                    </div>
                    <div class="pf-qa-field">
                        <label>Очікуваний результат <span class="pf-qa-req">*</span></label>
                        <textarea name="expected_result" placeholder="Що має бути зроблено…" rows="2"></textarea>
                        <span class="pf-field-error" style="display:none;"></span>
                        <span class="pf-field-hint">Коли заповнене — звужує задачу, не дає робити зайвого. Якщо
                            делегована — виконавець розуміє, що саме очікується, і переробок менше.</span>
                    </div>
                    <div class="pf-qa-row">
                        <div class="pf-qa-field">
                            <label>Плановий час, хв <span class="pf-qa-req">*</span></label>
                            <input type="number" name="expected_time" min="1" step="5" placeholder="0">
                            <span class="pf-field-error" style="display:none;"></span>
                            <span class="pf-field-hint">Дозволяє розуміти, скільки і яких реально задач можна поставити
                                в день.</span>
                        </div>
                        <div class="pf-qa-field">
                            <label>Тип</label>
                            <select name="type">
                                <?php foreach ($type_labels as $type_value => $type_label): ?>
                                    <option value="<?php echo htmlspecialchars($type_value); ?>" <?php echo $type_value === 'important-not-urgent' ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($type_label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="pf-qa-field">
                        <label>Відповідальні</label>
                        <select name="assignee_ids[]" multiple
                            size="<?php echo min(5, max(2, count($assignee_options))); ?>">
                            <?php foreach ($assignee_options as $assignee_id => $assignee_name): ?>
                                <option value="<?php echo (int) $assignee_id; ?>" <?php echo (int) $plan['user_id'] === (int) $assignee_id ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($assignee_name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <span class="pf-field-hint">Ctrl (або Cmd) — вибір кількох виконавців.</span>
                    </div>
                    <div class="pf-qa-field">
                        <label>Проект</label>
                        <select name="project_id">
                            <option value="">Без проекту</option>
                            <?php foreach ($projects as $project): ?>
                                <option value="<?php echo (int) ($project['id'] ?? 0); ?>">
                                    <?php echo htmlspecialchars((string) ($project['name'] ?? '')); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="pf-qa-field">
                        <label>Ціль</label>
                        <select name="result_id">
                            <option value="">Без цілі</option>
                            <?php foreach ($results_flat as $rf): ?>
                                <option value="<?php echo (int) ($rf['id'] ?? 0); ?>">
                                    <?php echo ($rf['_indent'] ? '— ' : '') . htmlspecialchars((string) ($rf['title'] ?? 'Ціль')); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="pf-qa-field">
                        <label>Опис</label>
                        <textarea name="description" placeholder="Контекст / примітки…" rows="2"></textarea>
                    </div>
                    <?php if (!empty($templates)): ?>
                    <div class="pf-qa-section">
                        <div class="pf-qa-section-label">Шаблони — клік заповнює поля</div>
                        <div class="pf-qa-chips" id="pfFactTemplateChips">
                            <?php foreach ($templates as $tpl): ?>
                            <button type="button" class="pf-qa-chip pf-qa-chip--template"
                                data-modal="fact"
                                data-name="<?php echo htmlspecialchars((string)($tpl['name'] ?? '')); ?>"
                                data-type="<?php echo htmlspecialchars((string)($tpl['type'] ?? '')); ?>"
                                data-description="<?php echo htmlspecialchars((string)($tpl['description'] ?? '')); ?>"
                                data-expected-result="<?php echo htmlspecialchars((string)($tpl['expected_result'] ?? '')); ?>"
                                data-expected-time="<?php echo (int)($tpl['expected_time'] ?? 0); ?>"
                                data-start-time="<?php echo htmlspecialchars((string)($tpl['start_time'] ?? '')); ?>">
                                <?php echo htmlspecialchars((string)($tpl['name'] ?? 'Шаблон')); ?>
                            </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($results_flat)): ?>
                    <div class="pf-qa-section">
                        <div class="pf-qa-section-label">Цілі — клік встановлює ціль</div>
                        <div class="pf-qa-chips" id="pfFactResultChips">
                            <?php foreach ($results_flat as $rf): ?>
                            <button type="button" class="pf-qa-chip pf-qa-chip--result<?php echo !empty($rf['_indent']) ? ' pf-qa-chip--indent' : ''; ?>"
                                data-modal="fact"
                                data-result-id="<?php echo (int)($rf['id'] ?? 0); ?>"
                                data-result-title="<?php echo htmlspecialchars((string)($rf['title'] ?? '')); ?>">
                                <?php echo (!empty($rf['_indent']) ? '— ' : '') . htmlspecialchars((string)($rf['title'] ?? 'Ціль')); ?>
                            </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="pf-qa-footer">
                    <button type="button" class="pf-btn-secondary" id="pfFactQuickAddCancelFooter">Скасувати</button>
                    <button type="submit" class="pf-btn">Додати задачу</button>
                </div>
            </form>
        </div>
    </div>

    <div class="pf-compare-rows">
        <?php foreach ($days as $day): ?>
            <?php
            $date = (string) $day['date'];
            $dayIsWeekend = $is_weekend($date);
            $dayIsToday = $date === date('Y-m-d');
            $day_items = $itemsByDay[$date] ?? [];
            $day_minutes = 0;
            foreach ($day_items as $day_item) {
                $day_minutes += (int) ($day_item['expected_time'] ?? 0);
            }
            $day_plan_count = count($day_items);
            $day_all_tasks = $weekTasksByDay[$date] ?? [];
            $day_fact_minutes = 0;
            foreach ($day_all_tasks as $dt) {
                $day_fact_minutes += (int) ($dt['actual_time'] ?? 0);
            }
            $day_fact_count = count($day_all_tasks);
            ?>
            <div class="pf-compare-row<?php echo $dayIsToday ? ' is-today' : ''; ?>"
                data-is-weekend="<?php echo $dayIsWeekend ? '1' : '0'; ?>">
                <article class="pf-day-card<?php echo $dayIsToday ? ' is-today' : ''; ?>"
                    data-is-weekend="<?php echo $dayIsWeekend ? '1' : '0'; ?>">
                    <div class="pf-charts-row">
                        <!-- Кругова діаграма типів задач -->
                        <div class="pf-chart-wrap">
                            <canvas width="80" height="80" class="pf-pie-type pf-pie-canvas"
                                data-day="<?php echo htmlspecialchars($date); ?>"></canvas>
                            <div class="pf-chart-label">Типи задач</div>
                        </div>
                        <!-- Графік запланованого часу -->
                        <div class="pf-chart-wrap">
                            <canvas width="80" height="80" class="pf-pie-hours pf-pie-canvas"
                                data-day="<?php echo htmlspecialchars($date); ?>"></canvas>
                            <div class="pf-chart-label">Заплановано годин</div>
                        </div>
                    </div>
                    <div class="pf-day-head">
                        <div class="pf-day-head-main">
                            <div class="pf-day-head-title">
                                <strong><?php echo htmlspecialchars((string) $day['label']); ?></strong>
                                <span><?php echo htmlspecialchars((string) $day['display']); ?></span>
                                <?php if ($dayIsToday): ?><span class="pf-today-badge">• Сьогодні</span><?php endif; ?>
                            </div>
                            <div class="pf-day-summary">
                                <span>Задач: <strong><?php echo $day_plan_count; ?></strong></span>
                                <span>Очікуваний час:
                                    <strong><?php echo htmlspecialchars($format_minutes($day_minutes)); ?></strong></span>
                            </div>
                        </div>
                        <div class="pf-day-load<?php echo $dayIsToday ? ' today' : ''; ?>">План</div>
                    </div>
                    <?php if (!empty($day_items)): ?>
                        <div class="pf-list">
                            <?php foreach ($day_items as $item): ?>
                                <?php
                                $itemTitleDisplay = trim((string) ($item['title'] ?? ''));
                                if ($itemTitleDisplay === '') {
                                    $itemTitleDisplay = trim((string) ($item['task_title'] ?? ''));
                                }
                                if ($itemTitleDisplay === '') {
                                    $itemTitleDisplay = trim((string) ($item['template_name'] ?? ''));
                                }
                                if ($itemTitleDisplay === '') {
                                    $itemTitleDisplay = 'Задача #' . (int) ($item['id'] ?? 0);
                                }
                                ?>
                <div class="pf-task-card<?php echo in_array(strtolower(trim((string) ($item['task_status'] ?? 'todo'))), ['done', 'completed'], true) ? ' is-completed' : ''; ?>" data-expected-time="<?php echo (int) ($item['expected_time'] ?? 0); ?>"
                                    onclick="this.classList.toggle('pf-expanded')">
                                    <div class="pf-task-row">
                                        <div class="pf-task-title"><?php echo htmlspecialchars($itemTitleDisplay); ?>
                                        </div>
                                        <div class="pf-task-actions">
                                            <div class="pf-task-meta">
                                                <span
                                                    class="pf-chip pf-chip-type pf-chip-type-<?php echo htmlspecialchars((string) ($item['type'] ?? 'not-important-not-urgent')); ?>"><?php echo htmlspecialchars($type_labels[(string) ($item['type'] ?? '')] ?? (string) ($item['type'] ?? 'Тип не вказано')); ?></span>
                                                <span
                                                    style="font-size:12px;color:#607084;"><?php echo htmlspecialchars($format_minutes($item['expected_time'] ?? 0)); ?></span>
                                                <?php if (!empty($item['template_name'])): ?>
                                                    <span class="pf-chip pf-chip-template">Шаблон:
                                                        <?php echo htmlspecialchars((string) $item['template_name']); ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="pf-task-details">
                                        <div class="pf-note-line">Плановий час:
                                            <?php echo htmlspecialchars($format_minutes($item['expected_time'] ?? 0)); ?>
                                        </div>
                                        <?php if (!empty($item['expected_result'])): ?>
                                            <div class="pf-note-line">Очікуваний результат:
                                                <?php echo htmlspecialchars((string) $item['expected_result']); ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($item['description'])): ?>
                                            <div class="pf-note-line">Контекст:
                                                <?php echo htmlspecialchars((string) $item['description']); ?>
                                            </div>
                                        <?php endif; ?>
                                        <div class="pf-edit-box" onclick="event.stopPropagation()">
                                                <form class="pf-inline-form js-pf-plan-item-form" method="post"
                                                    action="/weekly-plans/update-item/<?php echo (int) $plan['id']; ?>" novalidate>
                                                    <input type="hidden" name="item_id"
                                                        value="<?php echo (int) ($item['id'] ?? 0); ?>">
                                                    <div class="pf-inline-grid">
                                                        <input type="date" name="planned_date"
                                                            value="<?php echo htmlspecialchars((string) ($item['planned_date'] ?? '')); ?>"
                                                            min="<?php echo htmlspecialchars((string) $plan['week_start_date']); ?>"
                                                            max="<?php echo htmlspecialchars((string) $plan['week_end_date']); ?>"
                                                            required>
                                                        <input type="time" name="start_time" step="60"
                                                            value="<?php echo htmlspecialchars($extract_time($item['start_time'] ?? '')); ?>"
                                                            placeholder="Час старту">
                                                    </div>
                                                    <label
                                                        style="font-size:11px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:.04em;">Відповідальний</label>
                                                    <select name="assignee_ids[]" multiple
                                                        size="<?php echo min(4, max(2, count($assignee_options))); ?>">
                                                        <?php foreach ($assignee_options as $assignee_id => $assignee_name): ?>
                                                            <option value="<?php echo (int) $assignee_id; ?>" <?php echo (int) ($item['assignee_id'] ?? 0) === (int) $assignee_id ? 'selected' : ''; ?>>
                                                                <?php echo htmlspecialchars($assignee_name); ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <small class="pf-field-hint">Ctrl (або Cmd) — кілька виконавців.</small>
                                                    <select name="project_id">
                                                        <option value="">Без проекту</option>
                                                        <?php foreach ($projects as $project): ?>
                                                            <option value="<?php echo (int) ($project['id'] ?? 0); ?>" <?php echo (int) ($item['project_id'] ?? 0) === (int) ($project['id'] ?? 0) && (int) ($item['project_id'] ?? 0) > 0 ? 'selected' : ''; ?>>
                                                                <?php echo htmlspecialchars((string) ($project['name'] ?? '')); ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <input type="text" name="title"
                                                        value="<?php echo htmlspecialchars((string) ($item['title'] ?? '')); ?>"
                                                        required pattern=".*\S.*">
                                                    <div class="pf-inline-grid">
                                                        <div>
                                                            <label
                                                                style="font-size:11px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:.04em;">Плановий
                                                                час, хв <span style="color:#ef4444">*</span></label>
                                                            <input type="number" name="expected_time" min="1" step="5"
                                                                value="<?php echo htmlspecialchars((string) ($item['expected_time'] ?? '')); ?>"
                                                                placeholder="Хвилини">
                                                            <span class="pf-field-error" style="display:none;"></span>
                                                            <small class="pf-field-hint"
                                                                style="display:block;margin-top:3px;">Скільки часу займе задача.
                                                                Обов'язково.</small>
                                                        </div>
                                                        <select name="type">
                                                            <?php foreach ($type_labels as $type_value => $type_label): ?>
                                                                <option value="<?php echo htmlspecialchars($type_value); ?>" <?php echo (string) ($item['type'] ?? '') === $type_value ? 'selected' : ''; ?>>
                                                                    <?php echo htmlspecialchars($type_label); ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                    <label
                                                        style="font-size:11px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:.04em;">Очікуваний
                                                        результат <span style="color:#ef4444">*</span></label>
                                                    <textarea name="expected_result"
                                                        placeholder="Очікуваний результат"><?php echo htmlspecialchars((string) ($item['expected_result'] ?? '')); ?></textarea>
                                                    <span class="pf-field-error" style="display:none;"></span>
                                                    <small class="pf-field-hint">Коли заповнене — звужує задачу, не дає робити
                                                        зайвого. Якщо делегована — менше переробок.</small>
                                                    <textarea name="description"
                                                        placeholder="Опис"><?php echo htmlspecialchars((string) ($item['description'] ?? '')); ?></textarea>
                                                    <button type="submit">Зберегти зміни</button>
                                                </form>
                                                <form class="pf-inline-form js-pf-plan-item-delete-form" method="post"
                                                    action="/weekly-plans/delete-item/<?php echo (int) $plan['id']; ?>">
                                                    <input type="hidden" name="item_id"
                                                        value="<?php echo (int) ($item['id'] ?? 0); ?>">
                                                    <button type="submit" class="pf-delete-btn">Видалити з плану</button>
                                                </form>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="pf-empty">На цей день в початковому плані задач ще немає.</div>
                    <?php endif; ?>
                    <div class="pf-fact-add-row">
                        <button type="button" class="pf-fact-add-btn pf-plan-add-btn" data-date="<?php echo htmlspecialchars($date); ?>">
                            + Додати задачу
                        </button>
                    </div>
                </article>

                <article class="pf-day-card<?php echo $dayIsToday ? ' is-today' : ''; ?>"
                    data-is-weekend="<?php echo $dayIsWeekend ? '1' : '0'; ?>">
                    <div class="pf-charts-row">
                        <div class="pf-chart-wrap">
                            <canvas width="80" height="80" class="pf-pie-type-fact pf-pie-canvas"
                                data-day="<?php echo htmlspecialchars($date); ?>"></canvas>
                            <div class="pf-chart-label">Типи задач (факт)</div>
                        </div>
                        <div class="pf-chart-wrap">
                            <canvas width="80" height="80" class="pf-pie-done-fact pf-pie-canvas"
                                data-day="<?php echo htmlspecialchars($date); ?>"></canvas>
                            <div class="pf-chart-label">Виконано/всього</div>
                        </div>
                        <div class="pf-chart-wrap">
                            <canvas width="80" height="80" class="pf-pie-planned-fact pf-pie-canvas"
                                data-day="<?php echo htmlspecialchars($date); ?>"></canvas>
                            <div class="pf-chart-label">Планові/позапланові</div>
                        </div>
                    </div>
                    <div class="pf-day-head">
                        <div class="pf-day-head-main">
                            <div class="pf-day-head-title">
                                <strong><?php echo htmlspecialchars((string) $day['label']); ?></strong>
                                <span><?php echo htmlspecialchars((string) $day['display']); ?></span>
                                <?php if ($dayIsToday): ?><span class="pf-today-badge">• Сьогодні</span><?php endif; ?>
                            </div>
                            <div class="pf-day-summary">
                                <span>Задач: <strong><?php echo $day_fact_count; ?></strong></span>
                                <span>Фактичний час:
                                    <strong><?php echo htmlspecialchars($format_minutes($day_fact_minutes)); ?></strong></span>
                            </div>
                        </div>
                        <div class="pf-day-load<?php echo $dayIsToday ? ' today' : ''; ?>">Факт</div>
                    </div>
                    <?php if (!empty($day_all_tasks)): ?>
                        <div class="pf-list">
                            <?php foreach ($day_all_tasks as $task): ?>
                                <?php
                                $status = (string) ($task['status'] ?? 'todo');
                                $taskId = (int) ($task['id'] ?? 0);
                                $isPlanned = in_array($taskId, $plannedTaskIds, true);
                                $isCompleted = in_array($status, ['done', 'completed'], true);
                                $taskStartTime = $extract_time($task['due_date'] ?? '');
                                $taskTitle = trim((string) ($task['title'] ?? ''));
                                if ($taskTitle === '') {
                                    $taskTitle = trim((string) ($task['plan_title'] ?? ''));
                                }
                                $cardClass = 'pf-task-card';
                                if (!$isPlanned) {
                                    $cardClass .= ' is-unplanned';
                                }
                                if ($isCompleted) {
                                    $cardClass .= ' is-completed';
                                }
                                ?>
                                <div class="<?php echo htmlspecialchars($cardClass); ?>" onclick="openPfEdit(this)">
                                    <div class="pf-task-row">
                                        <div class="pf-task-title"><?php echo htmlspecialchars($taskTitle); ?></div>
                                        <div class="pf-task-actions">
                                            <div class="pf-task-meta">
                                                <span
                                                    class="pf-chip pf-chip-status <?php echo htmlspecialchars($status); ?>"><?php echo htmlspecialchars($status_labels[$status] ?? $status); ?></span>
                                                <span
                                                    class="pf-chip pf-chip-type pf-chip-type-<?php echo htmlspecialchars((string) ($task['type'] ?? 'not-important-not-urgent')); ?>"><?php echo htmlspecialchars($type_labels[(string) ($task['type'] ?? '')] ?? (string) ($task['type'] ?? '')); ?></span>
                                                <?php if (!$isPlanned): ?>
                                                    <span class="pf-chip" style="background:#fff6e6;color:#8f6100;">⚡ Позапланова</span>
                                                <?php else: ?>
                                                    <span class="pf-chip" style="background:#eaf8ef;color:#1d6e45;">📋 Планова</span>
                                                <?php endif; ?>
                                            </div>
                                            <?php if (!in_array($status, ['done', 'completed'], true)): ?>
                                                <button type="button" class="pf-complete-btn js-pf-complete"
                                                    data-task-id="<?php echo $taskId; ?>"
                                                    data-task-title="<?php echo htmlspecialchars($taskTitle, ENT_QUOTES, 'UTF-8'); ?>"
                                                    data-task-result="<?php echo htmlspecialchars((string) ($task['actual_result'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                                    data-result-id="<?php echo (int) ($task['result_id'] ?? 0) > 0 ? (int) $task['result_id'] : ''; ?>"
                                                    data-result-title="<?php echo htmlspecialchars((string) ($task['result_title'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                                    data-task-due-day="<?php echo htmlspecialchars(!empty($task['due_date']) ? date('Y-m-d', strtotime((string) $task['due_date'])) : '', ENT_QUOTES, 'UTF-8'); ?>"
                                                    onclick="event.stopPropagation();" aria-label="Позначити виконаною">✓</button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="pf-task-details" style="display:none;gap:8px;">
                                        <?php if ($taskStartTime !== ''): ?>
                                            <div class="pf-note-line">Старт: <?php echo htmlspecialchars($taskStartTime); ?></div>
                                        <?php endif; ?>
                                        <div class="pf-note-line">Очікувано:
                                            <?php echo htmlspecialchars($format_minutes($task['expected_time'] ?? 0)); ?> •
                                            Фактично: <?php echo htmlspecialchars($format_minutes($task['actual_time'] ?? 0)); ?>
                                        </div>
                                        <?php if (!empty($task['actual_result'])): ?>
                                            <div class="pf-note-line">Факт/результат:
                                                <?php echo htmlspecialchars((string) $task['actual_result']); ?>
                                            </div>
                                        <?php elseif (!empty($task['expected_result'])): ?>
                                            <div class="pf-note-line">Очікуваний результат:
                                                <?php echo htmlspecialchars((string) $task['expected_result']); ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($task['result_title'])): ?>
                                            <div class="pf-note-line">Ціль:
                                                <?php echo htmlspecialchars((string) $task['result_title']); ?>
                                            </div>
                                        <?php endif; ?>

                                        <div class="pf-edit-box" onclick="event.stopPropagation()">
                                            <form class="pf-inline-form" method="post"
                                                action="/weekly-plans/update-fact-task/<?php echo (int) $plan['id']; ?>">
                                                <input type="hidden" name="task_id" value="<?php echo $taskId; ?>">
                                                <div class="pf-inline-grid">
                                                    <select name="status">
                                                        <?php foreach (['todo' => 'Нова', 'in-progress' => 'В процесі', 'done' => 'Виконано', 'postponed' => 'Перенесено'] as $st_val => $st_lbl): ?>
                                                            <option value="<?php echo htmlspecialchars($st_val); ?>" <?php echo $status === $st_val ? 'selected' : ''; ?>>
                                                                <?php echo htmlspecialchars($st_lbl); ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <select name="assignee_id">
                                                        <?php foreach ($assignee_options as $assignee_id => $assignee_name): ?>
                                                            <option value="<?php echo (int) $assignee_id; ?>" <?php echo (int) ($task['assignee_id'] ?? 0) === (int) $assignee_id ? 'selected' : ''; ?>>
                                                                <?php echo htmlspecialchars($assignee_name); ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <input type="time" name="start_time" step="60"
                                                    value="<?php echo htmlspecialchars($taskStartTime); ?>"
                                                    placeholder="Час старту">
                                                <input type="text" name="title" value="<?php echo htmlspecialchars($taskTitle); ?>"
                                                    required pattern=".*\S.*">
                                                <div class="pf-inline-grid">
                                                    <input type="number" name="actual_time" min="0" step="5"
                                                        value="<?php echo htmlspecialchars((string) ($task['actual_time'] ?? '')); ?>"
                                                        placeholder="Факт хв.">
                                                    <input type="number" name="expected_time" min="0" step="5"
                                                        value="<?php echo htmlspecialchars((string) ($task['expected_time'] ?? '')); ?>"
                                                        placeholder="План хв.">
                                                </div>
                                                <select name="type">
                                                    <?php foreach ($type_labels as $type_value => $type_label): ?>
                                                        <option value="<?php echo htmlspecialchars($type_value); ?>" <?php echo (string) ($task['type'] ?? '') === $type_value ? 'selected' : ''; ?>>
                                                            <?php echo htmlspecialchars($type_label); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <div class="js-pf-fact-actual-result" style="display:none;">
                                                    <textarea name="actual_result"
                                                        placeholder="Фактичний результат"><?php echo htmlspecialchars((string) ($task['actual_result'] ?? '')); ?></textarea>
                                                </div>
                                                <textarea name="expected_result"
                                                    placeholder="Очікуваний результат"><?php echo htmlspecialchars((string) ($task['expected_result'] ?? '')); ?></textarea>
                                                <textarea name="description"
                                                    placeholder="Опис"><?php echo htmlspecialchars((string) ($task['description'] ?? '')); ?></textarea>
                                                <button type="submit">Зберегти зміни</button>
                                            </form>
                                        </div>

                                        <?php if ($taskId > 0): ?>
                                            <a class="pf-link" href="/tasks/view/<?php echo $taskId; ?>"
                                                onclick="event.stopPropagation()">Відкрити задачу</a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="pf-empty">На цей день у факті поки що немає задач.</div>
                    <?php endif; ?>
                    <div class="pf-fact-add-row">
                        <button type="button" class="pf-fact-add-btn" data-date="<?php echo htmlspecialchars($date); ?>">
                            + Додати задачу
                        </button>
                    </div>
                </article>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<div id="pfPieLegend" class="pf-pie-legend"></div>
</div>

<div class="pf-complete-overlay" id="pfCompleteOverlay"></div>
<div class="pf-complete-modal" id="pfCompleteModal" aria-hidden="true">
    <form id="pfCompleteForm" method="post" action="/weekly-plans/update-fact-task/<?php echo (int) $plan['id']; ?>">
        <div class="pf-complete-body">
            <h3>Завершити задачу</h3>
            <p id="pfCompleteText">Щоб позначити задачу виконаною, внесіть фактичний результат і фактичний час.</p>
            <textarea id="pfCompleteResult" name="actual_result"
                placeholder="Що саме було зроблено і який отримано результат" required></textarea>
            <input type="number" id="pfCompleteActualTime" name="actual_time" placeholder="Фактичний час (хвилин)"
                min="1" required
                style="background:#f8fbfe;border:1px solid #d1dbe7;border-radius:12px;padding:12px;width:100%;font:inherit;">
            <label for="pfCompleteDueDay"
                style="display:block;margin:8px 0 6px;font-size:13px;color:#334155;font-weight:600;">Дата виконання задачі</label>
            <input type="date" id="pfCompleteDueDay" name="completion_date"
                max="<?php echo date('Y-m-d'); ?>"
                style="background:#f8fbfe;border:1px solid #d1dbe7;border-radius:12px;padding:12px;width:100%;font:inherit;">
            <input type="hidden" name="task_id" id="pfCompleteTaskId" value="">
            <input type="hidden" name="status" value="done">
            <input type="hidden" id="pfCompleteResultId" value="">
            <div id="pfCompleteResultGoalWrap"
                style="display:none;margin-top:2px;padding:10px 14px;background:#f0fdf6;border:1px solid #bbf0d8;border-radius:10px;">
                <label
                    style="display:flex;align-items:center;gap:10px;cursor:pointer;font-size:14px;color:#1d5c3a;font-weight:500;">
                    <input type="checkbox" id="pfCompleteResultGoal"
                        style="width:17px;height:17px;accent-color:#1b7f5a;cursor:pointer;flex-shrink:0;">
                    <span id="pfCompleteResultGoalLabel">значити ціль виконаною</span>
                </label>
            </div>
            <div class="pf-complete-actions">
                <button type="button" class="pf-btn-secondary" id="pfCompleteCancel">Скасувати</button>
                <button type="submit" class="pf-btn">Завершити</button>
            </div>
        </div>
    </form>
</div>

<div class="pf-complete-overlay" id="pfGoogleImportOverlay"></div>
<div class="pf-import-modal" id="pfGoogleImportModal" aria-hidden="true">
    <div class="pf-import-body">
        <div class="pf-import-header">
            <div>
                <h3>Імпорт з Google Calendar</h3>
                <p>Оберіть календар, підтягніть події за цей тиждень: опис підтягнеться автоматично з Google Calendar, а
                    очікуваний результат потрібно обовʼязково сформулювати вручну для кожної задачі.</p>
            </div>
            <button type="button" class="pf-import-close" id="pfGoogleImportClose" aria-label="Закрити">×</button>
        </div>

        <div class="pf-import-grid">
            <div class="pf-import-sidebar">
                <button type="button" class="pf-btn" id="pfGoogleAuthorizeBtn">Авторизуватись в Google</button>
                <div>
                    <label for="pfGoogleCalendarSelect">Календар</label>
                    <select id="pfGoogleCalendarSelect" disabled>
                        <option value="">Спочатку авторизуйтесь</option>
                    </select>
                </div>
                <button type="button" class="pf-btn-secondary" id="pfGoogleLoadEventsBtn" disabled>Завантажити події
                    тижня</button>
                <div class="pf-import-note">Імпортуються тільки події з точним часом. Цілоденні події можна буде додати
                    окремо вручну. Опис події підставиться автоматично, але очікуваний результат без заповнення вручну
                    імпортувати не вийде.</div>
                <div class="pf-import-status" id="pfGoogleImportStatus">
                    <?php echo $googleCalendarEnabled ? 'Google Calendar готовий до підключення.' : 'Для імпорту потрібно налаштувати GOOGLE_CLIENT_ID.'; ?>
                </div>
            </div>

            <div class="pf-import-main">
                <label>Події до імпорту</label>
                <div class="pf-import-empty" id="pfGoogleImportEmpty">Після вибору календаря тут зʼявляться події за
                    тиждень <?php echo htmlspecialchars(date('d.m.Y', strtotime((string) $plan['week_start_date']))); ?>
                    - <?php echo htmlspecialchars(date('d.m.Y', strtotime((string) $plan['week_end_date']))); ?>.</div>
                <div class="pf-import-list" id="pfGoogleImportList"></div>
                <form id="pfGoogleImportForm" method="post"
                    action="/weekly-plans/import-google-calendar/<?php echo (int) $plan['id']; ?>">
                    <input type="hidden" name="calendar_import_payload" id="pfGoogleImportPayload" value="">
                    <div class="pf-import-actions">
                        <button type="button" class="pf-btn-secondary" id="pfGoogleImportCancel">Скасувати</button>
                        <button type="submit" class="pf-btn" id="pfGoogleImportSubmit" disabled>Імпортувати в
                            план</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php if ($googleCalendarEnabled): ?>
    <script src="https://accounts.google.com/gsi/client" async defer></script>
<?php endif; ?>


<?php
$content = ob_get_clean();
require APP_PATH . '/Views/layouts/main.php';
?>