/**
 * FINEKO Tour — покрокові підказки для кожної сторінки
 * Зберігає прогляд у localStorage. Плаваюча кнопка для перезапуску.
 *
 * ── Як додати / редагувати кроки ──
 * Знайди потрібну сторінку в об'єкті STEPS нижче і відредагуй масив.
 * Кожен крок: { icon, title, text, target?, position? }
 *   icon     — emoji або рядок
 *   title    — заголовок підказки
 *   text     — основний текст
 *   target   — CSS-селектор елемента на який вказуємо (необов'язково)
 *   position — 'top'|'bottom'|'left'|'right' (auto якщо не вказано)
 */
(function () {
    'use strict';

    /* ══════════════════════════════════════════════════════
       КРОКИ ДЛЯ КОЖНОЇ СТОРІНКИ
       ══════════════════════════════════════════════════════ */
    var STEPS = {

        dashboard: [
            {
                icon: '👋',
                title: 'Ласкаво просимо до FINEKO!',
                text: 'Це твоя робоча панель. Зараз ми швидко покажемо, що тут є і чому це інакше, ніж усе, чим ти користувався раніше. Поїхали!'
            },
            {
                icon: '📅',
                title: 'Задачі — на конкретний день, не на «колись»',
                text: 'Головна різниця від звичних систем: тут немає просто списку з дедлайном. Кожна задача стоїть на своєму дні. Ти завжди знаєш, хто що робить СЬОГОДНІ — не «планує зробити до п\'ятниці».'
            },
            {
                icon: '👀',
                title: 'Бачиш команду в реальному часі',
                text: 'Не треба чекати на стендап або питати «ти зайнятий?». Один погляд на дашборд — і ти знаєш навантаження кожного. Це менеджмент без мікроменеджменту.'
            },
            {
                icon: '🔍',
                title: 'Глобальний пошук — завжди під рукою',
                text: 'Шукай будь-яку задачу, ціль або шаблон по всій компанії. Просто почни вводити в рядку вгорі — результати з\'являться миттєво.',
                target: '#globalSearchInput',
                position: 'bottom'
            }
        ],

        tasks: [
            {
                icon: '📋',
                title: 'Це не просто список справ',
                text: 'Це твій день, розкладений по поличках. Введи очікуваний час на кожну задачу — і вперше побачиш, чи твій день взагалі реальний. Спробуй прямо зараз: додай сьогоднішні задачі і подивись, скільки годин виходить.'
            },
            {
                icon: '⏱️',
                title: 'Плановий і фактичний час',
                text: 'Кожна задача має два поля часу: скільки планував і скільки реально витратив. Система сама рахує загальний час на день — одразу видно, чи день перевантажений ще до початку.'
            },
            {
                icon: '🎯',
                title: 'Очікуваний результат — обов\'язкове поле',
                text: 'Перед виконанням — пишеш що саме має бути зроблено. Після — що реально зроблено. Так задача перетворюється на зобов\'язання, а не просто пункт у списку.'
            },
            {
                icon: '🏷️',
                title: 'Типи задач — матриця пріоритетів',
                text: 'Бачиш поле «Тип»? Це не формальність. «Важлива термінова» — сигнал: щось пішло не за планом, це хаос. Чим менше таких задач за тиждень — тим краще ти керуєш своїм часом. «Важлива нетермінова» — ось де має бути більшість роботи здорової команди.'
            },
            {
                icon: '👥',
                title: 'Перегляд по кожному співробітнику',
                text: 'Перемикай між учасниками команди і бач задачі кожного на будь-який день. Хочеш зрозуміти, чи зможе Марія взяти ще одну задачу? Один клік — і ти бачиш її день.'
            },
            {
                icon: '🤖',
                title: 'Telegram-бот — FINEKO завжди під рукою',
                text: 'Не хочеш відкривати сайт? Отримуй задачі та звітуй прямо в Telegram. Бот нагадає план на день вранці і зберіть факт увечері. Підключи в Налаштуваннях — це 2 хвилини.'
            }
        ],

        results: [
            {
                icon: '🎯',
                title: 'Цілі — куди ти рухаєшся',
                text: 'Задачі на день — це твій Google Календар. Цілі — це те, куди ти рухаєшся. FINEKO поєднує обидва підходи так, щоб операційка не з\'їдала розвиток.'
            },
            {
                icon: '🌳',
                title: 'Ціль → підціль → підпідціль',
                text: 'Цілі можуть бути багаторівневими. Великий річний результат розбивається на квартальні підцілі, ті — на конкретні кроки. Ніщо не губиться.'
            },
            {
                icon: '⚡',
                title: 'З цілі — одразу в задачу',
                text: 'Одним кліком з будь-якої цілі створюється задача на конкретний день. Не треба перемикатися між сторінками і копіювати назви. Ціль → дія → виконано.'
            }
        ],

        'weekly-plans': [
            {
                icon: '🗺️',
                title: 'Плануй тиждень наперед',
                text: 'Кожен складає свій тижневий план самостійно. Ти — свій, команда — свою. Керівник лише переглядає і затверджує. Більше не треба диктувати кожному що робити — люди самі планують і несуть відповідальність за свій тиждень.'
            },
            {
                icon: '📊',
                title: 'Факт фіксується автоматично',
                text: 'Після кожного дня система порівнює план і факт. Задачі з плану автоматично переносяться у факт — виконавець лише коригує під реальність. Жодної подвійної роботи.'
            },
            {
                icon: '🔎',
                title: 'Вперше бачиш, наскільки точно плануєш',
                text: 'Графіки і діаграми покажуть розбіжності між планом і фактом. З кожним тижнем точність планування зростає — і це видно в цифрах. Це чесна картина продуктивності без маніпуляцій.'
            }
        ],

        projects: [
            {
                icon: '🗂️',
                title: 'Проєкти — бачиш напрямки, не лише задачі',
                text: 'Ти бачиш не просто список справ, а що відбувається по кожному напрямку роботи. Запуск продукту, онбординг клієнта, внутрішній процес — кожен живе окремо.'
            },
            {
                icon: '🔗',
                title: 'Задачі і цілі — прив\'язані до проєкту',
                text: 'Все що робиться в рамках проєкту — задачі, цілі, результати — видно в одному місці. Загальний прогрес рахується автоматично. Не треба збирати звіт вручну.'
            }
        ],

        templates: [
            {
                icon: '📄',
                title: 'Рутина працює сама',
                text: 'Є процеси які повторюються щодня, щотижня або щомісяця? Один раз налаштовуєш шаблон — і більше не думаєш про це. Система сама створить задачу у потрібний день потрібному виконавцю.'
            },
            {
                icon: '⚡',
                title: 'Щоденні, щотижневі, щомісячні',
                text: 'Ранковий звіт кожен день, зустріч команди щопонеділка, звітність раз на місяць — все це налаштовується один раз. Потім система просто робить своє.'
            },
            {
                icon: '🎛️',
                title: 'Гнучко під будь-який ритм',
                text: 'Обирай: кожен день, конкретний день тижня, раз на місяць або будь-яку комбінацію. Призначай виконавця — і шаблон буде додавати задачі саме йому, а не «в загальний котел».'
            }
        ],

        company: [
            {
                icon: '🏢',
                title: 'Профіль компанії',
                text: 'Тут налаштовуєш команду, ролі та доступи. Можна мати кілька компаній і перемикатися між ними у верхній панелі — зручно якщо ведеш кілька напрямків.'
            },
            {
                icon: '📨',
                title: 'Запроси колег',
                text: 'Додай співробітників — кожен отримає власний вид системи зі своїми задачами на день. Ніяких спільних списків де все змішується.'
            },
            {
                icon: '📬',
                title: 'Постановка задач підлеглим',
                text: 'Нова задача від керівника з\'являється зверху у виконавця. Він сам обирає день і час — враховуючи реальне навантаження. А якщо задача з дедлайном — автоматично створиться задача-нагадування для перевірки. Задачі більше не випадають.'
            }
        ],

        account: [
            {
                icon: '⚙️',
                title: 'Налаштування — твій командний центр',
                text: 'Тут управляєш профілем, підключаєш Telegram-бота і обираєш тарифний план. Почни з бота — це займе 2 хвилини і змінить як ти використовуєш FINEKO.'
            },
            {
                icon: '🤖',
                title: 'Telegram-бот — підключи зараз',
                text: 'Бот @FinekoTasks_Bot — найкрута фіча системи. Отримуй план дня вранці, звітуй увечері, додавай задачі голосом у дорозі. FINEKO завжди під рукою, навіть без комп\'ютера.',
                target: '#telegram-section',
                position: 'top'
            },
            {
                icon: '💎',
                title: 'Тариф Pro — перші 3 тижні безкоштовно',
                text: 'На безкоштовному плані — до 3 учасників. На Pro — необмежена команда, AI-асистент в боті і інтеграція з Claude. Твій пробний Pro вже активний — використовуй на повну.',
                target: '#subscription-section',
                position: 'top'
            }
        ]
    };

    /* ══════════════════════════════════════════════════════
       ДВИЖОК ТУРУ
       ══════════════════════════════════════════════════════ */

    var currentPage = null;
    var currentStep = 0;
    var steps = [];
    var overlay, spotlight, tooltip;
    var isRunning = false;

    function lsKey(page) { return 'fineko_tour_seen_' + page; }

    function hasSeen(page) {
        try { return !!localStorage.getItem(lsKey(page)); } catch (e) { return false; }
    }
    function markSeen(page) {
        try { localStorage.setItem(lsKey(page), '1'); } catch (e) {}
    }

    /* ── Створення DOM ── */
    function buildDOM() {
        if (document.getElementById('finekoTourOverlay')) return;

        overlay = document.createElement('div');
        overlay.id = 'finekoTourOverlay';
        overlay.className = 'tour-overlay tour-hidden';
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) skipAll();
        });

        spotlight = document.createElement('div');
        spotlight.id = 'finekoTourSpotlight';
        spotlight.className = 'tour-spotlight';
        spotlight.style.display = 'none';

        tooltip = document.createElement('div');
        tooltip.id = 'finekoTourTooltip';
        tooltip.className = 'tour-tooltip';
        tooltip.innerHTML = buildTooltipHTML();

        document.body.appendChild(overlay);
        document.body.appendChild(spotlight);
        document.body.appendChild(tooltip);

        document.getElementById('tourBtnPrev').addEventListener('click', prevStep);
        document.getElementById('tourBtnNext').addEventListener('click', nextStep);
        document.getElementById('tourBtnSkip').addEventListener('click', skipAll);

        document.addEventListener('keydown', function (e) {
            if (!isRunning) return;
            if (e.key === 'ArrowRight' || e.key === 'Enter') nextStep();
            if (e.key === 'ArrowLeft') prevStep();
            if (e.key === 'Escape') skipAll();
        });

        window.addEventListener('resize', function () {
            if (isRunning) positionStep(currentStep);
        });
    }

    function buildTooltipHTML() {
        return '<div class="tour-header">' +
            '<div class="tour-icon" id="tourIcon">💡</div>' +
            '<div style="flex:1;min-width:0">' +
            '<div class="tour-title" id="tourTitle"></div>' +
            '</div>' +
            '<div class="tour-step-info" id="tourStepInfo"></div>' +
            '</div>' +
            '<div class="tour-progress"><div class="tour-progress-fill" id="tourProgressFill"></div></div>' +
            '<div class="tour-body" id="tourBody"></div>' +
            '<div class="tour-footer">' +
            '<button class="tour-btn-skip" id="tourBtnSkip">Пропустити всі</button>' +
            '<button class="tour-btn-prev" id="tourBtnPrev">← Назад</button>' +
            '<button class="tour-btn-next" id="tourBtnNext">Далі →</button>' +
            '</div>' +
            '<div class="tour-arrow" id="tourArrow"></div>';
    }

    /* ── Запуск ── */
    function start(page, autoStart) {
        var pageSteps = STEPS[page];
        if (!pageSteps || !pageSteps.length) return;
        if (autoStart && hasSeen(page)) return;

        currentPage = page;
        steps = pageSteps;
        currentStep = 0;
        isRunning = true;

        buildDOM();

        overlay.classList.remove('tour-hidden');
        tooltip.style.display = '';
        renderStep(0);
    }

    function renderStep(idx) {
        var step = steps[idx];
        if (!step) return;

        document.getElementById('tourIcon').textContent = step.icon || '💡';
        document.getElementById('tourTitle').textContent = step.title || '';
        document.getElementById('tourBody').textContent = step.text || '';
        document.getElementById('tourStepInfo').textContent = (idx + 1) + ' / ' + steps.length;

        var pct = ((idx + 1) / steps.length * 100).toFixed(0);
        document.getElementById('tourProgressFill').style.width = pct + '%';

        var prevBtn = document.getElementById('tourBtnPrev');
        var nextBtn = document.getElementById('tourBtnNext');
        prevBtn.disabled = idx === 0;
        nextBtn.textContent = idx === steps.length - 1 ? 'Готово ✓' : 'Далі →';
        nextBtn.className = 'tour-btn-next' + (idx === steps.length - 1 ? ' tour-btn-finish' : '');

        positionStep(idx);
    }

    function positionStep(idx) {
        var step = steps[idx];
        var target = step.target ? document.querySelector(step.target) : null;
        var arrow = document.getElementById('tourArrow');

        // Прибираємо всі класи стрілки
        arrow.className = 'tour-arrow';
        tooltip.classList.remove('tour-center');

        if (!target) {
            // Центр екрану
            spotlight.style.display = 'none';
            tooltip.classList.add('tour-center');
            tooltip.style.top = '';
            tooltip.style.left = '';
            tooltip.style.transform = 'translate(-50%,-50%)';
            return;
        }

        // Spotlight навколо елемента
        var pad = 6;
        var r = target.getBoundingClientRect();
        spotlight.style.display = '';
        spotlight.style.top    = (r.top  - pad) + 'px';
        spotlight.style.left   = (r.left - pad) + 'px';
        spotlight.style.width  = (r.width  + pad * 2) + 'px';
        spotlight.style.height = (r.height + pad * 2) + 'px';

        // Позиціонуємо tooltip
        var tw = 340;
        var th = tooltip.offsetHeight || 220;
        var pos = step.position || autoPosition(r);
        var top, left;

        tooltip.style.transform = '';

        if (pos === 'bottom') {
            top  = r.bottom + pad + 10;
            left = Math.max(8, Math.min(r.left, window.innerWidth - tw - 8));
            arrow.className = 'tour-arrow arrow-top';
            arrow.style.top = ''; arrow.style.bottom = ''; arrow.style.left = '20px'; arrow.style.right = '';
        } else if (pos === 'top') {
            top  = r.top - th - pad - 10;
            left = Math.max(8, Math.min(r.left, window.innerWidth - tw - 8));
            arrow.className = 'tour-arrow arrow-bottom';
            arrow.style.bottom = '-7px'; arrow.style.top = ''; arrow.style.left = '20px'; arrow.style.right = '';
        } else if (pos === 'right') {
            top  = Math.max(8, r.top);
            left = r.right + pad + 10;
            arrow.className = 'tour-arrow arrow-left';
            arrow.style.left = '-7px'; arrow.style.top = '20px'; arrow.style.bottom = ''; arrow.style.right = '';
        } else {
            top  = Math.max(8, r.top);
            left = r.left - tw - pad - 10;
            arrow.className = 'tour-arrow arrow-right';
            arrow.style.right = '-7px'; arrow.style.top = '20px'; arrow.style.bottom = ''; arrow.style.left = '';
        }

        // Не виходити за межі вікна
        if (top + th > window.innerHeight - 8) top = window.innerHeight - th - 8;
        if (top < 8) top = 8;
        if (left + tw > window.innerWidth - 8) left = window.innerWidth - tw - 8;
        if (left < 8) left = 8;

        tooltip.style.top  = top + 'px';
        tooltip.style.left = left + 'px';

        // Scroll елемент у видиму область
        target.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function autoPosition(r) {
        var spaceBottom = window.innerHeight - r.bottom;
        var spaceTop    = r.top;
        if (spaceBottom >= 240) return 'bottom';
        if (spaceTop    >= 240) return 'top';
        if (r.left      >= 360) return 'left';
        return 'right';
    }

    function nextStep() {
        if (currentStep < steps.length - 1) {
            currentStep++;
            renderStep(currentStep);
        } else {
            finish();
        }
    }

    function prevStep() {
        if (currentStep > 0) {
            currentStep--;
            renderStep(currentStep);
        }
    }

    function finish() {
        markSeen(currentPage);
        close();
    }

    function skipAll() {
        markSeen(currentPage);
        close();
    }

    function close() {
        isRunning = false;
        if (overlay)    overlay.classList.add('tour-hidden');
        if (spotlight)  spotlight.style.display = 'none';
        if (tooltip)    tooltip.style.display = 'none';
    }

    /* ── Плаваюча кнопка ── */
    function buildFAB(page) {
        if (!STEPS[page] || !STEPS[page].length) return;

        var btn = document.createElement('button');
        btn.className = 'tour-fab';
        btn.setAttribute('aria-label', 'Перезапустити тур-підказки');
        btn.innerHTML = '💡<span class="tour-fab-tooltip">Показати підказки</span>';
        btn.addEventListener('click', function () {
            start(page, false); // false = завжди показувати
        });
        document.body.appendChild(btn);
    }

    /* ── Ініціалізація ── */
    function init() {
        var el = document.getElementById('finekoTourPage');
        if (!el) return;
        var page = el.getAttribute('data-page') || '';
        if (!page) return;

        buildFAB(page);

        // Авто-старт після невеликої затримки щоб сторінка завантажилась
        setTimeout(function () {
            start(page, true);
        }, 600);
    }

    // Публічне API
    window.FinekoTour = { start: start, restart: function (p) { start(p, false); } };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
