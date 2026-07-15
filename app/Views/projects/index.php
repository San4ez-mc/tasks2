<?php
$title = 'Проекти';
$layout_container_class = 'container-wide';
$flash_success = $flash_success ?? '';
$flash_error = $flash_error ?? '';
$projects = $projects ?? [];
?>
<?php ob_start(); ?>
<style>
    .projects-page {
        width: 100%;
    }

    .projects-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        margin-bottom: 20px;
        flex-wrap: wrap;
    }

    .projects-header h1 {
        font-size: 28px;
        color: #102034;
        margin: 0;
    }

    .btn-primary {
        background: #102034;
        color: #fff;
        border: none;
        border-radius: 10px;
        padding: 9px 20px;
        font-size: 14px;
        font-weight: 700;
        text-decoration: none;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }

    .btn-primary:hover {
        opacity: .85;
    }

    .projects-table-wrap {
        background: #fff;
        border: 1px solid #dbe5ef;
        border-radius: 16px;
        overflow: hidden;
        box-shadow: 0 4px 18px rgba(15, 23, 42, .06);
    }

    .projects-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 14px;
    }

    .projects-table th {
        background: #f6f9fc;
        color: #374151;
        font-size: 11.5px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .04em;
        padding: 11px 16px;
        text-align: left;
        border-bottom: 1px solid #e8eef6;
    }

    .projects-table td {
        padding: 12px 16px;
        border-bottom: 1px solid #f0f4fa;
        vertical-align: middle;
    }

    .projects-table tr:last-child td {
        border-bottom: none;
    }

    .projects-table tr:hover td {
        background: #f9fbff;
    }

    .project-name {
        font-weight: 700;
        color: #0f172a;
    }

    .project-desc {
        color: #374151;
        font-size: 13px;
    }

    .project-meta {
        font-size: 12px;
        color: #617085;
    }

    .project-actions {
        display: flex;
        gap: 6px;
    }

    .btn-sm {
        border-radius: 7px;
        padding: 4px 12px;
        font-size: 12.5px;
        font-weight: 600;
        text-decoration: none;
        cursor: pointer;
        border: 1px solid transparent;
        display: inline-flex;
        align-items: center;
    }

    .btn-sm-edit {
        background: #eff6ff;
        color: #2563eb;
        border-color: #bfdbfe;
    }

    .btn-sm-edit:hover {
        background: #dbeafe;
    }

    .btn-sm-del {
        background: none;
        color: #ef4444;
        border-color: #fecaca;
    }

    .btn-sm-del:hover {
        background: #fee2e2;
    }

    .empty-state {
        text-align: center;
        padding: 48px 24px;
        color: #94a3b8;
    }

    .empty-state p {
        margin-top: 8px;
        font-size: 15px;
    }
</style>

<div class="projects-page">
    <div class="projects-header">
        <h1>Проекти</h1>
        <a href="/projects/create" class="btn-primary">+ Новий проект</a>
    </div>

    <?php if ($flash_success): ?>
        <div
            style="background:#dcfce7;border:1px solid #bbf7d0;border-radius:10px;padding:10px 16px;margin-bottom:14px;color:#15803d;font-size:14px;">
            <?php echo htmlspecialchars($flash_success); ?>
        </div>
    <?php endif; ?>
    <?php if ($flash_error): ?>
        <div
            style="background:#fee2e2;border:1px solid #fecaca;border-radius:10px;padding:10px 16px;margin-bottom:14px;color:#dc2626;font-size:14px;">
            <?php echo htmlspecialchars($flash_error); ?>
        </div>
    <?php endif; ?>

    <div class="projects-table-wrap">
        <?php if (empty($projects)): ?>
            <div class="empty-state">
                <div style="font-size:36px;">📁</div>
                <p>Проектів ще немає. Створіть перший!</p>
            </div>
        <?php else: ?>
            <table class="projects-table">
                <thead>
                    <tr>
                        <th>Назва</th>
                        <th>Опис</th>
                        <th>Учасники</th>
                        <th>Дії</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($projects as $project): ?>
                        <tr>
                            <td>
                                <div class="project-name">
                                    <a href="/projects/view/<?php echo (int) $project['id']; ?>" style="color:inherit;text-decoration:none;">
                                        <?php echo htmlspecialchars((string) ($project['name'] ?? '')); ?>
                                    </a>
                                </div>
                                <div class="project-meta">Створено:
                                    <?php echo htmlspecialchars((string) ($project['creator_first_name'] ?? '')); ?>
                                    <?php echo htmlspecialchars((string) ($project['creator_last_name'] ?? '')); ?>
                                </div>
                            </td>
                            <td>
                                <div class="project-desc">
                                    <?php echo nl2br(htmlspecialchars((string) ($project['description'] ?? ''))); ?>
                                </div>
                            </td>
                            <td>
                                <span class="project-meta"><?php echo (int) ($project['member_count'] ?? 0); ?> учасн.</span>
                            </td>
                            <td>
                                <div class="project-actions">
                                    <a href="/projects/edit/<?php echo (int) $project['id']; ?>"
                                        class="btn-sm btn-sm-edit">Редагувати</a>
                                    <form method="post" action="/projects/delete/<?php echo (int) $project['id']; ?>"
                                        onsubmit="return confirm('Видалити проект «<?php echo htmlspecialchars(addslashes((string) ($project['name'] ?? ''))); ?>»?');"
                                        style="margin:0;">
                                        <button type="submit" class="btn-sm btn-sm-del">Видалити</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
<?php $content = ob_get_clean(); ?>
<?php require __DIR__ . '/../layouts/main.php'; ?>