<?php
$extra_head = '<link rel="stylesheet" href="/public/css/company-profile.css">';
$extra_scripts = '<script src="/public/js/company-profile.js"></script>';
ob_start();
$company = $company ?? [];
$employees = $employees ?? [];
?>

<div class="company-page">
  <div class="company-grid">
    <section class="company-card">
      <header class="company-card-head">
        <div>
          <h2 class="company-card-title">Профіль компанії</h2>
          <p class="company-subtitle">Оновіть назву та опис компанії.</p>
        </div>
        <span class="company-badge">ID <?= (int) ($company['id'] ?? 0); ?></span>
      </header>

      <div class="company-card-body">
        <form method="post" action="/company/profile" class="company-form">
          <div class="field">
            <label for="name">Назва компанії</label>
            <input id="name" type="text" name="name" value="<?= e($company['name'] ?? ''); ?>" required>
          </div>

          <div class="field">
            <label for="description">Опис компанії</label>
            <textarea id="description" name="description"><?= e($company['description'] ?? ''); ?></textarea>
          </div>

          <div class="company-actions">
            <button type="submit" class="btn-save">Зберегти зміни</button>
          </div>
        </form>
      </div>
    </section>

    <section class="company-card">
      <header class="company-card-head">
        <div>
          <h2 class="company-card-title">Співробітники</h2>
          <p class="company-subtitle">Усі працівники компанії на одній сторінці.</p>
        </div>
        <div class="company-head-actions">
          <span class="company-badge"><?= (int) count($employees); ?></span>
          <a href="/company/logs" class="company-header-link">Логи переписок</a>
          <a href="/company/add-employee" class="company-add-user">+ Додати</a>
        </div>
      </header>

      <div class="company-card-body">
        <?php if (empty($employees)): ?>
          <div class="employees-empty">Поки що немає співробітників.</div>
        <?php else: ?>
          <div class="employees-table-wrap">
            <table class="employees-table">
              <thead>
                <tr>
                  <th>Ім'я</th>
                  <th>Email</th>
                  <th>Керівник</th>
                  <th>Роль</th>
                  <th style="width:150px;">Дії</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($employees as $employee): ?>
                  <tr>
                    <td>
                      <div class="employee-name">
                        <?= e(trim(($employee['first_name'] ?? '') . ' ' . ($employee['last_name'] ?? '')) ?: 'Без імені'); ?>
                      </div>
                    </td>
                    <td>
                      <?php if (!empty($employee['email'])): ?>
                        <a class="employee-email"
                          href="mailto:<?= e($employee['email']); ?>"><?= e($employee['email']); ?></a>
                      <?php else: ?>
                        <span>-</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php
                      $manager_label = trim((string) ($employee['manager_first_name'] ?? '') . ' ' . (string) ($employee['manager_last_name'] ?? ''));
                      ?>
                      <span><?= e($manager_label !== '' ? $manager_label : '—'); ?></span>
                    </td>
                    <td>
                      <span class="role-pill"><?= e($employee['role'] ?? 'member'); ?></span>
                    </td>
                    <td>
                      <div class="employee-actions">
                        <a class="btn-link btn-edit"
                          href="/company/generate-onboarding/<?= (int) ($employee['user_id'] ?? 0); ?>"
                          onclick="return confirm('Згенерувати посилання для онбордінгу?');">Онбордінг</a>
                        <?php if (!empty($employee['email'])): ?>
                          <a class="btn-link btn-edit"
                            href="/company/send-onboarding-email/<?= (int) ($employee['user_id'] ?? 0); ?>"
                            onclick="return confirm('Відправити онбординг-лист на <?= e($employee['email']); ?>?');">Надіслати
                            лист</a>
                        <?php endif; ?>
                        <a class="btn-link btn-edit js-employee-edit-open" href="#"
                          data-employee-id="<?= (int) ($employee['user_id'] ?? 0); ?>">Швидке редагування</a>
                        <a class="btn-link btn-edit" href="/company/logs/<?= (int) ($employee['user_id'] ?? 0); ?>">Лог
                          переписок</a>
                        <a class="btn-link btn-edit"
                          href="/company/edit-employee/<?= (int) ($employee['user_id'] ?? 0); ?>">Редагувати</a>
                        <a class="btn-link btn-delete"
                          href="/company/delete-employee/<?= (int) ($employee['user_id'] ?? 0); ?>"
                          onclick="return confirm('Видалити співробітника?');">Видалити</a>
                      </div>
                    </td>
                  </tr>
                  <tr class="employee-edit-row" id="employee-edit-row-<?= (int) ($employee['user_id'] ?? 0); ?>">
                    <td colspan="5" class="employee-edit-cell">
                      <form class="employee-inline-edit-form" method="POST"
                        action="/company/edit-employee/<?= (int) ($employee['user_id'] ?? 0); ?>">
                        <div class="employee-inline-grid">
                          <div class="employee-inline-field">
                            <label>Посада</label>
                            <input type="text" name="title" value="<?= e($employee['title'] ?? ''); ?>"
                              placeholder="Наприклад, Team Lead">
                          </div>
                          <div class="employee-inline-field">
                            <label>Керівник</label>
                            <select name="reports_to" aria-label="Оберіть керівника">
                              <option value="">-- Без керівника --</option>
                              <?php foreach ($employees as $mgr):
                                $mgr_id = (int) ($mgr['user_id'] ?? 0);
                                if ($mgr_id === (int) ($employee['user_id'] ?? 0)) {
                                  continue;
                                }
                                $is_selected = (int) ($employee['reports_to'] ?? 0) === $mgr_id;
                                ?>
                                <option value="<?= $mgr_id; ?>" <?= $is_selected ? 'selected' : ''; ?>>
                                  <?= e(trim(($mgr['first_name'] ?? '') . ' ' . ($mgr['last_name'] ?? '')) ?: '—'); ?>
                                </option>
                              <?php endforeach; ?>
                            </select>
                          </div>
                        </div>
                        <div class="employee-inline-actions">
                          <button type="button" class="employee-inline-cancel js-employee-edit-close"
                            data-employee-id="<?= (int) ($employee['user_id'] ?? 0); ?>">Скасувати</button>
                          <button type="submit" class="employee-inline-save">Зберегти</button>
                        </div>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </section>
  </div>
</div>

<?php
$content = ob_get_clean();
include APP_PATH . '/Views/layouts/main.php';
?>