<?php
/**
 * Список шаблонів задач
 */
$title = 'Шаблони';
$layout_container_class = 'container-wide';
$drawer_mode = get_param('drawer', '');
$drawer_template_id = (int) get_param('id', 0);
$scope = $scope ?? 'my';
$is_owner = !empty($is_owner);
$scope_tabs = ['my' => 'Мої'];
if ($is_owner) {
    $scope_tabs['all'] = 'Всі';
}
$scope_url = static function (string $scopeKey): string {
    return '/templates?' . http_build_query(['scope' => $scopeKey]);
};

// ── helpers ────────────────────────────────────────────────────────────────

$type_label = static function (string $t): string {
    return match ($t) {
        'important-urgent' => 'Важлива термінова',
        'important-not-urgent' => 'Важлива нетермінова',
        'not-important-urgent' => 'Неважлива термінова',
        'not-important-not-urgent' => 'Неважлива нетермінова',
        default => '—',
    };
};

$type_class = static function (string $t): string {
    return match ($t) {
        'important-urgent' => 'type-important-urgent',
        'important-not-urgent' => 'type-important-not-urgent',
        'not-important-urgent' => 'type-not-important-urgent',
        'not-important-not-urgent' => 'type-not-important-not-urgent',
        default => 'type-none',
    };
};

$repeat_label = static function (string $r, ?string $day): string {
    $day = trim((string) $day);
    $days_label = $day !== '' ? str_replace(',', ', ', $day) : '';
    return match ($r) {
        'daily' => 'Щодня',
        'weekly' => 'Щотижня' . ($days_label ? ' (' . $days_label . ')' : ''),
        'monthly' => 'Щомісяця' . ($days_label ? ' (' . $days_label . ')' : ''),
        default => '—',
    };
};

$format_time = static function (?int $minutes): string {
    if (!$minutes) {
        return '—';
    }
    if ($minutes < 60) {
        return $minutes . ' хв';
    }
    $h = intdiv($minutes, 60);
    $m = $minutes % 60;
    return $m ? "{$h} год {$m} хв" : "{$h} год";
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

$short_text = static function (?string $s, int $max = 55): string {
    if (!$s) {
        return '—';
    }
    $s = strip_tags(trim($s));
    return mb_strlen($s) > $max ? mb_substr($s, 0, $max) . '…' : $s;
};

$avatar_bg = static function (string $name): string {
    $colors = ['#3b82f6', '#ef4444', '#10b981', '#f59e0b', '#8b5cf6', '#ec4899', '#06b6d4', '#84cc16'];
    return $colors[abs(crc32($name)) % count($colors)];
};

$initials = static function (string $first, string $last): string {
    return mb_strtoupper(mb_substr($first, 0, 1) . mb_substr($last, 0, 1));
};

// Prepare employee list for select boxes
$employee_options = [];
$employee_name_map = [];
foreach ($employees as $emp) {
    $emp_id = (int) ($emp['user_id'] ?? 0);
    $emp_name = trim($emp['first_name'] . ' ' . ($emp['last_name'] ?? ''));
    $employee_options[] = [
        'id' => $emp_id,
        'name' => $emp_name,
        'initials' => $initials(
            (string) ($emp['first_name'] ?? ''),
            (string) ($emp['last_name'] ?? '')
        ),
        'color' => $avatar_bg($emp_name),
    ];
    if ($emp_id > 0) {
        $employee_name_map[$emp_id] = $emp_name;
    }
}

$drawer_payload = null;
if ($drawer_mode === 'edit' && $drawer_template_id > 0) {
    foreach ($templates as $template) {
        if ((int) ($template['id'] ?? 0) !== $drawer_template_id) {
            continue;
        }

        $drawer_payload = [
            'id' => (int) ($template['id'] ?? 0),
            'name' => (string) ($template['name'] ?? ''),
            'type' => (string) ($template['type'] ?? ''),
            'description' => (string) ($template['description'] ?? ''),
            'expected_result' => (string) ($template['expected_result'] ?? ''),
            'assignee_id' => $template['assignee_id'] ?? '',
            'assignee_ids' => (string) ($template['assignee_ids'] ?? ''),
            'expected_time' => $template['expected_time'] ?? '',
            'repeat_type' => (string) ($template['repeat_type'] ?? 'none'),
            'repeat_day' => (string) ($template['repeat_day'] ?? ''),
            'start_time' => (string) ($template['start_time'] ?? ''),
        ];
        break;
    }
}

$extra_head = '<link rel="stylesheet" href="/public/css/templates.css">';
$extra_scripts = '<script src="/public/js/templates.js"></script>';
ob_start();
?>
<script>
    window.TmpI = {
        initialDrawerMode: <?php echo json_encode((string) ($drawer_mode ?? ''), JSON_UNESCAPED_UNICODE); ?>,
        initialDrawerPayload: <?php echo json_encode($drawer_payload ?? null, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>
    };
</script>

<div class="tpl-page">

    <?php
    $success = flash('success');
    $error = flash('error');
    ?>
    <?php if ($success): ?>
        <div class="flash-msg success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="flash-msg error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <!-- page header -->
    <div class="tpl-header">
        <div class="tpl-header-title">
            <h1>Шаблони задач</h1>
            <p>Створюйте та керуйте шаблонами для автоматичного створення задач</p>
            <div class="tpl-scope-tabs">
                <?php foreach ($scope_tabs as $scope_key => $scope_label): ?>
                    <a class="tpl-scope-tab <?php echo $scope === $scope_key ? 'active' : ''; ?>"
                        href="<?php echo htmlspecialchars($scope_url($scope_key)); ?>"><?php echo htmlspecialchars($scope_label); ?></a>
                <?php endforeach; ?>
            </div>
        </div>
        <button type="button" class="btn-create-tpl" id="btnOpenCreate">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
                stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <path d="M5 12h14" />
                <path d="M12 5v14" />
            </svg>
            Створити шаблон
        </button>
    </div>

    <!-- templates table -->
    <div class="tpl-card tpl-table-wrap">
        <table class="tpl-table">
            <thead>
                <tr>
                    <th>Назва</th>
                    <th>Тип</th>
                    <th>Відповідальний</th>
                    <th>Повторення</th>
                    <th>Очікуваний час</th>
                    <th>Створено разів</th>
                    <th>Оновлено</th>
                    <th style="width:80px;"></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($templates)): ?>
                    <tr>
                        <td colspan="8">
                            <div class="tpl-empty">
                                <div class="tpl-empty-title">Шаблони відсутні</div>
                                <p>Натисніть «Створити шаблон», щоб додати перший шаблон</p>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($templates as $tpl):
                        $tpl_id = (int) $tpl['id'];
                        $t_type = (string) ($tpl['type'] ?? '');
                        $t_repeat = (string) ($tpl['repeat_type'] ?? 'none');
                        $t_day = (string) ($tpl['repeat_day'] ?? '');
                        $t_assignee_ids_raw = trim((string) ($tpl['assignee_ids'] ?? ''));
                        $t_assignee_ids = [];
                        if ($t_assignee_ids_raw !== '') {
                            foreach (explode(',', $t_assignee_ids_raw) as $raw_id) {
                                $assignee_id = (int) trim($raw_id);
                                if ($assignee_id > 0) {
                                    $t_assignee_ids[$assignee_id] = $assignee_id;
                                }
                            }
                        }
                        $single_assignee_id = (int) ($tpl['assignee_id'] ?? 0);
                        if ($single_assignee_id > 0) {
                            $t_assignee_ids[$single_assignee_id] = $single_assignee_id;
                        }
                        $assignee_fn = (string) ($tpl['assignee_first_name'] ?? '');
                        $assignee_ln = (string) ($tpl['assignee_last_name'] ?? '');
                        $assignee_name = trim($assignee_fn . ' ' . $assignee_ln);
                        $assignee_names = [];
                        foreach ($t_assignee_ids as $assignee_id) {
                            if (!empty($employee_name_map[$assignee_id])) {
                                $assignee_names[] = $employee_name_map[$assignee_id];
                            }
                        }
                        if (empty($assignee_names) && $assignee_name !== '') {
                            $assignee_names[] = $assignee_name;
                        }
                        $assignee_display = implode(', ', $assignee_names);
                        $payload = json_encode([
                            'id' => $tpl_id,
                            'name' => $tpl['name'] ?? '',
                            'type' => $t_type,
                            'description' => $tpl['description'] ?? '',
                            'expected_result' => $tpl['expected_result'] ?? '',
                            'assignee_id' => $tpl['assignee_id'] ?? '',
                            'assignee_ids' => $tpl['assignee_ids'] ?? '',
                            'expected_time' => $tpl['expected_time'] ?? '',
                            'repeat_type' => $t_repeat,
                            'repeat_day' => $t_day,
                            'start_time' => $tpl['start_time'] ?? '',
                        ], JSON_HEX_QUOT | JSON_HEX_APOS);
                        ?>
                        <tr class="tpl-row" data-payload='<?php echo htmlspecialchars($payload, ENT_QUOTES); ?>'>
                            <td>
                                <div class="tpl-name-main"><?php echo htmlspecialchars($tpl['name'] ?? '—'); ?></div>
                                <?php if (!empty($tpl['description'])): ?>
                                    <div class="tpl-name-sub"><?php echo htmlspecialchars($short_text($tpl['description'])); ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($t_type): ?>
                                    <span class="task-type-pill <?php echo $type_class($t_type); ?>">
                                        <span class="task-type-dot"></span>
                                        <?php echo $type_label($t_type); ?>
                                    </span>
                                <?php else: ?>
                                    <span style="color:#9ca3af;">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($assignee_display !== ''): ?>
                                    <div class="tpl-assignee">
                                        <div class="av"
                                            style="background:<?php echo $avatar_bg($assignee_names[0] ?? $assignee_display); ?>;">
                                            <?php echo $initials($assignee_fn, $assignee_ln); ?>
                                        </div>
                                        <?php echo htmlspecialchars($assignee_display); ?>
                                    </div>
                                <?php else: ?>
                                    <span style="color:#9ca3af;">—</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($repeat_label($t_repeat, $t_day ?: null)); ?></td>
                            <td><?php echo $format_time((int) ($tpl['expected_time'] ?? 0)); ?></td>
                            <td><?php echo (int) ($tpl['created_count'] ?? 0); ?></td>
                            <td><?php echo $format_date($tpl['updated_at'] ?? null); ?></td>
                            <td>
                                <div class="tpl-actions" onclick="event.stopPropagation();">
                                    <button type="button" class="btn-icon edit btn-edit-tpl" title="Редагувати"
                                        data-payload='<?php echo htmlspecialchars($payload, ENT_QUOTES); ?>'>
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                            stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                            stroke-linejoin="round">
                                            <path
                                                d="M21.174 6.812a1 1 0 0 0-3.986-3.987L3.842 16.174a2 2 0 0 0-.5.83l-1.321 4.352a.5.5 0 0 0 .623.622l4.353-1.32a2 2 0 0 0 .83-.497z" />
                                            <path d="m15 5 4 4" />
                                        </svg>
                                    </button>
                                    <form method="POST" action="/templates/delete/<?php echo $tpl_id; ?>"
                                        class="form-delete-tpl" onsubmit="return confirm('Видалити шаблон?');">
                                        <button type="submit" class="btn-icon del" title="Видалити">
                                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                                stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                                stroke-linejoin="round">
                                                <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6" />
                                                <path d="M3 6h18" />
                                                <path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" />
                                            </svg>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ── overlay ────────────────────────────────────────────────────────────── -->
<div class="drawer-overlay" id="drawerOverlay"></div>

<!-- ── drawer ─────────────────────────────────────────────────────────────── -->
<div class="tpl-drawer" id="tplDrawer">
    <div class="drawer-header">
        <h2 id="drawerTitle">Створити шаблон</h2>
        <p id="drawerSubtitle">Заповніть поля нового шаблону задачі</p>
    </div>

    <div class="drawer-body">
        <form id="drawerForm" method="POST" action="/templates/create" autocomplete="off">
            <input type="hidden" name="_template_id" id="fieldTplId" value="">

            <!-- Назва -->
            <div class="form-group">
                <label for="fieldName">Назва задачі <span class="req">*</span></label>
                <input type="text" class="form-control" id="fieldName" name="name" placeholder="Введіть назву шаблону"
                    required>
            </div>

            <!-- Очікуваний результат -->
            <div class="form-group">
                <label for="fieldExpectedResult">Очікуваний результат (опис керівника) <span
                        class="req">*</span></label>
                <textarea class="form-control" id="fieldExpectedResult" name="expected_result" rows="3"
                    placeholder="Опишіть, що задачу дійсно виконали. Наприклад: інвестори погодились на пропозицію і домовились про конкретну суму"></textarea>
            </div>

            <!-- Опис -->
            <div class="form-group">
                <label for="fieldDescription">Опис <span class="req">*</span></label>
                <textarea class="form-control" id="fieldDescription" name="description" rows="5"
                    placeholder="Введіть детальний опис задачі"></textarea>
            </div>

            <!-- Тип + Очікуваний час -->
            <div class="form-row">
                <div class="form-group" style="margin-bottom:0;">
                    <label for="fieldType">Тип <span class="req">*</span></label>
                    <select class="form-control" id="fieldType" name="type">
                        <option value="">— оберіть —</option>
                        <option value="important-urgent">🔴 Важлива термінова</option>
                        <option value="important-not-urgent">🔵 Важлива нетермінова</option>
                        <option value="not-important-urgent">🟣 Неважлива термінова</option>
                        <option value="not-important-not-urgent">⚪ Неважлива нетермінова</option>
                    </select>
                    <div id="drawerTypePreview" class="drawer-type-preview type-none" style="display:none;">
                        <span class="task-type-dot"></span>
                        <span id="drawerTypeLabel"></span>
                    </div>
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label for="fieldExpectedTime">Очікуваний час (хв)</label>
                    <input type="number" class="form-control" id="fieldExpectedTime" name="expected_time" min="1"
                        placeholder="30, 60, 120…">
                </div>
            </div>

            <!-- Час початку + Повторення -->
            <div class="form-row" style="margin-top:18px;">
                <div class="form-group" style="margin-bottom:0;">
                    <label for="fieldStartTime">Час початку</label>
                    <input type="time" class="form-control" id="fieldStartTime" name="start_time">
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label for="fieldRepeatType">Повторення</label>
                    <select class="form-control" id="fieldRepeatType" name="repeat_type">
                        <option value="none">Не повторюється</option>
                        <option value="daily">Щодня</option>
                        <option value="weekly">Щотижня</option>
                        <option value="monthly">Щомісяця</option>
                    </select>
                </div>
            </div>

            <!-- Repeat day selectors -->
            <div class="form-group" id="wrapRepeatDay" style="display:none; margin-top:18px;">
                <label>Дні тижня</label>
                <div class="choice-grid" id="fieldRepeatDays">
                    <label class="choice-chip"><input type="checkbox" name="repeat_days[]"
                            value="Пн"><span>Понеділок</span></label>
                    <label class="choice-chip"><input type="checkbox" name="repeat_days[]"
                            value="Вт"><span>Вівторок</span></label>
                    <label class="choice-chip"><input type="checkbox" name="repeat_days[]"
                            value="Ср"><span>Середа</span></label>
                    <label class="choice-chip"><input type="checkbox" name="repeat_days[]"
                            value="Чт"><span>Четвер</span></label>
                    <label class="choice-chip"><input type="checkbox" name="repeat_days[]"
                            value="Пт"><span>П'ятниця</span></label>
                    <label class="choice-chip"><input type="checkbox" name="repeat_days[]"
                            value="Сб"><span>Субота</span></label>
                    <label class="choice-chip"><input type="checkbox" name="repeat_days[]"
                            value="Нд"><span>Неділя</span></label>
                </div>
                <div class="choice-hint">Можна обрати кілька днів одним натисканням.</div>
            </div>

            <div class="form-group" id="wrapRepeatMonthDay" style="display:none; margin-top:18px;">
                <label>Дні місяця</label>
                <div class="choice-grid" id="fieldRepeatMonthDays"
                    style="grid-template-columns:repeat(auto-fit, minmax(64px, 1fr));">
                    <?php for ($day_num = 1; $day_num <= 31; $day_num++): ?>
                        <label class="choice-chip"><input type="checkbox" name="repeat_month_days[]"
                                value="<?php echo $day_num; ?>"><span><?php echo $day_num; ?></span></label>
                    <?php endfor; ?>
                </div>
                <div class="choice-hint">Можна обрати кілька чисел місяця одним натисканням.</div>
            </div>

            <!-- Хто назначив + Відповідальний -->
            <div class="form-row" style="margin-top:18px;">
                <div class="form-group" style="margin-bottom:0;">
                    <label for="fieldAssignees">Відповідальні</label>
                    <select class="form-control" id="fieldAssignees" name="assignee_ids[]" multiple size="7">
                        <?php foreach ($employee_options as $emp): ?>
                            <option value="<?php echo $emp['id']; ?>"><?php echo htmlspecialchars($emp['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div style="margin-top:6px;color:#6b7280;font-size:12px;">Можна обрати кількох виконавців.</div>
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <!-- placeholder for future fields -->
                </div>
            </div>

        </form><!-- /drawerForm -->
    </div><!-- /drawer-body -->

    <div class="drawer-footer">
        <button type="submit" form="drawerForm" class="btn-primary" id="drawerSubmitBtn">Створити шаблон</button>
        <button type="button" class="btn-secondary" id="btnCloseDrawer">Скасувати</button>
    </div>
</div>


<?php
$content = ob_get_clean();
require APP_PATH . '/Views/layouts/main.php';
