(function () {
    const viewToggle = document.getElementById('viewToggle');
    const tableView = document.getElementById('tableView');
    const cardsView = document.getElementById('cardsView');
    const addGoalBtn = document.getElementById('addGoalBtn');

    const drawer = document.getElementById('resultDrawer');
    const drawerOverlay = document.getElementById('resultDrawerOverlay');
    const drawerClose = document.getElementById('resultDrawerClose');
    const drawerCancel = document.getElementById('resultDrawerCancel');
    const openLinks = document.querySelectorAll('.open-result-drawer');
    const drawerForm = document.getElementById('resultDrawerForm');

    const drawerTitle = document.getElementById('drawerTitle');
    const drawerDescription = document.getElementById('drawerDescription');
    const drawerExpectedResult = document.getElementById('drawerExpectedResult');
    const drawerInstruction = document.getElementById('drawerInstruction');
    const drawerStatus = document.getElementById('drawerStatus');
    const drawerAssignee = document.getElementById('drawerAssignee');
    const drawerParentId = document.getElementById('drawerParentId');
    const drawerDeadline = document.getElementById('drawerDeadline');
    const drawerReporter = document.getElementById('drawerReporter');
    const drawerDeleteLink = document.getElementById('drawerDeleteLink');
    const drawerErrorBox = document.getElementById('drawerErrorBox');
    const drawerSuccessBox = document.getElementById('drawerSuccessBox');
    const currentUserId = (window.RI && window.RI.currentUserId) || 0;
    const currentUserName = (window.RI && window.RI.currentUserName) || '';
    const initialDrawerMode = (window.RI && window.RI.initialDrawerMode) || '';
    const initialDrawerResultId = (window.RI && window.RI.initialDrawerResultId) || 0;
    const resultPayloadMap = (window.RI && window.RI.resultPayloadMap) || {};
    const drawerFormPayload = (window.RI && window.RI.drawerFormPayload) || null;
    const resultDescendantsMap = (window.RI && window.RI.resultDescendantsMap) || {};
    const treeStorageKey = 'results_tree_collapsed_nodes';
    const expandAllTreeBtn = document.getElementById('expandAllTreeBtn');
    const collapseAllTreeBtn = document.getElementById('collapseAllTreeBtn');
    const collapsedTreeNodes = new Set((function () {
        try {
            const raw = localStorage.getItem(treeStorageKey);
            const parsed = raw ? JSON.parse(raw) : [];
            return Array.isArray(parsed) ? parsed.map(String) : [];
        } catch (e) {
            return [];
        }
    })());

    const drawerSubgoalsSection = document.getElementById('drawerSubgoalsSection');
    const drawerSubgoalsList = document.getElementById('drawerSubgoalsList');
    const drawerSubgoalsTitle = document.getElementById('drawerSubgoalsTitle');
    const drawerAddSubgoalBtn = document.getElementById('drawerAddSubgoalBtn');
    let currentDrawerResultId = 0;

    function escHtml(str) {
        return String(str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function refreshParentOptions(currentId) {
        if (!drawerParentId) {
            return;
        }

        const blockedValues = new Set();
        if (currentId) {
            blockedValues.add(String(currentId));
            const descendants = resultDescendantsMap[String(currentId)] || resultDescendantsMap[currentId] || [];
            descendants.forEach(function (value) {
                blockedValues.add(String(value));
            });
        }

        Array.from(drawerParentId.options).forEach(function (option) {
            if (!option.value) {
                option.disabled = false;
                return;
            }

            option.disabled = blockedValues.has(String(option.value));
        });
    }

    function setDrawerMessage(node, message) {
        if (!node) {
            return;
        }

        if (message) {
            node.textContent = message;
            node.style.display = 'block';
        } else {
            node.textContent = '';
            node.style.display = 'none';
        }
    }

    function setView(view) {
        const buttons = viewToggle.querySelectorAll('.view-btn');
        buttons.forEach(function (btn) {
            btn.classList.toggle('active', btn.dataset.view === view);
        });

        tableView.style.display = view === 'cards' ? 'none' : 'block';
        cardsView.classList.toggle('active', view === 'cards');
        localStorage.setItem('results_view_mode', view);
    }

    function saveCollapsedTreeState() {
        localStorage.setItem(treeStorageKey, JSON.stringify(Array.from(collapsedTreeNodes)));
    }

    function refreshTreeState() {
        const rows = document.querySelectorAll('#tableView .result-row[data-node-id]');
        rows.forEach(function (row) {
            const ancestors = (row.getAttribute('data-ancestors') || '')
                .split(',')
                .map(function (value) { return value.trim(); })
                .filter(Boolean);

            const hiddenByAncestor = ancestors.some(function (ancestorId) {
                return collapsedTreeNodes.has(String(ancestorId));
            });

            row.style.display = hiddenByAncestor ? 'none' : '';
        });

        const toggles = document.querySelectorAll('#tableView .tree-toggle[data-node-id]');
        toggles.forEach(function (toggle) {
            const nodeId = String(toggle.getAttribute('data-node-id') || '');
            const collapsed = collapsedTreeNodes.has(nodeId);
            toggle.classList.toggle('collapsed', collapsed);
            toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        });
    }

    function initTreeToggles() {
        const toggles = document.querySelectorAll('#tableView .tree-toggle[data-node-id]');
        toggles.forEach(function (toggle) {
            if (toggle.dataset.bound === '1') {
                return;
            }

            toggle.dataset.bound = '1';
            toggle.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation();

                const nodeId = String(toggle.getAttribute('data-node-id') || '');
                if (!nodeId) {
                    return;
                }

                if (collapsedTreeNodes.has(nodeId)) {
                    collapsedTreeNodes.delete(nodeId);
                } else {
                    collapsedTreeNodes.add(nodeId);
                }

                saveCollapsedTreeState();
                refreshTreeState();
            });
        });

        refreshTreeState();
    }

    if (expandAllTreeBtn) {
        expandAllTreeBtn.addEventListener('click', function () {
            collapsedTreeNodes.clear();
            saveCollapsedTreeState();
            refreshTreeState();
        });
    }

    if (collapseAllTreeBtn) {
        collapseAllTreeBtn.addEventListener('click', function () {
            const toggles = document.querySelectorAll('#tableView .tree-toggle[data-node-id]');
            toggles.forEach(function (toggle) {
                const nodeId = String(toggle.getAttribute('data-node-id') || '');
                if (nodeId) {
                    collapsedTreeNodes.add(nodeId);
                }
            });
            saveCollapsedTreeState();
            refreshTreeState();
        });
    }

    viewToggle.addEventListener('click', function (event) {
        const button = event.target.closest('.view-btn');
        if (!button) {
            return;
        }
        setView(button.dataset.view);
    });

    setView(localStorage.getItem('results_view_mode') || 'table');
    initTreeToggles();

    function closeDrawer() {
        drawer.classList.remove('open');
        drawerOverlay.classList.remove('open');
    }

    function openCreateDrawer(parentId) {
        currentDrawerResultId = 0;
        drawerTitle.value = '';
        drawerDescription.value = '';
        drawerExpectedResult.value = '';
        drawerInstruction.value = '';
        drawerStatus.value = 'in-progress';
        drawerAssignee.value = currentUserId ? String(currentUserId) : '';
        drawerParentId.value = parentId ? String(parentId) : '';
        drawerDeadline.value = '';
        drawerReporter.textContent = currentUserName || '—';
        refreshParentOptions(null);
        if (drawerFormPayload && Object.keys(drawerFormPayload).length > 0) {
            drawerTitle.value = drawerFormPayload.title || '';
            drawerDescription.value = drawerFormPayload.description || '';
            drawerExpectedResult.value = drawerFormPayload.expected_result || '';
            drawerInstruction.value = drawerFormPayload.instruction || '';
            drawerStatus.value = drawerFormPayload.status || 'in-progress';
            drawerAssignee.value = drawerFormPayload.assignee_id ? String(drawerFormPayload.assignee_id) : (currentUserId ? String(currentUserId) : '');
            drawerParentId.value = drawerFormPayload.parent_id ? String(drawerFormPayload.parent_id) : (parentId ? String(parentId) : '');
            drawerDeadline.value = drawerFormPayload.deadline || '';
        }

        drawerForm.action = '/results/create';
        drawerDeleteLink.style.display = 'none';
        if (drawerSubgoalsSection) { drawerSubgoalsSection.style.display = 'none'; }
        setDrawerMessage(drawerSuccessBox, '');

        drawer.classList.add('open');
        drawerOverlay.classList.add('open');
        setTimeout(function () {
            drawerTitle.focus();
        }, 30);
    }

    function openDrawer(payload) {
        const formPayload = (drawerFormPayload && Object.keys(drawerFormPayload).length > 0) ? drawerFormPayload : null;

        drawerTitle.value = formPayload ? (formPayload.title || '') : (payload.title || '');
        drawerDescription.value = formPayload ? (formPayload.description || '') : (payload.description || '');
        drawerExpectedResult.value = formPayload ? (formPayload.expected_result || '') : (payload.expected_result || '');
        drawerInstruction.value = formPayload ? (formPayload.instruction || '') : (payload.instruction || '');
        drawerStatus.value = formPayload ? (formPayload.status || 'in-progress') : (payload.status || (Number(payload.completed || 0) === 1 ? 'done' : 'in-progress'));
        drawerAssignee.value = formPayload ? (formPayload.assignee_id ? String(formPayload.assignee_id) : '') : (payload.assigneeId ? String(payload.assigneeId) : '');
        refreshParentOptions(payload.id || null);
        drawerParentId.value = formPayload ? (formPayload.parent_id ? String(formPayload.parent_id) : '') : (payload.parent_id ? String(payload.parent_id) : '');
        drawerDeadline.value = formPayload ? (formPayload.deadline || '') : (payload.deadlineRaw || '');
        drawerReporter.textContent = payload.reporterName || '—';

        drawerForm.action = '/results/edit/' + payload.id;
        drawerDeleteLink.href = '/results/delete/' + payload.id;
        drawerDeleteLink.style.display = 'inline-flex';
        setDrawerMessage(drawerSuccessBox, '');

        currentDrawerResultId = Number(payload.id) || 0;
        if (drawerSubgoalsSection && drawerSubgoalsList) {
            const children = Object.values(resultPayloadMap).filter(function (p) {
                return Number(p.parent_id) === currentDrawerResultId;
            });
            drawerSubgoalsSection.style.display = '';
            if (drawerSubgoalsTitle) {
                drawerSubgoalsTitle.textContent = 'Підцілі' + (children.length ? ' (' + children.length + ')' : '');
            }
            if (children.length === 0) {
                drawerSubgoalsList.innerHTML = '<div class="drawer-subgoals-empty">Підцілей ще немає</div>';
            } else {
                drawerSubgoalsList.innerHTML = children.map(function (child) {
                    const isDone = child.status === 'done' || Number(child.completed) === 1;
                    const sc = child.status === 'done' ? 'status-done' : child.status === 'postponed' ? 'status-postponed' : 'status-progress';
                    const st = child.status === 'done' ? 'Завершено' : child.status === 'postponed' ? 'Відкладено' : 'В процесі';
                    return '<div class="drawer-subgoal-item js-open-subgoal" data-id="' + Number(child.id) + '">' +
                        '<span class="sub-goal-check ' + (isDone ? 'done' : '') + '"></span>' +
                        '<span class="drawer-sg-title' + (isDone ? ' done' : '') + '">' + escHtml(String(child.title || '')) + '</span>' +
                        '<span class="status-badge ' + sc + '">' + st + '</span>' +
                        '</div>';
                }).join('');
                drawerSubgoalsList.querySelectorAll('.js-open-subgoal').forEach(function (item) {
                    item.addEventListener('click', function () {
                        const childId = item.dataset.id;
                        const childPayload = resultPayloadMap[childId] || resultPayloadMap[Number(childId)];
                        if (childPayload) { openDrawer(childPayload); }
                    });
                });
            }
        }

        drawer.classList.add('open');
        drawerOverlay.classList.add('open');
    }

    openLinks.forEach(function (link) {
        link.addEventListener('click', function (event) {
            event.preventDefault();
            const raw = link.getAttribute('data-result');
            const fallbackHref = link.getAttribute('href') || link.getAttribute('data-href');
            if (!raw) {
                if (fallbackHref) {
                    window.location.href = fallbackHref;
                }
                return;
            }
            try {
                openDrawer(JSON.parse(raw));
            } catch (e) {
                if (fallbackHref) {
                    window.location.href = fallbackHref;
                }
            }
        });
    });

    drawerClose.addEventListener('click', closeDrawer);
    drawerCancel.addEventListener('click', closeDrawer);
    drawerOverlay.addEventListener('click', closeDrawer);

    if (addGoalBtn) {
        addGoalBtn.addEventListener('click', function () {
            openCreateDrawer();
        });
    }

    if (initialDrawerMode === 'create') {
        openCreateDrawer();
    } else if (initialDrawerMode === 'edit' && initialDrawerResultId > 0) {
        const payload = resultPayloadMap[String(initialDrawerResultId)] || resultPayloadMap[initialDrawerResultId];
        if (payload) {
            openDrawer(payload);
        }
    }

    if (drawerAddSubgoalBtn) {
        drawerAddSubgoalBtn.addEventListener('click', function () {
            const pid = currentDrawerResultId;
            closeDrawer();
            setTimeout(function () { openCreateDrawer(pid); }, 80);
        });
    }

    document.querySelectorAll('.btn-add-subgoal').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            const targetId = btn.dataset.target;
            const ria = document.getElementById(targetId);
            if (!ria) { return; }
            document.querySelectorAll('.result-inline-add').forEach(function (other) {
                if (other !== ria && other.style.display !== 'none') {
                    other.style.display = 'none';
                    const inp = other.querySelector('.ria-input');
                    if (inp) { inp.value = ''; }
                }
            });
            const isOpen = ria.style.display !== 'none';
            ria.style.display = isOpen ? 'none' : '';
            if (!isOpen) {
                const input = ria.querySelector('.ria-input');
                if (input) { input.focus(); }
            }
        });
    });

    document.querySelectorAll('.result-inline-add').forEach(function (ria) {
        const input = ria.querySelector('.ria-input');
        const saveBtn = ria.querySelector('.ria-save');
        const cancelBtn = ria.querySelector('.ria-cancel');
        const parentId = ria.dataset.parentId;

        function closeRia() {
            ria.style.display = 'none';
            if (input) { input.value = ''; }
        }

        function submitRia() {
            const title = (input ? input.value : '').trim();
            if (!title) { if (input) { input.focus(); } return; }
            const body = new URLSearchParams();
            body.set('title', title);
            if (parentId) { body.set('parent_id', parentId); }
            if (saveBtn) { saveBtn.textContent = '…'; saveBtn.disabled = true; }
            fetch('/results/store-ajax', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: body.toString(),
                credentials: 'same-origin'
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success) {
                    window.location.reload();
                } else {
                    alert(data.error || 'Помилка збереження');
                    if (saveBtn) { saveBtn.textContent = 'Додати'; saveBtn.disabled = false; }
                }
            })
            .catch(function () {
                alert('Помилка мережі');
                if (saveBtn) { saveBtn.textContent = 'Додати'; saveBtn.disabled = false; }
            });
        }

        if (saveBtn) { saveBtn.addEventListener('click', submitRia); }
        if (cancelBtn) { cancelBtn.addEventListener('click', closeRia); }
        if (input) {
            input.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') { e.preventDefault(); var t=(input?input.value:'').trim(); closeRia(); openCreateDrawer(parentId); if(t){drawerTitle.value=t; setTimeout(function(){try{drawerTitle.setSelectionRange(t.length,t.length);}catch(_){}} ,60);} }
                if (e.key === 'Escape') { closeRia(); }
            });
        }
    });
})();
// ===== Bulk-дії над вибраними цілями =====
(function(){
  var bar=document.getElementById('bulkBar'); if(!bar) return;
  var countEl=document.getElementById('bulkCount'); var selected=new Set();
  function refresh(){ countEl.textContent=selected.size; bar.style.display=selected.size?'flex':'none'; }
  document.querySelectorAll('.bulk-select').forEach(function(cb){ cb.addEventListener('change',function(){ var r=cb.getAttribute('data-rid'); if(cb.checked)selected.add(r); else selected.delete(r); refresh(); }); });
  function run(fn){ var a=Array.from(selected); (async function(){ for(var i=0;i<a.length;i++){ try{ await fn(a[i]); }catch(e){} } location.reload(); })(); }
  var bc=document.getElementById('bulkComplete'); if(bc) bc.addEventListener('click',function(){ run(function(r){ return fetch('/results/complete/'+r,{method:'POST',credentials:'same-origin'}); }); });
  var bd=document.getElementById('bulkDelete'); if(bd) bd.addEventListener('click',function(){ if(!confirm('Видалити вибрані ('+selected.size+')?'))return; run(function(r){ return fetch('/results/delete/'+r,{method:'POST',credentials:'same-origin'}); }); });
  var ba=document.getElementById('bulkAssignee'); if(ba) ba.addEventListener('change',function(){ var v=ba.value; if(!v)return; var body=new URLSearchParams(); body.set('assignee_id',v); run(function(r){ return fetch('/results/assign/'+r,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body.toString(),credentials:'same-origin'}); }); });
  var bx=document.getElementById('bulkCancel'); if(bx) bx.addEventListener('click',function(){ selected.clear(); document.querySelectorAll('.bulk-select:checked').forEach(function(c){c.checked=false;}); refresh(); });
})();
