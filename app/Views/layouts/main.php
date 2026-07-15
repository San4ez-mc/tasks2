<?php
$current_path = trim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
$current_section = explode('/', $current_path)[0] ?? 'dashboard';
if ($current_section === '') {
    $current_section = 'dashboard';
}

$current_uri = $_SERVER['REQUEST_URI'] ?? '/dashboard';
$user = get_user();
$user_companies = is_auth() ? get_user_companies((int) ($user['id'] ?? 0)) : [];
$active_company = is_auth() ? get_active_company() : null;

$menu_items = [
    ['href' => '/dashboard', 'label' => 'Дашборд', 'section' => 'dashboard'],
    ['href' => '/results', 'label' => 'Цілі', 'section' => 'results'],
    ['href' => '/tasks', 'label' => 'Задачі', 'section' => 'tasks'],
    ['href' => '/weekly-plans', 'label' => 'План-факт', 'section' => 'weekly-plans'],
    ['href' => '/projects', 'label' => 'Проекти', 'section' => 'projects'],
    ['href' => '/templates', 'label' => 'Шаблони', 'section' => 'templates'],
    ['href' => '/company/profile', 'label' => 'Компанія', 'section' => 'company'],
    ['href' => '/account/settings', 'label' => 'Налаштування', 'section' => 'account'],
];
?>
<!DOCTYPE html>
<html lang="uk">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $title ?? APP_NAME; ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            background: #f4f5f7;
            color: #1f2937;
            min-height: 100vh;
            overflow-x: hidden;
            display: flex;
            flex-direction: column;
        }

        .app-shell {
            display: flex;
            flex: 1;
            position: relative;
        }

        .app-sidebar {
            width: 230px;
            min-width: 230px;
            background: #2d313a;
            color: #e5e7eb;
            border-right: 1px solid #3d4350;
            display: flex;
            flex-direction: column;
            padding: 16px 12px;
            transition: width .22s ease, min-width .22s ease, padding .22s ease, border-color .22s ease;
            height: 100vh;
            position: sticky;
            top: 0;
            overflow-x: hidden;
            overflow-y: auto;
        }

        .app-shell.sidebar-collapsed .app-sidebar {
            width: 0;
            min-width: 0;
            padding-left: 0;
            padding-right: 0;
            border-right-color: transparent;
        }

        .brand {
            font-size: 26px;
            font-weight: 700;
            letter-spacing: .5px;
            color: #fff;
            margin: 2px 8px 14px;
            text-decoration: none;
        }

        .menu-list {
            display: grid;
            gap: 4px;
        }

        .menu-link {
            text-decoration: none;
            color: #d1d5db;
            font-size: 15px;
            font-weight: 600;
            padding: 10px 12px;
            border-radius: 8px;
            border: 1px solid transparent;
            transition: .2s background, .2s color, .2s border-color;
        }

        .menu-link:hover {
            background: #3b4250;
            color: #fff;
        }

        .menu-link.active {
            background: #111827;
            border-color: #4b5563;
            color: #fff;
        }

        .sidebar-spacer {
            flex: 1;
        }

        .sidebar-exit {
            margin-top: 14px;
        }

        .sidebar-exit a {
            display: block;
            text-decoration: none;
            text-align: center;
            background: #ef4444;
            color: #fff;
            border-radius: 8px;
            padding: 9px 12px;
            font-weight: 700;
        }

        .sidebar-plan-badge {
            margin: 8px 0 4px;
            border-radius: 10px;
            padding: 8px 10px;
            font-size: 12px;
        }

        .sidebar-plan-free {
            background: #374151;
            border: 1px solid #4b5563;
        }

        .sidebar-plan-paid {
            background: #1e3a5f;
            border: 1px solid #2563eb;
        }

        .sidebar-plan-label {
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .5px;
            color: #6b7280;
            margin-bottom: 2px;
        }

        .sidebar-plan-paid .sidebar-plan-label {
            color: #60a5fa;
        }

        .sidebar-plan-name {
            font-weight: 700;
            color: #e5e7eb;
            font-size: 12px;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .sidebar-plan-trial {
            font-size: 9px;
            font-weight: 700;
            background: #fef3c7;
            color: #b45309;
            border-radius: 4px;
            padding: 0 4px;
            text-transform: uppercase;
        }

        .sidebar-plan-days {
            color: #94a3b8;
            font-size: 11px;
            margin-top: 2px;
        }

        .sidebar-plan-paid .sidebar-plan-days {
            color: #93c5fd;
        }

        .app-main {
            flex: 1;
            min-width: 0;
            display: flex;
            flex-direction: column;
        }

        .topbar {
            min-height: 46px;
            position: sticky;
            top: 0;
            z-index: 30;
            background: #fff;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 18px;
            gap: 12px;
        }

        /* ====== GLOBAL SEARCH ====== */
        .global-search-wrap {
            flex: 1 1 0;
            max-width: 480px;
            position: relative;
        }

        .global-search-input-row {
            display: flex;
            align-items: center;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 0 10px;
            gap: 6px;
            transition: border-color .15s, box-shadow .15s;
        }

        .global-search-input-row:focus-within {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, .1);
            background: #fff;
        }

        .global-search-icon {
            width: 16px;
            height: 16px;
            flex-shrink: 0;
        }

        .global-search-input {
            flex: 1;
            border: 0;
            background: transparent;
            font: inherit;
            font-size: 13px;
            color: #1e293b;
            padding: 7px 0;
            outline: none;
        }

        .global-search-input::placeholder {
            color: #94a3b8;
        }

        .global-search-clear {
            border: 0;
            background: transparent;
            color: #94a3b8;
            cursor: pointer;
            font-size: 13px;
            padding: 2px 4px;
            border-radius: 4px;
            line-height: 1;
        }

        .global-search-clear:hover {
            color: #334155;
            background: #f1f5f9;
        }

        .global-search-panel {
            position: absolute;
            top: calc(100% + 6px);
            left: 0;
            right: 0;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            box-shadow: 0 8px 32px rgba(15, 23, 42, .12);
            z-index: 200;
            max-height: 420px;
            overflow-y: auto;
            padding: 6px 0;
        }

        .gs-section-title {
            font-size: 11px;
            font-weight: 700;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: .06em;
            padding: 8px 14px 4px;
        }

        .gs-item {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 8px 14px;
            cursor: pointer;
            text-decoration: none;
            color: inherit;
            border-radius: 0;
            transition: background .1s;
        }

        .gs-item:hover,
        .gs-item:focus {
            background: #f1f5f9;
            outline: none;
        }

        .gs-item-icon {
            width: 28px;
            height: 28px;
            border-radius: 7px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            flex-shrink: 0;
            margin-top: 1px;
        }

        .gs-item-icon.icon-task {
            background: #eff6ff;
        }

        .gs-item-icon.icon-goal {
            background: #fefce8;
        }

        .gs-item-icon.icon-template {
            background: #f0fdf4;
        }

        .gs-item-body {
            flex: 1;
            min-width: 0;
        }

        .gs-item-title {
            font-size: 13px;
            font-weight: 600;
            color: #1e293b;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .gs-item-meta {
            font-size: 11px;
            color: #94a3b8;
            margin-top: 1px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .gs-empty {
            padding: 18px 14px;
            text-align: center;
            color: #94a3b8;
            font-size: 13px;
        }

        .gs-loading {
            padding: 18px 14px;
            text-align: center;
            color: #94a3b8;
            font-size: 13px;
        }

        /* ====== END GLOBAL SEARCH ====== */

        .topbar-left {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .menu-toggle {
            border: 0;
            background: #f1f5f9;
            color: #334155;
            width: 30px;
            height: 30px;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 16px;
            font-weight: 700;
        }

        .menu-toggle:hover {
            background: #e2e8f0;
        }

        .topbar-title {
            font-size: 14px;
            font-weight: 700;
            color: #334155;
            letter-spacing: .02em;
        }

        .topbar-user {
            font-size: 13px;
            color: #6b7280;
            font-weight: 600;
        }

        .topbar-right {
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 0;
            flex-wrap: wrap;
            justify-content: flex-end;
        }

        .company-switch-form {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .company-switch {
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            padding: 5px 9px;
            min-width: 180px;
            max-width: 320px;
            background: #fff;
            color: #0f172a;
            font-size: 13px;
            font-weight: 600;
        }

        .company-chip {
            border: 1px solid #dbeafe;
            background: #eff6ff;
            color: #1e3a8a;
            border-radius: 999px;
            padding: 4px 10px;
            font-size: 12px;
            font-weight: 700;
            max-width: 260px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .company-add-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            border-radius: 8px;
            border: 1px solid #cbd5e1;
            padding: 5px 10px;
            background: #ffffff;
            color: #0f172a;
            font-size: 12px;
            font-weight: 700;
            white-space: nowrap;
        }

        .company-add-btn:hover {
            background: #f8fafc;
        }

        .page-content {
            flex: 1 1 auto;
            display: flex;
            flex-direction: column;
            min-height: 0;
        }

        .container {
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
            padding: 16px 20px;
            flex: 1 1 auto;
            display: flex;
            flex-direction: column;
            background: #fff;
        }

        .container-wide {
            max-width: 1420px;
        }

        .alert {
            padding: 14px;
            margin-bottom: 14px;
            border-radius: 8px;
            border: 1px solid;
            font-size: 14px;
        }

        .alert-success {
            background: #ecfdf3;
            border-color: #86efac;
            color: #166534;
        }

        .alert-error {
            background: #fef2f2;
            border-color: #fca5a5;
            color: #991b1b;
        }

        .alert-info {
            background: #eff6ff;
            border-color: #93c5fd;
            color: #1e3a8a;
        }

        footer {
            background: #fff;
            border-top: 1px solid #e5e7eb;
            color: #6b7280;
            text-align: center;
            padding: 10px 14px;
            font-size: 12px;
            flex-shrink: 0;
            width: 100%;
        }

        .footer-boxed {
            background: transparent;
            border: none;
            color: #6b7280;
            text-align: center;
            padding: 24px 0 10px 0;
            font-size: 12px;
        }

        .footer-boxed .footer-inner {
            background: #fff;
            border-top: 1px solid #e5e7eb;
            margin: 0 auto;
            max-width: 1200px;
            border-radius: 0 0 18px 18px;
            box-shadow: 0 2px 16px rgba(16, 32, 52, 0.04);
            padding: 10px 14px;
        }

        .sidebar-backdrop {
            display: none;
        }

        @media (max-width: 980px) {
            .topbar {
                height: auto;
                min-height: 46px;
                padding: 10px 16px;
                align-items: flex-start;
                gap: 10px;
                flex-wrap: wrap;
            }

            .topbar-left,
            .topbar-right {
                width: 100%;
            }

            .global-search-wrap {
                width: 100%;
                max-width: 100%;
                order: 3;
            }

            .topbar-right {
                justify-content: flex-start;
            }

            .company-switch-form {
                flex: 1 1 220px;
                min-width: 0;
            }

            .company-switch {
                width: 100%;
                max-width: none;
                min-width: 0;
            }

            .app-sidebar {
                position: fixed;
                top: 0;
                left: 0;
                z-index: 70;
                width: 230px;
                min-width: 230px;
                height: 100vh;
                transform: translateX(-100%);
                transition: transform .22s ease;
            }

            .app-shell.sidebar-open .app-sidebar {
                transform: translateX(0);
            }

            .app-shell.sidebar-collapsed .app-sidebar {
                width: 230px;
                min-width: 230px;
                padding-left: 12px;
                padding-right: 12px;
                border-right-color: #3d4350;
            }

            .sidebar-backdrop {
                display: block;
                position: fixed;
                inset: 0;
                background: rgba(15, 23, 42, 0.35);
                opacity: 0;
                pointer-events: none;
                z-index: 60;
                transition: opacity .2s ease;
            }

            .app-shell.sidebar-open .sidebar-backdrop {
                opacity: 1;
                pointer-events: auto;
            }
        }

        @media (max-width: 640px) {
            .topbar {
                padding: 10px 12px;
            }

            .topbar-title {
                font-size: 13px;
            }

            .company-chip {
                max-width: 100%;
            }

            .company-add-btn,
            .topbar-user {
                font-size: 12px;
            }

            .container {
                padding: 12px;
            }
        }
    </style>
    <?php if (!empty($extra_head))
        echo $extra_head; ?>
    <link rel="stylesheet" href="/public/css/tour.css?v=1">
</head>

<body>
    <div class="app-shell sidebar-collapsed" id="appShell">
        <div class="sidebar-backdrop" id="sidebarBackdrop"></div>
        <aside class="app-sidebar">
            <a class="brand" href="/dashboard"><?php echo APP_NAME; ?></a>

            <nav class="menu-list">
                <?php foreach ($menu_items as $item):
                    $active = $current_section === $item['section'];
                    ?>
                    <a class="menu-link <?php echo $active ? 'active' : ''; ?>" href="<?php echo $item['href']; ?>">
                        <?php echo $item['label']; ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <div class="sidebar-spacer"></div>
            <?php if (is_auth()): ?>
                <?php
                $_sub_company_id = (int) ($_SESSION['company_id'] ?? 0);
                $_sub = $_sub_company_id > 0 ? get_active_subscription($_sub_company_id) : null;
                ?>
                <?php if ($_sub): ?>
                    <div
                        class="sidebar-plan-badge <?php echo $_sub['is_active'] ? 'sidebar-plan-paid' : 'sidebar-plan-free'; ?>">
                        <div class="sidebar-plan-label">Тариф</div>
                        <div class="sidebar-plan-name">
                            <?php echo htmlspecialchars($_sub['plan_name']); ?>
                            <?php if ($_sub['is_trial'] ?? false): ?><span
                                    class="sidebar-plan-trial">Trial</span><?php endif; ?>
                        </div>
                        <?php if ($_sub['is_active']): ?>
                            <?php
                            $_expires = $_sub['expires_at'];
                            $_lifetime = ($_expires !== '' && strtotime($_expires) > strtotime('+70 years'));
                            ?>
                            <?php if ($_lifetime): ?>
                                <div class="sidebar-plan-days">Пожиттєво ♾</div>
                            <?php elseif ($_sub['days_left'] > 0): ?>
                                <div class="sidebar-plan-days">
                                    <?php if ($_sub['is_trial'] ?? false): ?>
                                        Пробний — <?php echo (int) $_sub['days_left']; ?> д.
                                    <?php else: ?>
                                        до: <?php echo date('d.m.Y', strtotime($_expires)); ?>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="sidebar-plan-days">до <?php echo SUBSCRIPTION_FREE_MEMBER_LIMIT; ?> учасників</div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
            <div class="sidebar-exit">
                <a href="/auth/logout">Вихід</a>
            </div>
        </aside>

        <div class="app-main">
            <header class="topbar">
                <div class="topbar-left">
                    <button type="button" class="menu-toggle" id="sidebarToggle"
                        aria-label="Відкрити або закрити меню">☰</button>
                    <div class="topbar-title"><?php echo $title ?? 'Робоча панель'; ?></div>
                </div>

                <?php if (is_auth()): ?>
                    <div class="global-search-wrap" id="globalSearchWrap">
                        <div class="global-search-input-row">
                            <svg class="global-search-icon" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                                <circle cx="8.5" cy="8.5" r="5.5" stroke="#94a3b8" stroke-width="1.7" />
                                <path d="M13.5 13.5L17 17" stroke="#94a3b8" stroke-width="1.7" stroke-linecap="round" />
                            </svg>
                            <input type="text" id="globalSearchInput" class="global-search-input"
                                placeholder="Глобальний пошук по задачах, цілях, шаблонах…" autocomplete="off"
                                role="combobox" aria-autocomplete="list" aria-expanded="false"
                                aria-controls="globalSearchPanel" />
                            <button type="button" id="globalSearchClear" class="global-search-clear"
                                aria-label="Очистити пошук" style="display:none;">✕</button>
                        </div>
                        <div class="global-search-panel" id="globalSearchPanel" role="listbox"
                            aria-label="Результати пошуку" style="display:none;"></div>
                    </div>
                <?php endif; ?>

                <div class="topbar-right">
                    <?php if (!empty($user_companies) && count($user_companies) > 1): ?>
                        <form class="company-switch-form" method="post" action="/company/switch">
                            <input type="hidden" name="return_to" value="<?php echo e($current_uri); ?>">
                            <select class="company-switch" name="company_id" onchange="this.form.submit()"
                                aria-label="Активна компанія">
                                <?php foreach ($user_companies as $company_option): ?>
                                    <?php $is_selected = (int) ($active_company['id'] ?? 0) === (int) ($company_option['id'] ?? 0); ?>
                                    <option value="<?php echo (int) $company_option['id']; ?>" <?php echo $is_selected ? 'selected' : ''; ?>>
                                        <?php echo e($company_option['name'] ?? ('Компанія #' . (int) $company_option['id'])); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    <?php elseif (!empty($active_company)): ?>
                        <div class="company-chip"><?php echo e($active_company['name'] ?? 'Компанія'); ?></div>
                    <?php endif; ?>

                    <a class="company-add-btn" href="/company/create">+ Компанія</a>

                    <div class="topbar-user"><?php echo htmlspecialchars($user['first_name'] ?? 'Користувач'); ?></div>
                </div>
            </header>

            <main class="page-content">
                <div class="container <?php echo $layout_container_class ?? ''; ?>">
                    <?php
                    $success = flash('success');
                    $error = flash('error');
                    $info = flash('info');
                    ?>

                    <?php if ($success): ?>
                        <div class="alert alert-success"><?php echo $success; ?></div>
                    <?php endif; ?>

                    <?php if ($error): ?>
                        <div class="alert alert-error"><?php echo $error; ?></div>
                    <?php endif; ?>

                    <?php if ($info): ?>
                        <div class="alert alert-info"><?php echo $info; ?></div>
                    <?php endif; ?>

                    <?php echo $content ?? ''; ?>
                </div>
            </main>

        </div>
    </div>

    <footer>
        <p>&copy; <?php echo date('Y'); ?> <?php echo APP_NAME; ?>. Усі права захищені.</p>
    </footer>

    <script>
        (function () {
            const appShell = document.getElementById('appShell');
            const toggleButton = document.getElementById('sidebarToggle');
            const backdrop = document.getElementById('sidebarBackdrop');
            const mobileMedia = window.matchMedia('(max-width: 980px)');

            function syncInitialState() {
                if (!mobileMedia.matches) {
                    appShell.classList.add('sidebar-collapsed');
                }
            }

            function toggleSidebar() {
                if (mobileMedia.matches) {
                    appShell.classList.toggle('sidebar-open');
                    return;
                }

                appShell.classList.toggle('sidebar-collapsed');
                const collapsed = appShell.classList.contains('sidebar-collapsed');
                localStorage.setItem('sidebar_collapsed', collapsed ? '1' : '0');
            }

            function closeMobileSidebar() {
                if (mobileMedia.matches) {
                    appShell.classList.remove('sidebar-open');
                }
            }

            toggleButton.addEventListener('click', toggleSidebar);
            backdrop.addEventListener('click', closeMobileSidebar);

            window.addEventListener('resize', function () {
                if (!mobileMedia.matches) {
                    appShell.classList.remove('sidebar-open');
                }
            });

            syncInitialState();
        })();
    </script>
    <script>
        (function () {
            var input = document.getElementById('globalSearchInput');
            var panel = document.getElementById('globalSearchPanel');
            var clearBtn = document.getElementById('globalSearchClear');
            if (!input || !panel) return;

            var debounceTimer = null;
            var currentQuery = '';
            var iconMap = { task: '📋', goal: '🎯', template: '📄' };
            var labelMap = { task: 'Задача', goal: 'Ціль', template: 'Шаблон' };
            var iconClass = { task: 'icon-task', goal: 'icon-goal', template: 'icon-template' };

            function showPanel() { panel.style.display = ''; input.setAttribute('aria-expanded', 'true'); }
            function hidePanel() { panel.style.display = 'none'; input.setAttribute('aria-expanded', 'false'); }

            function renderResults(data) {
                var items = data.results || [];
                if (!items.length) {
                    panel.innerHTML = '<div class="gs-empty">Нічого не знайдено</div>';
                    showPanel();
                    return;
                }

                var sections = {};
                items.forEach(function (item) {
                    var t = item.type || 'task';
                    if (!sections[t]) sections[t] = [];
                    sections[t].push(item);
                });

                var html = '';
                ['task', 'goal', 'template'].forEach(function (t) {
                    if (!sections[t] || !sections[t].length) return;
                    var sectionTitle = t === 'task' ? 'Задачі' : (t === 'goal' ? 'Цілі' : 'Шаблони');
                    html += '<div class="gs-section-title">' + sectionTitle + '</div>';
                    sections[t].slice(0, 8).forEach(function (item) {
                        var href = item.url || '#';
                        var title = item.title || '';
                        var meta = item.meta || '';
                        html += '<a class="gs-item" href="' + escHtml(href) + '" role="option">' +
                            '<div class="gs-item-icon ' + iconClass[t] + '">' + iconMap[t] + '</div>' +
                            '<div class="gs-item-body">' +
                            '<div class="gs-item-title">' + escHtml(title) + '</div>' +
                            (meta ? '<div class="gs-item-meta">' + escHtml(meta) + '</div>' : '') +
                            '</div></a>';
                    });
                });

                panel.innerHTML = html;
                showPanel();
            }

            function escHtml(s) {
                return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
            }

            function doSearch(q) {
                currentQuery = q;
                panel.innerHTML = '<div class="gs-loading">Пошук…</div>';
                showPanel();
                fetch('/search?q=' + encodeURIComponent(q), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (currentQuery === q) renderResults(data);
                    })
                    .catch(function () {
                        if (currentQuery === q) {
                            panel.innerHTML = '<div class="gs-empty">Помилка пошуку</div>';
                        }
                    });
            }

            input.addEventListener('input', function () {
                var q = input.value.trim();
                if (clearBtn) clearBtn.style.display = q ? '' : 'none';
                clearTimeout(debounceTimer);
                if (q.length < 2) { hidePanel(); currentQuery = ''; return; }
                debounceTimer = setTimeout(function () { doSearch(q); }, 280);
            });

            if (clearBtn) {
                clearBtn.addEventListener('click', function () {
                    input.value = '';
                    clearBtn.style.display = 'none';
                    hidePanel();
                    currentQuery = '';
                    input.focus();
                });
            }

            document.addEventListener('mousedown', function (e) {
                var wrap = document.getElementById('globalSearchWrap');
                if (wrap && !wrap.contains(e.target)) hidePanel();
            });

            input.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') { hidePanel(); input.blur(); }
                if (e.key === 'Enter') {
                    var first = panel.querySelector('.gs-item');
                    if (first) { first.click(); }
                }
            });
        })();
    </script>
    <?php if (!empty($extra_scripts))
        echo $extra_scripts; ?>
    <?php if (is_auth()): ?>
    <span id="finekoTourPage" data-page="<?php echo e($current_section); ?>" style="display:none"></span>
    <script src="/public/js/tour.js?v=1"></script>
    <?php endif; ?>
</body>

</html>