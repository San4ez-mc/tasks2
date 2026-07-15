<?php
/**
 * Перегляд результату
 */
$title = 'Ціль: ' . htmlspecialchars($result['title']);
?>
<?php
ob_start();
?>
<style>
    .simple-view-page {
        max-width: 920px;
        display: grid;
        gap: 18px;
    }

    .simple-view-card {
        background: #fff;
        border: 1px solid #dbe5ef;
        border-radius: 20px;
        padding: 24px;
        box-shadow: 0 14px 32px rgba(15, 23, 42, .06);
    }

    .simple-view-card h2 {
        font-size: 30px;
        margin-bottom: 16px;
        color: #102034;
    }

    .simple-view-card h3 {
        margin: 18px 0 10px;
        color: #102034;
    }

    .simple-view-card p,
    .simple-view-card li {
        color: #334155;
        line-height: 1.55;
        word-break: break-word;
    }

    .simple-note {
        background: #f8fafc;
        padding: 12px;
        border-radius: 12px;
    }

    .simple-view-card ul {
        padding-left: 18px;
    }

    .simple-actions {
        margin-top: 20px;
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
    }

    .simple-link {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 160px;
        padding: 12px 16px;
        border-radius: 12px;
        font-weight: 800;
        text-decoration: none;
        color: #fff;
    }

    .simple-link.edit {
        background: #f39c12;
    }

    .simple-link.delete {
        background: #dc2626;
    }

    .simple-link.back {
        background: #95a5a6;
    }

    @media (max-width: 560px) {
        .simple-view-card {
            padding: 18px;
        }

        .simple-view-card h2 {
            font-size: 26px;
        }

        .simple-link {
            width: 100%;
            min-width: 0;
        }
    }
</style>
<section class="simple-view-page">
    <div class="simple-view-card">
        <h2><?php echo htmlspecialchars($result['title']); ?></h2>

        <p><strong>Статус:</strong> <?php echo $result['completed'] ? '✓ Завершено' : '○ В процесі'; ?></p>
        <p><strong>Виконавець:</strong>
            <?php echo htmlspecialchars($result['assignee_first_name'] . ' ' . $result['assignee_last_name']); ?></p>
        <p><strong>Звітувач:</strong>
            <?php echo htmlspecialchars($result['reporter_first_name'] . ' ' . $result['reporter_last_name']); ?></p>
        <p><strong>Дата створення:</strong> <?php echo date('d.m.Y H:i', strtotime($result['created_at'])); ?></p>

        <?php if ($result['description']): ?>
            <p><strong>Опис:</strong></p>
            <p class="simple-note">
                <?php echo htmlspecialchars($result['description']); ?>
            </p>
        <?php endif; ?>

        <?php if (!empty($result['expected_result'])): ?>
            <p><strong>Очікуваний результат:</strong></p>
            <p class="simple-note">
                <?php echo htmlspecialchars($result['expected_result']); ?>
            </p>
        <?php endif; ?>

        <?php if (!empty($children)): ?>
            <h3>Підцілі:</h3>
            <ul>
                <?php foreach ($children as $child): ?>
                    <li>
                        <a href="/results/view/<?php echo $child['id']; ?>">
                            <?php echo htmlspecialchars($child['title']); ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <div class="simple-actions">
            <a href="/results/edit/<?php echo $result['id']; ?>" class="simple-link edit">Редагувати</a>
            <a href="/results/delete/<?php echo $result['id']; ?>" class="simple-link delete"
                onclick="return confirm('Ви впевнені?');">Видалити</a>
            <a href="/results" class="simple-link back">Назад</a>
        </div>
    </div>
</section>
<?php
$content = ob_get_clean();
require APP_PATH . '/Views/layouts/main.php';
