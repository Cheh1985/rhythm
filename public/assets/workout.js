(() => {
    'use strict';
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const base = document.querySelector('meta[name="app-url"]')?.content || '';
    const userId = document.querySelector('meta[name="rhythm-user-id"]')?.content || '';

    async function request(path, options = {}) {
        let response;
        try {
            response = await fetch(base + path, {...options, credentials: 'same-origin', headers: {Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': csrf, ...(options.headers || {})}});
        } catch (cause) {
            const error = new Error('Сеть недоступна.');
            error.network = true;
            error.cause = cause;
            throw error;
        }
        let payload = {};
        try { payload = await response.json(); } catch (_) { payload = {error: 'Сервер вернул непонятный ответ.'}; }
        if (!response.ok) {
            const error = new Error(payload.error || 'Не удалось сохранить изменение.');
            error.status = response.status;
            error.conflict = response.status === 409 || payload.conflict === true;
            error.payload = payload;
            throw error;
        }
        return payload;
    }

    const readiness = document.querySelector('[data-readiness]');
    if (readiness) {
        const form = readiness.querySelector('#readiness-form');
        const message = form.querySelector('.form-message');
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (!form.reportValidity()) return;
            const data = new FormData(form);
            const button = form.querySelector('button[type="submit"]');
            button.disabled = true;
            button.textContent = 'Сохраняем готовность…';
            message.hidden = true;
            const weight = data.get('body_weight_kg');
            try {
                const result = await request('/api/sessions', {method: 'POST', body: JSON.stringify({
                    plan_id: Number(readiness.dataset.planId),
                    client_action_id: RhythmOffline.uuid('session.start'),
                    readiness: {sleep: Number(data.get('sleep')), energy: Number(data.get('energy')), readiness: Number(data.get('readiness')), body_weight_kg: weight === '' ? null : Number(weight), comment: String(data.get('comment') || '')},
                })});
                location.assign(result.redirect);
            } catch (error) {
                message.textContent = error.message;
                message.hidden = false;
                button.disabled = false;
                button.textContent = 'Начать тренировку';
            }
        });
    }

    const page = document.querySelector('.workout-page');
    if (!page || !window.RhythmOffline || !window.RhythmRestTimer || !userId) return;
    const sessionId = String(page.dataset.sessionId);
    let sessionVersion = Number(page.dataset.sessionVersion);
    let versions = {sessionVersion, exerciseVersions: {}, setVersions: {}};
    let sessionSnapshot = null;
    let syncRunning = false;
    let draftTimer = null;
    const tabId = RhythmOffline.uuid('tab');
    const saveState = page.querySelector('.autosave-state');
    const networkState = page.querySelector('[data-network-state]');
    const retryButton = page.querySelector('[data-sync-retry]');
    const conflictBanner = page.querySelector('#conflict-banner');
    const channel = 'BroadcastChannel' in window ? new BroadcastChannel('rhythm-session-' + userId + '-' + sessionId) : null;
    const topbar = document.querySelector('.topbar');

    function syncStickyOffset() {
        if (!topbar) return;
        page.style.setProperty('--topbar-sticky-offset', Math.ceil(topbar.getBoundingClientRect().height) + 'px');
    }
    syncStickyOffset();
    if (topbar && 'ResizeObserver' in window) new ResizeObserver(syncStickyOffset).observe(topbar);
    else if (topbar) window.addEventListener('resize', syncStickyOffset);

    function collectDomVersions() {
        const exerciseVersions = {}, setVersions = {};
        page.querySelectorAll('.exercise-card').forEach((card) => {
            exerciseVersions[card.dataset.exerciseId] = Number(card.dataset.exerciseVersion);
            card.querySelectorAll('.saved-set[data-set-id]').forEach((row) => {
                if (/^\d+$/.test(row.dataset.setId || '')) setVersions[row.dataset.setId] = Number(row.dataset.setVersion);
            });
        });
        return {sessionVersion, exerciseVersions, setVersions};
    }
    versions = collectDomVersions();

    function paintSync(mode, count = 0, detail = '') {
        networkState.textContent = navigator.onLine ? 'Онлайн' : 'Офлайн';
        networkState.classList.toggle('offline', !navigator.onLine);
        saveState.classList.remove('saving', 'save-error');
        retryButton.hidden = true;
        if (mode === 'syncing') {
            saveState.textContent = 'Сохраняем локально и синхронизируем…';
            saveState.classList.add('saving');
        } else if (mode === 'pending') {
            saveState.textContent = (navigator.onLine ? 'Ожидает синхронизации' : 'Офлайн · ожидает синхронизации') + (count ? ' · ' + count : '');
            retryButton.hidden = false;
        } else if (mode === 'conflict') {
            saveState.textContent = 'Ошибка · конфликт версий';
            saveState.classList.add('save-error');
        } else if (mode === 'error') {
            saveState.textContent = 'Ошибка синхронизации' + (detail ? ' · ' + detail : '');
            saveState.classList.add('save-error');
            retryButton.hidden = false;
        } else saveState.textContent = 'Синхронизировано';
    }

    function showConflict(action) {
        let detail = 'Локальное действие сохранено. Свежая серверная версия загружена; выберите явно, как продолжить.';
        const current = sessionSnapshot?.exercises?.find((item) => Number(item.id) === Number(action.body.session_exercise_id));
        if (action.type === 'exercise.weight-unit' && current && current.actual_exercise_id !== action.body.actual_exercise_id) {
            detail = 'Упражнение заменено на «' + current.exercise_name + '». Повтор применит выбранную единицу к этому упражнению. Число в форме сохранится.';
        }
        conflictBanner.querySelector('span').textContent = detail;
        conflictBanner.hidden = false;
        paintSync('conflict');
    }

    function setSessionVersion(value) {
        if (!Number.isFinite(Number(value)) || Number(value) < 1) return;
        sessionVersion = Number(value);
        versions.sessionVersion = sessionVersion;
        page.dataset.sessionVersion = String(sessionVersion);
    }
    function rowForAction(actionId) { return [...page.querySelectorAll('.saved-set')].find((row) => row.dataset.actionId === actionId) || null; }

    function renderSet(card, set, actionId = '', pending = false) {
        let row = set.id ? [...card.querySelectorAll('.saved-set')].find((item) => item.dataset.setId === String(set.id)) : rowForAction(actionId);
        if (!row) {
            row = document.createElement('div');
            row.className = 'saved-set';
            row.innerHTML = '<span></span><strong></strong><small></small><button type="button" data-edit-set aria-label="Изменить подход">Изменить</button>';
            card.querySelector('.saved-sets').append(row);
        }
        if (set.id) row.dataset.setId = String(set.id);
        if (actionId) row.dataset.actionId = actionId;
        row.dataset.setVersion = String(set.version || row.dataset.setVersion || 1);
        const weight = RhythmWeight.fields(set);
        row.dataset.weight = String(weight.value);
        row.dataset.weightUnit = weight.unit;
        row.dataset.reps = String(set.reps);
        row.dataset.rir = String(set.rir);
        row.dataset.setType = set.set_type || row.dataset.setType || 'working';
        row.classList.toggle('pending-sync', pending);
        row.querySelector('span').textContent = (row.dataset.setType === 'warmup' ? 'Р' : 'П') + set.set_number;
        row.querySelector('strong').textContent = weight.value + ' ' + RhythmWeight.label(weight.unit) + ' × ' + set.reps;
        row.querySelector('small').textContent = 'RIR ' + set.rir;
        return row;
    }

    function updateProgress() {
        const completed = page.querySelectorAll('.exercise-card.completed').length;
        const completedCount = page.querySelector('#completed-count');
        const totalCount = page.querySelector('#total-count');
        const progress = page.querySelector('.progress-line');
        const total = Number(totalCount.textContent);
        completedCount.textContent = completed;
        progress.querySelector('span').style.width = (total ? completed / total * 100 : 0) + '%';
        progress.setAttribute('aria-valuemax', String(total));
        progress.setAttribute('aria-valuenow', String(completed));
        progress.setAttribute('aria-valuetext', completed + ' из ' + total + ' упражнений');
    }
    function setCardStatus(card, status) {
        card.classList.remove('pending', 'active', 'waiting', 'completed', 'skipped');
        card.classList.add(status);
        const labels = {pending: 'Ожидает', active: 'В работе', waiting: 'Оборудование занято', completed: 'Готово', skipped: 'Пропущено'};
        card.querySelector('.exercise-state').textContent = labels[status] || status;
        const waitingButton = card.querySelector('[data-status]');
        if (waitingButton && ['waiting', 'active'].includes(status)) {
            waitingButton.dataset.status = status === 'waiting' ? 'active' : 'waiting';
            waitingButton.textContent = status === 'waiting' ? 'Оборудование свободно' : 'Оборудование занято';
        }
        const closed = status === 'completed' || status === 'skipped';
        for (const editor of [card.querySelector('.set-entry'), card.querySelector('.exercise-actions')]) {
            if (!editor) continue;
            editor.hidden = closed;
            editor.querySelectorAll('button,input,select,textarea').forEach((control) => { control.disabled = closed; });
        }
        updateProgress();
    }
    function appendLocalNote(card, text) {
        if ([...card.querySelectorAll('.local-note')].some((item) => item.textContent === text)) return;
        const note = document.createElement('p');
        note.className = 'local-note';
        note.textContent = text;
        card.append(note);
    }

    function applyOptimistic(action) {
        const body = action.body;
        const card = body.session_exercise_id ? page.querySelector('.exercise-card[data-exercise-id="' + body.session_exercise_id + '"]') : null;
        if (action.type === 'set.create' && card && !rowForAction(action.id)) {
            renderSet(card, {...body, version: 1}, action.id, true);
            const form = card.querySelector('.set-entry');
            const nextKey = body.set_type === 'working' ? 'workingNext' : 'warmupNext';
            if (form && Number(form.dataset[nextKey]) <= Number(body.set_number)) form.dataset[nextKey] = String(Number(body.set_number) + 1);
            if (card.classList.contains('pending') || card.classList.contains('waiting')) setCardStatus(card, 'active');
        } else if (action.type === 'set.update') {
            const setId = action.path.split('/').pop();
            const row = page.querySelector('.saved-set[data-set-id="' + setId + '"]');
            if (row) renderSet(row.closest('.exercise-card'), {...body, id: setId, set_type: row.dataset.setType, set_number: row.querySelector('span').textContent.slice(1)}, action.id, true);
        } else if (action.type === 'exercise.status' && card) setCardStatus(card, body.status);
        else if (action.type === 'exercise.weight-unit' && card) {
            setWeightUnit(card, body.weight_unit);
            const option = replacementOptions().find((item) => item.value === body.actual_exercise_id);
            if (option) option.dataset.weightUnit = body.weight_unit;
        }
        else if (action.type === 'exercise.replace' && card) {
            const option = replacementOptions().find((item) => item.value === String(body.actual_exercise_id));
            if (option) { card.querySelector('h2').textContent = option.textContent; card.dataset.actualExerciseId = body.actual_exercise_id; setWeightUnit(card, option.dataset.weightUnit || 'kg'); }
            appendLocalNote(card, 'Замена ожидает синхронизации');
        } else if (action.type === 'discomfort.create' && card) appendLocalNote(card, 'Дискомфорт записан локально');
        else if (action.type === 'session.finish') {
            const finish = page.querySelector('#finish-workout');
            finish.disabled = true;
            finish.textContent = 'Завершение ожидает синхронизации';
        }
    }

    let enqueueChain = Promise.resolve();
    function queueMutation(type, path, method, body, actionId = null) {
        const queued = enqueueChain.then(() => enqueueMutation(type, path, method, body, actionId));
        enqueueChain = queued.catch(() => {});
        return queued;
    }
    async function enqueueMutation(type, path, method, body, actionId = null) {
        const existing = await RhythmOffline.listActions(userId, sessionId);
        const duplicate = actionId ? existing.find((item) => item.id === actionId) : null;
        if (duplicate) { syncOutbox(); return duplicate; }
        const action = RhythmOffline.createAction({id: actionId || undefined, userId, sessionId, type, path, method, body, dependsOn: existing.length ? [existing[existing.length - 1].id] : []});
        if (!sessionSnapshot) sessionSnapshot = {id: Number(sessionId), version: sessionVersion, exercises: []};
        const sessionRecord = {key: 'user:' + userId + ':session:' + sessionId, userId: String(userId), sessionId: String(sessionId), snapshot: sessionSnapshot, draft: captureDraft(), updatedAt: Date.now()};
        await RhythmOffline.enqueue(action, sessionRecord);
        applyOptimistic(action);
        channel?.postMessage({type: 'queued', actionId: action.id});
        paintSync('pending', existing.length + 1);
        syncOutbox();
        return action;
    }

    function updateVersionsFromResult(action, data) {
        setSessionVersion(data?.session_version || (action.type === 'session.finish' ? data?.version : null));
        const exerciseId = action.body.session_exercise_id;
        if (exerciseId && data?.exercise_version != null) versions.exerciseVersions[exerciseId] = Number(data.exercise_version);
        if (data?.id && data?.version && (action.type === 'set.create' || action.type === 'set.update')) versions.setVersions[data.id] = Number(data.version);
    }
    function reconcileSuccess(action, data) {
        updateVersionsFromResult(action, data);
        if (action.type === 'set.create') {
            const row = rowForAction(action.id);
            if (row) { row.dataset.setId = String(data.id); row.dataset.setVersion = String(data.version); row.classList.remove('pending-sync'); }
        } else if (action.type === 'set.update') {
            const row = page.querySelector('.saved-set[data-set-id="' + data.id + '"]');
            row?.classList.remove('pending-sync');
            if (row) renderSet(row.closest('.exercise-card'), {...data, set_type: row.dataset.setType, set_number: row.querySelector('span').textContent.slice(1)});
        } else if (action.type === 'exercise.status' || action.type === 'exercise.weight-unit') {
            const card = page.querySelector('.exercise-card[data-exercise-id="' + action.body.session_exercise_id + '"]');
            if (card) card.dataset.exerciseVersion = String(data.exercise_version);
        } else if (action.type === 'exercise.replace' || action.type === 'discomfort.create') {
            const card = page.querySelector('.exercise-card[data-exercise-id="' + action.body.session_exercise_id + '"]');
            card?.querySelectorAll('.local-note').forEach((note) => note.remove());
            if (data.exercise_version && card) card.dataset.exerciseVersion = String(data.exercise_version);
            if (action.type === 'exercise.replace' && card) {
                card.dataset.actualExerciseId = data.actual_exercise_id;
                setWeightUnit(card, data.weight_unit || 'kg');
            }
        }
    }

    async function performSync(manual = false) {
        const actions = await RhythmOffline.listActions(userId, sessionId);
        if (!actions.length) return paintSync('synced');
        if (!navigator.onLine) return paintSync('pending', actions.length);
        paintSync('syncing', actions.length);
        for (const queued of actions) {
            // Claim the latest payload atomically with respect to edits in every tab.
            const action = await RhythmOffline.updateAction(queued.key, (current) => {
                if (current.status === 'conflict' && !manual) return current;
                const next = RhythmOffline.rebaseAction(current, versions);
                next.status = 'syncing';
                next.attempts += 1;
                next.error = null;
                return next;
            });
            if (!action) continue;
            if (action.status === 'conflict') {
                showConflict(action);
                return;
            }
            try {
                const payload = await request(action.path, {method: action.method, body: JSON.stringify(action.body)});
                const data = payload.data || payload;
                reconcileSuccess(action, data);
                await RhythmOffline.removeAction(action.key);
                channel?.postMessage({type: 'synced', actionId: action.id, versions});
            } catch (error) {
                action.status = error.conflict ? 'conflict' : (error.network ? 'pending' : 'error');
                action.error = {message: error.message, status: error.status || 0, at: Date.now()};
                await RhythmOffline.putAction(action);
                if (error.conflict) { await refreshSnapshot(true); showConflict(action); }
                else if (error.network) paintSync('pending', actions.length);
                else paintSync('error', actions.length, error.message);
                return;
            }
        }
        await refreshSnapshot(false);
        const remaining = await RhythmOffline.listActions(userId, sessionId);
        remaining.forEach(applyOptimistic);
        paintSync(remaining.length ? 'pending' : 'synced', remaining.length);
        if (actions.some((action) => action.type === 'session.finish')) location.assign(base + '/sessions/' + sessionId);
    }

    let syncRequested = false;
    async function syncOutbox(manual = false) {
        if (syncRunning) { syncRequested = true; return; }
        syncRunning = true;
        try {
            const lockName = 'rhythm-sync-' + userId + '-' + sessionId;
            if (navigator.locks?.request) {
                await navigator.locks.request(lockName, {mode: 'exclusive', ifAvailable: true}, async (lock) => { if (lock) await performSync(manual); });
            } else if (acquireLease(lockName)) {
                try { await performSync(manual); } finally { releaseLease(lockName); }
            }
        } finally {
            syncRunning = false;
            if (syncRequested) { syncRequested = false; syncOutbox(); }
        }
    }
    function acquireLease(name) {
        const key = 'rhythm-lease-' + name, now = Date.now();
        try {
            const current = JSON.parse(localStorage.getItem(key) || 'null');
            if (current && current.expiresAt > now && current.owner !== tabId) return false;
            localStorage.setItem(key, JSON.stringify({owner: tabId, expiresAt: now + 15000}));
            return JSON.parse(localStorage.getItem(key) || 'null')?.owner === tabId;
        } catch (_) { return true; }
    }
    function releaseLease(name) {
        const key = 'rhythm-lease-' + name;
        try { if (JSON.parse(localStorage.getItem(key) || 'null')?.owner === tabId) localStorage.removeItem(key); } catch (_) {}
    }

    async function refreshSnapshot(conflictOnly) {
        try {
            const payload = await request('/api/sessions/' + sessionId, {method: 'GET'});
            sessionSnapshot = payload.data;
            versions = RhythmOffline.versionsFromSession(sessionSnapshot);
            setSessionVersion(versions.sessionVersion);
            await RhythmOffline.saveSession(userId, sessionId, sessionSnapshot, captureDraft());
            if (!conflictOnly) restoreSnapshot(sessionSnapshot);
        } catch (_) {}
    }
    function restoreSnapshot(snapshot) {
        if (!snapshot) return;
        setSessionVersion(snapshot.version);
        for (const item of snapshot.available_exercises || []) {
            const option = replacementOptions().find((option) => option.value === item.exercise_id);
            if (option) option.dataset.weightUnit = item.weight_unit || 'kg';
        }
        for (const exercise of snapshot.exercises || []) {
            const card = page.querySelector('.exercise-card[data-exercise-id="' + exercise.id + '"]');
            if (!card) continue;
            card.dataset.exerciseVersion = String(exercise.version);
            card.dataset.actualExerciseId = exercise.actual_exercise_id;
            // A refresh must not overwrite an unsaved form or a queued unit selection.
            if (!card.querySelector('.set-entry')) card.dataset.weightUnit = exercise.weight_unit || 'kg';
            card.querySelector('h2').textContent = exercise.exercise_name;
            setCardStatus(card, exercise.status);
            for (const set of exercise.sets || []) renderSet(card, set);
            const form = card.querySelector('.set-entry');
            if (form) {
                const working = (exercise.sets || []).filter((set) => set.set_type === 'working').map((set) => Number(set.set_number));
                const warmups = (exercise.sets || []).filter((set) => set.set_type === 'warmup').map((set) => Number(set.set_number));
                form.dataset.workingNext = String((working.length ? Math.max(...working) : 0) + 1);
                form.dataset.warmupNext = String((warmups.length ? Math.max(...warmups) : 0) + 1);
            }
        }
    }

    function replacementOptions() {
        return [...document.querySelector('#replace-fields').content.querySelectorAll('option')];
    }

    function setWeightUnit(card, unit) {
        card.dataset.weightUnit = unit;
        const select = card.querySelector('.weight-unit');
        if (select) select.value = unit;
        card.querySelectorAll('[data-weight-direction]').forEach((button) => {
            const delta = RhythmWeight.step(unit) * Number(button.dataset.weightDirection);
            button.dataset.delta = String(delta);
            button.textContent = (delta > 0 ? '+' : '−') + Math.abs(delta);
        });
        const planned = card.querySelector('[data-planned-weight]');
        if (planned && card.dataset.plannedKg !== '') planned.textContent = RhythmWeight.fromKg(Number(card.dataset.plannedKg), unit) + ' ' + RhythmWeight.label(unit);
    }
    page.addEventListener('change', async (event) => {
        if (!event.target.matches('.weight-unit')) return;
        const card = event.target.closest('.exercise-card');
        const before = card.dataset.weightUnit || 'kg';
        const unit = event.target.value;
        setWeightUnit(card, unit);
        try {
            await queueMutation('exercise.weight-unit', '/api/sessions/' + sessionId + '/weight-unit', 'PATCH', {
                session_version: sessionVersion, session_exercise_id: Number(card.dataset.exerciseId),
                exercise_version: Number(card.dataset.exerciseVersion), actual_exercise_id: card.dataset.actualExerciseId, weight_unit: unit,
            });
        } catch (error) {
            setWeightUnit(card, before);
            paintSync('error', 0, error.message);
        }
    });

    function captureDraft() {
        const forms = {};
        page.querySelectorAll('.exercise-card').forEach((card) => {
            const form = card.querySelector('.set-entry');
            if (form) forms[card.dataset.exerciseId] = {weight: form.querySelector('.weight-input').value, weightUnit: form.querySelector('.weight-unit').value, actualExerciseId: card.dataset.actualExerciseId, reps: form.querySelector('.reps-input').value, rir: form.querySelector('.rir-input').value, type: form.querySelector('[data-type].active')?.dataset.type || 'working', workingNext: form.dataset.workingNext, warmupNext: form.dataset.warmupNext};
        });
        return {forms, finish: {rpe: page.querySelector('#session-rpe').value, wellbeing: page.querySelector('#session-wellbeing').value, comment: page.querySelector('#session-comment').value}};
    }
    function restoreDraft(draft) {
        if (!draft) return;
        for (const [exerciseId, values] of Object.entries(draft.forms || {})) {
            const form = page.querySelector('.exercise-card[data-exercise-id="' + exerciseId + '"] .set-entry');
            if (!form) continue;
            form.querySelector('.weight-input').value = values.weight;
            setWeightUnit(form.closest('.exercise-card'), values.weightUnit || 'kg');
            if (values.actualExerciseId) form.closest('.exercise-card').dataset.actualExerciseId = values.actualExerciseId;
            form.querySelector('.reps-input').value = values.reps;
            form.querySelector('.rir-input').value = values.rir;
            form.dataset.workingNext = values.workingNext;
            form.dataset.warmupNext = values.warmupNext;
            form.querySelectorAll('[data-type]').forEach((button) => button.classList.toggle('active', button.dataset.type === values.type));
            form.querySelectorAll('[data-rir]').forEach((button) => button.classList.toggle('active', button.dataset.rir === values.rir));
        }
        if (draft.finish) {
            page.querySelector('#session-rpe').value = draft.finish.rpe;
            page.querySelector('#session-wellbeing').value = draft.finish.wellbeing;
            page.querySelector('#session-comment').value = draft.finish.comment;
        }
    }
    async function saveDraft() {
        if (!sessionSnapshot) sessionSnapshot = {id: Number(sessionId), version: sessionVersion, exercises: []};
        await RhythmOffline.saveSession(userId, sessionId, sessionSnapshot, captureDraft());
    }
    window.rhythmPersistBeforeUpdate = saveDraft;
    page.addEventListener('input', () => { clearTimeout(draftTimer); draftTimer = setTimeout(() => saveDraft().catch(() => {}), 180); });
    page.addEventListener('change', () => { clearTimeout(draftTimer); draftTimer = setTimeout(() => saveDraft().catch(() => {}), 50); });
    window.addEventListener('rhythm-before-update', () => saveDraft().catch(() => {}));

    const elapsed = page.querySelector('[data-active-seconds]');
    const updateElapsed = () => {
        const stored = Math.max(0, Number(elapsed.dataset.activeSeconds || 0));
        const segment = Math.max(0, Math.floor((Date.now() - Date.parse(elapsed.dataset.segmentStartedAt)) / 1000));
        const seconds = stored + segment;
        elapsed.textContent = String(Math.floor(seconds / 60)).padStart(2, '0') + ':' + String(seconds % 60).padStart(2, '0');
    };
    updateElapsed(); setInterval(updateElapsed, 1000);

    page.addEventListener('click', (event) => {
        const button = event.target.closest('button'), form = button?.closest('.set-entry');
        if (!button) return;
        if (button.dataset.type && form) form.querySelectorAll('[data-type]').forEach((item) => item.classList.toggle('active', item === button));
        else if (button.dataset.rir !== undefined && form) {
            form.querySelectorAll('[data-rir]').forEach((item) => item.classList.toggle('active', item === button));
            form.querySelector('.rir-input').value = button.dataset.rir;
        } else if (button.dataset.delta !== undefined && form) {
            const input = button.classList.contains('reps-delta') ? form.querySelector('.reps-input') : form.querySelector('.weight-input');
            input.value = String(Math.round(Math.min(Number(input.max || 2000), Math.max(Number(input.min || 0), Number(input.value || 0) + Number(button.dataset.delta))) * 100) / 100);
        }
    });

    page.querySelectorAll('.set-entry').forEach((form) => form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!form.reportValidity()) return;
        const rir = form.querySelector('.rir-input');
        if (rir.value === '') { rir.closest('fieldset').classList.add('invalid'); return; }
        const card = form.closest('.exercise-card'), type = form.querySelector('[data-type].active').dataset.type;
        const nextKey = type === 'working' ? 'workingNext' : 'warmupNext', submit = form.querySelector('[type="submit"]');
        submit.disabled = true;
        const setAction = await queueMutation('set.create', '/api/sessions/' + sessionId + '/sets', 'POST', {session_version: sessionVersion, session_exercise_id: Number(card.dataset.exerciseId), set_number: Number(form.dataset[nextKey]), set_type: type, weight_value: Number(form.querySelector('.weight-input').value), weight_unit: form.querySelector('.weight-unit').value, reps: Number(form.querySelector('.reps-input').value), rir: Number(rir.value)});
        rir.value = '';
        form.querySelectorAll('[data-rir]').forEach((item) => item.classList.remove('active'));
        await startTimer(Number(card.dataset.rest), setAction.id, Number(card.dataset.exerciseId));
        submit.disabled = false;
    }));

    async function queueStatus(card, status, extra = {}) {
        await queueMutation('exercise.status', '/api/sessions/' + sessionId + '/exercise-status', 'PATCH', {session_version: sessionVersion, session_exercise_id: Number(card.dataset.exerciseId), exercise_version: Number(card.dataset.exerciseVersion), status, ...extra});
    }
    const dialog = page.querySelector('#workout-action-dialog'), dialogForm = page.querySelector('#workout-action-form'), dialogFields = dialog.querySelector('[data-dialog-fields]');
    let dialogContext = null;
    const dialogTitles = {skip: 'Пропустить упражнение', complete: 'Оценить упражнение', replace: 'Заменить упражнение', discomfort: 'Записать дискомфорт', edit: 'Изменить подход'};
    dialog.querySelectorAll('[data-dialog-cancel]').forEach((button) => button.addEventListener('click', () => dialog.close()));
    function openDialog(type, card, setRow = null) {
        dialogContext = {type, card, setRow};
        dialog.querySelector('[data-dialog-title]').textContent = dialogTitles[type];
        dialogFields.replaceChildren(document.querySelector('#' + type + '-fields').content.cloneNode(true));
        dialogForm.querySelector('.form-message').hidden = true;
        if (type === 'edit') { dialogForm.elements.weight_value.value = setRow.dataset.weight; dialogForm.elements.weight_unit.value = setRow.dataset.weightUnit || 'kg'; dialogForm.elements.reps.value = setRow.dataset.reps; dialogForm.elements.rir.value = setRow.dataset.rir; }
        dialog.showModal();
    }
    page.addEventListener('click', async (event) => {
        const direct = event.target.closest('[data-status]');
        if (direct) return queueStatus(direct.closest('.exercise-card'), direct.dataset.status);
        const open = event.target.closest('[data-open-action]');
        if (open) openDialog(open.dataset.openAction, open.closest('.exercise-card'));
        const edit = event.target.closest('[data-edit-set]');
        if (edit) openDialog('edit', edit.closest('.exercise-card'), edit.closest('.saved-set'));
    });
    dialogForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!dialogForm.reportValidity() || !dialogContext) return;
        const data = Object.fromEntries(new FormData(dialogForm)), {type, card, setRow} = dialogContext, submit = dialogForm.querySelector('[type="submit"]');
        submit.disabled = true;
        try {
            if (type === 'skip' || type === 'complete') await queueStatus(card, type === 'skip' ? 'skipped' : 'completed', data);
            else if (type === 'replace') await queueMutation('exercise.replace', '/api/sessions/' + sessionId + '/replace-exercise', 'PATCH', {session_version: sessionVersion, session_exercise_id: Number(card.dataset.exerciseId), exercise_version: Number(card.dataset.exerciseVersion), ...data});
            else if (type === 'discomfort') await queueMutation('discomfort.create', '/api/sessions/' + sessionId + '/discomfort', 'POST', {session_version: sessionVersion, session_exercise_id: Number(card.dataset.exerciseId), exercise_version: Number(card.dataset.exerciseVersion), body_area: data.body_area, intensity: Number(data.intensity), comment: data.comment});
            else if (type === 'edit' && setRow.dataset.actionId && setRow.classList.contains('pending-sync')) {
                const key = 'user:' + userId + ':action:' + setRow.dataset.actionId;
                const action = await RhythmOffline.updateAction(key, (current) => {
                    if (current.status === 'syncing') throw new Error('Подход синхронизируется. Повторите сохранение через несколько секунд.');
                    if (!['set.create', 'set.update'].includes(current.type)) throw new Error('Обновите тренировку, сохранив очередь.');
                    delete current.body.weight_kg;
                    Object.assign(current.body, {weight_value: Number(data.weight_value), weight_unit: data.weight_unit, reps: Number(data.reps), rir: Number(data.rir)});
                    return current;
                });
                if (action) {
                    renderSet(card, {...action.body, id: setRow.dataset.setId, set_type: setRow.dataset.setType, set_number: setRow.querySelector('span').textContent.slice(1)}, action.id, true);
                    syncOutbox();
                } else if (setRow.dataset.setId) {
                    await queueMutation('set.update', '/api/sets/' + setRow.dataset.setId, 'PATCH', {session_version: sessionVersion, version: Number(setRow.dataset.setVersion), weight_value: Number(data.weight_value), weight_unit: data.weight_unit, reps: Number(data.reps), rir: Number(data.rir)});
                } else throw new Error('Обновите тренировку, сохранив очередь.');
            } else if (type === 'edit') await queueMutation('set.update', '/api/sets/' + setRow.dataset.setId, 'PATCH', {session_version: sessionVersion, version: Number(setRow.dataset.setVersion), weight_value: Number(data.weight_value), weight_unit: data.weight_unit, reps: Number(data.reps), rir: Number(data.rir)});
            dialog.close();
        } catch (error) {
            const message = dialogForm.querySelector('.form-message'); message.textContent = error.message; message.hidden = false;
        } finally { submit.disabled = false; }
    });

    page.querySelector('#finish-workout').addEventListener('click', async (event) => {
        const button = event.currentTarget; button.disabled = true;
        try {
            if (timerState) await finalizeTimer(Date.now(), 'workout_finished');
            await queueMutation('session.finish', '/api/sessions/' + sessionId + '/finish', 'POST', {session_version: sessionVersion, session_rpe: Number(page.querySelector('#session-rpe').value), wellbeing: Number(page.querySelector('#session-wellbeing').value), comment: page.querySelector('#session-comment').value});
            if (navigator.onLine && !(await RhythmOffline.listActions(userId, sessionId)).length) location.assign(base + '/sessions/' + sessionId);
        } catch (error) {
            paintSync('error', (await RhythmOffline.listActions(userId, sessionId)).length, error.message);
            button.disabled = false;
        }
    });
    retryButton.addEventListener('click', async () => {
        const actions = await RhythmOffline.listActions(userId, sessionId);
        const conflict = actions.find((action) => action.status === 'conflict');
        if (conflict) { await refreshSnapshot(true); showConflict(conflict); return; }
        for (const action of actions) if (action.status === 'error') { action.status = 'pending'; action.error = null; await RhythmOffline.putAction(action); }
        syncOutbox(true);
    });
    conflictBanner.querySelector('[data-conflict-refresh]').addEventListener('click', () => location.reload());
    conflictBanner.querySelector('[data-conflict-retry]').addEventListener('click', async () => {
        const conflict = (await RhythmOffline.listActions(userId, sessionId)).find((item) => item.status === 'conflict');
        if (!conflict) return;
        const rebased = RhythmOffline.rebaseAction(conflict, versions); rebased.status = 'pending'; rebased.error = null;
        if (rebased.type === 'exercise.weight-unit') {
            const current = sessionSnapshot?.exercises?.find((item) => Number(item.id) === Number(rebased.body.session_exercise_id));
            if (current) rebased.body.actual_exercise_id = current.actual_exercise_id;
        }
        await RhythmOffline.putAction(rebased); conflictBanner.hidden = true; syncOutbox(true);
    });
    window.addEventListener('online', () => syncOutbox());
    window.addEventListener('offline', async () => paintSync('pending', (await RhythmOffline.listActions(userId, sessionId)).length));
    channel?.addEventListener('message', async (event) => {
        if (event.data?.type === 'synced' && event.data.versions) { versions = event.data.versions; setSessionVersion(versions.sessionVersion); }
        const actions = await RhythmOffline.listActions(userId, sessionId);
        const conflict = actions.find((action) => action.status === 'conflict');
        if (conflict) showConflict(conflict);
        else paintSync(actions.length ? 'pending' : 'synced', actions.length);
    });

    const timer = page.querySelector('#rest-timer'), timerDisplay = timer.querySelector('strong'), timerStatus = timer.querySelector('[data-timer-status]'), timerKey = 'rhythm-rest-' + userId + '-' + sessionId;
    let timerState = null, timerInterval = null, timerEventPromise = null;
    function persistTimer() { if (timerState) localStorage.setItem(timerKey, JSON.stringify(timerState)); else localStorage.removeItem(timerKey); }
    async function startTimer(seconds, sourceSetActionId, sessionExerciseId) {
        if (timerState && !RhythmRestTimer.isTerminal(timerState)) await finalizeTimer(Date.now(), 'next_set');
        else if (timerState) await enqueueRestEvent();
        if (!Number.isInteger(seconds) || seconds < 1) { dismissTimer(); return; }
        timerState = RhythmRestTimer.create({id: RhythmOffline.uuid('rest'), duration: seconds, sourceSetActionId, sessionExerciseId});
        timer.hidden = false;
        persistTimer();
        runTimer();
    }
    function remainingSeconds() { return RhythmRestTimer.remainingSeconds(timerState); }
    function notifyTimerCompletion() {
        if (!timerState || timerState.notified) return;
        timerState = {...timerState, notified: true};
        if (navigator.vibrate) navigator.vibrate([180, 100, 180]);
        try { const context = new (window.AudioContext || window.webkitAudioContext)(), oscillator = context.createOscillator(); oscillator.connect(context.destination); oscillator.frequency.value = 740; oscillator.start(); oscillator.stop(context.currentTime + .18); } catch (_) {}
        persistTimer();
    }
    function enqueueRestEvent() {
        if (!timerState || !RhythmRestTimer.isTerminal(timerState) || timerState.eventEnqueued || !timerState.sourceSetActionId) return Promise.resolve();
        if (timerEventPromise) return timerEventPromise;
        const stateId = timerState.id;
        timerEventPromise = queueMutation('rest.finish', '/api/sessions/' + sessionId + '/rest-events', 'POST', RhythmRestTimer.eventPayload(timerState), timerState.eventActionId)
            .then(() => {
                if (timerState?.id === stateId) { timerState = {...timerState, eventEnqueued: true}; persistTimer(); }
            })
            .finally(() => { timerEventPromise = null; });
        return timerEventPromise;
    }
    async function finalizeTimer(now, trigger) {
        if (!timerState) return;
        const transition = RhythmRestTimer.finish(timerState, now, trigger);
        timerState = transition.state;
        persistTimer();
        paintTimer();
        await enqueueRestEvent();
    }
    function dismissTimer() {
        timerState = null;
        timer.hidden = true;
        timer.classList.remove('expired');
        clearInterval(timerInterval);
        persistTimer();
    }
    function paintTimer() {
        if (!timerState) return;
        const checked = RhythmRestTimer.reconcile(timerState);
        if (checked.finalized) { timerState = checked.state; persistTimer(); enqueueRestEvent().catch((error) => paintSync('error', 0, error.message)); }
        const remaining = remainingSeconds();
        timerDisplay.textContent = String(Math.floor(remaining / 60)).padStart(2, '0') + ':' + String(remaining % 60).padStart(2, '0');
        const terminal = RhythmRestTimer.isTerminal(timerState), completed = timerState.status === 'completed';
        timer.classList.toggle('expired', completed);
        timer.querySelector('[data-timer="pause"]').textContent = timerState.paused ? 'Продолжить' : 'Пауза';
        timer.querySelector('[data-timer="stop"]').textContent = terminal ? 'Закрыть' : 'Закончить раньше';
        timerStatus.textContent = completed ? 'Отдых полностью завершён' : 'Таймер продолжит считать по времени окончания';
        timer.querySelectorAll('[data-timer="pause"],[data-timer="reset"],[data-timer="add"]').forEach((button) => { button.hidden = terminal; });
        if (completed) notifyTimerCompletion();
    }
    function runTimer() { clearInterval(timerInterval); paintTimer(); timerInterval = setInterval(paintTimer, 250); }
    timer.addEventListener('click', async (event) => {
        const action = event.target.closest('[data-timer]')?.dataset.timer;
        if (!action || !timerState) return;
        try {
            let transition = null;
            if (action === 'pause') transition = timerState.paused ? RhythmRestTimer.resume(timerState) : RhythmRestTimer.pause(timerState);
            else if (action === 'reset') transition = RhythmRestTimer.reset(timerState);
            else if (action === 'add') transition = RhythmRestTimer.add(timerState, 30);
            else if (action === 'stop' && RhythmRestTimer.isTerminal(timerState)) { await enqueueRestEvent(); dismissTimer(); return; }
            else if (action === 'stop') { await finalizeTimer(Date.now(), 'user'); dismissTimer(); return; }
            if (transition) timerState = transition.state;
            persistTimer(); paintTimer();
            if (transition?.finalized) await enqueueRestEvent();
        } catch (error) { paintSync('error', 0, error.message); }
    });
    try { timerState = RhythmRestTimer.restore(JSON.parse(localStorage.getItem(timerKey))); } catch (_) { timerState = null; }
    if (timerState) { timer.hidden = false; runTimer(); if (RhythmRestTimer.isTerminal(timerState)) enqueueRestEvent().catch(() => {}); }
    else localStorage.removeItem(timerKey);

    (async function initialise() {
        const local = await RhythmOffline.getSession(userId, sessionId).catch(() => null);
        if (local?.snapshot) { sessionSnapshot = local.snapshot; restoreSnapshot(local.snapshot); }
        restoreDraft(local?.draft);
        const actions = await RhythmOffline.listActions(userId, sessionId);
        actions.forEach(applyOptimistic);
        paintSync(actions.length ? 'pending' : 'synced', actions.length);
        const conflict = actions.find((item) => item.status === 'conflict');
        if (conflict) { if (navigator.onLine) await refreshSnapshot(true); showConflict(conflict); }
        else if (navigator.onLine) { if (!actions.length) await refreshSnapshot(false); syncOutbox(); }
    })().catch((error) => paintSync('error', 0, error.message));
})();
