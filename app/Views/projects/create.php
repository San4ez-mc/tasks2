<?php
$title = 'Новий проект';
$employees = $employees ?? [];
?>
<?php ob_start(); ?>
<style>
    .simple-form-page {
        max-width: 680px;
        display: grid;
        gap: 18px;
    }

    .simple-form-card {
        background: #fff;
        border: 1px solid #dbe5ef;
        border-radius: 20px;
        padding: 28px;
        box-shadow: 0 14px 32px rgba(15, 23, 42, .06);
    }

    .simple-form-card h2 {
        font-size: 26px;
        margin-bottom: 20px;
        color: #102034;
    }

    .form-group {
        display: grid;
        gap: 6px;
        margin-bottom: 14px;
    }

    .form-group label {
        font-weight: 700;
        color: #18324d;
        font-size: 14px;
    }

    .form-group input,
    .form-group select,
    .form-group textarea {
        width: 100%;
        border-radius: 10px;
        border: 1px solid #cfd9e5;
        padding: 10px 12px;
        font: inherit;
        background: #fbfdff;
        font-size: 14px;
    }

    .form-group textarea {
        min-height: 90px;
        resize: vertical;
    }

    .form-group small {
        color: #64748b;
        font-size: 12.5px;
    }

    .members-list {
        display: flex;
        flex-direction: column;
        gap: 6px;
        max-height: 240px;
        overflow-y: auto;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        padding: 10px 12px;
        background: #fbfdff;
    }

    .member-item {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 14px;
        cursor: pointer;
    }

    .member-item input[type="checkbox"] {
        width: 16px;
        height: 16px;
        accent-color: #2563eb;
        cursor: pointer;
        flex-shrink: 0;
    }

    .simple-actions {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
        margin-top: 6px;
    }

    .simple-btn {
        border: 0;
        background: #102034;
        color: #fff;
        cursor: pointer;
        border-radius: 10px;
        padding: 11px 24px;
        font-weight: 800;
        font-size: 14px;
    }

    .simple-btn:hover {
        opacity: .85;
    }

    .simple-link {
        background: #e5ebf3;
        color: #334155;
        border-radius: 10px;
        padding: 11px 24px;
        font-weight: 700;
        font-size: 14px;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
    }

    .simple-link:hover {
        background: #d1dbe7;
    }
</style>

<div class="simple-form-page">
    <div class="simple-form-card">
        <h2>Новий проект</h2>

        <?php $err = flash('error');
        if ($err): ?>
            <div
                style="background:#fee2e2;border:1px solid #fecaca;border-radius:10px;padding:10px 14px;margin-bottom:14px;color:#dc2626;font-size:13.5px;">
                <?php echo htmlspecialchars($err); ?>
            </div>
        <?php endif; ?>

        <form method="post" action="/projects/create">
            <div class="form-group">
                <label for="name">Назва проекту *</label>
                <input type="text" id="name" name="name" required placeholder="Наприклад: Запуск продукту X">
            </div>

            <div class="form-group">
                <label for="description">Опис</label>
                <textarea id="description" name="description"
                    placeholder="Мета, контекст, важливі деталі..."></textarea>
            </div>

            <div class="form-group">
                <label>Учасники <small>(хто може бачити проект та задачі в ньому)</small></label>
                <?php if (empty($employees)): ?>
                    <small style="color:#94a3b8;">Немає інших співробітників у компанії</small>
                <?php else: ?>
                    <div class="members-list">
                        <?php foreach ($employees as $emp): ?>
                            <?php
                            $uid = (int) ($emp['user_id'] ?? 0);
                            $name = trim((string) ($emp['first_name'] ?? '') . ' ' . (string) ($emp['last_name'] ?? ''));
                            if ($name === '')
                                $name = (string) ($emp['email'] ?? '#' . $uid);
                            ?>
                            <label class="member-item">
                                <input type="checkbox" name="member_ids[]" value="<?php echo $uid; ?>" checked>
                                <?php echo htmlspecialchars($name); ?>
                                <span
                                    style="color:#94a3b8;font-size:12px;">(<?php echo htmlspecialchars((string) ($emp['role'] ?? '')); ?>)</span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="simple-actions">
                <button type="submit" class="simple-btn">Створити проект</button>
                <a href="/projects" class="simple-link">Скасувати</a>
            </div>
        </form>
    </div>
</div>
<?php $content = ob_get_clean(); ?>
<?php require __DIR__ . '/../layouts/main.php'; ?>