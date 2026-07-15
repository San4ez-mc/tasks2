const statusSelect = document.getElementById('statusSelect');
const filterType = document.getElementById('filterType');
const filterProject = document.getElementById('filterProject');
const filterSearch = document.getElementById('filterSearch');
const filterSearchClear = document.getElementById('filterSearchClear');
const filterCount = document.getElementById('filterCount');
const tasksEmptyBlock = document.getElementById('tasksEmptyBlock');
const footerTaskCount = document.getElementById('footerTaskCount');
const dateLabel = document.getElementById('dateLabel');
const datePicker = document.getElementById('datePicker');
const viewToggle = document.getElementById('viewToggle');
const listView = document.getElementById('listView');
const kanbanView = document.getElementById('kanbanView');
const currentTab = (window.TI && window.TI.currentTab) || '';
const drawer = document.getElementById('taskDrawer');
const drawerOverlay = document.getElementById('taskDrawerOverlay');
const drawerClose = document.getElementById('taskDrawerClose');
const drawerCancel = document.getElementById('drawerCancelBtn');
const drawerForm = document.getElementById('taskDrawerForm');
const openTaskLinks = document.querySelectorAll('.open-task-panel');
const completeButtons = document.querySelectorAll('.js-task-complete');
const quickTaskInput = document.getElementById('quickTaskInput');
const quickTaskInputs = document.querySelectorAll('.js-quick-task-input');
const quickTaskSubmitButtons = document.querySelectorAll('.js-quick-task-submit');
const quickCreateSources = document.querySelectorAll('.quick-create-source');
const kanbanLists = document.querySelectorAll('.js-kanban-list');
const kanbanTasks = document.querySelectorAll('.kanban-task[draggable="true"]');
const timerButtons = document.querySelectorAll('.js-task-timer');
const actualTimeDisplays = document.querySelectorAll('.js-task-actual-time');
const footerActualTotals = document.querySelectorAll('.js-footer-actual-total');
const completeOverlay = document.getElementById('taskCompleteOverlay');
const completeModal = document.getElementById('taskCompleteModal');
const completeForm = document.getElementById('taskCompleteForm');
const completeResult = document.getElementById('taskCompleteResult');
const completeActualTime = document.getElementById('taskCompleteActualTime');
const completeDueDay = document.getElementById('taskCompleteDueDay');
const completeText = document.getElementById('taskCompleteText');
const completeCancel = document.getElementById('taskCompleteCancel');
const selectedDate = (window.TI && window.TI.selectedDate) || '';
const currentUserId = (window.TI && window.TI.currentUserId) || 0;
const currentUserName = (window.TI && window.TI.currentUserName) || '';
const typeClassMap = {
    'important-urgent': 'type-important-urgent',
    'important-not-urgent': 'type-important-not-urgent',
    'not-important-urgent': 'type-not-important-urgent',
    'not-important-not-urgent': 'type-not-important-not-urgent'
};
const typeLabelMap = {
    'important-urgent': 'Важлива термінова',
    'important-not-urgent': 'Важлива нетермінова',
    'not-important-urgent': 'Неважлива термінова',
    'not-important-not-urgent': 'Неважлива нетермінова'
};
const typeHintMap = {
    'important-urgent': '"Тушіння пожеж" — термінові непланові задачі, які виникли неочікувано.',
    'important-not-urgent': 'Звичайні робочі процеси. Таких задач має бути найбільше.',
    'not-important-urgent': 'Задачу можна делегувати. Одразу можна призначити на підлеглого.',
    'not-important-not-urgent': 'Задача, яку можна не виконувати.'
};
let refocusQuickInputElement = null;
let completionDraft = null;
let draggedKanbanTask = null;
let dragPlaceholder = null;
let suppressNextTaskOpen = false;
let timerTickHandle = null;
const timerStorageKey = 'fineko-task-timers';

const drawerFields = {
    title: document.getElementById('drawerTitle'),
    description: document.getElementById('drawerDescription'),
    expectedResult: document.getElementById('drawerExpectedResult'),
    actualResult: document.getElementById('drawerActualResult'),
    resultId: document.getElementById('drawerResultId'),
    projectId: document.getElementById('drawerProjectId'),
    templateId: document.getElementById('drawerTemplateId'),
    templateName: document.getElementById('drawerTemplateName'),
    dueDay: document.getElementById('drawerDueDay'),
    startTime: document.getElementById('drawerStartTime'),
    dueDate: document.getElementById('drawerDueDate'),
    type: document.getElementById('drawerType'),
    status: document.getElementById('drawerStatus'),
    expectedTime: document.getElementById('drawerExpectedTime'),
    actualTime: document.getElementById('drawerActualTime'),
    assignee: document.getElementById('drawerAssignee'),
    assignees: document.getElementById('drawerAssignees'),
    assigneeSingleWrap: document.getElementById('drawerAssigneeSingleWrap'),
    assigneeMultiWrap: document.getElementById('drawerAssigneeMultiWrap'),
    reporter: document.getElementById('drawerReporter'),
    typePreview: document.getElementById('drawerTypePreview'),
    typePreviewText: document.getElementById('drawerTypePreviewText')
};

function setSelectedOptions(select, values) {
    if (!select) {
        return;
    }

    const normalizedValues = Array.isArray(values)
        ? values.map(function (value) { return String(value); })
        : [];

    Array.prototype.forEach.call(select.options, function (option) {
        option.selected = normalizedValues.indexOf(String(option.value)) !== -1;
    });
}

function setAssigneeMode(mode) {
    const isCreate = mode === 'create';

    if (drawerFields.assigneeSingleWrap) {
        drawerFields.assigneeSingleWrap.style.display = isCreate ? 'none' : '';
    }

    if (drawerFields.assigneeMultiWrap) {
        drawerFields.assigneeMultiWrap.style.display = isCreate ? '' : 'none';
    }

    if (drawerFields.assignee) {
        drawerFields.assignee.disabled = isCreate;
        drawerFields.assignee.required = !isCreate;
    }

    if (drawerFields.assignees) {
        drawerFields.assignees.disabled = !isCreate;
        drawerFields.assignees.required = isCreate;
    }
}

function updateTypePreview(typeValue) {
    const preview = drawerFields.typePreview;
    if (!preview) {
        return;
    }

    preview.classList.remove('type-important-urgent', 'type-important-not-urgent', 'type-not-important-urgent', 'type-not-important-not-urgent');
    preview.classList.add(typeClassMap[typeValue] || 'type-important-not-urgent');
    if (drawerFields.typePreviewText) {
        drawerFields.typePreviewText.textContent = typeLabelMap[typeValue] || 'Не вказано';
    }
    const hintEl = document.getElementById('drawerTypeHint');
    if (hintEl) {
        hintEl.textContent = typeHintMap[typeValue] || '';
    }
}

function buildUrl(nextDate, nextStatus) {
    const params = new URLSearchParams(window.location.search);
    params.set('tab', currentTab);
    params.set('date', nextDate || (window.TI && window.TI.selectedDate) || '');
    params.set('status', nextStatus || (window.TI && window.TI.selectedStatus) || '');
    return '/tasks?' + params.toString();
}

function temporarilySuppressTaskOpen() {
    suppressNextTaskOpen = true;
    window.setTimeout(function () {
        suppressNextTaskOpen = false;
    }, 220);
}

function updateTaskStatusByDrop(taskId, nextStatus) {
    const payload = new URLSearchParams();
    payload.set('status', nextStatus);
    payload.set('return_url', window.location.pathname + window.location.search);

    return fetch('/tasks/edit/' + taskId, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
        },
        body: payload.toString(),
        credentials: 'same-origin'
    });
}

function loadTaskTimers() {
    try {
        const raw = window.localStorage.getItem(timerStorageKey);
        if (!raw) {
            return {};
        }

        const parsed = JSON.parse(raw);
        return parsed && typeof parsed === 'object' ? parsed : {};
    } catch (error) {
        return {};
    }
}

function saveTaskTimers(timers) {
    window.localStorage.setItem(timerStorageKey, JSON.stringify(timers || {}));
}

function formatDurationWithSeconds(totalSeconds) {
    const seconds = Math.max(0, Number(totalSeconds || 0));
    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    const restSeconds = seconds % 60;

    if (hours > 0) {
        return String(hours) + ':' + String(minutes).padStart(2, '0') + ':' + String(restSeconds).padStart(2, '0');
    }

    return String(minutes) + ':' + String(restSeconds).padStart(2, '0');
}

function formatDurationMinutes(totalMinutes) {
    const minutes = Math.max(0, Math.round(Number(totalMinutes || 0)));
    if (!minutes) {
        return '0 хв';
    }

    const hours = Math.floor(minutes / 60);
    const restMinutes = minutes % 60;
    if (hours > 0 && restMinutes > 0) {
        return String(hours) + 'г ' + String(restMinutes) + 'хв';
    }
    if (hours > 0) {
        return String(hours) + 'г';
    }

    return String(restMinutes) + 'хв';
}

function getTimerState(taskId, baseMinutes) {
    const timers = loadTaskTimers();
    const stored = timers[String(taskId)] || null;
    if (!stored) {
        return {
            running: false,
            totalSeconds: Math.max(0, Number(baseMinutes || 0)) * 60,
        };
    }

    const baseSeconds = Math.max(0, Number(stored.baseSeconds || 0));
    if (!stored.startedAt) {
        return {
            running: false,
            totalSeconds: baseSeconds,
        };
    }

    const elapsedSeconds = Math.max(0, Math.floor((Date.now() - Number(stored.startedAt || 0)) / 1000));
    return {
        running: true,
        totalSeconds: baseSeconds + elapsedSeconds,
    };
}

function persistTimerActualMinutes(taskId, totalSeconds) {
    const payload = new URLSearchParams();
    payload.set('actual_time', String(totalSeconds > 0 ? Math.max(1, Math.round(totalSeconds / 60)) : 0));
    payload.set('return_url', window.location.pathname + window.location.search);

    return fetch('/tasks/edit/' + taskId, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: payload.toString(),
        credentials: 'same-origin'
    });
}

function setTaskBaseMinutes(taskId, minutes) {
    const safeMinutes = Math.max(0, Number(minutes || 0));

    actualTimeDisplays.forEach(function (display) {
        if (Number(display.dataset.taskId || 0) === taskId) {
            display.dataset.baseMinutes = String(safeMinutes);
        }
    });

    timerButtons.forEach(function (button) {
        if (Number(button.dataset.taskId || 0) === taskId) {
            button.dataset.baseMinutes = String(safeMinutes);
        }
    });
}

function updateFooterActualTotals() {
    footerActualTotals.forEach(function (footerTotal) {
        const summaryGroup = footerTotal.dataset.summaryGroup || '';
        let totalSeconds = 0;

        actualTimeDisplays.forEach(function (display) {
            if ((display.dataset.summaryGroup || '') !== summaryGroup) {
                return;
            }

            const taskId = Number(display.dataset.taskId || 0);
            const baseMinutes = Number(display.dataset.baseMinutes || 0);
            const timerState = getTimerState(taskId, baseMinutes);
            totalSeconds += timerState.totalSeconds;
        });

        footerTotal.textContent = formatDurationMinutes(totalSeconds / 60);
    });
}

function stopTaskTimer(taskId, baseMinutes, shouldPersist) {
    const timers = loadTaskTimers();
    const storageKey = String(taskId);
    const existingState = getTimerState(taskId, baseMinutes);
    const roundedMinutes = existingState.totalSeconds > 0 ? Math.max(1, Math.round(existingState.totalSeconds / 60)) : 0;

    timers[storageKey] = {
        baseSeconds: existingState.totalSeconds,
        startedAt: null
    };
    saveTaskTimers(timers);
    setTaskBaseMinutes(taskId, roundedMinutes);
    syncTimerVisuals();

    if (!shouldPersist) {
        return Promise.resolve({ totalSeconds: existingState.totalSeconds, roundedMinutes: roundedMinutes });
    }

    return persistTimerActualMinutes(taskId, existingState.totalSeconds)
        .catch(function () {
            return null;
        })
        .then(function () {
            syncTimerVisuals();
            return { totalSeconds: existingState.totalSeconds, roundedMinutes: roundedMinutes };
        });
}

function syncTimerVisuals() {
    actualTimeDisplays.forEach(function (display) {
        const taskId = Number(display.dataset.taskId || 0);
        const baseMinutes = Number(display.dataset.baseMinutes || 0);
        const timerState = getTimerState(taskId, baseMinutes);
        if (timerState.running) {
            display.textContent = formatDurationWithSeconds(timerState.totalSeconds);
            display.classList.add('is-live');
        } else {
            display.textContent = formatDurationMinutes(timerState.totalSeconds / 60);
            display.classList.remove('is-live');
        }
    });

    timerButtons.forEach(function (button) {
        const taskId = Number(button.dataset.taskId || 0);
        const baseMinutes = Number(button.dataset.baseMinutes || 0);
        const timerState = getTimerState(taskId, baseMinutes);
        button.classList.toggle('is-running', timerState.running);
        button.setAttribute('aria-label', timerState.running ? 'Зупинити таймер' : 'Запустити таймер');
        button.textContent = timerState.running ? '⏸' : '⏱';
    });

    updateFooterActualTotals();
}

function ensureTimerTicking() {
    if (timerTickHandle !== null) {
        window.clearInterval(timerTickHandle);
    }

    timerTickHandle = window.setInterval(syncTimerVisuals, 1000);
    syncTimerVisuals();
}

statusSelect.addEventListener('change', function () {
    window.location.href = buildUrl(null, statusSelect.value);
});

function applyClientFilters() {
    var typeVal = filterType ? filterType.value : '';
    var projectVal = filterProject ? filterProject.value : '';
    var searchVal = filterSearch ? filterSearch.value.trim().toLowerCase() : '';

    var rows = document.querySelectorAll('#taskListBody .task-row');
    var visible = 0;

    rows.forEach(function (row) {
        var rowType = row.getAttribute('data-task-type') || '';
        var rowProject = row.getAttribute('data-task-project') || '';

        var typeMatch = !typeVal || rowType === typeVal;
        var projectMatch = !projectVal || rowProject === projectVal;

        var searchMatch = true;
        if (searchVal) {
            var taskData = row.getAttribute('data-task') || '{}';
            var task = {};
            try { task = JSON.parse(taskData); } catch (e) { }
            var haystack = [
                task.title || '',
                task.description || '',
                task.expected_result || '',
                task.assignee || '',
                task.result_title || '',
                task.template_name || ''
            ].join(' ').toLowerCase();
            searchMatch = haystack.indexOf(searchVal) !== -1;
        }

        if (typeMatch && projectMatch && searchMatch) {
            row.style.display = '';
            visible++;
        } else {
            row.style.display = 'none';
        }
    });

    if (footerTaskCount) footerTaskCount.textContent = visible;
    if (tasksEmptyBlock) {
        if (visible === 0 && rows.length > 0) {
            tasksEmptyBlock.style.display = '';
            tasksEmptyBlock.textContent = 'Жодна задача не відповідає фільтрам.';
        } else {
            tasksEmptyBlock.style.display = 'none';
        }
    }
    if (filterCount) {
        if (typeVal || projectVal || searchVal) {
            filterCount.textContent = 'Показано: ' + visible + ' з ' + rows.length;
        } else {
            filterCount.textContent = '';
        }
    }
}

if (filterType) filterType.addEventListener('change', applyClientFilters);
if (filterProject) filterProject.addEventListener('change', applyClientFilters);
if (filterSearch) {
    filterSearch.addEventListener('input', function () {
        if (filterSearchClear) filterSearchClear.style.display = filterSearch.value ? '' : 'none';
        applyClientFilters();
    });
}
if (filterSearchClear) {
    filterSearchClear.addEventListener('click', function () {
        if (filterSearch) { filterSearch.value = ''; filterSearch.focus(); }
        filterSearchClear.style.display = 'none';
        applyClientFilters();
    });
}

dateLabel.addEventListener('click', function () {
    if (typeof datePicker.showPicker === 'function') {
        datePicker.showPicker();
    } else {
        datePicker.focus();
        datePicker.click();
    }
});

datePicker.addEventListener('change', function () {
    if (!datePicker.value) {
        return;
    }
    window.location.href = buildUrl(datePicker.value, null);
});

function setView(view) {
    const buttons = viewToggle.querySelectorAll('.view-btn');
    buttons.forEach(function (btn) {
        btn.classList.toggle('active', btn.dataset.view === view);
    });

    if (view === 'kanban') {
        listView.style.display = 'none';
        kanbanView.classList.add('active');
    } else {
        listView.style.display = 'grid';
        kanbanView.classList.remove('active');
    }

    localStorage.setItem('tasks_view_mode', view);
}

viewToggle.addEventListener('click', function (event) {
    const button = event.target.closest('.view-btn');
    if (!button) {
        return;
    }
    setView(button.dataset.view);
});

setView(localStorage.getItem('tasks_view_mode') || 'list');

function closeDrawer() {
    drawer.classList.remove('open');
    drawerOverlay.classList.remove('open');

    if (refocusQuickInputElement) {
        const inputToFocus = refocusQuickInputElement;
        refocusQuickInputElement = null;
        setTimeout(function () {
            inputToFocus.focus();
        }, 30);
    }
}

function closeCompleteModal() {
    if (!completeModal || !completeOverlay) {
        return;
    }

    completeModal.classList.remove('open');
    completeOverlay.classList.remove('open');
    completionDraft = null;
}

function openCompleteModal(config) {
    completionDraft = config || null;
    if (!completeModal || !completeOverlay || !completeForm || !completeResult) {
        return;
    }

    completeForm.action = (config && config.action) ? config.action : '/tasks/edit/0';
    completeResult.value = (config && config.result) ? config.result : '';
    if (completeActualTime) {
        const actualTime = config && Number(config.actualTime || 0) > 0
            ? Number(config.actualTime || 0)
            : 0;
        const expectedTime = config && Number(config.expectedTime || 0) > 0
            ? Number(config.expectedTime || 0)
            : 0;
        const minutes = actualTime > 0 ? actualTime : expectedTime;
        completeActualTime.value = minutes > 0 ? String(minutes) : '';
    }
    if (completeText) {
        const taskTitle = config && config.title ? '«' + config.title + '»' : 'цю задачу';
        completeText.textContent = 'Щоб позначити ' + taskTitle + ' виконаною, опишіть фактичний результат.';
    }
    if (completeDueDay) {
        var today = new Date().toISOString().slice(0, 10);
        completeDueDay.value = (config && config.dueDay) ? config.dueDay : (selectedDate || today);
    }

    completeModal.classList.add('open');
    completeOverlay.classList.add('open');
    setTimeout(function () {
        completeResult.focus();
    }, 30);
}

function openDrawer(payload) {
    setAssigneeMode('edit');
    drawerFields.title.value = payload.title || '';
    drawerFields.description.value = payload.description || '';
    drawerFields.expectedResult.value = payload.expected_result || '';
    drawerFields.actualResult.value = payload.actual_result || '';
    drawerFields.resultId.value = payload.result_id ? String(payload.result_id) : '';
    if (drawerFields.projectId) drawerFields.projectId.value = payload.project_id ? String(payload.project_id) : '';
    if (drawerFields.templateId) drawerFields.templateId.value = payload.template_id ? String(payload.template_id) : '';
    if (drawerFields.templateName) drawerFields.templateName.value = payload.template_name || 'Звичайна задача';
    drawerFields.dueDay.value = payload.due_day || '';
    drawerFields.startTime.value = payload.start_time || '';
    drawerFields.type.value = payload.type || 'important-urgent';
    updateTypePreview(drawerFields.type.value);
    drawerFields.status.value = payload.status || 'todo';
    drawerFields.expectedTime.value = Number(payload.expected_time || 0);
    drawerFields.actualTime.value = Number(payload.actual_time || 0);
    drawerFields.assignee.value = String(payload.assignee_id || currentUserId || '');
    setSelectedOptions(drawerFields.assignees, [payload.assignee_id || currentUserId || '']);
    drawerFields.reporter.textContent = payload.reporter || '—';
    drawerForm.action = '/tasks/edit/' + payload.id;

    // Show/hide actual_result field
    var status = drawerFields.status.value;
    var actualResultField = document.getElementById('drawerActualResultField');
    var resultGrid = document.getElementById('drawerResultGrid');
    if (status === 'done') {
        actualResultField.style.display = '';
        if (resultGrid) resultGrid.style.gridTemplateColumns = '';
    } else {
        actualResultField.style.display = 'none';
        if (resultGrid) resultGrid.style.gridTemplateColumns = '1fr';
    }

    drawer.classList.add('open');
    drawerOverlay.classList.add('open');
}

// Show/hide actual_result field on status change
var drawerStatus = document.getElementById('drawerStatus');
if (drawerStatus) {
    drawerStatus.addEventListener('change', function () {
        var actualResultField = document.getElementById('drawerActualResultField');
        var resultGrid = document.getElementById('drawerResultGrid');
        if (this.value === 'done') {
            actualResultField.style.display = '';
            if (resultGrid) resultGrid.style.gridTemplateColumns = '';
        } else {
            actualResultField.style.display = 'none';
            if (resultGrid) resultGrid.style.gridTemplateColumns = '1fr';
        }
    });
}

function openCreateDrawer(prefill, options) {
    const data = prefill || {};
    const opts = options || {};
    setAssigneeMode('create');
    drawerFields.title.value = '';
    drawerFields.description.value = '';
    drawerFields.expectedResult.value = '';
    drawerFields.actualResult.value = '';
    drawerFields.resultId.value = '';
    if (drawerFields.projectId) drawerFields.projectId.value = '';
    if (drawerFields.templateName) drawerFields.templateName.value = 'Звичайна задача';
    drawerFields.dueDay.value = selectedDate;
    drawerFields.startTime.value = '';
    drawerFields.type.value = 'important-not-urgent';
    updateTypePreview(drawerFields.type.value);
    drawerFields.status.value = 'todo';
    drawerFields.expectedTime.value = '';
    drawerFields.actualTime.value = 0;
    drawerFields.assignee.value = String(currentUserId || drawerFields.assignee.value || '');
    setSelectedOptions(drawerFields.assignees, [currentUserId || drawerFields.assignee.value || '']);
    drawerFields.reporter.textContent = currentUserName;
    drawerForm.action = '/tasks/create';

    if (data.title) {
        drawerFields.title.value = data.title;
    }

    if (data.description) {
        drawerFields.description.value = data.description;
    }

    if (data.expected_result) {
        drawerFields.expectedResult.value = data.expected_result;
    }

    if (data.result_id) {
        drawerFields.resultId.value = String(data.result_id);
    }

    if (data.template_id) {
        if (drawerFields.templateId) drawerFields.templateId.value = String(data.template_id);
        if (drawerFields.templateName) drawerFields.templateName.value = data.template_name || 'Шаблон';
    }

    if (data.type) {
        drawerFields.type.value = data.type;
        updateTypePreview(drawerFields.type.value);
    }

    if (typeof data.expected_time !== 'undefined') {
        drawerFields.expectedTime.value = Number(data.expected_time || 0);
    }

    if (data.assignee_id) {
        drawerFields.assignee.value = String(data.assignee_id);
        setSelectedOptions(drawerFields.assignees, [data.assignee_id]);
    }

    if (opts.refocusQuickInputElement) {
        refocusQuickInputElement = opts.refocusQuickInputElement;
    }

    syncDueDate();

    drawer.classList.add('open');
    drawerOverlay.classList.add('open');
}

function deriveTaskTitleFromGoal(label) {
    if (!label) {
        return '';
    }

    const parts = String(label).split('→');
    const last = parts[parts.length - 1] || '';
    return last.trim();
}

function syncDueDate() {
    const day = drawerFields.dueDay.value;
    const start = drawerFields.startTime.value || '00:00';
    drawerFields.dueDate.value = day ? (day + ' ' + start + ':00') : '';
}

function submitQuickTaskFromInput(inputElement) {
    if (!inputElement) {
        return;
    }

    const title = (inputElement.value || '').trim();
    if (!title) {
        return;
    }

    inputElement.value = '';

    openCreateDrawer({
        title: title
    }, {
        refocusQuickInputElement: inputElement
    });
}

drawerFields.dueDay.addEventListener('change', syncDueDate);
drawerFields.startTime.addEventListener('change', syncDueDate);
drawerFields.type.addEventListener('change', function () {
    updateTypePreview(drawerFields.type.value);
});

// Clear validation errors when user corrects the fields
drawerFields.expectedResult.addEventListener('input', function () {
    if ((this.value || '').trim()) {
        var el = document.getElementById('drawerExpectedResultError');
        if (el) el.style.display = 'none';
        this.classList.remove('field-invalid');
    }
});
drawerFields.expectedTime.addEventListener('input', function () {
    if (Number(this.value) >= 1) {
        var el = document.getElementById('drawerExpectedTimeError');
        if (el) el.style.display = 'none';
        this.classList.remove('field-invalid');
    }
});

drawerForm.addEventListener('submit', function (event) {
    syncDueDate();
    var hasError = false;

    const normalizedTitle = (drawerFields.title.value || '').trim();
    if (!normalizedTitle) {
        event.preventDefault();
        drawerFields.title.value = '';
        drawerFields.title.focus();
        return;
    }
    drawerFields.title.value = normalizedTitle;
    if (!drawerFields.assignees.disabled) {
        const selectedAssignees = Array.prototype.filter.call(drawerFields.assignees.options, function (option) {
            return option.selected;
        });

        if (selectedAssignees.length === 0) {
            event.preventDefault();
            drawerFields.assignees.focus();
            return;
        }
    }

    // Validate expected_result
    var expectedResultVal = (drawerFields.expectedResult.value || '').trim();
    var expectedResultError = document.getElementById('drawerExpectedResultError');
    if (!expectedResultVal) {
        if (expectedResultError) {
            expectedResultError.textContent = 'Заповніть очікуваний результат — це обов\'язкове поле.';
            expectedResultError.style.display = 'block';
        }
        drawerFields.expectedResult.classList.add('field-invalid');
        if (!hasError) {
            drawerFields.expectedResult.scrollIntoView({ behavior: 'smooth', block: 'center' });
            drawerFields.expectedResult.focus();
        }
        hasError = true;
    } else {
        if (expectedResultError) expectedResultError.style.display = 'none';
        drawerFields.expectedResult.classList.remove('field-invalid');
    }

    // Validate expected_time
    var expectedTimeVal = Number(drawerFields.expectedTime.value || 0);
    var expectedTimeError = document.getElementById('drawerExpectedTimeError');
    if (!expectedTimeVal || expectedTimeVal < 1) {
        if (expectedTimeError) {
            expectedTimeError.textContent = 'Вкажіть очікуваний час виконання (хоча б 1 хв) — це обов\'язкове поле.';
            expectedTimeError.style.display = 'block';
        }
        drawerFields.expectedTime.classList.add('field-invalid');
        if (!hasError) {
            drawerFields.expectedTime.scrollIntoView({ behavior: 'smooth', block: 'center' });
            drawerFields.expectedTime.focus();
        }
        hasError = true;
    } else {
        if (expectedTimeError) expectedTimeError.style.display = 'none';
        drawerFields.expectedTime.classList.remove('field-invalid');
    }

    if (hasError) {
        event.preventDefault();
        return;
    }

    if (drawerFields.status.value === 'done' && !(drawerFields.actualResult.value || '').trim()) {
        event.preventDefault();
        openCompleteModal({
            action: drawerForm.action,
            title: drawerFields.title.value || '',
            result: '',
            expectedTime: Number(drawerFields.expectedTime.value || 0),
            dueDay: drawerFields.dueDay ? drawerFields.dueDay.value : '',
            onSubmit: function (value, actualTimeValue, dueDayValue) {
                drawerFields.actualResult.value = value;
                const minutes = Number(actualTimeValue || 0);
                drawerFields.actualTime.value = minutes > 0 ? String(minutes) : '';
                if (dueDayValue && drawerFields.dueDay) {
                    drawerFields.dueDay.value = dueDayValue;
                    syncDueDate();
                }
                drawerForm.submit();
            }
        });
    }
});

updateTypePreview(drawerFields.type.value);

updateTypePreview(drawerFields.type.value);

// Event delegation for task panel opening — works for all rows including filtered ones
document.addEventListener('click', function (event) {
    var link = event.target.closest('.open-task-panel');
    if (!link) return;

    if (suppressNextTaskOpen) {
        event.preventDefault();
        return;
    }
    if (event.target.closest('.js-task-complete')) return;
    if (event.target.closest('.js-task-timer')) return;

    event.preventDefault();
    var raw = link.getAttribute('data-task');
    if (!raw || raw === '{}') {
        console.error('openDrawer: no data-task on', link);
        return;
    }
    try {
        openDrawer(JSON.parse(raw));
    } catch (err) {
        console.error('openDrawer error:', err);
    }
});

document.addEventListener('keydown', function (event) {
    if (event.key !== 'Enter' && event.key !== ' ') return;
    var link = event.target.closest('.open-task-panel');
    if (!link) return;
    event.preventDefault();
    link.click();
});

// Keep legacy forEach for any code that relied on openTaskLinks (no-op now)
openTaskLinks.forEach(function () { });

completeButtons.forEach(function (button) {
    button.addEventListener('click', function (event) {
        event.preventDefault();
        event.stopPropagation();

        const taskId = Number(button.dataset.taskId || 0);
        const relatedTimerButton = Array.prototype.find.call(timerButtons, function (timerButton) {
            return Number(timerButton.dataset.taskId || 0) === taskId;
        }) || null;
        const baseMinutes = relatedTimerButton ? Number(relatedTimerButton.dataset.baseMinutes || 0) : 0;
        const currentState = getTimerState(taskId, baseMinutes);
        const openModal = function (actualMinutes) {
            openCompleteModal({
                action: '/tasks/edit/' + taskId,
                title: button.dataset.taskTitle || '',
                result: button.dataset.taskResult || '',
                expectedTime: Number(button.dataset.taskExpectedTime || 0),
                actualTime: actualMinutes,
                dueDay: button.dataset.taskDueDay || ''
            });
        };

        if (currentState.running) {
            stopTaskTimer(taskId, baseMinutes, true).then(function (result) {
                openModal(result ? result.roundedMinutes : Math.max(1, Math.round(currentState.totalSeconds / 60)));
            });
            return;
        }

        openModal(baseMinutes > 0 ? baseMinutes : Number(button.dataset.taskExpectedTime || 0));
    });
});

completeForm.addEventListener('submit', function (event) {
    const value = (completeResult.value || '').trim();
    if (!value) {
        event.preventDefault();
        completeResult.focus();
        return;
    }

    const actualTimeValue = completeActualTime ? String(completeActualTime.value || '').trim() : '';
    const dueDayValue = completeDueDay ? completeDueDay.value : '';

    if (completionDraft && typeof completionDraft.onSubmit === 'function') {
        event.preventDefault();
        const callback = completionDraft.onSubmit;
        closeCompleteModal();
        callback(value, actualTimeValue, dueDayValue);
    }
});

if (completeCancel) {
    completeCancel.addEventListener('click', closeCompleteModal);
}

if (completeOverlay) {
    completeOverlay.addEventListener('click', closeCompleteModal);
}

drawerClose.addEventListener('click', closeDrawer);
drawerCancel.addEventListener('click', closeDrawer);
drawerOverlay.addEventListener('click', closeDrawer);
quickTaskInputs.forEach(function (inputElement) {
    inputElement.addEventListener('keydown', function (event) {
        if (event.key !== 'Enter') {
            return;
        }
        event.preventDefault();
        submitQuickTaskFromInput(inputElement);
    });
});

quickTaskSubmitButtons.forEach(function (button) {
    button.addEventListener('click', function () {
        const inputId = button.dataset.quickInputId || '';
        const targetInput = inputId ? document.getElementById(inputId) : null;
        submitQuickTaskFromInput(targetInput);
    });
});

timerButtons.forEach(function (button) {
    button.addEventListener('click', function (event) {
        event.preventDefault();
        event.stopPropagation();

        const taskId = Number(button.dataset.taskId || 0);
        const baseMinutes = Number(button.dataset.baseMinutes || 0);
        if (!taskId) {
            return;
        }

        if (button.dataset.canStart === '0') {
            window.alert('Спочатку прийміть цю делеговану задачу, а вже потім запускайте таймер.');
            return;
        }

        const timers = loadTaskTimers();
        const storageKey = String(taskId);
        const existingState = getTimerState(taskId, baseMinutes);

        if (timers[storageKey] && timers[storageKey].startedAt) {
            stopTaskTimer(taskId, baseMinutes, true);
            return;
        }

        timers[storageKey] = {
            baseSeconds: existingState.totalSeconds,
            startedAt: Date.now()
        };
        saveTaskTimers(timers);
        syncTimerVisuals();
    });
});

kanbanTasks.forEach(function (taskCard) {
    taskCard.addEventListener('dragstart', function (event) {
        draggedKanbanTask = taskCard;
        temporarilySuppressTaskOpen();
        taskCard.classList.add('is-dragging');
        if (event.dataTransfer) {
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', String(taskCard.dataset.taskId || ''));
        }

        // Створюємо placeholder з тими ж розмірами
        dragPlaceholder = document.createElement('div');
        dragPlaceholder.className = 'kanban-drag-placeholder';
        dragPlaceholder.style.height = taskCard.offsetHeight + 'px';
    });

    taskCard.addEventListener('dragend', function () {
        taskCard.classList.remove('is-dragging');
        if (dragPlaceholder && dragPlaceholder.parentNode) {
            dragPlaceholder.parentNode.removeChild(dragPlaceholder);
        }
        dragPlaceholder = null;
        draggedKanbanTask = null;
        kanbanLists.forEach(function (list) {
            const column = list.closest('.kanban-col');
            if (column) {
                column.classList.remove('is-drop-target');
            }
        });
    });
});

// Знаходить елемент після якого вставити placeholder (вертикально)
function getDragAfterElement(list, y) {
    const draggableItems = Array.from(list.querySelectorAll('.kanban-task:not(.is-dragging):not(.kanban-drag-placeholder)'));
    return draggableItems.reduce(function (closest, child) {
        const box = child.getBoundingClientRect();
        const offset = y - box.top - box.height / 2;
        if (offset < 0 && offset > closest.offset) {
            return { offset: offset, element: child };
        }
        return closest;
    }, { offset: Number.NEGATIVE_INFINITY }).element;
}

// Оновлює лічильник у шапці колонки
function updateKanbanColCounter(list) {
    const col = list.closest('.kanban-col');
    if (!col) return;
    const head = col.querySelector('.kanban-head');
    if (!head) return;
    const count = list.querySelectorAll('.kanban-task').length;
    head.textContent = head.textContent.replace(/\s*\(\d+\)/, '') + ' (' + count + ')';
}

// Оновлює .empty-block видимість
function updateKanbanEmptyBlock(list) {
    const count = list.querySelectorAll('.kanban-task').length;
    let emptyBlock = list.querySelector('.empty-block');
    if (count === 0) {
        if (!emptyBlock) {
            emptyBlock = document.createElement('div');
            emptyBlock.className = 'empty-block';
            emptyBlock.textContent = 'Порожньо';
            list.appendChild(emptyBlock);
        }
    } else if (emptyBlock) {
        emptyBlock.remove();
    }
}

kanbanLists.forEach(function (list) {
    list.addEventListener('dragover', function (event) {
        if (!draggedKanbanTask || !dragPlaceholder) {
            return;
        }

        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';

        const column = list.closest('.kanban-col');
        if (column) {
            column.classList.add('is-drop-target');
        }

        // Вставляємо placeholder у потрібне місце
        const afterElement = getDragAfterElement(list, event.clientY);
        if (afterElement) {
            list.insertBefore(dragPlaceholder, afterElement);
        } else {
            list.appendChild(dragPlaceholder);
        }
    });

    list.addEventListener('dragleave', function (event) {
        const column = list.closest('.kanban-col');
        if (!column) {
            return;
        }
        // Перевіряємо чи курсор справді вийшов з колонки (не просто з дочірнього елемента)
        if (event.relatedTarget && column.contains(event.relatedTarget)) {
            return;
        }
        column.classList.remove('is-drop-target');
    });

    list.addEventListener('drop', function (event) {
        if (!draggedKanbanTask || !dragPlaceholder) {
            return;
        }

        event.preventDefault();
        temporarilySuppressTaskOpen();

        const column = list.closest('.kanban-col');
        if (column) {
            column.classList.remove('is-drop-target');
        }

        const taskId = Number(draggedKanbanTask.dataset.taskId || 0);
        const prevStatus = String(draggedKanbanTask.dataset.taskStatus || '');
        const nextStatus = String(list.dataset.status || '');

        // Зберігаємо позицію для revert
        const prevList = draggedKanbanTask.parentNode;
        const prevNextSibling = draggedKanbanTask.nextSibling;

        // Переміщаємо картку в DOM на місце placeholder
        list.insertBefore(draggedKanbanTask, dragPlaceholder);
        dragPlaceholder.remove();
        dragPlaceholder = null;
        draggedKanbanTask.classList.remove('is-dragging');

        // Оновлюємо атрибут статусу і клас is-done
        draggedKanbanTask.dataset.taskStatus = nextStatus;
        if (nextStatus === 'done') {
            draggedKanbanTask.classList.add('is-done');
        } else {
            draggedKanbanTask.classList.remove('is-done');
        }

        // Оновлюємо лічильники та empty-блоки
        updateKanbanColCounter(list);
        updateKanbanEmptyBlock(list);
        if (prevList && prevList !== list) {
            updateKanbanColCounter(prevList);
            updateKanbanEmptyBlock(prevList);
        }

        if (!taskId || !nextStatus || prevStatus === nextStatus) {
            return;
        }

        // AJAX оновлення статусу
        updateTaskStatusByDrop(taskId, nextStatus)
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }
            })
            .catch(function () {
                // Revert: повертаємо картку назад
                if (prevList) {
                    prevList.insertBefore(draggedKanbanTask || list.querySelector('[data-task-id="' + taskId + '"]'), prevNextSibling || null);
                    const card = list.querySelector('[data-task-id="' + taskId + '"]') || (draggedKanbanTask);
                    if (card) {
                        card.dataset.taskStatus = prevStatus;
                        if (prevStatus === 'done') { card.classList.add('is-done'); }
                        else { card.classList.remove('is-done'); }
                    }
                    updateKanbanColCounter(list);
                    updateKanbanEmptyBlock(list);
                    updateKanbanColCounter(prevList);
                    updateKanbanEmptyBlock(prevList);
                } else {
                    window.location.reload();
                }
            });
    });
});

ensureTimerTicking();

quickCreateSources.forEach(function (button) {
    button.addEventListener('click', function () {
        const sourceType = button.dataset.sourceType || '';
        const sourceLabel = button.dataset.sourceLabel || '';
        const sourceId = Number(button.dataset.sourceId || 0);

        if (sourceType === 'goal') {
            openCreateDrawer({
                title: deriveTaskTitleFromGoal(sourceLabel),
                expected_result: 'Створено з цілі: ' + sourceLabel,
                result_id: sourceId
            });
            return;
        }

        if (sourceType === 'template') {
            openCreateDrawer({
                title: sourceLabel,
                description: button.dataset.sourceDescription || ('Створено з шаблону: ' + sourceLabel),
                expected_result: button.dataset.sourceExpected || '',
                template_id: sourceId,
                template_name: sourceLabel,
                type: button.dataset.sourceTypevalue || 'important-not-urgent',
                assignee_id: Number(button.dataset.sourceAssignee || 0),
                expected_time: Number(button.dataset.sourceTime || 0)
            });
        }
    });
});