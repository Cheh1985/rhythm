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
            response = await fetchImpl(url.toString(), {
                method: 'GET',
                credentials: 'same-origin',
                cache: 'no-store',
                redirect: 'error',
                referrerPolicy: 'same-origin',
                headers: {'Accept': 'application/json'},
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
                default:
                    return errorResult('unknown_tool', 'Неизвестный read-only tool.');
            }
        } catch (error) {
            if (error && error.name === 'AbortError') throw error;
            if (error instanceof InputError) return errorResult('validation_error', error.message);
            return errorResult('adapter_error', 'Не удалось выполнить read-only tool.');
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

    function registerTools(options = {}) {
        const doc = options.document || (typeof document !== 'undefined' ? document : null);
        const win = options.window || (typeof window !== 'undefined' ? window : null);
        const catalog = options.catalog || parseCatalog(doc);
        if (!Array.isArray(catalog) || catalog.length === 0) {
            updateStatus(doc, 'disabled', 'Сервер не опубликовал read-only tools.');
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
                execute(input, execution = {}) {
                    return executeTool(metadata.name, input, {
                        fetchImpl: options.fetchImpl,
                        locationHref,
                        signal: execution.signal,
                    });
                },
            };
            try {
                return Promise.resolve(context.registerTool(definition, {signal: controller.signal}));
            } catch (error) {
                return Promise.reject(error);
            }
        });

        updateStatus(doc, 'checking', 'Регистрируем read-only Site tools…');
        const ready = Promise.allSettled(registrations).then((results) => {
            const failed = results.filter((result) => result.status === 'rejected').length;
            if (failed > 0) {
                abort();
                updateStatus(doc, 'error', 'Не удалось зарегистрировать Site tools. Перезагрузите страницу или выключите feature flag.');
            } else {
                updateStatus(doc, 'ready', 'Готово: зарегистрировано ' + results.length + ' read-only tools для этой страницы.');
            }
            return results;
        });

        return {status: 'registering', controller, ready, abort};
    }

    return {registerTools, executeTool, requestJson};
});
