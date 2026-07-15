/* weekly-plans.js — Plan-Fact page scripts
 * PHP data is injected via window.WP in the view before this file loads.
 */

// --- Тултіп для діаграм ---
function showPieLegend(html, x, y) {
    var legend = document.getElementById('pfPieLegend');
    if (!legend) return;
    legend.innerHTML = html;
    legend.style.left = x + 12 + 'px';
    legend.style.top = y + 12 + 'px';
    legend.style.opacity = 1;
}
function hidePieLegend() {
    var legend = document.getElementById('pfPieLegend');
    if (!legend) return;
    legend.style.opacity = 0;
}
function legendTypeText(typeCounts, typeLabels, typeColors) {
    var html = '<b>Типи задач</b><br>';
    Object.entries(typeCounts).forEach(function (entry) {
        var type = entry[0], count = entry[1];
        if (count > 0) {
            html += '<span style="display:inline-block;width:12px;height:12px;border-radius:3px;background:' + (typeColors[type] || '#ccc') + ';margin-right:6px;"></span> ' + (typeLabels[type] || type) + ': <b>' + count + '</b><br>';
        }
    });
    return html;
}
function legendHoursText(hours) {
    var color = hours > 8 ? '#b42318' : '#1b7f5a';
    var label = hours > 8 ? 'Більше 8 годин!' : 'Оптимально';
    return '<b>Заплановано</b>: ' + hours.toFixed(1) + ' год<br><span style="color:' + color + '">' + label + '</span>';
}
function legendDoneText(done, total) {
    var pct = total > 0 ? Math.round(done / total * 100) + '%' : '0%';
    return '<b>Виконано</b>: ' + done + ' із ' + total + '<br>(' + pct + ')';
}

// --- Відкривати лише одну форму редагування задачі одночасно ---
function openPfEdit(card) {
    document.querySelectorAll('.pf-task-card.pf-expanded').forEach(function (el) {
        if (el !== card) {
            el.classList.remove('pf-expanded');
            var det = el.querySelector('.pf-task-details');
            if (det) det.style.display = 'none';
        }
    });
    card.classList.add('pf-expanded');
    var details = card.querySelector('.pf-task-details');
    if (details) details.style.display = 'grid';
    var form = card.querySelector('form.pf-inline-form');
    if (form) {
        var firstInput = form.querySelector('input,textarea,select');
        if (firstInput) firstInput.focus();
    }
}

// --- Тост-повідомлення ---
function pfShowToast(message, type) {
    var toast = document.createElement('div');
    toast.textContent = message;
    toast.style.cssText = 'position:fixed;bottom:24px;right:24px;z-index:9999;padding:12px 18px;border-radius:12px;font-size:14px;font-weight:600;box-shadow:0 4px 16px rgba(0,0,0,.15);transition:opacity .4s;';
    toast.style.background = type === 'error' ? '#fff0f0' : '#edf9f2';
    toast.style.color = type === 'error' ? '#8b1e1e' : '#145a38';
    toast.style.border = '1px solid ' + (type === 'error' ? '#efb6b6' : '#b7e3c8');
    document.body.appendChild(toast);
    setTimeout(function () { toast.style.opacity = '0'; setTimeout(function () { toast.remove(); }, 400); }, 2500);
}

// --- Загальний AJAX-сабміт форми ---
function ajaxSubmitForm(form, onSuccess, onError) {
    var xhr = new XMLHttpRequest();
    xhr.open('POST', form.action, true);
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.onreadystatechange = function () {
        if (xhr.readyState === 4) {
            if (xhr.status === 200) {
                try {
                    var resp = JSON.parse(xhr.responseText);
                    if (resp && resp.success) { if (onSuccess) onSuccess(resp); return; }
                } catch (e) { }
            }
            if (onError) onError(xhr);
        }
    };
    xhr.send(new FormData(form));
}

// --- AJAX завершення задачі у план-факті ---
function ajaxCompleteTask(taskId, actualResult, actualTime, completionDate, onSuccess, onError) {
    var xhr = new XMLHttpRequest();
    var url = (window.WP && window.WP.updateFactTaskUrl) || '';
    xhr.open('POST', url, true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.onreadystatechange = function () {
        if (xhr.readyState === 4) {
            if (xhr.status === 200) {
                try {
                    var resp = JSON.parse(xhr.responseText);
                    if (resp && resp.success) {
                        if (onSuccess) onSuccess(resp);
                        return;
                    }
                } catch (e) { }
            }
            if (onError) onError(xhr);
        }
    };
    var params = 'task_id=' + encodeURIComponent(taskId) + '&status=done' +
        '&actual_result=' + encodeURIComponent(actualResult) +
        '&actual_time=' + encodeURIComponent(actualTime);
    if (completionDate) {
        params += '&completion_date=' + encodeURIComponent(completionDate);
    }
    xhr.send(params);
}

document.addEventListener('DOMContentLoaded', function () {
    var WP = window.WP || {};
    var googleCalendarEnabled = !!WP.googleCalendarEnabled;
    var googleClientId = WP.googleClientId || '';
    var importWeekStart = WP.weekStart || '';
    var importWeekEnd = WP.weekEnd || '';
    var importTypeLabels = WP.typeLabels || {};

    // --- Швидке додавання задачі у Факт (per-day buttons) ---
    var quickAddOverlay = document.getElementById('pfFactQuickAddOverlay');
    var quickAddModal = document.getElementById('pfFactQuickAddModal');
    var quickAddCancel = document.getElementById('pfFactQuickAddCancel');
    var quickAddCancelFooter = document.getElementById('pfFactQuickAddCancelFooter');
    var quickAddForm = document.getElementById('pfFactQuickAddForm');
    var qaDateInput = document.getElementById('pfQaDate');

    if (quickAddOverlay && quickAddModal) {
        function openQuickAdd(date) {
            if (date && qaDateInput) {
                qaDateInput.value = date;
            }
            quickAddOverlay.classList.add('open');
            quickAddModal.classList.add('open');
            quickAddModal.removeAttribute('aria-hidden');
            setTimeout(function () {
                var titleInput = quickAddForm ? quickAddForm.querySelector('[name="title"]') : null;
                if (titleInput) titleInput.focus();
            }, 40);
        }
        function closeQuickAdd() {
            quickAddOverlay.classList.remove('open');
            quickAddModal.classList.remove('open');
            quickAddModal.setAttribute('aria-hidden', 'true');
            if (quickAddForm) quickAddForm.reset();
        }
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('.pf-fact-add-btn:not(.pf-plan-add-btn)');
            if (btn) {
                openQuickAdd(btn.dataset.date || '');
            }
        });
        if (quickAddCancel) quickAddCancel.addEventListener('click', closeQuickAdd);
        if (quickAddCancelFooter) quickAddCancelFooter.addEventListener('click', closeQuickAdd);
        quickAddOverlay.addEventListener('click', closeQuickAdd);
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && quickAddModal.classList.contains('open')) closeQuickAdd();
        });

        if (quickAddForm) {
            quickAddForm.addEventListener('submit', function (e) {
                e.preventDefault();
                if (!pfValidateRequiredFields(quickAddForm)) return;
                var submitBtn = quickAddForm.querySelector('[type="submit"]');
                if (submitBtn) submitBtn.disabled = true;
                ajaxSubmitForm(quickAddForm, function () {
                    if (submitBtn) submitBtn.disabled = false;
                    closeQuickAdd();
                    pfShowToast('Задачу додано у факт.', 'success');
                }, function () {
                    if (submitBtn) submitBtn.disabled = false;
                    pfShowToast('Не вдалося додати задачу.', 'error');
                });
            });
        }
    }

    // --- Швидке додавання задачі у План (per-day buttons) ---
    var planQuickAddOverlay = document.getElementById('pfPlanQuickAddOverlay');
    var planQuickAddModal = document.getElementById('pfPlanQuickAddModal');
    var planQuickAddCancel = document.getElementById('pfPlanQuickAddCancel');
    var planQuickAddCancelFooter = document.getElementById('pfPlanQuickAddCancelFooter');
    var planQuickAddForm = document.getElementById('pfPlanQuickAddForm');
    var planQaDateInput = document.getElementById('pfPlanQaDate');

    if (planQuickAddOverlay && planQuickAddModal) {
        function openPlanQuickAdd(date) {
            if (date && planQaDateInput) {
                planQaDateInput.value = date;
            }
            planQuickAddOverlay.classList.add('open');
            planQuickAddModal.classList.add('open');
            planQuickAddModal.removeAttribute('aria-hidden');
            setTimeout(function () {
                var titleInput = planQuickAddForm ? planQuickAddForm.querySelector('[name="title"]') : null;
                if (titleInput) titleInput.focus();
            }, 40);
        }
        function closePlanQuickAdd() {
            planQuickAddOverlay.classList.remove('open');
            planQuickAddModal.classList.remove('open');
            planQuickAddModal.setAttribute('aria-hidden', 'true');
            if (planQuickAddForm) planQuickAddForm.reset();
        }
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('.pf-plan-add-btn');
            if (btn) {
                openPlanQuickAdd(btn.dataset.date || '');
            }
        });
        if (planQuickAddCancel) planQuickAddCancel.addEventListener('click', closePlanQuickAdd);
        if (planQuickAddCancelFooter) planQuickAddCancelFooter.addEventListener('click', closePlanQuickAdd);
        planQuickAddOverlay.addEventListener('click', closePlanQuickAdd);
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && planQuickAddModal.classList.contains('open')) closePlanQuickAdd();
        });

        if (planQuickAddForm) {
            planQuickAddForm.addEventListener('submit', function (e) {
                e.preventDefault();
                if (!pfValidateRequiredFields(planQuickAddForm)) return;
                var submitBtn = planQuickAddForm.querySelector('[type="submit"]');
                if (submitBtn) submitBtn.disabled = true;
                ajaxSubmitForm(planQuickAddForm, function () {
                    if (submitBtn) submitBtn.disabled = false;
                    closePlanQuickAdd();
                    pfShowToast('Задачу додано в план.', 'success');
                }, function () {
                    if (submitBtn) submitBtn.disabled = false;
                    pfShowToast('Не вдалося додати задачу.', 'error');
                });
            });
        }
    }

    // --- Chip click handlers: templates and goals fill form fields ---
    function applyTemplateChip(chip, form) {
        if (!form) return;
        var name = chip.dataset.name || '';
        var type = chip.dataset.type || '';
        var description = chip.dataset.description || '';
        var expectedResult = chip.dataset.expectedResult || '';
        var expectedTime = chip.dataset.expectedTime || '';
        var startTime = chip.dataset.startTime || '';

        var titleInput = form.querySelector('[name="title"]');
        if (titleInput && name) titleInput.value = name;

        var typeSelect = form.querySelector('[name="type"]');
        if (typeSelect && type) typeSelect.value = type;

        var descTextarea = form.querySelector('[name="description"]');
        if (descTextarea && description) descTextarea.value = description;

        var expectedResultTextarea = form.querySelector('[name="expected_result"]');
        if (expectedResultTextarea && expectedResult) expectedResultTextarea.value = expectedResult;

        var expectedTimeInput = form.querySelector('[name="expected_time"]');
        if (expectedTimeInput && expectedTime) expectedTimeInput.value = expectedTime;

        var startTimeInput = form.querySelector('[name="start_time"]');
        if (startTimeInput && startTime) startTimeInput.value = startTime;

        // Mark active chip (toggle)
        var siblings = chip.closest('.pf-qa-chips').querySelectorAll('.pf-qa-chip--template');
        siblings.forEach(function (s) { s.classList.remove('pf-qa-chip--active'); });
        chip.classList.add('pf-qa-chip--active');
    }

    function applyResultChip(chip, form) {
        if (!form) return;
        var resultId = chip.dataset.resultId || '';
        var resultSelect = form.querySelector('[name="result_id"]');
        if (resultSelect && resultId) {
            resultSelect.value = resultId;
        }
        // Mark active chip
        var siblings = chip.closest('.pf-qa-chips').querySelectorAll('.pf-qa-chip--result');
        siblings.forEach(function (s) { s.classList.remove('pf-qa-chip--active'); });
        chip.classList.add('pf-qa-chip--active');
    }

    document.addEventListener('click', function (e) {
        var chip = e.target.closest('.pf-qa-chip');
        if (!chip) return;
        var modal = chip.dataset.modal; // 'plan' or 'fact'
        var form = modal === 'plan' ? planQuickAddForm : quickAddForm;

        if (chip.classList.contains('pf-qa-chip--template')) {
            applyTemplateChip(chip, form);
        } else if (chip.classList.contains('pf-qa-chip--result')) {
            applyResultChip(chip, form);
        }
    });

    var hideWeekendsToggle = document.getElementById('pfHideWeekendsToggle');

    // Show/hide actual_result field in fact edit forms
    var factEditForms = document.querySelectorAll('form[action^="/weekly-plans/update-fact-task/"]');
    factEditForms.forEach(function (form) {
        var statusField = form.querySelector('[name="status"]');
        var actualResultField = form.querySelector('.js-pf-fact-actual-result');
        if (statusField && actualResultField) {
            function updateFactActualResultField() {
                actualResultField.style.display = statusField.value === 'done' ? '' : 'none';
            }
            statusField.addEventListener('change', updateFactActualResultField);
            updateFactActualResultField();
        }
    });

    // --- Автозаповнення фактичного часу у модалці завершення ---
    var completeButtons = document.querySelectorAll('.js-pf-complete');
    var completeActualTime = document.getElementById('pfCompleteActualTime');
    completeButtons.forEach(function (btn) {
        btn.addEventListener('click', function () {
            var resultId = btn.getAttribute('data-result-id') || '';
            var resultTitle = btn.getAttribute('data-result-title') || '';
            var resultIdField = document.getElementById('pfCompleteResultId');
            var resultCheckbox = document.getElementById('pfCompleteResultGoal');
            var resultGoalWrap = document.getElementById('pfCompleteResultGoalWrap');
            if (resultIdField) resultIdField.value = resultId;
            if (resultGoalWrap) resultGoalWrap.style.display = resultId ? '' : 'none';
            if (resultCheckbox) resultCheckbox.checked = false;
            var resultGoalLabel = document.getElementById('pfCompleteResultGoalLabel');
            if (resultGoalLabel && resultTitle) resultGoalLabel.textContent = '\u0437\u043d\u0430\u0447\u0438\u0442\u0438 \u0446\u0456\u043b\u044c \u00ab' + resultTitle + '\u00bb \u0432\u0438\u043a\u043e\u043d\u0430\u043d\u043e\u044e';
            var card = btn.closest('.pf-task-card');
            var expectedTime = 0;
            if (card) {
                var planTimeText = (card.querySelector('.pf-note-line') || {}).textContent || '';
                var match = planTimeText.match(/Плановий час: (\d+)г\s?(\d+)?хв|Плановий час: (\d+)хв/);
                if (match) {
                    if (match[1]) {
                        expectedTime = parseInt(match[1], 10) * 60;
                        if (match[2]) expectedTime += parseInt(match[2], 10);
                    } else if (match[3]) {
                        expectedTime = parseInt(match[3], 10);
                    }
                } else {
                    var planMinutes = card.getAttribute('data-expected-time');
                    if (planMinutes) expectedTime = parseInt(planMinutes, 10);
                }
            }
            if (completeActualTime) {
                completeActualTime.value = expectedTime > 0 ? expectedTime : '';
            }
        });
    });

    // Забороняємо відправку, якщо поле порожнє або 0
    var completeFormCheck = document.getElementById('pfCompleteForm');
    if (completeFormCheck) {
        completeFormCheck.addEventListener('submit', function (e) {
            if (completeActualTime && (!completeActualTime.value || parseInt(completeActualTime.value, 10) <= 0)) {
                e.preventDefault();
                completeActualTime.focus();
            }
        });
    }

    // ---- Спільна утиліта валідації required-полів у план-фактових формах ----
    function pfShowFieldError(field, errorEl, message) {
        if (errorEl) {
            errorEl.textContent = message;
            errorEl.style.display = 'block';
        }
        field.classList.add('pf-field-invalid');
    }
    function pfClearFieldError(field, errorEl) {
        if (errorEl) errorEl.style.display = 'none';
        field.classList.remove('pf-field-invalid');
    }
    function pfValidateRequiredFields(form) {
        var hasError = false;
        var firstErrorField = null;

        // expected_result
        var resultField = form.querySelector('[name="expected_result"]');
        if (resultField) {
            var resultError = resultField.nextElementSibling && resultField.nextElementSibling.classList.contains('pf-field-error')
                ? resultField.nextElementSibling : null;
            if (!(resultField.value || '').trim()) {
                pfShowFieldError(resultField, resultError, 'Заповніть очікуваний результат — це обов\'язкове поле.');
                if (!firstErrorField) firstErrorField = resultField;
                hasError = true;
                resultField.addEventListener('input', function fix() {
                    if ((resultField.value || '').trim()) { pfClearFieldError(resultField, resultError); resultField.removeEventListener('input', fix); }
                });
            } else {
                pfClearFieldError(resultField, resultError);
            }
        }

        // expected_time
        var timeField = form.querySelector('[name="expected_time"]');
        if (timeField) {
            var timeError = timeField.nextElementSibling && timeField.nextElementSibling.classList.contains('pf-field-error')
                ? timeField.nextElementSibling : null;
            if (!Number(timeField.value) || Number(timeField.value) < 1) {
                pfShowFieldError(timeField, timeError, 'Вкажіть очікуваний час виконання (хоча б 1 хв) — це обов\'язкове поле.');
                if (!firstErrorField) firstErrorField = timeField;
                hasError = true;
                timeField.addEventListener('input', function fix() {
                    if (Number(timeField.value) >= 1) { pfClearFieldError(timeField, timeError); timeField.removeEventListener('input', fix); }
                });
            } else {
                pfClearFieldError(timeField, timeError);
            }
        }

        if (firstErrorField) {
            firstErrorField.scrollIntoView({ behavior: 'smooth', block: 'center' });
            firstErrorField.focus();
        }
        return !hasError;
    }

    // Валідація: бокова форма "Додати задачу в план"
    var planAddForm = document.querySelector('form.js-pf-plan-add-form');
    if (planAddForm) {
        planAddForm.addEventListener('submit', function (e) {
            var titleField = planAddForm.querySelector('[name="title"]');
            if (titleField && !(titleField.value || '').trim()) {
                e.preventDefault();
                titleField.focus();
                return;
            }
            if (!pfValidateRequiredFields(planAddForm)) {
                e.preventDefault();
            }
        });
    }

    // Валідація title у формах inline-редагування + expected_result/expected_time
    var titleForms = document.querySelectorAll('form.pf-inline-form[action^="/weekly-plans/update-item/"]');
    titleForms.forEach(function (form) {
        form.addEventListener('submit', function (event) {
            var titleField = form.querySelector('[name="title"]');
            if (titleField) {
                var normalizedTitle = (titleField.value || '').trim();
                if (!normalizedTitle) {
                    event.preventDefault();
                    titleField.value = '';
                    titleField.focus();
                    return;
                }
                titleField.value = normalizedTitle;
            }
            if (!pfValidateRequiredFields(form)) {
                event.preventDefault();
                return;
            }
            event.preventDefault();
            var submitBtn = form.querySelector('[type="submit"]');
            if (submitBtn) submitBtn.disabled = true;
            ajaxSubmitForm(form, function () {
                if (submitBtn) submitBtn.disabled = false;
                pfShowToast('\u0415\u043b\u0435\u043c\u0435\u043d\u0442 \u043f\u043b\u0430\u043d\u0443 \u043e\u043d\u043e\u0432\u043b\u0435\u043d\u043e.', 'success');
            }, function () {
                if (submitBtn) submitBtn.disabled = false;
                pfShowToast('\u041d\u0435 \u0432\u0434\u0430\u043b\u043e\u0441\u044f \u0437\u0431\u0435\u0440\u0435\u0433\u0442\u0438. \u0421\u043f\u0440\u043e\u0431\u0443\u0439\u0442\u0435 \u0449\u0435 \u0440\u0430\u0437.', 'error');
            });
        });
    });

    // AJAX для видалення елементів плану
    var deleteForms = document.querySelectorAll('form.js-pf-plan-item-delete-form');
    deleteForms.forEach(function (form) {
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            if (!confirm('\u0412\u0438\u0434\u0430\u043b\u0438\u0442\u0438 \u0435\u043b\u0435\u043c\u0435\u043d\u0442 \u043f\u043b\u0430\u043d\u0443 \u0456 \u043f\u043e\u0432\u2019\u044f\u0437\u0430\u043d\u0443 \u0437\u0430\u0434\u0430\u0447\u0443?')) return;
            var card = form.closest('.pf-task-card');
            ajaxSubmitForm(form, function () {
                if (card) card.remove();
                pfShowToast('\u0415\u043b\u0435\u043c\u0435\u043d\u0442 \u043f\u043b\u0430\u043d\u0443 \u0432\u0438\u0434\u0430\u043b\u0435\u043d\u043e.', 'success');
            }, function () {
                pfShowToast('\u041d\u0435 \u0432\u0434\u0430\u043b\u043e\u0441\u044f \u0432\u0438\u0434\u0430\u043b\u0438\u0442\u0438.', 'error');
            });
        });
    });

    var sourceSelect = document.getElementById('pf-copy-source');
    var itemsSelect = document.getElementById('pf-copy-items');
    var completeButtons2 = document.querySelectorAll('.js-pf-complete');
    var completeOverlay = document.getElementById('pfCompleteOverlay');
    var completeModal = document.getElementById('pfCompleteModal');
    var completeForm = document.getElementById('pfCompleteForm');
    var completeTaskId = document.getElementById('pfCompleteTaskId');
    var completeResult = document.getElementById('pfCompleteResult');
    var completeText = document.getElementById('pfCompleteText');
    var completeDueDay = document.getElementById('pfCompleteDueDay');
    var completeCancel = document.getElementById('pfCompleteCancel');
    var googleImportButton = document.getElementById('pfGoogleImportBtn');
    var googleImportOverlay = document.getElementById('pfGoogleImportOverlay');
    var googleImportModal = document.getElementById('pfGoogleImportModal');
    var googleImportClose = document.getElementById('pfGoogleImportClose');
    var googleAuthorizeButton = document.getElementById('pfGoogleAuthorizeBtn');
    var googleCalendarSelect = document.getElementById('pfGoogleCalendarSelect');
    var googleLoadEventsButton = document.getElementById('pfGoogleLoadEventsBtn');
    var googleImportStatus = document.getElementById('pfGoogleImportStatus');
    var googleImportEmpty = document.getElementById('pfGoogleImportEmpty');
    var googleImportList = document.getElementById('pfGoogleImportList');
    var googleImportForm = document.getElementById('pfGoogleImportForm');
    var googleImportPayload = document.getElementById('pfGoogleImportPayload');
    var googleImportSubmit = document.getElementById('pfGoogleImportSubmit');
    var googleImportCancel = document.getElementById('pfGoogleImportCancel');
    var completionDraft = null;
    var googleTokenClient = null;
    var googleAccessToken = '';
    var googleCalendars = [];
    var googleEvents = [];

    function escapeHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function setGoogleImportStatus(message, variant) {
        if (!googleImportStatus) return;
        googleImportStatus.textContent = message || '';
        googleImportStatus.classList.remove('is-error', 'is-success');
        if (variant === 'error') googleImportStatus.classList.add('is-error');
        else if (variant === 'success') googleImportStatus.classList.add('is-success');
    }

    function buildGoogleApiErrorMessage(error, fallbackMessage) {
        var status = error && error.status ? Number(error.status) : 0;
        var googleMessage = error && error.googleMessage ? String(error.googleMessage) : '';
        var googleReason = error && error.googleReason ? String(error.googleReason) : '';
        var normalized = (googleMessage + ' ' + googleReason).toLowerCase();

        if (normalized.indexOf('access not configured') !== -1 || normalized.indexOf('has not been used in project') !== -1 || normalized.indexOf('calendar api has not been used') !== -1) {
            return 'У Google Cloud для цього проєкту не увімкнено Google Calendar API.';
        }
        if (normalized.indexOf('insufficient authentication scopes') !== -1) {
            return 'Google повернув токен без потрібного доступу до Calendar. Треба повторно погодити доступ.';
        }
        if (normalized.indexOf('request had insufficient authentication scopes') !== -1) {
            return 'Недостатньо прав доступу до Google Calendar для цього токена.';
        }
        if (normalized.indexOf('api disabled') !== -1) {
            return 'Google Calendar API вимкнений для поточного Google Cloud проєкту.';
        }
        if (status === 401) return 'Google відхилив токен доступу. Спробуйте авторизуватись повторно.';
        if (status === 403) {
            return googleMessage !== '' ? 'Google заборонив доступ до Calendar API: ' + googleMessage : 'Google заборонив доступ до Calendar API для цього застосунку або проєкту.';
        }
        return googleMessage !== '' ? googleMessage : fallbackMessage;
    }

    function resetGoogleImportList(message) {
        googleEvents = [];
        if (googleImportList) googleImportList.innerHTML = '';
        if (googleImportEmpty) {
            googleImportEmpty.style.display = '';
            googleImportEmpty.textContent = message || 'Події не завантажені.';
        }
        if (googleImportSubmit) googleImportSubmit.disabled = true;
        if (googleImportPayload) googleImportPayload.value = '';
    }

    function openGoogleImportModal() {
        if (!googleImportModal || !googleImportOverlay) return;
        googleImportModal.classList.add('open');
        googleImportOverlay.classList.add('open');
    }

    function closeGoogleImportModal() {
        if (!googleImportModal || !googleImportOverlay) return;
        googleImportModal.classList.remove('open');
        googleImportOverlay.classList.remove('open');
    }

    function formatEventDate(dateValue) {
        if (!dateValue) return '';
        var date = new Date(dateValue);
        if (Number.isNaN(date.getTime())) return String(dateValue);
        return date.toLocaleDateString('uk-UA', { day: '2-digit', month: '2-digit', year: 'numeric' });
    }

    function formatEventTime(dateValue) {
        if (!dateValue) return '';
        var date = new Date(dateValue);
        if (Number.isNaN(date.getTime())) return '';
        return date.toLocaleTimeString('uk-UA', { hour: '2-digit', minute: '2-digit' });
    }

    function renderGoogleImportEvents(events, skippedAllDayCount) {
        googleEvents = events.slice();
        if (!googleImportList || !googleImportEmpty) return;
        if (!events.length) {
            resetGoogleImportList('За цей тиждень не знайдено подій з точним часом.');
            return;
        }
        googleImportEmpty.style.display = 'none';
        googleImportList.innerHTML = events.map(function (event, index) {
            var title = escapeHtml(event.title || 'Подія Google Calendar');
            var description = escapeHtml(event.description || '');
            var dateLabel = escapeHtml(formatEventDate(event.startDateTime));
            var timeLabel = escapeHtml(formatEventTime(event.startDateTime));
            var plannedDate = escapeHtml(event.plannedDate || '');
            var startTime = escapeHtml(event.startTime || '');
            var eventId = escapeHtml(event.eventId || '');
            var defaultType = 'important-not-urgent';
            var typeOptions = Object.keys(importTypeLabels).map(function (key) {
                var selected = key === defaultType ? ' selected' : '';
                return '<option value="' + escapeHtml(key) + '"' + selected + '>' + escapeHtml(importTypeLabels[key]) + '</option>';
            }).join('');
            return '' +
                '<div class="pf-import-item" data-event-index="' + index + '" data-event-id="' + eventId + '" data-planned-date="' + plannedDate + '" data-start-time="' + startTime + '">' +
                '<div class="pf-import-item-head">' +
                '<div>' +
                '<div class="pf-import-item-title">' + title + '</div>' +
                '<div class="pf-import-item-meta">' + dateLabel + (timeLabel ? ' о ' + timeLabel : '') + '</div>' +
                '</div>' +
                '<label class="pf-import-check"><input type="checkbox" class="js-pf-import-check" checked> Імпортувати</label>' +
                '</div>' +
                '<div class="pf-import-item-grid">' +
                '<div>' +
                '<label class="pf-import-field-label">Тип задачі</label>' +
                '<select class="js-pf-import-type">' + typeOptions + '</select>' +
                '</div>' +
                '<div>' +
                '<label class="pf-import-field-label">Очікуваний результат<span class="pf-import-field-note">заповнюється вручну</span></label>' +
                '<textarea class="js-pf-import-expected" rows="2" placeholder="Сформулюйте вручну, який конкретний результат має бути отриманий" required></textarea>' +
                '</div>' +
                '</div>' +
                '<div>' +
                '<label class="pf-import-field-label">Опис<span class="pf-import-field-note">автопідтягується з події</span></label>' +
                '<textarea class="js-pf-import-description" rows="2" placeholder="Опис уже підтягнуто з Google Calendar, за потреби відредагуйте перед імпортом">' + description + '</textarea>' +
                '</div>' +
                '</div>';
        }).join('');
        if (googleImportSubmit) googleImportSubmit.disabled = false;
        var statusMessage = 'Знайдено ' + events.length + ' подій для імпорту.';
        if (skippedAllDayCount > 0) statusMessage += ' Цілоденних подій пропущено: ' + skippedAllDayCount + '.';
        setGoogleImportStatus(statusMessage, 'success');
    }

    function ensureGoogleTokenClient() {
        if (!googleCalendarEnabled) {
            setGoogleImportStatus('Для імпорту потрібно налаштувати GOOGLE_CLIENT_ID.', 'error');
            return false;
        }
        if (!window.google || !window.google.accounts || !window.google.accounts.oauth2) {
            setGoogleImportStatus('Google API ще не завантажився. Спробуйте ще раз за мить.', 'error');
            return false;
        }
        if (!googleTokenClient) {
            googleTokenClient = window.google.accounts.oauth2.initTokenClient({
                client_id: googleClientId,
                scope: 'https://www.googleapis.com/auth/calendar.readonly',
                callback: function (response) {
                    if (!response || !response.access_token) {
                        setGoogleImportStatus('Google не повернув токен доступу.', 'error');
                        return;
                    }
                    googleAccessToken = response.access_token;
                    loadGoogleCalendars();
                },
                error_callback: function () {
                    setGoogleImportStatus('Не вдалося авторизуватись у Google Calendar.', 'error');
                }
            });
        }
        return true;
    }

    function loadGoogleCalendars() {
        if (!googleAccessToken || !googleCalendarSelect) return;
        setGoogleImportStatus('Завантажую список календарів...', '');
        googleCalendarSelect.disabled = true;
        googleLoadEventsButton.disabled = true;
        fetch('https://www.googleapis.com/calendar/v3/users/me/calendarList', {
            headers: { Authorization: 'Bearer ' + googleAccessToken }
        })
            .then(function (response) {
                if (!response.ok) {
                    return response.json().catch(function () { return null; }).then(function (data) {
                        throw {
                            status: response.status,
                            googleMessage: data && data.error && data.error.message ? data.error.message : '',
                            googleReason: data && data.error && data.error.errors && data.error.errors[0] && data.error.errors[0].reason ? data.error.errors[0].reason : ''
                        };
                    });
                }
                return response.json();
            })
            .then(function (data) {
                googleCalendars = Array.isArray(data.items) ? data.items : [];
                googleCalendarSelect.innerHTML = '<option value="">Оберіть календар</option>' + googleCalendars.map(function (calendar) {
                    var value = escapeHtml(calendar.id || '');
                    var label = escapeHtml(calendar.summary || calendar.id || 'Без назви');
                    return '<option value="' + value + '">' + label + '</option>';
                }).join('');
                googleCalendarSelect.disabled = googleCalendars.length === 0;
                googleLoadEventsButton.disabled = googleCalendars.length === 0;
                setGoogleImportStatus(
                    googleCalendars.length > 0 ? 'Оберіть календар для завантаження подій.' : 'У Google Calendar не знайдено доступних календарів.',
                    googleCalendars.length > 0 ? 'success' : 'error'
                );
            })
            .catch(function (error) {
                setGoogleImportStatus(buildGoogleApiErrorMessage(error, 'Не вдалося завантажити список календарів.'), 'error');
            });
    }

    function loadGoogleEvents() {
        if (!googleAccessToken || !googleCalendarSelect || !googleCalendarSelect.value) {
            setGoogleImportStatus('Спочатку оберіть календар.', 'error');
            return;
        }
        resetGoogleImportList('Завантажую події календаря...');
        googleLoadEventsButton.disabled = true;
        var timeMin = new Date(importWeekStart + 'T00:00:00').toISOString();
        var timeMax = new Date(importWeekEnd + 'T23:59:59').toISOString();
        var params = new URLSearchParams({
            singleEvents: 'true', orderBy: 'startTime',
            timeMin: timeMin, timeMax: timeMax, maxResults: '250'
        });
        fetch('https://www.googleapis.com/calendar/v3/calendars/' + encodeURIComponent(googleCalendarSelect.value) + '/events?' + params.toString(), {
            headers: { Authorization: 'Bearer ' + googleAccessToken }
        })
            .then(function (response) {
                if (!response.ok) {
                    return response.json().catch(function () { return null; }).then(function (data) {
                        throw {
                            status: response.status,
                            googleMessage: data && data.error && data.error.message ? data.error.message : '',
                            googleReason: data && data.error && data.error.errors && data.error.errors[0] && data.error.errors[0].reason ? data.error.errors[0].reason : ''
                        };
                    });
                }
                return response.json();
            })
            .then(function (data) {
                var items = Array.isArray(data.items) ? data.items : [];
                var skippedAllDayCount = 0;
                var normalized = [];
                items.forEach(function (item) {
                    if (!item || !item.id || !item.start || !item.start.dateTime) {
                        skippedAllDayCount += 1;
                        return;
                    }
                    var startDate = new Date(item.start.dateTime);
                    if (Number.isNaN(startDate.getTime())) return;
                    normalized.push({
                        eventId: String(item.id || ''),
                        title: String(item.summary || 'Подія Google Calendar'),
                        description: String(item.description || ''),
                        startDateTime: item.start.dateTime,
                        plannedDate: startDate.toISOString().slice(0, 10),
                        startTime: startDate.toISOString().slice(11, 16)
                    });
                });
                renderGoogleImportEvents(normalized, skippedAllDayCount);
            })
            .catch(function (error) {
                resetGoogleImportList('Не вдалося завантажити події з календаря.');
                setGoogleImportStatus(buildGoogleApiErrorMessage(error, 'Не вдалося завантажити події з вибраного календаря.'), 'error');
            })
            .finally(function () {
                googleLoadEventsButton.disabled = !googleCalendarSelect.value;
            });
    }

    if (sourceSelect && itemsSelect) {
        sourceSelect.addEventListener('change', function () {
            var selectedDay = this.value;
            var options = itemsSelect.querySelectorAll('option');
            var hideWeekends = !!(hideWeekendsToggle && hideWeekendsToggle.checked);
            options.forEach(function (opt) {
                var isWeekend = opt.getAttribute('data-is-weekend') === '1';
                if (opt.getAttribute('data-day') === selectedDay && !(hideWeekends && isWeekend)) {
                    opt.style.display = ''; opt.hidden = false; opt.disabled = false; opt.selected = false;
                } else {
                    opt.style.display = 'none'; opt.hidden = true; opt.disabled = true; opt.selected = false;
                }
            });
        });
    }

    function updateWeekendVisibility() {
        var hideWeekends = !!(hideWeekendsToggle && hideWeekendsToggle.checked);
        document.querySelectorAll('[data-is-weekend="1"]').forEach(function (element) {
            if (element.tagName === 'OPTION') {
                element.hidden = hideWeekends;
                element.disabled = hideWeekends;
                if (hideWeekends && element.selected) element.selected = false;
                return;
            }
            element.style.display = hideWeekends ? 'none' : '';
        });
        if (sourceSelect) {
            var sourceOption = sourceSelect.options[sourceSelect.selectedIndex];
            if (hideWeekends && sourceOption && sourceOption.getAttribute('data-is-weekend') === '1') {
                sourceSelect.value = '';
            }
            sourceSelect.dispatchEvent(new Event('change'));
        }
        var targetSelect = document.querySelector('select[name="target_date"]');
        if (targetSelect) {
            var targetOption = targetSelect.options[targetSelect.selectedIndex];
            if (hideWeekends && targetOption && targetOption.getAttribute('data-is-weekend') === '1') {
                targetSelect.value = '';
            }
        }
    }

    if (hideWeekendsToggle) {
        var storageKey = 'fineko-plan-fact-hide-weekends';
        hideWeekendsToggle.checked = window.localStorage.getItem(storageKey) === '1';
        hideWeekendsToggle.addEventListener('change', function () {
            window.localStorage.setItem(storageKey, hideWeekendsToggle.checked ? '1' : '0');
            updateWeekendVisibility();
        });
        updateWeekendVisibility();
    }

    if (googleImportButton) {
        googleImportButton.addEventListener('click', function () {
            openGoogleImportModal();
            if (!googleCalendarEnabled) {
                setGoogleImportStatus('Для імпорту потрібно налаштувати GOOGLE_CLIENT_ID.', 'error');
                return;
            }
            setGoogleImportStatus('Авторизуйтесь у Google, щоб отримати список календарів.', '');
        });
    }
    if (googleImportClose) googleImportClose.addEventListener('click', closeGoogleImportModal);
    if (googleImportCancel) googleImportCancel.addEventListener('click', closeGoogleImportModal);
    if (googleImportOverlay) googleImportOverlay.addEventListener('click', closeGoogleImportModal);
    if (googleAuthorizeButton) {
        googleAuthorizeButton.addEventListener('click', function () {
            if (!ensureGoogleTokenClient()) return;
            setGoogleImportStatus('Відкриваю авторизацію Google...', '');
            googleTokenClient.requestAccessToken({ prompt: googleAccessToken ? '' : 'consent' });
        });
    }
    if (googleCalendarSelect) {
        googleCalendarSelect.addEventListener('change', function () {
            googleLoadEventsButton.disabled = !googleCalendarSelect.value;
            resetGoogleImportList('Після натискання "Завантажити події тижня" тут зʼявиться попередній перегляд задач.');
        });
    }
    if (googleLoadEventsButton) googleLoadEventsButton.addEventListener('click', loadGoogleEvents);

    if (googleImportForm) {
        googleImportForm.addEventListener('submit', function (event) {
            if (!googleImportList || !googleImportPayload || !googleCalendarSelect) { event.preventDefault(); return; }
            var selectedCalendarId = googleCalendarSelect.value;
            var selectedCalendar = googleCalendars.find(function (calendar) {
                return String(calendar.id || '') === selectedCalendarId;
            });
            var items = [];
            var hasValidationError = false;
            googleImportList.querySelectorAll('.pf-import-item').forEach(function (row) {
                var enabledInput = row.querySelector('.js-pf-import-check');
                if (!enabledInput || !enabledInput.checked) return;
                var expectedField = row.querySelector('.js-pf-import-expected');
                var descriptionField = row.querySelector('.js-pf-import-description');
                var typeField = row.querySelector('.js-pf-import-type');
                var expectedValue = expectedField ? String(expectedField.value || '').trim() : '';
                if (!expectedValue) {
                    hasValidationError = true;
                    if (expectedField) expectedField.focus();
                    return;
                }
                var index = Number(row.getAttribute('data-event-index'));
                var sourceEvent = googleEvents[index] || null;
                if (!sourceEvent) return;
                items.push({
                    event_id: String(row.getAttribute('data-event-id') || ''),
                    title: String(sourceEvent.title || 'Подія Google Calendar'),
                    description: descriptionField ? String(descriptionField.value || '').trim() : '',
                    planned_date: String(row.getAttribute('data-planned-date') || ''),
                    start_time: String(row.getAttribute('data-start-time') || ''),
                    expected_result: expectedValue,
                    type: typeField ? String(typeField.value || 'important-not-urgent') : 'important-not-urgent'
                });
            });
            if (hasValidationError) {
                event.preventDefault();
                setGoogleImportStatus('Для кожної вибраної події заповніть очікуваний результат.', 'error');
                return;
            }
            if (!items.length) {
                event.preventDefault();
                setGoogleImportStatus('Оберіть хоча б одну подію для імпорту.', 'error');
                return;
            }
            googleImportPayload.value = JSON.stringify({
                calendar_id: selectedCalendarId,
                calendar_name: selectedCalendar ? String(selectedCalendar.summary || selectedCalendarId) : selectedCalendarId,
                events: items
            });
        });
    }

    function closeCompleteModal() {
        if (!completeModal || !completeOverlay) return;
        completeModal.classList.remove('open');
        completeOverlay.classList.remove('open');
        completionDraft = null;
    }

    function openCompleteModal(config) {
        completionDraft = config || null;
        if (!completeModal || !completeOverlay || !completeTaskId || !completeResult) return;
        completeTaskId.value = String((config && config.taskId) || '');
        completeResult.value = (config && config.result) ? config.result : '';
        if (completeDueDay) {
            var today = new Date().toISOString().slice(0, 10);
            completeDueDay.value = (config && config.dueDay) ? config.dueDay : today;
        }
        if (completeText) {
            var title = config && config.title ? '«' + config.title + '»' : 'цю задачу';
            completeText.textContent = 'Щоб позначити ' + title + ' виконаною, внесіть фактичний результат.';
        }
        completeModal.classList.add('open');
        completeOverlay.classList.add('open');
        setTimeout(function () { completeResult.focus(); }, 30);
    }

    completeButtons2.forEach(function (button) {
        button.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();
            openCompleteModal({
                taskId: button.getAttribute('data-task-id'),
                title: button.getAttribute('data-task-title') || '',
                result: button.getAttribute('data-task-result') || '',
                dueDay: button.getAttribute('data-task-due-day') || '',
                ajax: true
            });
        });
    });

    factEditForms.forEach(function (form) {
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            var statusField = form.querySelector('[name="status"]');
            var resultField = form.querySelector('[name="actual_result"]');
            var taskIdField = form.querySelector('[name="task_id"]');
            var titleField = form.querySelector('[name="title"]');
            var isDone = statusField && statusField.value === 'done';
            var resultEmpty = !resultField || (resultField.value || '').trim() === '';

            if (isDone && resultEmpty) {
                // Потрібен фактичний результат — відкриваємо модалку
                openCompleteModal({
                    taskId: taskIdField ? taskIdField.value : '',
                    title: titleField ? titleField.value : '',
                    result: '',
                    onSubmit: function (value) {
                        if (resultField) resultField.value = value;
                        doAjaxFactSave(form);
                    }
                });
                return;
            }

            doAjaxFactSave(form);
        });
    });

    function doAjaxFactSave(form) {
        var submitBtn = form.querySelector('[type="submit"]');
        if (submitBtn) submitBtn.disabled = true;
        ajaxSubmitForm(form, function () {
            if (submitBtn) submitBtn.disabled = false;
            pfShowToast('\u0417\u0431\u0435\u0440\u0435\u0436\u0435\u043d\u043e.', 'success');
            var statusField = form.querySelector('[name="status"]');
            if (statusField && statusField.value === 'done') {
                var card = form.closest('.pf-task-card');
                if (card) card.classList.add('is-completed');
                var completeBtn = card ? card.querySelector('.pf-complete-btn') : null;
                if (completeBtn) completeBtn.remove();
            }
        }, function () {
            if (submitBtn) submitBtn.disabled = false;
            pfShowToast('\u041d\u0435 \u0432\u0434\u0430\u043b\u043e\u0441\u044f \u0437\u0431\u0435\u0440\u0435\u0433\u0442\u0438. \u0421\u043f\u0440\u043e\u0431\u0443\u0439\u0442\u0435 \u0449\u0435 \u0440\u0430\u0437.', 'error');
        });
    }

    if (completeForm) {
        completeForm.addEventListener('submit', function (event) {
            var value = (completeResult.value || '').trim();
            var actualTime = (completeActualTime && completeActualTime.value) ? completeActualTime.value : '';
            if (!value) { event.preventDefault(); completeResult.focus(); return; }
            if (completionDraft && completionDraft.ajax) {
                event.preventDefault();
                var taskId = completeTaskId.value;
                var completionDate = completeDueDay ? completeDueDay.value : '';
                var markResultDone = false;
                var resultCheckbox = document.getElementById('pfCompleteResultGoal');
                var resultIdField = document.getElementById('pfCompleteResultId');
                if (resultCheckbox && resultCheckbox.checked && resultIdField && resultIdField.value) {
                    markResultDone = true;
                }
                ajaxCompleteTask(taskId, value, actualTime, completionDate, function () {
                    var btn = document.querySelector('.pf-task-card .pf-complete-btn[data-task-id="' + taskId + '"]');
                    if (btn) {
                        var parent = btn.closest('.pf-task-card');
                        if (parent) parent.classList.add('is-completed');
                        btn.remove();
                    }
                    if (window.pfRenderCharts) window.pfRenderCharts();
                    if (markResultDone && resultIdField && resultIdField.value) {
                        var xhr2 = new XMLHttpRequest();
                        xhr2.open('POST', '/results/complete/' + encodeURIComponent(resultIdField.value), true);
                        xhr2.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                        xhr2.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
                        xhr2.send('status=done');
                    }
                    closeCompleteModal();
                }, function () {
                    alert('Не вдалося завершити задачу. Спробуйте ще раз.');
                });
                return;
            }
            if (completionDraft && typeof completionDraft.onSubmit === 'function') {
                event.preventDefault();
                var callback = completionDraft.onSubmit;
                closeCompleteModal();
                callback(value);
            }
        });
    }

    if (completeCancel) completeCancel.addEventListener('click', closeCompleteModal);
    if (completeOverlay) completeOverlay.addEventListener('click', closeCompleteModal);
});

// --- Діаграми (ПЛАН та ФАКТ) ---
(function () {
    var typeColors = {
        'important-urgent': '#b42318',
        'important-not-urgent': '#1d4f91',
        'not-important-urgent': '#6d43b5',
        'not-important-not-urgent': '#637388'
    };

    function getTypeLabels() {
        return (window.WP && window.WP.typeLabels) || {};
    }

    function drawPie(canvas, typeCounts, typeColorMap) {
        var ctx = canvas.getContext('2d');
        var cx = 40, cy = 40, r = 36, holeR = 20;
        var total = 0;
        Object.keys(typeCounts).forEach(function (k) { total += typeCounts[k]; });
        // Фон кола
        ctx.beginPath(); ctx.arc(cx, cy, r, 0, 2 * Math.PI);
        ctx.fillStyle = '#edf1f7'; ctx.fill();
        if (total === 0) {
            ctx.beginPath(); ctx.arc(cx, cy, holeR, 0, 2 * Math.PI);
            ctx.fillStyle = '#f8fafd'; ctx.fill();
            ctx.beginPath(); ctx.arc(cx, cy, r, 0, 2 * Math.PI);
            ctx.strokeStyle = '#d8e4f0'; ctx.lineWidth = 2; ctx.stroke();
            return;
        }
        var start = -0.5 * Math.PI;
        Object.keys(typeCounts).forEach(function (type) {
            var count = typeCounts[type];
            if (count > 0) {
                var angle = (count / total) * 2 * Math.PI;
                ctx.beginPath(); ctx.moveTo(cx, cy);
                ctx.arc(cx, cy, r, start, start + angle);
                ctx.closePath();
                ctx.fillStyle = typeColorMap[type] || '#ccc'; ctx.fill();
                start += angle;
            }
        });
        // Donut hole
        ctx.beginPath(); ctx.arc(cx, cy, holeR, 0, 2 * Math.PI);
        ctx.fillStyle = '#fff'; ctx.fill();
        // Обводка
        ctx.beginPath(); ctx.arc(cx, cy, r, 0, 2 * Math.PI);
        ctx.strokeStyle = 'rgba(200,215,235,.6)'; ctx.lineWidth = 1.5; ctx.stroke();
    }

    function renderCharts() {
        var typeLabels = getTypeLabels();

        // --- ПЛАН: типи задач ---
        document.querySelectorAll('.pf-pie-type').forEach(function (canvas) {
            var dayCard = canvas.closest('.pf-day-card');
            var typeCounts = { 'important-urgent': 0, 'important-not-urgent': 0, 'not-important-urgent': 0, 'not-important-not-urgent': 0 };
            if (dayCard) {
                dayCard.querySelectorAll('.pf-chip-type').forEach(function (chip) {
                    var m = chip.className.match(/pf-chip-type-([\w-]+)/);
                    if (m && m[1] && typeCounts[m[1]] !== undefined) typeCounts[m[1]]++;
                });
            }
            canvas.addEventListener('mousemove', function (e) {
                showPieLegend(legendTypeText(typeCounts, typeLabels, typeColors), e.clientX, e.clientY);
            });
            canvas.addEventListener('mouseleave', hidePieLegend);
            drawPie(canvas, typeCounts, typeColors);
        });

        // --- ПЛАН: заплановані години ---
        document.querySelectorAll('.pf-pie-hours').forEach(function (canvas) {
            var dayCard = canvas.closest('.pf-day-card');
            var minutes = 0;
            if (dayCard) {
                dayCard.querySelectorAll('.pf-task-card').forEach(function (tc) {
                    var et = parseInt(tc.getAttribute('data-expected-time') || '0', 10);
                    if (!isNaN(et)) minutes += et;
                });
            }
            var hours = minutes / 60;
            canvas.addEventListener('mousemove', function (e) {
                showPieLegend(legendHoursText(hours), e.clientX, e.clientY);
            });
            canvas.addEventListener('mouseleave', hidePieLegend);
            var ctx = canvas.getContext('2d');
            var cx = 40, cy = 40, r = 36, holeR = 20;
            // Фон
            ctx.beginPath(); ctx.arc(cx, cy, r, 0, 2 * Math.PI);
            ctx.fillStyle = '#edf1f7'; ctx.fill();
            // Сектор прогресу
            var fillColor = hours > 8 ? '#b42318' : '#1b7f5a';
            var angle = Math.min(hours / 8, 1) * 2 * Math.PI;
            if (angle > 0) {
                ctx.beginPath(); ctx.moveTo(cx, cy);
                ctx.arc(cx, cy, r, -0.5 * Math.PI, -0.5 * Math.PI + angle);
                ctx.closePath();
                ctx.fillStyle = fillColor; ctx.fill();
            }
            // Donut hole
            ctx.beginPath(); ctx.arc(cx, cy, holeR, 0, 2 * Math.PI);
            ctx.fillStyle = '#fff'; ctx.fill();
            // Текст
            ctx.font = 'bold 12px Arial'; ctx.fillStyle = fillColor;
            ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
            ctx.fillText(hours > 0 ? hours.toFixed(1) : '0', cx, cy);
            // Обводка
            ctx.beginPath(); ctx.arc(cx, cy, r, 0, 2 * Math.PI);
            ctx.strokeStyle = 'rgba(200,215,235,.6)'; ctx.lineWidth = 1.5; ctx.stroke();
        });

        // --- ФАКТ: типи задач ---
        document.querySelectorAll('.pf-pie-type-fact').forEach(function (canvas) {
            var factCol = canvas.closest('.pf-day-card');
            var typeCounts = { 'important-urgent': 0, 'important-not-urgent': 0, 'not-important-urgent': 0, 'not-important-not-urgent': 0 };
            if (factCol) {
                factCol.querySelectorAll('.pf-list .pf-task-card').forEach(function (card) {
                    var type = 'not-important-not-urgent';
                    var chip = card.querySelector('.pf-chip-type');
                    if (chip) {
                        var chipType = chip.className.match(/pf-chip-type-([\w-]+)/);
                        if (chipType && chipType[1]) type = chipType[1];
                    }
                    if (typeCounts[type] !== undefined) typeCounts[type]++;
                    else typeCounts['not-important-not-urgent']++;
                });
            }
            canvas.addEventListener('mousemove', function (e) {
                showPieLegend(legendTypeText(typeCounts, typeLabels, typeColors), e.clientX, e.clientY);
            });
            canvas.addEventListener('mouseleave', hidePieLegend);
            drawPie(canvas, typeCounts, typeColors);
        });

        // --- ФАКТ: виконано/всього ---
        document.querySelectorAll('.pf-pie-done-fact').forEach(function (canvas) {
            var factCol = canvas.closest('.pf-day-card');
            var done = 0, total = 0;
            if (factCol) {
                factCol.querySelectorAll('.pf-list .pf-task-card').forEach(function (card) {
                    total++;
                    if (card.classList.contains('is-completed')) done++;
                });
            }
            canvas.addEventListener('mousemove', function (e) {
                showPieLegend(legendDoneText(done, total), e.clientX, e.clientY);
            });
            canvas.addEventListener('mouseleave', hidePieLegend);
            var ctx = canvas.getContext('2d');
            var cx = 40, cy = 40, r = 36, holeR = 20;
            // Фон
            ctx.beginPath(); ctx.arc(cx, cy, r, 0, 2 * Math.PI);
            ctx.fillStyle = '#edf1f7'; ctx.fill();
            // Сектор виконаних
            var pct = total > 0 ? done / total : 0;
            var angle = pct * 2 * Math.PI;
            var fillColor = pct >= 1 ? '#1b7f5a' : pct >= 0.5 ? '#2a9d6c' : '#637388';
            if (angle > 0) {
                ctx.beginPath(); ctx.moveTo(cx, cy);
                ctx.arc(cx, cy, r, -0.5 * Math.PI, -0.5 * Math.PI + angle);
                ctx.closePath();
                ctx.fillStyle = fillColor; ctx.fill();
            }
            // Donut hole
            ctx.beginPath(); ctx.arc(cx, cy, holeR, 0, 2 * Math.PI);
            ctx.fillStyle = '#fff'; ctx.fill();
            // Текст
            ctx.font = 'bold 12px Arial'; ctx.fillStyle = total > 0 ? fillColor : '#9aabb8';
            ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
            ctx.fillText(done + '/' + total, cx, cy);
            // Обводка
            ctx.beginPath(); ctx.arc(cx, cy, r, 0, 2 * Math.PI);
            ctx.strokeStyle = 'rgba(200,215,235,.6)'; ctx.lineWidth = 1.5; ctx.stroke();
        });

        // --- ФАКТ: планові / позапланові задачі ---
        document.querySelectorAll('.pf-pie-planned-fact').forEach(function (canvas) {
            var factCol = canvas.closest('.pf-day-card');
            var planned = 0, unplanned = 0;
            if (factCol) {
                factCol.querySelectorAll('.pf-list .pf-task-card').forEach(function (card) {
                    if (card.classList.contains('is-unplanned')) unplanned++;
                    else planned++;
                });
            }
            var total = planned + unplanned;
            canvas.addEventListener('mousemove', function (e) {
                var lines = [
                    '📋 Планових: ' + planned,
                    '⚡ Позапланових: ' + unplanned,
                    'Всього: ' + total
                ];
                showPieLegend(lines.join('<br>'), e.clientX, e.clientY);
            });
            canvas.addEventListener('mouseleave', hidePieLegend);
            var ctx = canvas.getContext('2d');
            var cx = 40, cy = 40, r = 36, holeR = 20;
            var plannedColor = '#1b7f5a';
            var unplannedColor = '#f59e0b';
            // Фон
            ctx.beginPath(); ctx.arc(cx, cy, r, 0, 2 * Math.PI);
            ctx.fillStyle = '#edf1f7'; ctx.fill();
            if (total > 0) {
                var startAngle = -0.5 * Math.PI;
                // Сектор планових
                if (planned > 0) {
                    var plannedAngle = (planned / total) * 2 * Math.PI;
                    ctx.beginPath(); ctx.moveTo(cx, cy);
                    ctx.arc(cx, cy, r, startAngle, startAngle + plannedAngle);
                    ctx.closePath();
                    ctx.fillStyle = plannedColor; ctx.fill();
                    startAngle += plannedAngle;
                }
                // Сектор позапланових
                if (unplanned > 0) {
                    var unplannedAngle = (unplanned / total) * 2 * Math.PI;
                    ctx.beginPath(); ctx.moveTo(cx, cy);
                    ctx.arc(cx, cy, r, startAngle, startAngle + unplannedAngle);
                    ctx.closePath();
                    ctx.fillStyle = unplannedColor; ctx.fill();
                }
            }
            // Donut hole
            ctx.beginPath(); ctx.arc(cx, cy, holeR, 0, 2 * Math.PI);
            ctx.fillStyle = '#fff'; ctx.fill();
            // Текст
            var textColor = total === 0 ? '#9aabb8' : (unplanned === 0 ? plannedColor : unplannedColor);
            ctx.font = 'bold 11px Arial'; ctx.fillStyle = textColor;
            ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
            ctx.fillText(total > 0 ? planned + '/' + total : '0', cx, cy);
            // Обводка
            ctx.beginPath(); ctx.arc(cx, cy, r, 0, 2 * Math.PI);
            ctx.strokeStyle = 'rgba(200,215,235,.6)'; ctx.lineWidth = 1.5; ctx.stroke();
        });
    }

    window.pfRenderCharts = renderCharts;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', renderCharts);
    } else {
        renderCharts();
    }
})();
