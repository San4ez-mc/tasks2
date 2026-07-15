<?php
/**
 * Список результатів
 */
$title = 'Цілі';
$layout_container_class = 'container-wide';

$selected_tab = $selected_tab ?? 'my';
$selected_status = $selected_status ?? 'all';
$selected_assignee = $selected_assignee ?? 'all';
$selected_reporter = $selected_reporter ?? 'all';
$search_query = $search_query ?? '';
$drawer_mode = $drawer_mode ?? '';
$drawer_result_id = $drawer_result_id ?? 0;
$drawer_error = flash('error');
$drawer_success = flash('success');
$drawer_form_data = flash('result_form_data');
$drawer_form_payload = [];
if (is_string($drawer_form_data) && $drawer_form_data !== '') {
    $decoded_drawer_form = json_decode($drawer_form_data, true);
    if (is_array($decoded_drawer_form)) {
        $drawer_form_payload = $decoded_drawer_form;
    }
}

$tabs = [
    'my' => 'Мої',
    'delegated' => 'Делеговані',
    'subordinates' => 'Підлеглих',
    'postponed' => 'Відкладені',
];

$status_options = [
    'all' => 'Всі статуси',
    'in-progress' => 'В процесі',
    'done' => 'Завершено',
    'postponed' => 'Відкладено',
];

$status_label = static function (array $result): string {
    $status = (string) ($result['status'] ?? (((int) ($result['completed'] ?? 0) === 1) ? 'done' : 'in-progress'));
    $map = [
        'in-progress' => 'В процесі',
        'done' => 'Завершено',
        'postponed' => 'Відкладено',
    ];
    return $map[$status] ?? 'В процесі';
};

$status_class = static function (array $result): string {
    $status = (string) ($result['status'] ?? (((int) ($result['completed'] ?? 0) === 1) ? 'done' : 'in-progress'));
    if ($status === 'done') {
        return 'status-done';
    }
    if ($status === 'postponed') {
        return 'status-postponed';
    }
    return 'status-progress';
};

$format_date = static function ($value): string {
    if (!$value) {
        return '—';
    }

    $ts = strtotime((string) $value);
    if (!$ts) {
        return '—';
    }

    $months = [
        1 => 'січ.',
        2 => 'лют.',
        3 => 'бер.',
        4 => 'квіт.',
        5 => 'трав.',
        6 => 'черв.',
        7 => 'лип.',
        8 => 'серп.',
        9 => 'вер.',
        10 => 'жовт.',
        11 => 'лист.',
        12 => 'груд.',
    ];

    return date('j', $ts) . ' ' . ($months[(int) date('n', $ts)] ?? '') . ' ' . date('Y', $ts);
};

$build_result_payload = static function (array $result) use ($status_label, $format_date): array {
    $assignee_name = trim((string) (($result['assignee_first_name'] ?? '') . ' ' . ($result['assignee_last_name'] ?? '')));
    $reporter_name = trim((string) (($result['reporter_first_name'] ?? '') . ' ' . ($result['reporter_last_name'] ?? '')));

    return [
        'id' => (int) ($result['id'] ?? 0),
        'title' => (string) ($result['title'] ?? ''),
        'description' => (string) ($result['description'] ?? ''),
        'expected_result' => (string) ($result['expected_result'] ?? ''),
        'parent_id' => (int) ($result['parent_id'] ?? 0),
        'completed' => (int) ($result['completed'] ?? 0),
        'status' => (string) ($result['status'] ?? (((int) ($result['completed'] ?? 0) === 1) ? 'done' : 'in-progress')),
        'instruction' => (string) ($result['instruction'] ?? ''),
        'statusLabel' => $status_label($result),
        'deadlineLabel' => $format_date($result['deadline'] ?? $result['created_at'] ?? null),
        'deadlineRaw' => (string) ($result['deadline'] ?? ''),
        'assigneeName' => $assignee_name ?: '—',
        'reporterName' => $reporter_name ?: '—',
        'assigneeId' => (int) ($result['assignee_id'] ?? 0),
    ];
};

$make_url = static function (array $overrides = []) use ($selected_tab, $selected_status, $selected_assignee, $selected_reporter, $search_query) {
    $params = [
        'tab' => $selected_tab,
        'status' => $selected_status,
        'assignee' => $selected_assignee,
        'reporter' => $selected_reporter,
        'q' => $search_query,
    ];

    foreach ($overrides as $key => $value) {
        $params[$key] = $value;
    }

    return '/results?' . http_build_query($params);
};

$task_counts = [];
foreach ($results as $result) {
    $task_counts[(int) $result['id']] = 0;
}

$results_with_payload = [];
$result_payload_map = [];
foreach ($results as $result) {
    $payload = $build_result_payload($result);

    $results_with_payload[] = [
        'row' => $result,
        'payload' => $payload,
    ];
    $result_payload_map[(int) ($result['id'] ?? 0)] = $payload;
}

foreach ($children_map as $child_rows) {
    foreach ($child_rows as $child_row) {
        $child_id = (int) ($child_row['id'] ?? 0);
        if ($child_id <= 0) {
            continue;
        }

        $result_payload_map[$child_id] = $build_result_payload($child_row);
    }
}

$render_result_tree_rows = function (array $result, int $depth = 0, array $ancestor_ids = []) use (&$render_result_tree_rows, $children_map, $build_result_payload, $status_class, $status_label, $format_date) {
    $rid = (int) ($result['id'] ?? 0);
    $sub_goals = $children_map[$rid] ?? [];
    $has_children = !empty($sub_goals);
    $payload_json = htmlspecialchars(json_encode($build_result_payload($result), JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
    $title_text = htmlspecialchars((string) ($result['title'] ?? ''));
    $desc_text = htmlspecialchars((string) ($result['description'] ?? ''));
    $indent_px = max(0, $depth) * 20;
    $ancestor_attr = htmlspecialchars(implode(',', array_map('intval', $ancestor_ids)), ENT_QUOTES, 'UTF-8');
    $is_done = (int) ($result['completed'] ?? 0) === 1 || ($result['status'] ?? '') === 'done';
    $row_class = $depth > 0 ? 'result-row result-row-subgoal open-result-drawer' : 'result-row open-result-drawer';
    if ($is_done) {
        $row_class .= ' is-done';
    }

    ob_start();
    ?>
    <div class="<?php echo $row_class; ?>" data-href="/results/view/<?php echo $rid; ?>"
        data-result="<?php echo $payload_json; ?>" data-node-id="<?php echo $rid; ?>"
        data-ancestors="<?php echo $ancestor_attr; ?>">
        <div>
            <form method="post" action="/results/edit/<?php echo $rid; ?>" onclick="event.stopPropagation();"
                style="background:transparent;box-shadow:none;padding:0;margin:0;">
                <input type="hidden" name="title" value="<?php echo $title_text; ?>" />
                <input type="hidden" name="description" value="<?php echo $desc_text; ?>" />
                <input type="hidden" name="assignee_id" value="<?php echo (int) ($result['assignee_id'] ?? 0); ?>" />
                <input type="hidden" name="completed" value="<?php echo (int) (!empty($result['completed']) ? 0 : 1); ?>" />
                <input type="hidden" name="return_url"
                    value="<?php echo htmlspecialchars($_SERVER['REQUEST_URI'] ?? '/results'); ?>" />
                <input class="result-check" type="checkbox" <?php echo (int) ($result['completed'] ?? 0) === 1 ? 'checked' : ''; ?> onchange="this.form.submit()" />
            </form>
        </div>
        <div>
            <div class="result-tree" style="padding-left: <?php echo $indent_px; ?>px;">
                <?php if ($has_children): ?>
                    <button type="button" class="tree-toggle" data-node-id="<?php echo $rid; ?>" aria-expanded="true"
                        title="Розгорнути/згорнути гілку">▾</button>
                <?php else: ?>
                    <span class="tree-spacer"></span>
                <?php endif; ?>
                <div class="result-tree-content">
                    <div class="result-title"><?php echo $title_text; ?></div>
                </div>
            </div>
        </div>
        <div class="user-mini">
            <?php echo htmlspecialchars($format_date($result['deadline'] ?? $result['created_at'] ?? null)); ?>
        </div>
        <div class="user-mini">
            <?php echo htmlspecialchars(trim(($result['assignee_first_name'] ?? '') . ' ' . ($result['assignee_last_name'] ?? '')) ?: '—'); ?>
        </div>
        <div class="user-mini hide-lg">
            <?php echo htmlspecialchars(trim(($result['reporter_first_name'] ?? '') . ' ' . ($result['reporter_last_name'] ?? '')) ?: '—'); ?>
        </div>
        <div><span
                class="status-badge <?php echo $status_class($result); ?>"><?php echo htmlspecialchars($status_label($result)); ?></span>
        </div>
        <div class="result-row-end" onclick="event.stopPropagation();">
            <button type="button" class="btn-add-subgoal" data-target="ria-<?php echo $rid; ?>" title="Додати підціль">+</button>
            <span class="row-arrow">&#8250;</span>
        </div>
    </div>
    <div class="result-inline-add" id="ria-<?php echo $rid; ?>" style="display:none;" data-parent-id="<?php echo $rid; ?>">
        <div class="ria-inner" style="padding-left:<?php echo ($indent_px + 30); ?>px;">
            <span class="ria-icon">&#8627;</span>
            <input type="text" class="ria-input" placeholder="Назва підцілі… Enter для збереження" autocomplete="off">
            <div class="ria-btns">
                <button type="button" class="ria-save">Додати</button>
                <button type="button" class="ria-cancel">&#10005;</button>
            </div>
        </div>
    </div>
    <?php

    foreach ($sub_goals as $sub_goal) {
        echo $render_result_tree_rows($sub_goal, $depth + 1, array_merge($ancestor_ids, [$rid]));
    }

    return ob_get_clean();
};
?>
<?php
$extra_head = '<link rel="stylesheet" href="/public/css/results.css">';
$extra_scripts = '<script src="/public/js/results.js"></script>';

ob_start();
?>
<script>
    window.RI = {
        currentUserId: <?php echo (int) (get_user()['id'] ?? 0); ?>,
        currentUserName: <?php echo json_encode(trim((string) (get_user()['first_name'] ?? '') . ' ' . (string) (get_user()['last_name'] ?? '')) ?: (string) (get_user()['first_name'] ?? 'ористувач'), JSON_UNESCAPED_UNICODE); ?>,
        initialDrawerMode: <?php echo json_encode((string) ($drawer_mode ?? ''), JSON_UNESCAPED_UNICODE); ?>,
        initialDrawerResultId: <?php echo (int) ($drawer_result_id ?? 0); ?>,
        resultPayloadMap: <?php echo json_encode($result_payload_map ?? [], JSON_UNESCAPED_UNICODE); ?>,
        drawerFormPayload: <?php echo json_encode($drawer_form_payload ?? null, JSON_UNESCAPED_UNICODE); ?>,
        resultDescendantsMap: <?php echo json_encode($result_descendants_map ?? [], JSON_UNESCAPED_UNICODE); ?>
    };
</script>
<section class="results-page">

    <div class="results-shell">
        <header class="results-header">
            <div class="results-title-row" style="position:relative;">
                <h1>Цілі</h1>
                <a href="<?php echo htmlspecialchars(defined('TELEGRAM_BOT_USERNAME') && TELEGRAM_BOT_USERNAME ? 'https://t.me/' . TELEGRAM_BOT_USERNAME : '#'); ?>"
                    target="_blank" rel="noopener noreferrer">&#9993;</a>
                <div class="results-title-actions">
                    <button type="button" id="addGoalBtn" class="btn-add-goal">+ Додати ціль</button>
                    <div class="view-toggle" id="viewToggle">
                        <button class="view-btn active" type="button" data-view="table">☰</button>
                        <button class="view-btn" type="button" data-view="cards">◻</button>
                    </div>
                </div>
            </div>

            <form method="get" action="/results">
                <div class="results-filters">
                    <div class="tabs">
                        <?php foreach ($tabs as $tab_key => $tab_name): ?>
                            <a class="tab <?php echo $selected_tab === $tab_key ? 'active' : ''; ?>"
                                href="<?php echo htmlspecialchars($make_url(['tab' => $tab_key])); ?>"><?php echo htmlspecialchars($tab_name); ?></a>
                        <?php endforeach; ?>
                    </div>
                    <div class="search-box">
                        <input type="text" name="q" value="<?php echo htmlspecialchars($search_query); ?>"
                            placeholder="Пошук..." />
                    </div>
                </div>

                <div class="toolbar-2">
                    <select name="status">
                        <?php foreach ($status_options as $status_key => $status_name): ?>
                            <option value="<?php echo htmlspecialchars($status_key); ?>" <?php echo $selected_status === $status_key ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($status_name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <select name="reporter">
                        <option value="all">Фільтр по постановщику</option>
                        <?php foreach ($employees as $employee):
                            $full_name = trim(($employee['first_name'] ?? '') . ' ' . ($employee['last_name'] ?? ''));
                            $id = (string) ($employee['user_id'] ?? '');
                            ?>
                            <option value="<?php echo htmlspecialchars($id); ?>" <?php echo $selected_reporter === $id ? 'selected' : ''; ?>><?php echo htmlspecialchars($full_name ?: '—'); ?></option>
                        <?php endforeach; ?>
                    </select>

                    <select name="assignee">
                        <option value="all">Фільтр по відповідальному</option>
                        <?php foreach ($employees as $employee):
                            $full_name = trim(($employee['first_name'] ?? '') . ' ' . ($employee['last_name'] ?? ''));
                            $id = (string) ($employee['user_id'] ?? '');
                            ?>
                            <option value="<?php echo htmlspecialchars($id); ?>" <?php echo $selected_assignee === $id ? 'selected' : ''; ?>><?php echo htmlspecialchars($full_name ?: '—'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <input type="hidden" name="tab" value="<?php echo htmlspecialchars($selected_tab); ?>" />
                <button type="submit" style="display:none;">apply</button>
            </form>
        </header>

        <div class="results-content" id="tableView">
            <?php if (empty($results_with_payload)): ?>
                <div class="empty-block">Цілей за вибраними фільтрами не знайдено.</div>
            <?php else: ?>
                <div class="results-table-wrap">
                    <div class="tree-controls">
                        <button type="button" class="tree-control-btn" id="expandAllTreeBtn">Розгорнути все</button>
                        <button type="button" class="tree-control-btn" id="collapseAllTreeBtn">Згорнути все</button>
                    </div>
                    <div class="results-grid-head">
                        <div></div>
                        <div>Назва</div>
                        <div>Дедлайн</div>
                        <div>Відповідальний</div>
                        <div class="hide-lg">Постановщик</div>
                        <div>Статус</div>
                        <div>Дії</div>
                    </div>

                    <?php foreach ($results_with_payload as $item):
                        $result = $item['row'];
                        ?>
                        <?php echo $render_result_tree_rows($result, 0, []); ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="results-content cards-view" id="cardsView">
            <?php
            // Recursive renderer for nested sub-goals in card view
            $render_card_subtree = function (int $parent_id, int $depth = 0) use (&$render_card_subtree, $children_map): string {
                $children = $children_map[$parent_id] ?? [];
                if (empty($children)) {
                    return '';
                }
                $max_shown = $depth === 0 ? 6 : 4;
                $items_html = '';
                $shown = 0;
                foreach ($children as $child) {
                    if ($shown >= $max_shown) {
                        $remaining = count($children) - $shown;
                        $items_html .= '<li class="cst-more">+' . $remaining . ' ще…</li>';
                        break;
                    }
                    $cid = (int) ($child['id'] ?? 0);
                    $cdone = (int) ($child['completed'] ?? 0) === 1 || ($child['status'] ?? '') === 'done';
                    $cpostponed = ($child['status'] ?? '') === 'postponed';
                    $dot = $cdone ? 'done' : ($cpostponed ? 'postponed' : 'active');
                    $ctitle = htmlspecialchars((string) ($child['title'] ?? ''));
                    $sub_ch = $children_map[$cid] ?? [];
                    $items_html .= '<li class="cst-item">';
                    $items_html .= '<div class="cst-row">';
                    $items_html .= '<span class="cst-dot ' . $dot . '"></span>';
                    $items_html .= '<span class="cst-title' . ($cdone ? ' done' : '') . '">' . $ctitle . '</span>';
                    if (!empty($sub_ch) && $depth >= 1) {
                        $items_html .= '<span class="cst-sub-count">+' . count($sub_ch) . '</span>';
                    }
                    $items_html .= '</div>';
                    if ($depth < 1) {
                        $items_html .= $render_card_subtree($cid, $depth + 1);
                    }
                    $items_html .= '</li>';
                    $shown++;
                }
                $class = 'cst-list' . ($depth > 0 ? ' cst-nested' : '');
                return '<ul class="' . $class . '">' . $items_html . '</ul>';
            };
            ?>
            <?php if (empty($results_with_payload)): ?>
                <div class="empty-block" style="grid-column:1/-1;">Цілей за вибраними фільтрами не знайдено.</div>
            <?php else: ?>
                <?php foreach ($results_with_payload as $item):
                    $result = $item['row'];
                    $payload_json = htmlspecialchars(json_encode($item['payload'], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
                    $rid = (int) $result['id'];
                    $sub_goals = $children_map[$rid] ?? [];
                    $total_sub = count($sub_goals);
                    $done_sub = 0;
                    foreach ($sub_goals as $sg) {
                        if ((int) ($sg['completed'] ?? 0) === 1 || ($sg['status'] ?? '') === 'done') {
                            $done_sub++;
                        }
                    }
                    $progress_pct = $total_sub > 0 ? round($done_sub / $total_sub * 100) : 0;
                    ?>
                    <a href="/results/view/<?php echo $rid; ?>" class="result-card open-result-drawer"
                        data-result="<?php echo $payload_json; ?>">
                        <div class="result-card-body">
                            <h3><?php echo htmlspecialchars($result['title'] ?? ''); ?></h3>
                            <div class="result-card-desc"><?php echo htmlspecialchars($result['description'] ?? ''); ?></div>

                            <div class="prog-wrap">
                                <div class="prog-labels">
                                    <span>Прогрес</span>
                                    <span><?php echo $progress_pct; ?>%</span>
                                </div>
                                <div class="prog-bar-bg">
                                    <div class="prog-bar-fill" style="width:<?php echo $progress_pct; ?>%;"></div>
                                </div>
                            </div>

                            <div class="result-card-meta">
                                <div class="result-card-meta-row">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24"
                                        fill="none" stroke="#94a3b8" stroke-width="2">
                                        <path d="M8 2v4" />
                                        <path d="M16 2v4" />
                                        <rect width="18" height="18" x="3" y="4" rx="2" />
                                        <path d="M3 10h18" />
                                    </svg>
                                    <?php
                                    $dl = $format_date($result['deadline'] ?? $result['created_at'] ?? null);
                                    $is_overdue = !empty($result['deadline']) && strtotime((string) $result['deadline']) < time() && ($result['status'] ?? '') !== 'done';
                                    ?>
                                    <span <?php if ($is_overdue)
                                        echo 'style="color:#ef4444;"'; ?>><?php echo htmlspecialchars($dl); ?></span>
                                    <?php if ($is_overdue): ?>
                                        <span
                                            style="background:#ef4444;color:#fff;font-size:10px;font-weight:700;padding:2px 7px;border-radius:5px;">Прострочено</span>
                                    <?php endif; ?>
                                </div>
                                <div class="result-card-meta-row">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24"
                                        fill="none" stroke="#94a3b8" stroke-width="2">
                                        <path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2" />
                                        <circle cx="12" cy="7" r="4" />
                                    </svg>
                                    <span><?php echo htmlspecialchars(trim(($result['assignee_first_name'] ?? '') . ' ' . ($result['assignee_last_name'] ?? '')) ?: '—'); ?></span>
                                </div>
                                <div class="result-card-meta-row" style="justify-content:space-between;">
                                    <span
                                        class="status-badge <?php echo $status_class($result); ?>"><?php echo htmlspecialchars($status_label($result)); ?></span>
                                    <?php if ($total_sub > 0): ?>
                                        <span
                                            style="font-size:11px;color:#94a3b8;"><?php echo $done_sub; ?>/<?php echo $total_sub; ?>
                                            підцілей</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <?php if (!empty($sub_goals)): ?>
                            <div class="card-sub-goals" onclick="void(0);">
                                <div class="card-sub-goals-title">
                                    <span>Підцілі</span>
                                    <span class="cst-progress"><?php echo $done_sub; ?>/<?php echo $total_sub; ?></span>
                                </div>
                                <?php echo $render_card_subtree($rid, 0); ?>
                            </div>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="drawer-overlay" id="resultDrawerOverlay"></div>
    <aside class="result-drawer" id="resultDrawer">
        <form id="resultDrawerForm" method="post" action="/results/edit/0"
            style="background:transparent;box-shadow:none;padding:0;margin:0;border-radius:0;height:100%;display:flex;flex-direction:column;">
            <div class="drawer-head">
                <input id="drawerTitle" name="title" type="text" placeholder="Назва цілі" />
                <button type="button" id="resultDrawerClose" class="btn-d btn-cancel"
                    style="padding:8px 10px;">✕</button>
            </div>

            <div class="drawer-body">
                <input type="hidden" name="return_url"
                    value="<?php echo htmlspecialchars($_SERVER['REQUEST_URI'] ?? '/results'); ?>" />

                <?php if ($drawer_error): ?>
                    <div class="drawer-alert drawer-alert-error" id="drawerErrorBox">
                        <?php echo htmlspecialchars($drawer_error); ?>
                    </div>
                <?php else: ?>
                    <div class="drawer-alert drawer-alert-error" id="drawerErrorBox" style="display:none;"></div>
                <?php endif; ?>

                <?php if ($drawer_success): ?>
                    <div class="drawer-alert drawer-alert-success" id="drawerSuccessBox">
                        <?php echo htmlspecialchars($drawer_success); ?>
                    </div>
                <?php else: ?>
                    <div class="drawer-alert drawer-alert-success" id="drawerSuccessBox" style="display:none;"></div>
                <?php endif; ?>

                <div class="field">
                    <label>Опис</label>
                    <textarea id="drawerDescription" name="description" placeholder="Додайте опис цілі..."></textarea>
                </div>

                <div class="field">
                    <label>Очікуваний результат</label>
                    <textarea id="drawerExpectedResult" name="expected_result"
                        placeholder="Що має бути отримано в результаті..."></textarea>
                </div>

                <div class="field">
                    <label>Інструкція</label>
                    <textarea id="drawerInstruction" name="instruction" placeholder="Додайте інструкцію..."></textarea>
                </div>

                <div class="grid-2">
                    <div class="field">
                        <label>Статус</label>
                        <select id="drawerStatus" name="status">
                            <option value="in-progress">В процесі</option>
                            <option value="done">Завершено</option>
                            <option value="postponed">Відкладено</option>
                        </select>
                    </div>
                    <div class="field">
                        <label>Відповідальний</label>
                        <select id="drawerAssignee" name="assignee_id">
                            <option value="">Не призначено</option>
                            <?php foreach ($employees as $employee):
                                $full_name = trim(($employee['first_name'] ?? '') . ' ' . ($employee['last_name'] ?? ''));
                                $id = (string) ($employee['user_id'] ?? '');
                                ?>
                                <option value="<?php echo htmlspecialchars($id); ?>">
                                    <?php echo htmlspecialchars($full_name ?: '—'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="field">
                    <label>Батьківська ціль</label>
                    <select id="drawerParentId" name="parent_id">
                        <option value="">Без батьківської цілі</option>
                        <?php foreach (($parent_goal_options ?? []) as $option): ?>
                            <option value="<?php echo (int) ($option['id'] ?? 0); ?>">
                                <?php echo htmlspecialchars((string) ($option['label'] ?? '')); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="grid-2">
                    <div class="field">
                        <label>Дедлайн</label>
                        <input type="date" id="drawerDeadline" name="deadline" />
                    </div>
                    <div class="field">
                        <label>Постановщик</label>
                        <div class="readonly-box" id="drawerReporter">—</div>
                    </div>
                </div>

                <div class="drawer-section" id="drawerSubgoalsSection" style="display:none;">
                    <div class="drawer-section-head">
                        <span id="drawerSubgoalsTitle" class="drawer-section-title">Підцілі</span>
                        <button type="button" id="drawerAddSubgoalBtn" class="btn-add-subgoal-drawer">+ Підціль</button>
                    </div>
                    <div id="drawerSubgoalsList" class="drawer-subgoals-list"></div>
                </div>

                <div class="drawer-actions">
                    <a id="drawerDeleteLink" class="btn-d btn-delete" href="#"
                        onclick="return confirm('Ви впевнені, що хочете видалити ціль?');">Видалити</a>
                    <div class="btn-row">
                        <button type="button" id="resultDrawerCancel" class="btn-d btn-cancel">Скасувати</button>
                        <button type="submit" class="btn-d btn-save">Зберегти</button>
                    </div>
                </div>
            </div>
        </form>
    </aside>

</section>
<?php
$content = ob_get_clean();
require APP_PATH . '/Views/layouts/main.php';
