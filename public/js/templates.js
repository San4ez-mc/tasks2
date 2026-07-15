(function () {
    'use strict';

    var overlay = document.getElementById('drawerOverlay');
    var drawer = document.getElementById('tplDrawer');
    var form = document.getElementById('drawerForm');
    var titleEl = document.getElementById('drawerTitle');
    var subtitleEl = document.getElementById('drawerSubtitle');
    var submitBtn = document.getElementById('drawerSubmitBtn');

    var fieldId = document.getElementById('fieldTplId');
    var fieldName = document.getElementById('fieldName');
    var fieldDesc = document.getElementById('fieldDescription');
    var fieldER = document.getElementById('fieldExpectedResult');
    var fieldType = document.getElementById('fieldType');
    var fieldTime = document.getElementById('fieldExpectedTime');
    var fieldStart = document.getElementById('fieldStartTime');
    var fieldRepeat = document.getElementById('fieldRepeatType');
    var fieldDays = document.getElementById('fieldRepeatDays');
    var fieldMonthDays = document.getElementById('fieldRepeatMonthDays');
    var wrapDay = document.getElementById('wrapRepeatDay');
    var wrapMonthDay = document.getElementById('wrapRepeatMonthDay');
    var fieldAssignees = document.getElementById('fieldAssignees');

    var typePreview = document.getElementById('drawerTypePreview');
    var typeLabelEl = document.getElementById('drawerTypeLabel');

    // ── type preview ────────────────────────────────────────────────────────
    var typeInfo = {
        'important-urgent': { label: 'Важлива термінова', cls: 'type-important-urgent' },
        'important-not-urgent': { label: 'Важлива нетермінова', cls: 'type-important-not-urgent' },
        'not-important-urgent': { label: 'Неважлива термінова', cls: 'type-not-important-urgent' },
        'not-important-not-urgent': { label: 'Неважлива нетермінова', cls: 'type-not-important-not-urgent' },
    };

    function updateTypePreview(val) {
        var allCls = ['type-none', 'type-important-urgent', 'type-important-not-urgent', 'type-not-important-urgent', 'type-not-important-not-urgent'];
        allCls.forEach(function (c) { typePreview.classList.remove(c); });
        if (val && typeInfo[val]) {
            typePreview.style.display = 'inline-flex';
            typePreview.classList.add(typeInfo[val].cls);
            typeLabelEl.textContent = typeInfo[val].label;
        } else {
            typePreview.style.display = 'none';
        }
    }

    fieldType.addEventListener('change', function () { updateTypePreview(fieldType.value); });

    function updateRepeatVisibility() {
        wrapDay.style.display = (fieldRepeat.value === 'weekly') ? '' : 'none';
        wrapMonthDay.style.display = (fieldRepeat.value === 'monthly') ? '' : 'none';
    }

    // ── repeat day visibility ────────────────────────────────────────────────
    fieldRepeat.addEventListener('change', updateRepeatVisibility);

    // ── open/close ───────────────────────────────────────────────────────────
    function openDrawer() {
        overlay.classList.add('open');
        drawer.classList.add('open');
        document.body.style.overflow = 'hidden';
        setTimeout(function () { fieldName.focus(); }, 250);
    }

    function closeDrawer() {
        overlay.classList.remove('open');
        drawer.classList.remove('open');
        document.body.style.overflow = '';
    }

    function resetForm() {
        form.reset();
        fieldId.value = '';
        wrapDay.style.display = 'none';
        wrapMonthDay.style.display = 'none';
        setCheckedValues(fieldDays, []);
        setCheckedValues(fieldMonthDays, []);
        setSelectedOptions(fieldAssignees, []);
        updateTypePreview('');
    }

    function parseCsvIds(raw) {
        var value = (raw || '').toString().trim();
        if (!value) {
            return [];
        }

        return value.split(',').map(function (part) {
            return part.trim();
        }).filter(function (part) {
            return part !== '';
        });
    }

    function setCheckedValues(container, values) {
        if (!container) {
            return;
        }

        var normalized = Array.isArray(values) ? values.map(function (v) { return String(v); }) : [];
        var checkboxes = container.querySelectorAll('input[type="checkbox"]');
        Array.prototype.forEach.call(checkboxes, function (checkbox) {
            checkbox.checked = normalized.indexOf(String(checkbox.value)) !== -1;
        });
    }

    function setSelectedOptions(select, values) {
        if (!select) {
            return;
        }

        var normalized = Array.isArray(values) ? values.map(function (v) { return String(v); }) : [];
        Array.prototype.forEach.call(select.options, function (opt) {
            opt.selected = normalized.indexOf(String(opt.value)) !== -1;
        });
    }

    // ── create mode ─────────────────────────────────────────────────────────
    document.getElementById('btnOpenCreate').addEventListener('click', function () {
        resetForm();
        form.action = '/templates/create';
        titleEl.textContent = 'Створити шаблон';
        subtitleEl.textContent = 'Заповніть поля нового шаблону задачі';
        submitBtn.textContent = 'Створити шаблон';
        openDrawer();
    });

    // ── edit mode ────────────────────────────────────────────────────────────
    function openEdit(data) {
        resetForm();
        form.action = '/templates/edit/' + data.id;
        titleEl.textContent = 'Редагувати шаблон';
        subtitleEl.textContent = 'Внесіть зміни до шаблону задачі';
        submitBtn.textContent = 'Зберегти зміни';

        fieldId.value = data.id;
        fieldName.value = data.name || '';
        fieldDesc.value = data.description || '';
        fieldER.value = data.expected_result || '';
        fieldTime.value = data.expected_time || '';
        fieldStart.value = data.start_time || '';

        if (data.type) { fieldType.value = data.type; }
        updateTypePreview(data.type || '');

        if (data.repeat_type) { fieldRepeat.value = data.repeat_type; }
        updateRepeatVisibility();
        if (data.repeat_type === 'weekly') {
            setCheckedValues(fieldDays, parseCsvIds(data.repeat_day || ''));
        } else if (data.repeat_type === 'monthly') {
            setCheckedValues(fieldMonthDays, parseCsvIds(data.repeat_day || ''));
        }

        var assigneeIds = parseCsvIds(data.assignee_ids || '');
        if (assigneeIds.length === 0 && data.assignee_id) {
            assigneeIds = [String(data.assignee_id)];
        }
        setSelectedOptions(fieldAssignees, assigneeIds);

        openDrawer();
    }

    // edit buttons in table
    document.querySelectorAll('.btn-edit-tpl').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var data = JSON.parse(btn.dataset.payload);
            openEdit(data);
        });
    });

    // row click (except actions column)
    document.querySelectorAll('.tpl-row').forEach(function (row) {
        row.addEventListener('click', function (e) {
            if (e.target.closest('.tpl-actions')) return;
            var data = JSON.parse(row.dataset.payload);
            openEdit(data);
        });
    });

    // close
    document.getElementById('btnCloseDrawer').addEventListener('click', closeDrawer);
    overlay.addEventListener('click', closeDrawer);
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { closeDrawer(); }
    });

    var initialDrawerMode = (window.TmpI && window.TmpI.initialDrawerMode) || '';
    var initialDrawerPayload = (window.TmpI && window.TmpI.initialDrawerPayload) || null;

    if (initialDrawerMode === 'create') {
        document.getElementById('btnOpenCreate').click();
    } else if (initialDrawerMode === 'edit' && initialDrawerPayload && initialDrawerPayload.id) {
        openEdit(initialDrawerPayload);
    }

}());