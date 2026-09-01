'use strict';

const {execFileSync} = require('node:child_process');
const path = require('node:path');
const adapter = require('../public/assets/webmcp.js');

let checks = 0;
const check = (condition, label) => {
    checks += 1;
    if (!condition) throw new Error(label);
};
const root = path.resolve(__dirname, '..');
const catalog = JSON.parse(execFileSync(process.env.PHP_BINARY || 'php', ['tests/stage17-webmcp-writes.php', '--catalog'], {
    cwd: root,
    encoding: 'utf8',
}));

const response = (status, payload) => ({
    ok: status >= 200 && status < 300,
    status,
    async json() { return payload; },
});
const options = (fetchImpl, extra = {}) => ({
    locationHref: 'https://rhythm.example/assistant',
    csrfToken: 'csrf-stage17',
    fetchImpl,
    ...extra,
});
const actionId = 'stage17-action-0001';

(async () => {
    const requests = [];
    const fetchSuccess = async (url, request) => {
        requests.push({url, request});
        return response(200, {data: {draft_id: 41, lock_version: 1}, meta: {request_id: 'write-success'}});
    };

    const created = await adapter.executeTool('training.create_plan_draft', {
        mode: 'new', metadata: {program_id: 'stage17', name: 'Stage 17'}, reason: 'Create', client_action_id: actionId,
    }, options(fetchSuccess));
    check(created.ok && created.data.draft_id === 41, 'create_plan_draft возвращает structured success');
    const createRequest = requests.pop();
    const createBody = JSON.parse(createRequest.request.body);
    check(createRequest.request.method === 'POST' && createRequest.request.credentials === 'same-origin' && createRequest.request.cache === 'no-store', 'write использует прямой no-store same-origin fetch');
    check(createRequest.request.headers['X-CSRF-Token'] === 'csrf-stage17' && createRequest.request.headers['Idempotency-Key'] === actionId, 'write передаёт CSRF и idempotency headers');
    check(!Object.hasOwn(createBody, 'client_action_id') && createBody.metadata.program_id === 'stage17', 'draft action ID не попадает в domain body');

    await adapter.executeTool('training.update_plan_draft', {
        draft_id: 41, lock_version: 1, operation: 'set_program_metadata', payload: {description: 'Updated'}, client_action_id: 'stage17-update-0001',
    }, options(fetchSuccess));
    const updateRequest = requests.pop();
    check(updateRequest.request.method === 'PATCH' && updateRequest.url.endsWith('/api/assistant/program-drafts/41'), 'update_plan_draft вызывает узкий PATCH endpoint');

    await adapter.executeTool('training.reschedule_workout', {
        instance_id: 'workout-1', scope: 'scheduled_instance', scheduled_date: '2026-09-01', instance_version: 2, client_action_id: 'stage17-reschedule-0001',
    }, options(fetchSuccess));
    const rescheduleRequest = requests.pop();
    check(JSON.parse(rescheduleRequest.request.body).client_action_id === 'stage17-reschedule-0001' && rescheduleRequest.request.headers['Idempotency-Key'] === 'stage17-reschedule-0001', 'instance body и header используют один action ID');
    await adapter.executeTool('training.reschedule_workout', {
        instance_id: 'workout-1', scope: 'scheduled_instance', scheduled_date: '2026-09-01', instance_version: 2, client_action_id: 'stage17-reschedule-0001',
    }, options(fetchSuccess));
    const duplicateRequest = requests.pop();
    check(duplicateRequest.request.body === rescheduleRequest.request.body && duplicateRequest.request.headers['Idempotency-Key'] === rescheduleRequest.request.headers['Idempotency-Key'], 'duplicate call сохраняет тот же payload-bound idempotency key');

    await adapter.executeTool('training.replace_exercise', {
        instance_id: 'session-1', scope: 'active_session', exercise_sequence: 1, replacement_exercise_id: 'row', reason: 'Занято',
        instance_version: 3, exercise_version: 2, client_action_id: 'stage17-replace-0001',
    }, options(fetchSuccess));
    check(requests.pop().url.endsWith('/api/assistant/workout-instances/session-1/replace-exercise'), 'replace_exercise вызывает instance-only endpoint');

    for (const status of [401, 404, 409, 419, 422, 429]) {
        const result = await adapter.executeTool('training.reschedule_workout', {
            instance_id: 'workout-1', scope: 'scheduled_instance', scheduled_date: '2026-09-01', instance_version: 2, client_action_id: 'stage17-errors-0001',
        }, options(async () => response(status, {error: {code: 'status_' + status, message: 'Expected', request_id: 'error-' + status}})));
        check(!result.ok && result.error.status === status && result.error.code === 'status_' + status, 'HTTP ' + status + ' остаётся structured error');
    }

    const invalid = await adapter.executeTool('training.update_plan_draft', {
        draft_id: 41, lock_version: 1, operation: 'set_program_metadata', payload: {}, client_action_id: actionId, user_id: 7,
    }, options(async () => { throw new Error('fetch не должен вызываться'); }));
    check(!invalid.ok && invalid.error.code === 'validation_error', 'write adapter отклоняет model-supplied user_id');

    const preview = {
        confirmation_token: 'a'.repeat(64), expires_at_utc: '2026-08-31T12:00:00Z',
        preview: {
            draft: {program_name: 'Plan', version: 2},
            window: {effective_from: '2026-09-01', effective_to: '2026-09-28', future_plan_policy: 'supersede'},
            future_plans: {created: [{}], superseded: [{}], kept: [], protected: [], blocked_materialization: []},
            programs: {will_pause_count: 1},
        },
    };
    const activationInput = {
        draft_id: 41, lock_version: 3, aggregate_hash: 'b'.repeat(64), effective_from: '2026-09-01', horizon_weeks: 4, future_plan_policy: 'supersede',
    };

    const cancelledCalls = [];
    const cancelled = await adapter.executeTool('training.activate_plan', activationInput, options(async (url) => {
        cancelledCalls.push(url);
        if (url.endsWith('/prepare')) return response(202, {data: preview, meta: {request_id: 'prepare-cancel'}});
        if (url.endsWith('/cancel')) return response(200, {data: {code: 'USER_CANCELLED', mutated: false}, meta: {request_id: 'cancelled'}});
        throw new Error('confirm не должен вызываться');
    }, {confirmActivation: async () => 'cancel'}));
    check(!cancelled.ok && cancelled.error.code === 'USER_CANCELLED' && cancelled.error.mutated === false, 'modal cancel возвращает structured USER_CANCELLED');
    check(cancelledCalls.some((url) => url.endsWith('/cancel')) && !cancelledCalls.some((url) => url.endsWith('/confirm')), 'cancel потребляет token без activation');

    let decide;
    const confirmCalls = [];
    const pending = adapter.executeTool('training.activate_plan', activationInput, options(async (url) => {
        confirmCalls.push(url);
        if (url.endsWith('/prepare')) return response(202, {data: preview, meta: {request_id: 'prepare-confirm'}});
        if (url.endsWith('/confirm')) return response(200, {data: {lifecycle_status: 'published'}, meta: {request_id: 'confirmed'}});
        throw new Error('unexpected activation route');
    }, {confirmActivation: () => new Promise((resolve) => { decide = resolve; })}));
    await new Promise((resolve) => setImmediate(resolve));
    check(confirmCalls.length === 1 && confirmCalls[0].endsWith('/prepare'), 'execute остаётся pending и не активирует до ручного confirm');
    decide('confirm');
    const confirmed = await pending;
    check(confirmed.ok && confirmed.data.lifecycle_status === 'published' && confirmCalls[1].endsWith('/confirm'), 'ручной confirm завершает activation');

    const stale = await adapter.executeTool('training.activate_plan', activationInput, options(async (url) => {
        if (url.endsWith('/prepare')) return response(202, {data: preview});
        return response(409, {error: {code: 'version_conflict', message: 'Stale confirmation', request_id: 'stale'}});
    }, {confirmActivation: async () => 'confirm'}));
    check(!stale.ok && stale.error.code === 'version_conflict' && stale.error.status === 409, 'stale confirmation возвращает structured conflict');

    let interactionStarted = false;
    const offline = await adapter.executeTool('training.activate_plan', activationInput, options(async () => { throw new TypeError('offline'); }, {
        confirmActivation: async () => { interactionStarted = true; return 'confirm'; },
    }));
    check(!offline.ok && offline.error.code === 'network_error' && !interactionStarted, 'offline prepare не открывает modal и не активирует draft');

    const abortController = new AbortController();
    const aborted = adapter.executeTool('training.activate_plan', activationInput, options(async (url) => {
        if (url.endsWith('/prepare')) return response(202, {data: preview});
        throw new Error('после abort mutation endpoint не вызывается');
    }, {
        signal: abortController.signal,
        confirmActivation: (_prepared, {signal}) => new Promise((_resolve, reject) => signal.addEventListener('abort', () => {
            const error = new Error('cancelled'); error.name = 'AbortError'; reject(error);
        }, {once: true})),
    }));
    await new Promise((resolve) => setImmediate(resolve));
    abortController.abort();
    let abortPropagated = false;
    try { await aborted; } catch (error) { abortPropagated = error.name === 'AbortError'; }
    check(abortPropagated, 'navigation/agent cancellation прерывает pending confirmation без mutation');

    const registrations = [];
    const status = {dataset: {}, textContent: ''};
    const lifecycle = adapter.registerTools({
        catalog,
        csrfToken: 'csrf-stage17',
        document: {modelContext: {registerTool(tool) { registrations.push(tool); }}, getElementById(id) { return id === 'webmcp-capability' ? status : null; }},
        window: {location: {href: 'https://rhythm.example/assistant'}, addEventListener() {}, removeEventListener() {}},
        fetchImpl: fetchSuccess,
        confirmActivation: async () => 'cancel',
    });
    await lifecycle.ready;
    check(registrations.length === 17 && registrations.filter((tool) => tool.annotations.readOnlyHint === false).length === 5, 'fake modelContext получает 12 reads и 5 writes');
    lifecycle.abort();

    console.log(`WebMCP write checks passed (${checks}).`);
})().catch((error) => {
    console.error(error);
    process.exit(1);
});
