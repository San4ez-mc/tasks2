<?php
ob_start();
?>

<style>
  .company-create-wrap {
    max-width: 760px;
    margin: 0 auto;
    display: grid;
    gap: 14px;
  }

  .company-create-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    padding: 18px;
    box-shadow: 0 8px 28px rgba(15, 23, 42, 0.06);
  }

  .company-create-title {
    margin: 0;
    font-size: 20px;
    font-weight: 700;
    color: #0f172a;
  }

  .company-create-subtitle {
    margin: 6px 0 0;
    color: #475569;
    font-size: 14px;
  }

  .company-create-form {
    margin-top: 14px;
    display: grid;
    gap: 12px;
  }

  .company-create-field {
    display: grid;
    gap: 6px;
  }

  .company-create-field label {
    font-size: 13px;
    font-weight: 700;
    color: #334155;
  }

  .company-create-field input,
  .company-create-field textarea {
    border: 1px solid #cbd5e1;
    border-radius: 10px;
    padding: 10px 12px;
    font-size: 14px;
    color: #0f172a;
    background: #fff;
  }

  .company-create-field textarea {
    min-height: 110px;
    resize: vertical;
  }

  .company-create-actions {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
  }

  .company-create-btn {
    border: 0;
    border-radius: 10px;
    padding: 10px 14px;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
  }

  .company-create-btn.primary {
    background: #0f172a;
    color: #fff;
  }

  .company-create-btn.light {
    background: #e2e8f0;
    color: #1e293b;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
  }
</style>

<div class="company-create-wrap">
  <section class="company-create-card">
    <h2 class="company-create-title">Нова компанія</h2>
    <p class="company-create-subtitle">Після створення ця компанія стане активною, і всі нові задачі/цілі будуть створюватися в ній.</p>

    <form method="post" action="/company/create" class="company-create-form">
      <div class="company-create-field">
        <label for="name">Назва компанії</label>
        <input id="name" type="text" name="name" required>
      </div>

      <div class="company-create-field">
        <label for="description">Опис</label>
        <textarea id="description" name="description"></textarea>
      </div>

      <div class="company-create-actions">
        <a class="company-create-btn light" href="/dashboard">Скасувати</a>
        <button class="company-create-btn primary" type="submit">Створити компанію</button>
      </div>
    </form>
  </section>
</div>

<?php
$title = 'Нова компанія';
$content = ob_get_clean();
include APP_PATH . '/Views/layouts/main.php';
