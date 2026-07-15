<?php
/**
 * Список завдань
 */
$title = 'Задачі';
$layout_container_class = 'container-wide';

$selected_tab = $selected_tab ?? 'my';
$selected_status = $selected_status ?? 'active';
$selected_date = $selected_date ?? date('Y-m-d');
$current_user = get_user() ?? [];
$current_user_id = (int) ($current_user['id'] ?? 0);
$current_user_name = trim((string) (($current_user['first_name'] ?? '') . ' ' . ($current_user['last_name'] ?? ''))) ?: ($current_user['first_name'] ?? 'Користувач');
$goals = $goals ?? [];
$all_sub_results = $all_sub_results ?? [];
$templates = $templates ?? [];
$incoming_assigned_tasks = $incoming_assigned_tasks ?? [];
$flash_success = flash('success');
$flash_error = flash('error');

$goal_children_map = [];
foreach ($all_sub_results as $sub_result) {
    $parent_id = (int) ($sub_result['parent_id'] ?? 0);
    if ($parent_id <= 0) {
        continue;
    }

    $goal_children_map[$parent_id][] = $sub_result;
}

$render_goal_source_buttons = null;
$render_goal_source_buttons = static function (array $nodes, int $depth = 0) use (&$render_goal_source_buttons, $goal_children_map) {
    foreach ($nodes as $index => $node) {
        if (!is_array($node)) {
            continue;
        }

        $node_id = (int) ($node['id'] ?? 0);
        $title = trim((string) ($node['title'] ?? ''));
        if ($node_id <= 0 || $title === '') {
            continue;
        }

        $branch = $depth === 0 ? '•' : '↳';
        ?>
        <button type="button" class="quick-create-source quick-create-source-tree" data-source-type="goal"
            data-source-id="<?php echo $node_id; ?>"
            data-source-label="<?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?>"
            style="padding-left: <?php echo 8 + ($depth * 18); ?>px;">
            <span class="goal-tree-branch"><?php echo $branch; ?></span>
            <span class="goal-tree-label"><?php echo htmlspecialchars($title); ?></span>
        </button>
        <?php

        if (!empty($goal_children_map[$node_id])) {
            $render_goal_source_buttons($goal_children_map[$node_id], $depth + 1);
        }
    }
};

$tabs = [
    'my' => 'Мої',
    'delegated' => 'Делеговані',
    'subordinates' => 'Підлеглих',
    'postponed' => 'Відкладені',
];

$status_options = [
    'active' => 'Нова, В процесі',
    'todo' => 'Нова',
    'in-progress' => 'В процесі',
    'done' => 'Завершено',
    'postponed' => 'Відкладено',
    'all' => 'Всі статуси',
];

$ua_months = [
    1 => 'січня',
    2 => 'лютого',
    3 => 'березня',
    4 => 'квітня',
    5 => 'травня',
    6 => 'червня',
    7 => 'липня',
    8 => 'серпня',
    9 => 'вересня',
    10 => 'жовтня',
    11 => 'листопада',
    12 => 'грудня',
];

$date_ts = strtotime($selected_date) ?: time();
$date_label = date('j', $date_ts) . ' ' . ($ua_months[(int) date('n', $date_ts)] ?? '') . ' ' . date('Y', $date_ts);
$prev_date = date('Y-m-d', strtotime($selected_date . ' -1 day'));
$next_date = date('Y-m-d', strtotime($selected_date . ' +1 day'));

$format_duration = static function ($value): string {
    if ($value === null || $value === '') {
        return '0 хв';
    }

    if (!is_numeric($value)) {
        return (string) $value;
    }

    $minutes = (int) $value;
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

$status_label = static function ($status): string {
    $map = [
        'todo' => 'Нова',
        'in-progress' => 'В процесі',
        'done' => 'Завершено',
        'postponed' => 'Відкладено',
    ];

    return $map[$status] ?? (string) $status;
};

$status_class = static function ($status): string {
    $map = [
        'todo' => 'status-todo',
        'in-progress' => 'status-progress',
        'done' => 'status-done',
        'postponed' => 'status-postponed',
    ];

    return $map[$status] ?? 'status-todo';
};

$acceptance_info = static function (array $task): array {
    $assignee_id = (int) ($task['assignee_id'] ?? 0);
    $reporter_id = (int) ($task['reporter_id'] ?? 0);

    if ($assignee_id > 0 && $assignee_id === $reporter_id) {
        return ['accepted' => true, 'label' => 'Власна', 'class' => 'acceptance-self'];
    }

    $accepted_at = trim((string) ($task['accepted_at'] ?? ''));
    $is_accepted = $accepted_at !== '' && $accepted_at !== '0000-00-00 00:00:00';

    return [
        'accepted' => $is_accepted,
        'label' => $is_accepted ? 'Прийнята виконавцем' : 'Очікує прийняття',
        'class' => $is_accepted ? 'acceptance-accepted' : 'acceptance-pending',
    ];
};

$type_label = static function ($type): string {
    $map = [
        'important-urgent' => 'Важлива термінова',
        'important-not-urgent' => 'Важлива нетермінова',
        'not-important-urgent' => 'Неважлива термінова',
        'not-important-not-urgent' => 'Неважлива нетермінова',
    ];

    return $map[(string) $type] ?? ((string) $type ?: 'Не вказано');
};

$type_class = static function ($type): string {
    $map = [
        'important-urgent' => 'type-important-urgent',
        'important-not-urgent' => 'type-important-not-urgent',
        'not-important-urgent' => 'type-not-important-urgent',
        'not-important-not-urgent' => 'type-not-important-not-urgent',
    ];

    return $map[(string) $type] ?? 'type-important-not-urgent';
};

$short_text = static function ($value, int $limit = 46): string {
    $text = trim((string) $value);
    if ($text === '') {
        return '—';
    }

    if (mb_strlen($text) <= $limit) {
        return $text;
    }

    return rtrim(mb_substr($text, 0, $limit - 1)) . '…';
};

$task_meta_line = static function (array $task) use ($short_text): string {
    $result_source = $task['result_title']
        ?? $task['source_result']
        ?? $task['result_source']
        ?? '';

    $template_source = $task['template_name']
        ?? $task['source_template']
        ?? $task['template_source']
        ?? '';

    return 'Ціль: ' . $short_text($result_source, 46)
        . ' | Шаблон: ' . $short_text($template_source, 46);
};

$fixed_tasks = [];
$other_tasks = [];
$kanban_groups = ['todo' => [], 'in-progress' => [], 'done' => [], 'postponed' => []];
$total_expected_minutes = 0;
$total_actual_minutes = 0;

foreach ($tasks as $task) {
    $due_ts = !empty($task['due_date']) ? strtotime($task['due_date']) : false;
    $has_fixed_time = $due_ts && date('H:i', $due_ts) !== '00:00';

    $task['_time_label'] = ($due_ts && date('H:i', $due_ts) !== '00:00') ? date('H:i', $due_ts) : '';
    $task['_assignee_name'] = trim(($task['assignee_first_name'] ?? '') . ' ' . ($task['assignee_last_name'] ?? '')) ?: '—';

    $expected = (int) ($task['expected_time'] ?? 0);
    $actual = (int) ($task['actual_time'] ?? 0);

    $total_expected_minutes += max(0, $expected);
    $total_actual_minutes += max(0, $actual);

    if ($has_fixed_time) {
        $fixed_tasks[] = $task;
    } else {
        $other_tasks[] = $task;
    }

    $group_key = (string) ($task['status'] ?? 'todo');
    if (!isset($kanban_groups[$group_key])) {
        $group_key = 'todo';
    }
    $kanban_groups[$group_key][] = $task;
}

$completed_tasks = count(array_filter($tasks, static fn($task) => ($task['status'] ?? '') === 'done'));
$telegram_username = defined('TELEGRAM_BOT_USERNAME') ? TELEGRAM_BOT_USERNAME : '';
$telegram_url = $telegram_username ? 'https://t.me/' . rawurlencode($telegram_username) : '#';

$make_url = static function (array $overrides = []) use ($selected_tab, $selected_status, $selected_date) {
    $params = [
        'tab' => $selected_tab,
        'status' => $selected_status,
        'date' => $selected_date,
    ];

    foreach ($overrides as $key => $value) {
        $params[$key] = $value;
    }

    return '/tasks?' . http_build_query($params);
};

$task_payload = static function (array $task): array {
    $due_ts = !empty($task['due_date']) ? strtotime($task['due_date']) : false;

    return [
        'id' => (int) ($task['id'] ?? 0),
        'title' => mb_convert_encoding((string) ($task['title'] ?? ''), 'UTF-8', 'UTF-8'),
        'description' => mb_convert_encoding((string) ($task['description'] ?? ''), 'UTF-8', 'UTF-8'),
        'expected_result' => mb_convert_encoding((string) ($task['expected_result'] ?? ''), 'UTF-8', 'UTF-8'),
        'actual_result' => mb_convert_encoding((string) ($task['actual_result'] ?? ''), 'UTF-8', 'UTF-8'),
        'type' => (string) ($task['type'] ?? 'important-urgent'),
        'status' => (string) ($task['status'] ?? 'todo'),
        'expected_time' => (int) ($task['expected_time'] ?? 0),
        'actual_time' => (int) ($task['actual_time'] ?? 0),
        'due_day' => $due_ts ? date('Y-m-d', $due_ts) : '',
        'start_time' => $due_ts ? date('H:i', $due_ts) : '',
        'assignee_id' => (int) ($task['assignee_id'] ?? 0),
        'assignee' => mb_convert_encoding((string) ($task['_assignee_name'] ?? '—'), 'UTF-8', 'UTF-8'),
        'reporter' => mb_convert_encoding(trim((string) (($task['reporter_first_name'] ?? '') . ' ' . ($task['reporter_last_name'] ?? ''))) ?: '—', 'UTF-8', 'UTF-8'),
        'result_id' => (int) ($task['result_id'] ?? 0),
        'result_title' => mb_convert_encoding((string) ($task['result_title'] ?? ''), 'UTF-8', 'UTF-8'),
        'project_id' => (int) ($task['project_id'] ?? 0),
        'template_id' => (int) ($task['template_id'] ?? 0),
        'template_name' => mb_convert_encoding((string) ($task['template_name'] ?? ''), 'UTF-8', 'UTF-8'),
    ];
};
$extra_head = '<link rel="stylesheet" href="/public/css/tasks.css?v=4"><link rel="stylesheet" href="/public/css/overdue-popup.css">';
$extra_scripts = '<script src="/public/js/tasks.js?v=2"></script><script src="/public/js/overdue-popup.js"></script>';

ob_start();
?>
<script>
    window.TI = {
        currentTab: <?php echo json_encode((string) ($selected_tab ?? ""), JSON_UNESCAPED_UNICODE); ?>,
        selectedDate: <?php echo json_encode((string) ($selected_date ?? ""), JSON_UNESCAPED_UNICODE); ?>,
        selectedStatus: <?php echo json_encode((string) ($selected_status ?? ""), JSON_UNESCAPED_UNICODE); ?>,
        currentUserId: <?php echo (int) ($current_user_id ?? 0); ?>,
        currentUserName: <?php echo json_encode((string) ($current_user_name ?? ""), JSON_UNESCAPED_UNICODE); ?>
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
<section class="tasks-page">

    <div class="tasks-shell">
        <?php if ($flash_success || $flash_error): ?>
            <div class="tasks-flash">
                <?php if ($flash_success): ?>
                    <div class="tasks-flash-item success"><?php echo htmlspecialchars($flash_success); ?></div>
                <?php endif; ?>
                <?php if ($flash_error): ?>
                    <div class="tasks-flash-item error"><?php echo htmlspecialchars($flash_error); ?></div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="tasks-top">
            <div class="tasks-headline">
                <span>Задачі</span>
                <?php if ($telegram_username): ?>
                    <a href="<?php echo htmlspecialchars($telegram_url); ?>" target="_blank" rel="noopener noreferrer"
                        title="Відкрити Telegram-бота"
                        style="display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:999px;">
                        <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"
                            style="display:block;fill:#0088cc;">
                            <path
                                d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm4.64 6.8c-.15 1.58-.8 5.42-1.13 7.19-.14.75-.42 1-.68 1.03-.58.05-1.02-.38-1.58-.75-.88-.58-1.38-.94-2.23-1.5-.99-.65-.35-1.01.22-1.59.15-.15 2.71-2.48 2.76-2.69a.2.2 0 00-.05-.18c-.06-.05-.14-.03-.21-.02-.09.02-1.49.95-4.22 2.79-.4.27-.76.41-1.08.4-.36-.01-1.04-.2-1.55-.37-.63-.2-1.12-.31-1.08-.66.02-.18.27-.36.74-.55 2.92-1.27 4.86-2.11 5.83-2.51 2.78-1.16 3.35-1.36 3.73-1.36.08 0 .27.02.39.12.1.08.13.19.14.27-.01.06.01.24 0 .38z">
                            </path>
                        </svg>
                    </a>
                <?php endif; ?>
            </div>

            <div class="toolbar">
                <div class="tabs">
                    <?php foreach ($tabs as $tab_key => $tab_label): ?>
                        <a class="tab <?php echo $selected_tab === $tab_key ? 'active' : ''; ?>"
                            href="<?php echo htmlspecialchars($make_url(['tab' => $tab_key])); ?>">
                            <?php echo htmlspecialchars($tab_label); ?>
                        </a>
                    <?php endforeach; ?>
                </div>

                <div class="date-nav">
                    <a href="<?php echo htmlspecialchars($make_url(['date' => $prev_date])); ?>"
                        aria-label="Попередній день">&#8249;</a>
                    <button type="button" id="dateLabel" class="date-label"
                        aria-label="Вибрати дату"><?php echo htmlspecialchars($date_label); ?></button>
                    <a href="<?php echo htmlspecialchars($make_url(['date' => $next_date])); ?>"
                        aria-label="Наступний день">&#8250;</a>
                    <input id="datePicker" type="date" value="<?php echo htmlspecialchars($selected_date); ?>"
                        style="position:absolute;opacity:0;pointer-events:none;" />
                </div>

                <div class="actions-top">
                    <div class="view-toggle" id="viewToggle">
                        <button class="view-btn active" type="button" data-view="list">Список</button>
                        <button class="view-btn" type="button" data-view="kanban">Канбан</button>
                    </div>
                </div>
            </div>
        </div>

        <div class="tasks-filter-bar">
            <div class="tasks-filter-group">
                <label class="tasks-filter-label">Статус</label>
                <div class="status-filter">
                    <select id="statusSelect">
                        <?php foreach ($status_options as $status_key => $status_name): ?>
                            <option value="<?php echo htmlspecialchars($status_key); ?>" <?php echo $selected_status === $status_key ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($status_name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="tasks-filter-group">
                <label class="tasks-filter-label">Тип</label>
                <select id="filterType" class="tasks-filter-select">
                    <option value="">Всі типи</option>
                    <option value="important-urgent">Важлива термінова</option>
                    <option value="important-not-urgent">Важлива нетермінова</option>
                    <option value="not-important-urgent">Неважлива термінова</option>
                    <option value="not-important-not-urgent">Неважлива нетермінова</option>
                </select>
            </div>
            <div class="tasks-filter-group">
                <label class="tasks-filter-label">Проект</label>
                <select id="filterProject" class="tasks-filter-select">
                    <option value="">Всі проекти</option>
                    <?php foreach ($projects as $project): ?>
                        <option value="<?php echo (int) ($project['id'] ?? 0); ?>">
                            <?php echo htmlspecialchars((string) ($project['name'] ?? '')); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="tasks-filter-group tasks-filter-search-group">
                <label class="tasks-filter-label">Пошук по задачах дня</label>
                <div class="tasks-filter-search-wrap">
                    <input type="text" id="filterSearch" class="tasks-filter-search" placeholder="Назва або опис…"
                        autocomplete="off" />
                    <button type="button" id="filterSearchClear" class="tasks-filter-search-clear" aria-label="Очистити"
                        style="display:none;">✕</button>
                </div>
            </div>
            <span class="tasks-filter-count" id="filterCount"></span>
        </div>

        <div class="tasks-content" id="listView">
            <?php if ($selected_tab === 'my' && !empty($incoming_assigned_tasks)): ?>
                <div class="tasks-card tasks-card-assigned">
                    <h3>Задачі призначені мені</h3>

                    <?php foreach ($incoming_assigned_tasks as $task): ?>
                        <?php
                        $reporter_name = trim((string) (($task['reporter_first_name'] ?? '') . ' ' . ($task['reporter_last_name'] ?? ''))) ?: '—';
                        $accept_url = '/tasks/accept/' . (int) ($task['id'] ?? 0);
                        ?>
                        <div class="assigned-task-row">
                            <div>
                                <div class="assigned-task-title">
                                    <?php echo htmlspecialchars((string) ($task['title'] ?? '')); ?>
                                </div>
                                <div class="assigned-task-meta"><?php echo htmlspecialchars($task_meta_line($task)); ?></div>
                            </div>
                            <div class="assigned-task-meta">
                                <span>Постановник: <strong><?php echo htmlspecialchars($reporter_name); ?></strong></span>
                                <span>Очікуваний час:
                                    <strong><?php echo htmlspecialchars($format_duration($task['expected_time'] ?? null)); ?></strong></span>
                                <span>Тип:
                                    <strong><?php echo htmlspecialchars($type_label($task['type'] ?? '')); ?></strong></span>
                            </div>
                            <form method="post" action="<?php echo htmlspecialchars($accept_url); ?>" style="margin:0;">
                                <input type="hidden" name="return_url"
                                    value="<?php echo htmlspecialchars($_SERVER['REQUEST_URI'] ?? '/tasks'); ?>" />
                                <button type="submit" class="accept-btn">Прийняти</button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="tasks-card" id="tasksMainCard">
                <div class="tasks-grid tasks-grid-unified">
                    <div>Час</div>
                    <div>Назва</div>
                    <div class="hide-lg">Очікуваний результат задачі</div>
                    <div>Учасники</div>
                    <div>Очікуваний час</div>
                    <div>Фактичний час</div>
                    <div>Статус</div>
                    <div>Тип</div>
                    <div></div>
                </div>

                <div id="taskListBody">
                    <?php
                    $all_list_tasks = array_merge($fixed_tasks, $other_tasks);
                    if (empty($all_list_tasks)): ?>
                        <div class="empty-block" id="tasksEmptyBlock">На обрану дату задач немає.</div>
                    <?php else: ?>
                        <?php foreach ($all_list_tasks as $task): ?>
                            <?php
                            $is_fixed = !empty($task['_time_label']);
                            $payload_arr = $task_payload($task);
                            $payload_json_raw = json_encode($payload_arr, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
                            $payload_json = htmlspecialchars($payload_json_raw ?: '{}', ENT_QUOTES, 'UTF-8');
                            $acceptance = $acceptance_info($task);
                            ?>
                            <div class="task-row task-row-clickable open-task-panel tasks-grid-unified <?php echo (($task['status'] ?? '') === 'done') ? 'is-done' : ''; ?>"
                                data-href="/tasks/view/<?php echo (int) $task['id']; ?>"
                                data-task="<?php echo $payload_json; ?>"
                                data-task-type="<?php echo htmlspecialchars((string) ($task['type'] ?? '')); ?>"
                                data-task-project="<?php echo (int) ($task['project_id'] ?? 0); ?>" role="button" tabindex="0">
                                <div class="task-time-col">
                                    <?php echo htmlspecialchars($is_fixed ? $task['_time_label'] : ''); ?>
                                </div>
                                <div class="task-title-wrap">
                                    <div class="task-title"><?php echo htmlspecialchars($task['title'] ?? ''); ?></div>
                                    <div class="task-meta-small"><?php echo htmlspecialchars($task_meta_line($task)); ?></div>
                                </div>
                                <div class="task-muted hide-lg"><?php echo htmlspecialchars($task['expected_result'] ?? '—'); ?>
                                </div>
                                <div class="task-user"><?php echo htmlspecialchars($task['_assignee_name']); ?></div>
                                <div class="task-time">
                                    <?php echo htmlspecialchars($format_duration($task['expected_time'] ?? null)); ?>
                                </div>
                                <div class="task-time actual js-task-actual-time"
                                    data-task-id="<?php echo (int) ($task['id'] ?? 0); ?>"
                                    data-base-minutes="<?php echo (int) ($task['actual_time'] ?? 0); ?>"
                                    data-summary-group="all">
                                    <?php echo htmlspecialchars($format_duration($task['actual_time'] ?? null)); ?>
                                </div>
                                <div><span
                                        class="status-badge <?php echo $status_class($task['status'] ?? 'todo'); ?>"><?php echo htmlspecialchars($status_label($task['status'] ?? 'todo')); ?></span>
                                    <?php if ($selected_tab === 'delegated'): ?>
                                        <span
                                            class="acceptance-badge <?php echo htmlspecialchars($acceptance['class']); ?>"><?php echo htmlspecialchars($acceptance['label']); ?></span>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <span class="task-type-pill <?php echo $type_class($task['type'] ?? ''); ?>">
                                        <span class="task-type-dot" aria-hidden="true"></span>
                                        <?php echo htmlspecialchars($type_label($task['type'] ?? '')); ?>
                                    </span>
                                </div>
                                <div class="task-actions-cell">
                                    <?php if (($task['status'] ?? '') !== 'done'): ?>
                                        <button type="button" class="task-timer-btn js-task-timer"
                                            data-task-id="<?php echo (int) ($task['id'] ?? 0); ?>"
                                            data-base-minutes="<?php echo (int) ($task['actual_time'] ?? 0); ?>"
                                            data-can-start="<?php echo $acceptance['accepted'] ? '1' : '0'; ?>"
                                            aria-label="Запустити таймер">⏱</button>
                                        <button type="button" class="task-complete-btn js-task-complete"
                                            data-task-id="<?php echo (int) ($task['id'] ?? 0); ?>"
                                            data-task-title="<?php echo htmlspecialchars((string) ($task['title'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                            data-task-result="<?php echo htmlspecialchars((string) ($task['actual_result'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                            data-task-expected-time="<?php echo (int) ($task['expected_time'] ?? 0); ?>"
                                            data-task-due-day="<?php echo htmlspecialchars(!empty($task['due_date']) ? date('Y-m-d', strtotime((string) $task['due_date'])) : '', ENT_QUOTES, 'UTF-8'); ?>"
                                            aria-label="Позначити виконаною">✓</button>
                                    <?php endif; ?>
                                    <div class="task-arrow">&#8250;</div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <div class="empty-block" id="tasksEmptyBlock" style="display:none;">Жодна задача не відповідає
                            фільтрам.</div>
                    <?php endif; ?>
                </div>

                <div class="card-footer" id="tasksCardFooter">
                    <span>Всього: <strong id="footerTaskCount"><?php echo count($all_list_tasks); ?></strong>
                        задач</span>
                    <span>Очікуваний час:
                        <strong><?php echo htmlspecialchars($format_duration(array_sum(array_map(static fn($item) => (int) ($item['expected_time'] ?? 0), $all_list_tasks)))); ?></strong></span>
                    <span>Фактичний час: <strong class="js-footer-actual-total"
                            data-summary-group="all"><?php echo htmlspecialchars($format_duration(array_sum(array_map(static fn($item) => (int) ($item['actual_time'] ?? 0), $all_list_tasks)))); ?></strong></span>
                </div>
            </div>

            <div class="quick-add-box" id="quickAddBox">
                <div class="quick-add-label">Додати задачу</div>
                <div class="quick-add-rows" id="quickAddRows"></div>
                <div class="quick-add-input-row">
                    <input type="text" id="quickTaskInput" class="quick-task-input js-quick-task-input"
                        placeholder="Введіть назву задачі" autocomplete="off" />
                    <button type="button" class="quick-add-submit js-quick-task-submit"
                        data-quick-input-id="quickTaskInput">Створити</button>
                </div>
            </div>

            <div class="create-actions">
                <div class="mini-card">
                    <h4>Створити задачу з цілі</h4>
                    <p style="margin:0 0 10px;color:#64748b;font-size:13px;">Ціль, з якої нарізаються задачі.</p>
                    <div class="mini-list">
                        <?php if (empty($goals)): ?>
                            <span style="color:#94a3b8;font-size:13px;">Немає доступних цілей</span>
                        <?php else: ?>
                            <?php $render_goal_source_buttons($goals); ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="mini-card">
                    <h4>Створити задачу з шаблону</h4>
                    <div class="mini-list">
                        <?php if (empty($templates)): ?>
                            <span style="color:#94a3b8;font-size:13px;">Немає доступних шаблонів</span>
                        <?php else: ?>
                            <?php foreach ($templates as $template): ?>
                                <button type="button" class="quick-create-source" data-source-type="template"
                                    data-source-id="<?php echo (int) ($template['id'] ?? 0); ?>"
                                    data-source-label="<?php echo htmlspecialchars((string) ($template['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                    data-source-description="<?php echo htmlspecialchars((string) ($template['description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                    data-source-expected="<?php echo htmlspecialchars((string) ($template['expected_result'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                    data-source-typevalue="<?php echo htmlspecialchars((string) ($template['type'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                    data-source-assignee="<?php echo (int) ($template['assignee_id'] ?? 0); ?>"
                                    data-source-time="<?php echo (int) ($template['expected_time'] ?? 0); ?>">
                                    <?php echo htmlspecialchars((string) ($template['name'] ?? 'Шаблон')); ?>
                                </button>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="stats-bar">
                <div>
                    <div class="stats-label">Всього задач</div>
                    <div class="stats-value"><?php echo count($tasks); ?></div>
                </div>
                <div>
                    <div class="stats-label">Завершено</div>
                    <div class="stats-value done"><?php echo $completed_tasks; ?></div>
                </div>
                <div>
                    <div class="stats-label">Очікуваний час</div>
                    <div class="stats-value expected">
                        <?php echo htmlspecialchars($format_duration($total_expected_minutes)); ?>
                    </div>
                </div>
                <div>
                    <div class="stats-label">Фактичний час</div>
                    <div class="stats-value actual">
                        <?php echo htmlspecialchars($format_duration($total_actual_minutes)); ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="tasks-content kanban-board" id="kanbanView">
            <?php foreach ($kanban_groups as $group_key => $group_tasks): ?>
                <div class="kanban-col">
                    <div class="kanban-head"><?php echo htmlspecialchars($status_label($group_key)); ?>
                        (<?php echo count($group_tasks); ?>)</div>
                    <div class="kanban-list js-kanban-list" data-status="<?php echo htmlspecialchars($group_key); ?>">
                        <?php if (empty($group_tasks)): ?>
                            <div class="empty-block">Порожньо</div>
                        <?php else: ?>
                            <?php foreach ($group_tasks as $task): ?>
                                <?php
                                $payload_json = htmlspecialchars(json_encode($task_payload($task), JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
                                $acceptance = $acceptance_info($task);
                                ?>
                                <div class="kanban-task kanban-task-clickable open-task-panel <?php echo (($task['status'] ?? '') === 'done') ? 'is-done' : ''; ?>"
                                    data-href="/tasks/view/<?php echo (int) $task['id']; ?>"
                                    data-task-id="<?php echo (int) ($task['id'] ?? 0); ?>"
                                    data-task-status="<?php echo htmlspecialchars((string) ($task['status'] ?? 'todo')); ?>"
                                    data-task="<?php echo $payload_json; ?>" role="button" tabindex="0" draggable="true">
                                    <h5><?php echo htmlspecialchars($task['title'] ?? ''); ?></h5>
                                    <div class="kanban-meta">
                                        <span><?php echo htmlspecialchars($task['_assignee_name']); ?></span>
                                        <span>Очікуваний:
                                            <?php echo htmlspecialchars($format_duration($task['expected_time'] ?? null)); ?></span>
                                    </div>
                                    <div class="kanban-meta">
                                        <span>Фактичний:</span>
                                        <span class="js-task-actual-time" data-task-id="<?php echo (int) ($task['id'] ?? 0); ?>"
                                            data-base-minutes="<?php echo (int) ($task['actual_time'] ?? 0); ?>"><?php echo htmlspecialchars($format_duration($task['actual_time'] ?? null)); ?></span>
                                    </div>
                                    <?php if ($selected_tab === 'delegated'): ?>
                                        <div style="margin-top:8px;">
                                            <span class="acceptance-badge <?php echo htmlspecialchars($acceptance['class']); ?>"
                                                style="margin-left:0;">
                                                <?php echo htmlspecialchars($acceptance['label']); ?>
                                            </span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (($task['status'] ?? '') !== 'done'): ?>
                                        <div style="margin-top:8px;display:flex;justify-content:flex-end;">
                                            <button type="button" class="task-timer-btn js-task-timer"
                                                data-task-id="<?php echo (int) ($task['id'] ?? 0); ?>"
                                                data-base-minutes="<?php echo (int) ($task['actual_time'] ?? 0); ?>"
                                                data-can-start="<?php echo $acceptance['accepted'] ? '1' : '0'; ?>"
                                                aria-label="Запустити таймер" style="margin-right:6px;">⏱</button>
                                            <button type="button" class="task-complete-btn js-task-complete"
                                                data-task-id="<?php echo (int) ($task['id'] ?? 0); ?>"
                                                data-task-title="<?php echo htmlspecialchars((string) ($task['title'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                                data-task-result="<?php echo htmlspecialchars((string) ($task['actual_result'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                                data-task-expected-time="<?php echo (int) ($task['expected_time'] ?? 0); ?>"
                                                data-task-due-day="<?php echo htmlspecialchars(!empty($task['due_date']) ? date('Y-m-d', strtotime((string) $task['due_date'])) : '', ENT_QUOTES, 'UTF-8'); ?>"
                                                aria-label="Позначити виконаною">✓</button>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="task-drawer-overlay" id="taskDrawerOverlay"></div>
    <div class="task-complete-modal-overlay" id="taskCompleteOverlay"></div>
    <aside class="task-drawer" id="taskDrawer">
        <form id="taskDrawerForm" method="post" action="/tasks/edit/0" novalidate
            style="background:transparent; box-shadow:none; margin:0; padding:0; border-radius:0; height:100%; display:flex; flex-direction:column;">
            <div class="drawer-header">
                <input id="drawerTitle" name="title" type="text" placeholder="Назва задачі" required pattern=".*\S.*" />
                <button type="button" id="taskDrawerClose" class="drawer-btn cancel"
                    style="padding:8px 10px;">✕</button>
            </div>

            <div class="drawer-body">
                <input type="hidden" id="drawerDueDate" name="due_date" value="" />
                <input type="hidden" id="drawerTemplateId" name="template_id" value="" />
                <input type="hidden" name="return_url"
                    value="<?php echo htmlspecialchars($_SERVER['REQUEST_URI'] ?? '/tasks'); ?>" />

                <div class="drawer-field">
                    <label>Опис</label>
                    <textarea id="drawerDescription" name="description" placeholder="Додайте опис задачі..."></textarea>
                </div>

                <div class="drawer-grid-2" id="drawerResultGrid" style="grid-template-columns:1fr;">
                    <div class="drawer-field">
                        <label>Очікуваний результат <span style="color:#ef4444">*</span></label>
                        <textarea id="drawerExpectedResult" name="expected_result"
                            placeholder="Опишіть, як керівник бачить виконання задачі"></textarea>
                        <div id="drawerExpectedResultError" class="drawer-field-error" style="display:none;"></div>
                        <div class="drawer-field-hint">Звужує задачу — не дає робити зайвого. Якщо делеговано —
                            виконавець одразу розуміє, що очікується, і переробок менше.</div>
                    </div>
                    <div class="drawer-field" id="drawerActualResultField" style="display:none;">
                        <label>Фактичний результат (виконання працівника)</label>
                        <textarea id="drawerActualResult" name="actual_result"
                            placeholder="Опишіть фактичний результат виконання"></textarea>
                    </div>
                </div>

                <div class="drawer-grid-2">
                    <div class="drawer-field">
                        <label>День виконання</label>
                        <input id="drawerDueDay" type="date" value="" />
                    </div>
                    <div class="drawer-field">
                        <label>Час старту</label>
                        <input id="drawerStartTime" type="time" value="" />
                    </div>
                </div>

                <div class="drawer-grid-2">
                    <div class="drawer-field">
                        <label>Ціль</label>
                        <select id="drawerResultId" name="result_id">
                            <option value="">— Без цілі —</option>
                            <?php foreach ($goals as $goal): ?>
                                <option value="<?php echo (int) ($goal['id'] ?? 0); ?>">
                                    <?php echo htmlspecialchars((string) ($goal['title'] ?? 'Ціль')); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="drawer-field">
                        <label>Проект</label>
                        <select id="drawerProjectId" name="project_id">
                            <option value="">— Без проекту —</option>
                            <?php foreach ($projects ?? [] as $proj): ?>
                                <option value="<?php echo (int) ($proj['id'] ?? 0); ?>">
                                    <?php echo htmlspecialchars((string) ($proj['name'] ?? 'Проект')); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="drawer-grid-2">
                    <div class="drawer-field">
                        <label>Тип</label>
                        <select id="drawerType" name="type">
                            <option value="important-urgent">🔴 Важлива термінова</option>
                            <option value="important-not-urgent">🔵 Важлива нетермінова</option>
                            <option value="not-important-urgent">🟣 Неважлива термінова</option>
                            <option value="not-important-not-urgent">⚪ Неважлива нетермінова</option>
                        </select>
                        <div id="drawerTypeHint" style="margin-top:6px;font-size:12px;color:#64748b;line-height:1.4;">
                        </div>
                        <div class="drawer-type-preview-wrap">
                            <span id="drawerTypePreview"
                                class="task-type-pill drawer-type-preview type-important-not-urgent">
                                <span class="task-type-dot" aria-hidden="true"></span>
                                <span id="drawerTypePreviewText">Важлива нетермінова</span>
                            </span>
                        </div>
                    </div>
                    <div class="drawer-field">
                        <label>Очікуваний час виконання, хв <span style="color:#ef4444">*</span></label>
                        <input id="drawerExpectedTime" type="number" min="1" step="1" name="expected_time" />
                        <div id="drawerExpectedTimeError" class="drawer-field-error" style="display:none;"></div>
                        <div class="drawer-field-hint">Дозволяє розуміти, скільки і яких реально задач можна поставити в
                            день.</div>
                    </div>
                </div>

                <div class="drawer-grid-2">
                    <div class="drawer-field">
                        <label>Статус</label>
                        <select id="drawerStatus" name="status">
                            <option value="todo">Нова</option>
                            <option value="in-progress">В процесі</option>
                            <option value="done">Завершено</option>
                            <option value="postponed">Відкладено</option>
                        </select>
                    </div>
                    <div class="drawer-field">
                        <label>Фактичний час виконання (хв)</label>
                        <input id="drawerActualTime" type="number" min="0" step="1" name="actual_time" />
                    </div>
                </div>

                <div class="drawer-grid-2">
                    <div class="drawer-field">
                        <label>Відповідальний</label>
                        <div id="drawerAssigneeSingleWrap">
                            <select id="drawerAssignee" name="assignee_id" required>
                                <?php foreach (($employees ?? []) as $emp): ?>
                                    <?php $emp_name = trim((string) (($emp['first_name'] ?? '') . ' ' . ($emp['last_name'] ?? ''))) ?: '—'; ?>
                                    <option value="<?php echo (int) ($emp['user_id'] ?? 0); ?>">
                                        <?php echo htmlspecialchars($emp_name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div id="drawerAssigneeMultiWrap" style="display:none;">
                            <select id="drawerAssignees" name="assignee_ids[]" multiple disabled>
                                <?php foreach (($employees ?? []) as $emp): ?>
                                    <?php $emp_name = trim((string) (($emp['first_name'] ?? '') . ' ' . ($emp['last_name'] ?? ''))) ?: '—'; ?>
                                    <option value="<?php echo (int) ($emp['user_id'] ?? 0); ?>">
                                        <?php echo htmlspecialchars($emp_name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="drawer-field-hint">Можна вибрати кількох виконавців. Для кожного буде створена
                                окрема задача. Щоб вибрати кілька людей, затисніть Ctrl.</div>
                        </div>
                    </div>
                    <div class="drawer-field">
                        <label>Призначив</label>
                        <div class="drawer-people" id="drawerReporter">—</div>
                    </div>
                </div>

                <div class="drawer-actions">
                    <button type="button" class="drawer-btn cancel" id="drawerCancelBtn">Скасувати</button>
                    <button type="submit" class="drawer-btn save">Зберегти</button>
                </div>
            </div>
        </form>
    </aside>

    <div class="task-complete-modal" id="taskCompleteModal" aria-hidden="true">
        <form id="taskCompleteForm" method="post" action="/tasks/edit/0">
            <div class="task-complete-modal-body">
                <h3>Завершити задачу</h3>
                <p id="taskCompleteText">Щоб позначити задачу виконаною, опишіть фактичний результат.</p>
                <textarea id="taskCompleteResult" name="actual_result"
                    placeholder="Що саме було зроблено та який результат отримано" required></textarea>
                <label for="taskCompleteActualTime"
                    style="display:block;margin:8px 0 6px;font-size:13px;color:#334155;font-weight:600;">Фактичний час
                    виконання (хв)</label>
                <input id="taskCompleteActualTime" name="actual_time" type="number" min="0" step="1"
                    placeholder="Наприклад: 45"
                    style="width:100%;border:1px solid #cbd5e1;border-radius:10px;padding:10px 12px;font:inherit;" />
                <label for="taskCompleteDueDay"
                    style="display:block;margin:8px 0 6px;font-size:13px;color:#334155;font-weight:600;">Дата виконання задачі</label>
                <input id="taskCompleteDueDay" name="completion_date" type="date"
                    max="<?php echo date('Y-m-d'); ?>"
                    style="width:100%;border:1px solid #cbd5e1;border-radius:10px;padding:10px 12px;font:inherit;" />
                <input type="hidden" name="status" value="done" />
                <input type="hidden" name="return_url"
                    value="<?php echo htmlspecialchars($_SERVER['REQUEST_URI'] ?? '/tasks'); ?>" />
                <div class="task-complete-actions">
                    <button type="button" class="drawer-btn cancel" id="taskCompleteCancel">Скасувати</button>
                    <button type="submit" class="drawer-btn save">Завершити</button>
                </div>
            </div>
        </form>
    </div>

</section>
<?php
$content = ob_get_clean();
require APP_PATH . '/Views/layouts/main.php';
