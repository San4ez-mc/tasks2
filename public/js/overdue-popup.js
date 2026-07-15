(function () {
    'use strict';

    var tasks = window.OVERDUE_TASKS || [];
    if (!tasks.length) return;

    // Session-storage dedup: if user already dismissed this exact set today, skip
    var storageKey = 'od_dismissed_' + new Date().toISOString().slice(0, 10);
    var dismissedIds = [];
    try {
        dismissedIds = JSON.parse(sessionStorage.getItem(storageKey) || '[]');
    } catch (e) { }

    var pendingTasks = tasks.filter(function (t) {
        return dismissedIds.indexOf(t.id) === -1;
    });

    if (!pendingTasks.length) return;

    // --- Build modal HTML ---
    var overlay = document.createElement('div');
    overlay.className = 'od-overlay';
    overlay.id = 'odOverlay';

    var modal = document.createElement('div');
    modal.className = 'od-modal';
    modal.id = 'odModal';
    modal.setAttribute('aria-modal', 'true');
    modal.setAttribute('role', 'dialog');

    var totalCount = pendingTasks.length;

    function formatDate(raw) {
        if (!raw) return '';
        var d = new Date(raw.replace(' ', 'T'));
        if (isNaN(d)) return raw;
        return d.toLocaleDateString('uk-UA', { day: 'numeric', month: 'long' });
    }

    function buildTaskHTML(task) {
        var id = task.id;
        return '<div class="od-task" data-id="' + id + '" id="od-task-' + id + '">' +
            '<div class="od-task-head">' +
            '<label class="od-task-check"><input type="checkbox" class="od-task-cb" data-id="' + id + '"></label>' +
            '<div class="od-task-info">' +
            '<div class="od-task-title">' + escHtml(task.title) + '</div>' +
            '<div class="od-task-date">' + escHtml(formatDate(task.due_date)) + '</div>' +
            '</div>' +
            '<div class="od-task-actions">' +
            '<button type="button" class="od-btn-done" data-task="' + id + '" data-action="done">&#10003; Виконано</button>' +
            '<button type="button" class="od-btn-reschedule" data-task="' + id + '" data-action="reschedule">&#128197; Перенести</button>' +
            '<button type="button" class="od-btn-delete" data-task="' + id + '" data-action="delete" title="Видалити задачу">&#128465;</button>' +
            '</div>' +
            '</div>' +
            // Done sub-form
            '<div class="od-subform" id="od-done-' + id + '">' +
            '<div class="od-subform-title">Фактичний результат</div>' +
            '<textarea placeholder="Що було зроблено? (необовʼязково)"></textarea>' +
            '<div class="od-subform-row">' +
            '<input type="number" min="0" step="5" placeholder="Фактичний час, хв">' +
            '<div class="od-done-date-wrap">' +
            '<label class="od-done-date-label">Дата виконання</label>' +
            '<input type="date" class="od-done-date" max="' + todayStr() + '" value="' + todayStr() + '">' +
            '</div>' +
            '</div>' +
            (task.result_id ? (
                '<label class="od-subform-check">' +
                '<input type="checkbox" class="od-result-check" checked> ' +
                'Позначити ціль «' + escHtml(task.result_title) + '» виконаною' +
                '</label>'
            ) : '') +
            '<div class="od-subform-actions">' +
            '<button type="button" class="od-btn-cancel-sub" data-cancel="' + id + '">Скасувати</button>' +
            '<button type="button" class="od-btn-submit-sub done" data-submit="done" data-id="' + id + '">Позначити виконаним</button>' +
            '</div>' +
            '</div>' +
            // Reschedule sub-form
            '<div class="od-subform" id="od-reschedule-' + id + '">' +
            '<div class="od-subform-title">Нова дата</div>' +
            '<input type="date" min="' + todayStr() + '">' +
            '<div class="od-subform-actions">' +
            '<button type="button" class="od-btn-cancel-sub" data-cancel="' + id + '">Скасувати</button>' +
            '<button type="button" class="od-btn-submit-sub reschedule" data-submit="reschedule" data-id="' + id + '">Перенести</button>' +
            '</div>' +
            '</div>' +
            '</div>';
    }

    function todayStr() {
        return new Date().toISOString().slice(0, 10);
    }

    function escHtml(str) {
        return String(str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    var listHTML = pendingTasks.map(buildTaskHTML).join('');

    modal.innerHTML =
        '<div class="od-header">' +
        '<div class="od-header-icon">⏰</div>' +
        '<div class="od-header-text">' +
        '<h3>Незавершені задачі з минулих днів (' + totalCount + ')</h3>' +
        '<p>Ці задачі були заплановані на попередні дні, але залишились без статусу. Позначте їх виконаними або перенесіть на нову дату.</p>' +
        '</div>' +
        '<button type="button" class="od-close" id="odClose" aria-label="Закрити">&times;</button>' +
        '</div>' +
        '<div class="od-list" id="odList">' + listHTML + '</div>' +
        '<div class="od-bulk-bar" id="odBulkBar">' +
        '<label class="od-select-all-label"><input type="checkbox" id="odSelectAll"><span>Обрати всі</span></label>' +
        '<span class="od-bulk-count" id="odBulkCount"></span>' +
        '<div class="od-bulk-actions">' +
        '<button type="button" class="od-bulk-btn od-bulk-done-btn" id="odBulkDone">&#10003; Виконати вибрані</button>' +
        '<div class="od-bulk-resc-wrap">' +
        '<button type="button" class="od-bulk-btn od-bulk-resc-btn" id="odBulkReschedule">&#128197; Перенести</button>' +
        '<div class="od-bulk-date-wrap" id="odBulkDateWrap">' +
        '<input type="date" id="odBulkDate" min="' + todayStr() + '">' +
        '<button type="button" class="od-bulk-date-confirm" id="odBulkDateConfirm">Підтвердити</button>' +
        '</div>' +
        '</div>' +
        '</div>' +
        '</div>' +
        '<div class="od-footer">' +
        '<span class="od-footer-hint">Зміни застосовуються одразу</span>' +
        '<button type="button" class="od-btn-dismiss" id="odDismiss">Нагадати пізніше</button>' +
        '</div>';

    document.body.appendChild(overlay);
    document.body.appendChild(modal);

    // Open with slight delay so CSS transition plays
    requestAnimationFrame(function () {
        requestAnimationFrame(function () {
            overlay.classList.add('open');
            modal.classList.add('open');
        });
    });

    function closeModal() {
        overlay.classList.remove('open');
        modal.classList.remove('open');
    }

    function dismissAll() {
        var ids = pendingTasks.map(function (t) { return t.id; });
        try {
            var existing = JSON.parse(sessionStorage.getItem(storageKey) || '[]');
            var merged = existing.concat(ids.filter(function (id) { return existing.indexOf(id) === -1; }));
            sessionStorage.setItem(storageKey, JSON.stringify(merged));
        } catch (e) { }
        closeModal();
    }

    document.getElementById('odClose').addEventListener('click', dismissAll);
    document.getElementById('odDismiss').addEventListener('click', dismissAll);
    overlay.addEventListener('click', dismissAll);

    // Toggle sub-forms
    document.getElementById('odList').addEventListener('click', function (e) {
        var btn = e.target.closest('[data-action]');
        var cancelBtn = e.target.closest('[data-cancel]');
        var submitBtn = e.target.closest('[data-submit]');

        if (cancelBtn) {
            var cid = cancelBtn.dataset.cancel;
            var doneF = document.getElementById('od-done-' + cid);
            var resF = document.getElementById('od-reschedule-' + cid);
            if (doneF) doneF.classList.remove('open');
            if (resF) resF.classList.remove('open');
            return;
        }

        if (btn) {
            var tid = btn.dataset.task;
            var action = btn.dataset.action;
            var doneForm = document.getElementById('od-done-' + tid);
            var resForm = document.getElementById('od-reschedule-' + tid);

            if (action === 'delete') {
                if (!confirm('Видалити задачу «' + (document.getElementById('od-task-' + tid) ? document.getElementById('od-task-' + tid).querySelector('.od-task-title').textContent : '') + '»? Дію не можна скасувати.')) {
                    return;
                }
                sendOverdueAction(tid, 'delete', {});
                return;
            }

            if (action === 'done') {
                if (doneForm) doneForm.classList.toggle('open');
                if (resForm) resForm.classList.remove('open');
            } else if (action === 'reschedule') {
                if (resForm) resForm.classList.toggle('open');
                if (doneForm) doneForm.classList.remove('open');
                // Set default date to today
                var dateInput = resForm ? resForm.querySelector('input[type="date"]') : null;
                if (dateInput && !dateInput.value) {
                    dateInput.value = todayStr();
                }
                // Open calendar picker automatically
                if (resForm && resForm.classList.contains('open') && dateInput) {
                    setTimeout(function () { try { dateInput.showPicker(); } catch (ex) { } }, 50);
                }
            }
            return;
        }

        if (submitBtn) {
            var sid = submitBtn.dataset.id;
            var saction = submitBtn.dataset.submit;
            var payload = {};

            if (saction === 'done') {
                var form = document.getElementById('od-done-' + sid);
                if (form) {
                    payload.actual_result = (form.querySelector('textarea') || {}).value || '';
                    payload.actual_time = (form.querySelector('input[type="number"]') || {}).value || '';
                    var doneDateInput = form.querySelector('.od-done-date');
                    payload.completion_date = doneDateInput ? (doneDateInput.value || todayStr()) : todayStr();
                    var resultCheck = form.querySelector('.od-result-check');
                    if (resultCheck) {
                        payload.mark_result_done = resultCheck.checked ? '1' : '0';
                    }
                }
            } else if (saction === 'reschedule') {
                var rform = document.getElementById('od-reschedule-' + sid);
                var dateVal = rform ? (rform.querySelector('input[type="date"]') || {}).value : '';
                if (!dateVal) {
                    alert('Оберіть нову дату');
                    return;
                }
                payload.new_date = dateVal;
            }

            sendOverdueAction(sid, saction, payload);
        }
    });

    function sendOverdueAction(taskId, action, extraPayload) {
        var taskEl = document.getElementById('od-task-' + taskId);
        var payload = Object.assign({ task_id: taskId, action: action }, extraPayload);

        if (taskEl) taskEl.classList.add('loading');

        var body = Object.keys(payload).map(function (k) {
            return encodeURIComponent(k) + '=' + encodeURIComponent(payload[k]);
        }).join('&');

        fetch('/tasks/resolve-overdue', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
            body: body,
        })
            .then(function (r) {
                if (!r.ok && r.status !== 422) {
                    return r.text().then(function (text) {
                        throw new Error('HTTP ' + r.status + ': ' + text.substring(0, 200));
                    });
                }
                return r.json();
            })
            .then(function (data) {
                if (data.ok) {
                    if (taskEl) {
                        taskEl.classList.remove('loading');
                        taskEl.classList.add('resolved');
                        taskEl.style.transition = 'opacity .4s, max-height .4s';
                        setTimeout(function () {
                            taskEl.style.maxHeight = taskEl.offsetHeight + 'px';
                            requestAnimationFrame(function () {
                                taskEl.style.maxHeight = '0';
                                taskEl.style.overflow = 'hidden';
                                taskEl.style.marginBottom = '0';
                                taskEl.style.paddingBottom = '0';
                            });
                            setTimeout(function () {
                                taskEl.remove();
                                // Check if list is now empty
                                var remaining = document.querySelectorAll('#odList .od-task:not(.resolved)');
                                if (remaining.length === 0) {
                                    closeModal();
                                }
                            }, 420);
                        }, 60);
                        // Persist as dismissed
                        try {
                            var existing = JSON.parse(sessionStorage.getItem(storageKey) || '[]');
                            existing.push(parseInt(taskId, 10));
                            sessionStorage.setItem(storageKey, JSON.stringify(existing));
                        } catch (ex) { }
                    }
                } else {
                    if (taskEl) taskEl.classList.remove('loading');
                    alert(data.error || 'Помилка збереження');
                }
            })
            .catch(function (err) {
                if (taskEl) taskEl.classList.remove('loading');
                console.error('overdue-popup save error:', err);
                alert('Помилка мережі. Спробуйте ще раз.');
            });
    }

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && modal.classList.contains('open')) {
            dismissAll();
        }
    });

    // --- Bulk selection ---
    var bulkBar = document.getElementById('odBulkBar');
    var selectAllCb = document.getElementById('odSelectAll');
    var bulkCountEl = document.getElementById('odBulkCount');
    var bulkDoneBtn = document.getElementById('odBulkDone');
    var bulkRescBtn = document.getElementById('odBulkReschedule');
    var bulkDateWrap = document.getElementById('odBulkDateWrap');
    var bulkDateInput = document.getElementById('odBulkDate');
    var bulkDateConfirm = document.getElementById('odBulkDateConfirm');

    function getCheckedIds() {
        var cbs = document.querySelectorAll('#odList .od-task-cb:checked');
        return Array.prototype.map.call(cbs, function (cb) { return cb.dataset.id; });
    }

    function getAllTaskCbs() {
        return document.querySelectorAll('#odList .od-task-cb');
    }

    function updateBulkBar() {
        var checked = getCheckedIds();
        var all = getAllTaskCbs();
        if (checked.length > 0) {
            bulkBar.classList.add('active');
            bulkCountEl.textContent = 'Обрано: ' + checked.length + ' з ' + all.length;
            selectAllCb.checked = checked.length === all.length;
            selectAllCb.indeterminate = checked.length > 0 && checked.length < all.length;
        } else {
            bulkBar.classList.remove('active');
            selectAllCb.checked = false;
            selectAllCb.indeterminate = false;
            bulkDateWrap.style.display = 'none';
        }
    }

    document.getElementById('odList').addEventListener('change', function (e) {
        if (e.target.classList.contains('od-task-cb')) {
            updateBulkBar();
        }
    });

    selectAllCb.addEventListener('change', function () {
        var all = getAllTaskCbs();
        Array.prototype.forEach.call(all, function (cb) {
            var taskEl = cb.closest('.od-task');
            if (taskEl && !taskEl.classList.contains('resolved')) {
                cb.checked = selectAllCb.checked;
            }
        });
        updateBulkBar();
    });

    bulkDoneBtn.addEventListener('click', function () {
        var ids = getCheckedIds();
        if (!ids.length) return;
        if (!confirm('Позначити ' + ids.length + ' задач(y/и) виконаними?')) return;
        ids.forEach(function (id) {
            sendOverdueAction(id, 'done', { actual_result: '', actual_time: '' });
        });
        bulkBar.classList.remove('active');
        bulkDateWrap.style.display = 'none';
    });

    bulkRescBtn.addEventListener('click', function () {
        if (!getCheckedIds().length) return;
        var isOpen = bulkDateWrap.style.display === 'flex';
        bulkDateWrap.style.display = isOpen ? 'none' : 'flex';
        if (!isOpen) {
            if (!bulkDateInput.value) bulkDateInput.value = todayStr();
            bulkDateInput.focus();
            setTimeout(function () { try { bulkDateInput.showPicker(); } catch (ex) { } }, 50);
        }
    });

    bulkDateConfirm.addEventListener('click', function () {
        var ids = getCheckedIds();
        if (!ids.length) return;
        if (!bulkDateInput.value) { alert('Оберіть нову дату'); return; }
        ids.forEach(function (id) {
            sendOverdueAction(id, 'reschedule', { new_date: bulkDateInput.value });
        });
        bulkDateWrap.style.display = 'none';
        bulkBar.classList.remove('active');
    });

}());
