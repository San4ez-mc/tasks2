<?php
$title = htmlspecialchars((string) ($project['name'] ?? 'Проект'));
$layout_container_class = 'container-wide';

$status_labels = [
    'todo'       => 'Нова',
    'in-progress' => 'В процесі',
    'done'       => 'Виконано',
    'postponed'  => 'Перенесено',
];
$status_colors = [
    'todo'       => '#6b7280',
    'in-progress' => '#2563eb',
    'done'       => '#16a34a',
    'postponed'  => '#d97706',
];
?>
<?php ob_start(); ?>
<style>
    .pv-page { width: 100%; }

    .pv-header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 12px;
        margin-bottom: 24px;
        flex-wrap: wrap;
    }
    .pv-header h1 { font-size: 26px; color: #102034; margin: 0 0 4px; }
    .pv-header-desc { color: #617085; font-size: 14px; margin: 0; }
    .pv-header-actions { display: flex; gap: 8px; align-items: center; }

    .btn-back {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        text-decoration: none;
        color: #374151;
        font-size: 14px;
        font-weight: 600;
        background: #f1f5f9;
        border: 1px solid #e2e8f0;
        border-radius: 9px;
        padding: 7px 14px;
    }
    .btn-back:hover { background: #e8eef6; }

    .btn-edit {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        text-decoration: none;
        color: #2563eb;
        font-size: 14px;
        font-weight: 600;
        background: #eff6ff;
        border: 1px solid #bfdbfe;
        border-radius: 9px;
        padding: 7px 14px;
    }
    .btn-edit:hover { background: #dbeafe; }

    .pv-members {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
        margin-bottom: 28px;
    }
    .pv-member-chip {
        font-size: 12.5px;
        color: #374151;
        background: #f1f5f9;
        border: 1px solid #e2e8f0;
        border-radius: 20px;
        padding: 3px 12px;
    }

    .pv-section { margin-bottom: 32px; }
    .pv-section-title {
        font-size: 15px;
        font-weight: 700;
        color: #102034;
        margin: 0 0 12px;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .pv-count-badge {
        font-size: 12px;
        background: #e8eef6;
        color: #617085;
        border-radius: 10px;
        padding: 1px 9px;
        font-weight: 600;
    }

    .pv-table-wrap {
        background: #fff;
        border: 1px solid #dbe5ef;
        border-radius: 14px;
        overflow: hidden;
        box-shadow: 0 2px 10px rgba(15,23,42,.05);
    }
    .pv-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 14px;
    }
    .pv-table th {
        background: #f6f9fc;
        color: #374151;
        font-size: 11.5px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .04em;
        padding: 10px 14px;
        text-align: left;
        border-bottom: 1px solid #e8eef6;
    }
    .pv-table td {
        padding: 10px 14px;
        border-bottom: 1px solid #f0f4fa;
        vertical-align: middle;
        color: #1e293b;
    }
    .pv-table tr:last-child td { border-bottom: none; }
    .pv-table tr:hover td { background: #f9fbff; }

    .pv-status {
        display: inline-block;
        font-size: 12px;
        font-weight: 600;
        border-radius: 6px;
        padding: 2px 9px;
        background: #f1f5f9;
        color: #617085;
    }

    .pv-empty {
        text-align: center;
        padding: 28px 16px;
        color: #94a3b8;
        font-size: 14px;
    }

    .pv-task-title { font-weight: 600; color: #0f172a; }
    .pv-task-title a { color: inherit; text-decoration: none; }
    .pv-task-title a:hover { text-decoration: underline; }
    .pv-meta { font-size: 12px; color: #617085; }

    .pv-result-title { font-weight: 600; color: #0f172a; }
    .pv-result-title a { color: inherit; text-decoration: none; }
    .pv-result-title a:hover { text-decoration: underline; }
</style>

<div class="pv-page">

    <div class="pv-header">
        <div>
            <h1><?php echo htmlspecialchars((string) ($project['name'] ?? '')); ?></h1>
            <?php if (!empty($project['description'])): ?>
                <p class="pv-header-desc"><?php echo nl2br(htmlspecialchars((string) $project['description'])); ?></p>
            <?php endif; ?>
        </div>
        <div class="pv-header-actions">
            <a href="/projects" class="btn-back">← Всі проекти</a>
            <a href="/projects/edit/<?php echo (int) $project['id']; ?>" class="btn-edit">Редагувати</a>
        </div>
    </div>

    <?php if (!empty($members)): ?>
        <div class="pv-members">
            <?php foreach ($members as $m): ?>
                <span class="pv-member-chip">
                    <?php echo htmlspecialchars(trim(($m['first_name'] ?? '') . ' ' . ($m['last_name'] ?? ''))); ?>
                </span>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- ====== ЗАДАЧІ ====== -->
    <div class="pv-section">
        <div class="pv-section-title">
            Задачі
            <span class="pv-count-badge"><?php echo count($tasks); ?></span>
        </div>
        <div class="pv-table-wrap">
            <?php if (empty($tasks)): ?>
                <div class="pv-empty">Задач у цьому проекті ще немає</div>
            <?php else: ?>
                <table class="pv-table">
                    <thead>
                        <tr>
                            <th>Назва</th>
                            <th>Виконавець</th>
                            <th>Дедлайн</th>
                            <th>Статус</th>
                            <th>Очік. час</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tasks as $task): ?>
                            <?php
                            $taskStatus = (string) ($task['status'] ?? 'todo');
                            $statusLabel = $status_labels[$taskStatus] ?? $taskStatus;
                            $statusColor = $status_colors[$taskStatus] ?? '#6b7280';
                            $assigneeName = trim(($task['assignee_first_name'] ?? '') . ' ' . ($task['assignee_last_name'] ?? ''));
                            $dueDate = !empty($task['due_date']) ? date('d.m.Y', strtotime((string) $task['due_date'])) : '—';
                            $expectedTime = (int) ($task['expected_time'] ?? 0);
                            $timeLabel = $expectedTime > 0 ? ($expectedTime >= 60 ? floor($expectedTime / 60) . 'г ' . ($expectedTime % 60 > 0 ? ($expectedTime % 60) . 'хв' : '') : $expectedTime . 'хв') : '—';
                            ?>
                            <tr>
                                <td>
                                    <div class="pv-task-title">
                                        <?php echo htmlspecialchars((string) ($task['title'] ?? '')); ?>
                                    </div>
                                    <?php if (!empty($task['expected_result'])): ?>
                                        <div class="pv-meta"><?php echo htmlspecialchars((string) $task['expected_result']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $assigneeName !== '' ? htmlspecialchars($assigneeName) : '<span class="pv-meta">—</span>'; ?></td>
                                <td><?php echo $dueDate; ?></td>
                                <td>
                                    <span class="pv-status" style="color:<?php echo $statusColor; ?>;background:<?php echo $statusColor; ?>18;">
                                        <?php echo htmlspecialchars($statusLabel); ?>
                                    </span>
                                </td>
                                <td><?php echo $timeLabel; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- ====== ЦІЛІ ====== -->
    <div class="pv-section">
        <div class="pv-section-title">
            Цілі
            <span class="pv-count-badge"><?php echo count($results); ?></span>
        </div>
        <div class="pv-table-wrap">
            <?php if (empty($results)): ?>
                <div class="pv-empty">Задачі проекту ще не прив'язані до жодної цілі</div>
            <?php else: ?>
                <table class="pv-table">
                    <thead>
                        <tr>
                            <th>Назва</th>
                            <th>Відповідальний</th>
                            <th>Дедлайн</th>
                            <th>Статус</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($results as $result): ?>
                            <?php
                            $resultStatus = (string) ($result['status'] ?? 'in-progress');
                            $completed = (int) ($result['completed'] ?? 0);
                            if ($completed === 1) $resultStatus = 'done';
                            $rStatusLabel = match($resultStatus) {
                                'done' => 'Виконано',
                                'postponed' => 'Перенесено',
                                default => 'В процесі',
                            };
                            $rStatusColor = match($resultStatus) {
                                'done' => '#16a34a',
                                'postponed' => '#d97706',
                                default => '#2563eb',
                            };
                            $rAssignee = trim(($result['assignee_first_name'] ?? '') . ' ' . ($result['assignee_last_name'] ?? ''));
                            $rDeadline = !empty($result['deadline']) ? date('d.m.Y', strtotime((string) $result['deadline'])) : '—';
                            ?>
                            <tr>
                                <td>
                                    <div class="pv-result-title">
                                        <a href="/results/view/<?php echo (int) $result['id']; ?>">
                                            <?php echo htmlspecialchars((string) ($result['title'] ?? '')); ?>
                                        </a>
                                    </div>
                                    <?php if (!empty($result['expected_result'])): ?>
                                        <div class="pv-meta"><?php echo htmlspecialchars((string) $result['expected_result']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $rAssignee !== '' ? htmlspecialchars($rAssignee) : '<span class="pv-meta">—</span>'; ?></td>
                                <td><?php echo $rDeadline; ?></td>
                                <td>
                                    <span class="pv-status" style="color:<?php echo $rStatusColor; ?>;background:<?php echo $rStatusColor; ?>18;">
                                        <?php echo $rStatusLabel; ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

</div>
<?php $content = ob_get_clean(); ?>
<?php require __DIR__ . '/../layouts/main.php'; ?>
