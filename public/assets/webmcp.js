(function (root, factory) {
    const api = factory();
    if (typeof module === 'object' && module.exports) module.exports = api;
    root.RhythmWebMcp = api;

    if (typeof document !== 'undefined') {
        const start = () => {
            if (document.getElementById('webmcp-tool-catalog')) api.registerTools();
        };
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, {once: true});
        else start();
    }
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
    'use strict';

    class InputError extends Error {}

    function errorResult(code, message, details = {}) {
        return {ok: false, error: {code, message, ...details}};
    }

    function successResult(data, meta = {}) {
        return {ok: true, data, meta};
    }

    function objectInput(input) {
        if (input === undefined || input === null) return {};
        if (typeof input !== 'object' || Array.isArray(input)) throw new InputError('Аргументы tool должны быть объектом.');
        return input;
    }

    function onlyKeys(input, allowed) {
        const extra = Object.keys(input).filter((key) => !allowed.includes(key));
        if (extra.length) throw new InputError('Недопустимые аргументы: ' + extra.join(', ') + '.');
    }

    function identifier(input, key, required = true) {
        const value = input[key];
        if ((value === undefined || value === null || value === '') && !required) return null;
        if (typeof value !== 'string' || !/^[A-Za-z0-9][A-Za-z0-9._:-]{0,79}$/.test(value)) {
            throw new InputError(key + ' должен быть стабильным идентификатором.');
        }
        return value;
    }

    function stringValue(input, key, required = false) {
        const value = input[key];
        if ((value === undefined || value === null) && !required) return null;
        if (typeof value !== 'string' || (required && value.length === 0)) throw new InputError(key + ' должен быть строкой.');
        return value;
    }

    function integerValue(input, key, maximum) {
        const value = input[key];
        if (value === undefined || value === null) return null;
        if (!Number.isInteger(value) || value < 1 || (maximum && value > maximum)) {
            throw new InputError(key + ' должен быть положительным целым числом' + (maximum ? ' не больше ' + maximum : '') + '.');
        }
        return value;
    }

    function requiredInteger(input, key, maximum) {
        const value = integerValue(input, key, maximum);
        if (value === null) throw new InputError(key + ' обязателен.');
        return value;
    }

    function objectValue(input, key) {
        const value = input[key];
        if (!value || typeof value !== 'object' || Array.isArray(value)) throw new InputError(key + ' должен быть объектом.');
        return value;
    }

    function enumValue(input, key, allowed) {
        const value = stringValue(input, key, true);
        if (!allowed.includes(value)) throw new InputError(key + ' содержит неподдерживаемое значение.');
        return value;
    }

    function dateValue(input, key) {
        const value = stringValue(input, key, true);
        if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) throw new InputError(key + ' должен быть датой YYYY-MM-DD.');
        return value;
    }

    function actionId(input) {
        const value = stringValue(input, 'client_action_id', true);
        if (!/^[A-Za-z0-9][A-Za-z0-9._:-]{7,79}$/.test(value)) throw new InputError('Некорректный client_action_id.');
        return value;
    }

    function aggregateHash(input) {
        const value = stringValue(input, 'aggregate_hash', true);
        if (!/^[a-f0-9]{64}$/.test(value)) throw new InputError('aggregate_hash должен быть lowercase SHA-256.');
        return value;
    }

    function queryPath(base, input, fields) {
        const parameters = new URLSearchParams();
        for (const field of fields) {
            const value = input[field];
            if (value !== undefined && value !== null && value !== '') parameters.set(field, String(value));
        }
        const query = parameters.toString();
        return query ? base + '?' + query : base;
    }

    function normalizeApiError(payload, status) {
        const error = payload && typeof payload.error === 'object' && payload.error !== null ? payload.error : {};
        return errorResult(
            typeof error.code === 'string' ? error.code : 'http_error',
            typeof error.message === 'string' ? error.message : 'API вернул ошибку.',
            {
                status,
                request_id: typeof error.request_id === 'string' ? error.request_id : undefined,
                details: error.details && typeof error.details === 'object' ? error.details : undefined,
            }
        );
    }

    async function requestJson(path, options = {}) {
        const fetchImpl = options.fetchImpl || (typeof fetch === 'function' ? fetch.bind(globalThis) : null);
        const locationHref = options.locationHref || (typeof location !== 'undefined' ? location.href : null);
        if (!fetchImpl || !locationHref) return errorResult('network_unavailable', 'Fetch или текущий origin недоступны.');

        let url;
        try {
            url = new URL(path, locationHref);
            if (url.origin !== new URL(locationHref).origin) {
                return errorResult('origin_mismatch', 'Tool отказался обращаться к другому origin.');
            }
        } catch (_) {
            return errorResult('invalid_endpoint', 'Некорректный same-origin endpoint.');
        }

        let response;
        try {
            const method = options.method || 'GET';
            const headers = {'Accept': 'application/json', ...(options.headers || {})};
            response = await fetchImpl(url.toString(), {
                method,
                credentials: 'same-origin',
                cache: 'no-store',
                redirect: 'error',
                referrerPolicy: 'same-origin',
                headers,
                body: options.body === undefined ? undefined : JSON.stringify(options.body),
                signal: options.signal,
            });
        } catch (error) {
            if (error && error.name === 'AbortError') throw error;
            return errorResult('network_error', 'Не удалось связаться с тренировочным API.');
        }

        let payload;
        try {
            payload = await response.json();
        } catch (_) {
            return errorResult('invalid_response', 'API вернул некорректный JSON.', {status: response.status});
        }
        if (!response.ok) return normalizeApiError(payload, response.status);
        if (!payload || typeof payload !== 'object' || !Object.prototype.hasOwnProperty.call(payload, 'data')) {
            return errorResult('invalid_response', 'API response не содержит data.', {status: response.status});
        }
        return successResult(payload.data, payload.meta && typeof payload.meta === 'object' ? payload.meta : {});
    }

    function csrfToken(options = {}) {
        if (typeof options.csrfToken === 'string' && options.csrfToken) return options.csrfToken;
        const doc = options.document || (typeof document !== 'undefined' ? document : null);
        const node = doc && doc.querySelector ? doc.querySelector('meta[name="csrf-token"]') : null;
        return node && typeof node.content === 'string' ? node.content : '';
    }

    function writeJson(path, body, idempotencyKey, options = {}) {
        const token = csrfToken(options);
        if (!token) return Promise.resolve(errorResult('csrf_unavailable', 'CSRF token недоступен. Обновите страницу.'));
        const headers = {
            'Content-Type': 'application/json',
            'X-CSRF-Token': token,
        };
        if (idempotencyKey) headers['Idempotency-Key'] = idempotencyKey;
        return requestJson(path, {...options, method: options.method || 'POST', headers, body});
    }

    async function currentPlan(options) {
        const programs = await requestJson('/api/assistant/plans', options);
        if (!programs.ok) return programs;
        const items = Array.isArray(programs.data && programs.data.items) ? programs.data.items : [];
        const active = items.filter((item) => item && item.status === 'active');
        const resolved = active.filter((item) => item.active_version_state === 'resolved' && Number.isInteger(item.current_version));

        if (resolved.length === 1) {
            const plan = await requestJson('/api/assistant/plans/' + encodeURIComponent(resolved[0].program_id), options);
            if (!plan.ok) return plan;
            return successResult({state: 'resolved', program: plan.data}, plan.meta);
        }
        if (resolved.length > 1) {
            return successResult({state: 'multiple_active_programs', programs: resolved}, programs.meta);
        }
        if (active.length > 0) {
            return successResult({state: 'unresolved_active_program', programs: active}, programs.meta);
        }
        return successResult({state: 'no_active_program', programs: []}, programs.meta);
    }

    let activationDialogPending = false;

    function abortedError() {
        const error = new Error('Tool execution cancelled.');
        error.name = 'AbortError';
        return error;
    }

    function requestActivationDecision(prepared, options = {}) {
        if (typeof options.confirmActivation === 'function') {
            return Promise.resolve(options.confirmActivation(prepared, {signal: options.signal}));
        }
        const doc = options.document || (typeof document !== 'undefined' ? document : null);
        const dialog = doc && doc.getElementById ? doc.getElementById('webmcp-activation-dialog') : null;
        if (!dialog || typeof dialog.showModal !== 'function') {
            throw new InputError('Браузер не может показать обязательное подтверждение activation.');
        }
        if (activationDialogPending || dialog.open) {
            throw new InputError('Другое подтверждение activation уже открыто.');
        }

        const preview = prepared && prepared.preview && typeof prepared.preview === 'object' ? prepared.preview : {};
        const draft = preview.draft && typeof preview.draft === 'object' ? preview.draft : {};
        const windowData = preview.window && typeof preview.window === 'object' ? preview.window : {};
        const plans = preview.future_plans && typeof preview.future_plans === 'object' ? preview.future_plans : {};
        const programs = preview.programs && typeof preview.programs === 'object' ? preview.programs : {};
        const setText = (selector, value) => {
            const node = dialog.querySelector(selector);
            if (node) node.textContent = String(value === undefined || value === null ? '—' : value);
        };
        const count = (key) => Array.isArray(plans[key]) ? plans[key].length : 0;
        setText('[data-activation-program]', draft.program_name);
        setText('[data-activation-version]', draft.version);
        setText('[data-activation-window]', (windowData.effective_from || '—') + ' — ' + (windowData.effective_to || '—'));
        setText('[data-activation-policy]', windowData.future_plan_policy);
        setText('[data-activation-created]', count('created'));
        setText('[data-activation-superseded]', count('superseded'));
        setText('[data-activation-kept]', count('kept'));
        setText('[data-activation-protected]', count('protected'));
        setText('[data-activation-blocked]', count('blocked_materialization'));
        setText('[data-activation-paused]', programs.will_pause_count || 0);
        setText('[data-activation-expiry]', prepared.expires_at_utc);

        const form = dialog.querySelector('[data-activation-form]');
        const cancelButtons = Array.from(dialog.querySelectorAll('[data-activation-cancel]'));
        if (!form || cancelButtons.length === 0) throw new InputError('Форма подтверждения activation недоступна.');

        activationDialogPending = true;
        return new Promise((resolve, reject) => {
            let finished = false;
            const finish = (decision, error) => {
                if (finished) return;
                finished = true;
                activationDialogPending = false;
                form.removeEventListener('submit', submit);
                dialog.removeEventListener('cancel', cancelEvent);
                for (const button of cancelButtons) button.removeEventListener('click', cancelClick);
                if (options.signal) options.signal.removeEventListener('abort', abort);
                if (dialog.open && typeof dialog.close === 'function') dialog.close();
                if (error) reject(error); else resolve(decision);
            };
            const submit = (event) => { event.preventDefault(); finish('confirm'); };
            const cancelEvent = (event) => { event.preventDefault(); finish('cancel'); };
            const cancelClick = () => finish('cancel');
            const abort = () => finish(null, abortedError());
            form.addEventListener('submit', submit);
            dialog.addEventListener('cancel', cancelEvent);
            for (const button of cancelButtons) button.addEventListener('click', cancelClick);
            if (options.signal) options.signal.addEventListener('abort', abort, {once: true});
            if (options.signal && options.signal.aborted) {
                abort();
                return;
            }
            dialog.showModal();
        });
    }

    async function executeTool(name, rawInput, options = {}) {
        try {
            const input = objectInput(rawInput);
            switch (name) {
                case 'training.get_profile':
                    onlyKeys(input, []);
                    return requestJson('/api/assistant/profile', options);
                case 'training.get_current_plan':
                    onlyKeys(input, []);
                    return currentPlan(options);
                case 'training.get_plan': {
                    onlyKeys(input, ['program_id', 'version']);
                    const programId = identifier(input, 'program_id');
                    const version = integerValue(input, 'version');
                    const path = version === null
                        ? '/api/assistant/plans/' + encodeURIComponent(programId)
                        : '/api/assistant/plans/' + encodeURIComponent(programId) + '/versions/' + version;
                    return requestJson(path, options);
                }
                case 'training.list_plan_versions': {
                    onlyKeys(input, ['program_id']);
                    const programId = identifier(input, 'program_id');
                    return requestJson('/api/assistant/plans/' + encodeURIComponent(programId) + '/versions', options);
                }
                case 'training.list_workouts':
                    onlyKeys(input, ['from', 'to', 'type', 'status', 'limit', 'cursor']);
                    stringValue(input, 'from'); stringValue(input, 'to'); stringValue(input, 'type'); stringValue(input, 'status'); stringValue(input, 'cursor');
                    integerValue(input, 'limit', 50);
                    return requestJson(queryPath('/api/assistant/workouts', input, ['from', 'to', 'type', 'status', 'limit', 'cursor']), options);
                case 'training.get_workout': {
                    onlyKeys(input, ['workout_id', 'session_id']);
                    const workoutId = identifier(input, 'workout_id', false);
                    const sessionId = identifier(input, 'session_id', false);
                    if (!workoutId && !sessionId) throw new InputError('Нужен workout_id, session_id или оба идентификатора.');
                    const data = {};
                    const meta = {};
                    if (workoutId) {
                        const planned = await requestJson('/api/assistant/workouts/' + encodeURIComponent(workoutId), options);
                        if (!planned.ok) return planned;
                        data.planned = planned.data;
                        meta.planned_request_id = planned.meta.request_id;
                    }
                    if (sessionId) {
                        const fact = await requestJson('/api/assistant/sessions/' + encodeURIComponent(sessionId), options);
                        if (!fact.ok) return fact;
                        data.fact = fact.data;
                        meta.fact_request_id = fact.meta.request_id;
                    }
                    return successResult(data, meta);
                }
                case 'training.get_exercise_history': {
                    onlyKeys(input, ['exercise_id', 'from', 'to', 'limit', 'cursor']);
                    const exerciseId = identifier(input, 'exercise_id');
                    stringValue(input, 'from'); stringValue(input, 'to'); stringValue(input, 'cursor'); integerValue(input, 'limit', 50);
                    const base = '/api/assistant/exercises/' + encodeURIComponent(exerciseId) + '/history';
                    return requestJson(queryPath(base, input, ['from', 'to', 'limit', 'cursor']), options);
                }
                case 'training.get_progress_summary':
                    onlyKeys(input, ['from', 'to']);
                    stringValue(input, 'from'); stringValue(input, 'to');
                    return requestJson(queryPath('/api/assistant/progress', input, ['from', 'to']), options);
                case 'training.get_scheduled_workout': {
                    onlyKeys(input, ['date']);
                    const date = stringValue(input, 'date', true);
                    return requestJson('/api/assistant/schedule/' + encodeURIComponent(date), options);
                }
                case 'training.search_exercises': {
                    onlyKeys(input, ['query', 'limit', 'cursor']);
                    const query = stringValue(input, 'query', true);
                    if (query.length > 120) throw new InputError('query не должен превышать 120 символов.');
                    stringValue(input, 'cursor'); integerValue(input, 'limit', 30);
                    return requestJson(queryPath('/api/assistant/exercises/search', input, ['query', 'limit', 'cursor']), options);
                }
                case 'training.find_alternatives': {
                    onlyKeys(input, ['exercise_id', 'limit']);
                    const exerciseId = identifier(input, 'exercise_id');
                    integerValue(input, 'limit', 20);
                    const base = '/api/assistant/exercises/' + encodeURIComponent(exerciseId) + '/alternatives';
                    return requestJson(queryPath(base, input, ['limit']), options);
                }
                case 'training.create_plan_draft': {
                    onlyKeys(input, ['mode', 'metadata', 'program_id', 'source_version', 'reason', 'client_action_id']);
                    const mode = enumValue(input, 'mode', ['new', 'clone']);
                    const reason = stringValue(input, 'reason', true);
                    if (reason.length > 1000) throw new InputError('reason не должен превышать 1000 символов.');
                    const key = actionId(input);
                    let body;
                    if (mode === 'new') {
                        if (input.program_id !== undefined || input.source_version !== undefined) throw new InputError('program_id и source_version доступны только в mode=clone.');
                        body = {mode, metadata: objectValue(input, 'metadata'), reason};
                    } else {
                        if (input.metadata !== undefined) throw new InputError('metadata доступен только в mode=new.');
                        body = {mode, program_id: identifier(input, 'program_id'), reason};
                        const sourceVersion = integerValue(input, 'source_version', 100000);
                        if (sourceVersion !== null) body.source_version = sourceVersion;
                    }
                    return writeJson('/api/assistant/program-drafts', body, key, options);
                }
                case 'training.update_plan_draft': {
                    onlyKeys(input, ['draft_id', 'lock_version', 'operation', 'payload', 'client_action_id']);
                    const draftId = requiredInteger(input, 'draft_id');
                    const lockVersion = requiredInteger(input, 'lock_version', 1000000);
                    const operation = enumValue(input, 'operation', [
                        'set_program_metadata', 'upsert_template', 'remove_template', 'upsert_exercise',
                        'remove_exercise', 'set_schedule_slot', 'remove_schedule_slot',
                    ]);
                    const body = {lock_version: lockVersion, operation, payload: objectValue(input, 'payload')};
                    return writeJson('/api/assistant/program-drafts/' + draftId, body, actionId(input), {...options, method: 'PATCH'});
                }
                case 'training.reschedule_workout': {
                    onlyKeys(input, ['instance_id', 'scope', 'scheduled_date', 'instance_version', 'client_action_id']);
                    const instanceId = identifier(input, 'instance_id');
                    const body = {
                        scope: enumValue(input, 'scope', ['scheduled_instance']),
                        scheduled_date: dateValue(input, 'scheduled_date'),
                        instance_version: requiredInteger(input, 'instance_version', 1000000),
                        client_action_id: actionId(input),
                    };
                    return writeJson('/api/assistant/workout-instances/' + encodeURIComponent(instanceId) + '/reschedule', body, body.client_action_id, {...options, method: 'PATCH'});
                }
                case 'training.replace_exercise': {
                    onlyKeys(input, [
                        'instance_id', 'scope', 'exercise_sequence', 'replacement_exercise_id', 'reason',
                        'instance_version', 'exercise_version', 'client_action_id',
                    ]);
                    const instanceId = identifier(input, 'instance_id');
                    const reason = stringValue(input, 'reason', true);
                    if (reason.length > 1000) throw new InputError('reason не должен превышать 1000 символов.');
                    const body = {
                        scope: enumValue(input, 'scope', ['scheduled_instance', 'active_session']),
                        exercise_sequence: requiredInteger(input, 'exercise_sequence', 1000),
                        replacement_exercise_id: identifier(input, 'replacement_exercise_id'),
                        reason,
                        instance_version: requiredInteger(input, 'instance_version', 1000000),
                        exercise_version: requiredInteger(input, 'exercise_version', 1000000),
                        client_action_id: actionId(input),
                    };
                    return writeJson('/api/assistant/workout-instances/' + encodeURIComponent(instanceId) + '/replace-exercise', body, body.client_action_id, {...options, method: 'PATCH'});
                }
                case 'training.activate_plan': {
                    onlyKeys(input, ['draft_id', 'lock_version', 'aggregate_hash', 'effective_from', 'horizon_weeks', 'future_plan_policy']);
                    const draftId = requiredInteger(input, 'draft_id');
                    const body = {
                        lock_version: requiredInteger(input, 'lock_version', 1000000),
                        aggregate_hash: aggregateHash(input),
                        effective_from: dateValue(input, 'effective_from'),
                        horizon_weeks: requiredInteger(input, 'horizon_weeks', 12),
                        future_plan_policy: enumValue(input, 'future_plan_policy', ['keep', 'supersede']),
                    };
                    const base = '/api/assistant/program-drafts/' + draftId + '/activation/';
                    const prepared = await writeJson(base + 'prepare', body, null, options);
                    if (!prepared.ok) return prepared;
                    const decision = await requestActivationDecision(prepared.data, options);
                    const confirmationBody = {confirmation_token: prepared.data.confirmation_token};
                    if (decision !== 'confirm') {
                        const cancelled = await writeJson(base + 'cancel', confirmationBody, null, options);
                        return errorResult('USER_CANCELLED', 'Пользователь отменил activation. Программа не изменена.', {
                            mutated: false,
                            request_id: cancelled.meta && cancelled.meta.request_id,
                            cancellation_sync: cancelled.ok,
                            cancellation_error: cancelled.ok ? undefined : cancelled.error,
                        });
                    }
                    return writeJson(base + 'confirm', confirmationBody, null, options);
                }
                default:
                    return errorResult('unknown_tool', 'Неизвестный Site tool.');
            }
        } catch (error) {
            if (error && error.name === 'AbortError') throw error;
            if (error instanceof InputError) return errorResult('validation_error', error.message);
            return errorResult('adapter_error', 'Не удалось выполнить Site tool.');
        }
    }

    function parseCatalog(doc) {
        const node = doc && doc.getElementById ? doc.getElementById('webmcp-tool-catalog') : null;
        if (!node) return [];
        try {
            const catalog = JSON.parse(node.textContent || '[]');
            return Array.isArray(catalog) ? catalog : [];
        } catch (_) {
            return [];
        }
    }

    function updateStatus(doc, state, message) {
        const node = doc && doc.getElementById ? doc.getElementById('webmcp-capability') : null;
        if (!node) return;
        node.dataset.state = state;
        node.textContent = message;
    }

    function updateOperationStatus(doc, name, result) {
        const node = doc && doc.getElementById ? doc.getElementById('webmcp-operation-status') : null;
        if (!node) return;
        node.hidden = false;
        if (result && result.ok) {
            node.dataset.state = 'ready';
            node.textContent = name === 'training.activate_plan' ? 'Программа активирована после ручного подтверждения.' : 'Site tool выполнил изменение.';
            return;
        }
        node.dataset.state = 'error';
        node.textContent = result && result.error && result.error.code === 'USER_CANCELLED'
            ? 'Activation отменена пользователем; данные не изменены.'
            : 'Site tool не выполнил изменение.';
    }

    function registerTools(options = {}) {
        const doc = options.document || (typeof document !== 'undefined' ? document : null);
        const win = options.window || (typeof window !== 'undefined' ? window : null);
        const catalog = options.catalog || parseCatalog(doc);
        if (!Array.isArray(catalog) || catalog.length === 0) {
            updateStatus(doc, 'disabled', 'Сервер не опубликовал Site tools.');
            return {status: 'disabled', controller: null, ready: Promise.resolve([]), abort() {}};
        }

        const context = doc && doc.modelContext;
        if (!context || typeof context.registerTool !== 'function') {
            updateStatus(doc, 'unsupported', 'Браузер не поддерживает document.modelContext; обычное приложение продолжает работать.');
            return {status: 'unsupported', controller: null, ready: Promise.resolve([]), abort() {}};
        }

        const controller = new AbortController();
        let cleaned = false;
        const abort = () => {
            if (cleaned) return;
            cleaned = true;
            controller.abort();
            if (win && typeof win.removeEventListener === 'function') {
                win.removeEventListener('pagehide', abort);
                win.removeEventListener('beforeunload', abort);
            }
        };
        if (win && typeof win.addEventListener === 'function') {
            win.addEventListener('pagehide', abort, {once: true});
            win.addEventListener('beforeunload', abort, {once: true});
        }

        const locationHref = options.locationHref || (win && win.location ? win.location.href : undefined);
        const registrations = catalog.map((metadata) => {
            const definition = {
                name: metadata.name,
                title: metadata.title,
                description: metadata.description,
                inputSchema: metadata.inputSchema,
                annotations: metadata.annotations,
                async execute(input, execution = {}) {
                    const result = await executeTool(metadata.name, input, {
                        fetchImpl: options.fetchImpl,
                        locationHref,
                        signal: execution.signal,
                        document: doc,
                        csrfToken: options.csrfToken,
                        confirmActivation: options.confirmActivation,
                    });
                    if (metadata.annotations && metadata.annotations.readOnlyHint === false) {
                        updateOperationStatus(doc, metadata.name, result);
                    }
                    return result;
                },
            };
            try {
                return Promise.resolve(context.registerTool(definition, {signal: controller.signal}));
            } catch (error) {
                return Promise.reject(error);
            }
        });

        updateStatus(doc, 'checking', 'Регистрируем Site tools…');
        const ready = Promise.allSettled(registrations).then((results) => {
            const failed = results.filter((result) => result.status === 'rejected').length;
            if (failed > 0) {
                abort();
                updateStatus(doc, 'error', 'Не удалось зарегистрировать Site tools. Перезагрузите страницу или выключите feature flag.');
            } else {
                updateStatus(doc, 'ready', 'Готово: зарегистрировано ' + results.length + ' Site tools для этой страницы.');
            }
            return results;
        });

        return {status: 'registering', controller, ready, abort};
    }

    return {registerTools, executeTool, requestJson};
});
