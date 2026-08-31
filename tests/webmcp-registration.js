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
const catalog = JSON.parse(execFileSync(process.env.PHP_BINARY || 'php', ['tests/stage14-webmcp-page.php', '--catalog'], {
    cwd: root,
    encoding: 'utf8',
}));

const response = (status, payload) => ({
    ok: status >= 200 && status < 300,
    status,
    async json() { return payload; },
});

function fakePage(modelContext) {
    const status = {dataset: {}, textContent: ''};
    const listeners = new Map();
    return {
        status,
        listeners,
        document: {
            modelContext,
            getElementById(id) { return id === 'webmcp-capability' ? status : null; },
        },
        window: {
            location: {href: 'https://rhythm.example/assistant'},
            addEventListener(type, listener) { listeners.set(type, listener); },
            removeEventListener(type, listener) { if (listeners.get(type) === listener) listeners.delete(type); },
        },
    };
}

(async () => {
    const registrations = [];
    const page = fakePage({
        registerTool(tool, options) {
            registrations.push({tool, options});
            return Promise.resolve();
        },
    });
    const lifecycle = adapter.registerTools({
        ...page,
        catalog,
        fetchImpl: async () => response(200, {data: {timezone: 'Europe/Moscow'}, meta: {request_id: 'node-success'}}),
    });
    await lifecycle.ready;

    check(registrations.length === 11, 'регистрируются все 11 tools');
    check(registrations.map(({tool}) => tool.name).join('|') === catalog.map(({name}) => name).join('|'), 'имена берутся из серверного каталога');
    check(registrations.every(({tool}) => tool.description && tool.inputSchema.type === 'object'), 'description и inputSchema передаются в WebMCP');
    check(registrations.every(({tool}) => tool.annotations.readOnlyHint === true && tool.annotations.untrustedContentHint === true), 'annotations сохраняются');
    check(registrations.every(({options}) => options.signal === lifecycle.controller.signal), 'один AbortSignal управляет page-scoped регистрацией');
    check(page.status.dataset.state === 'ready' && page.status.textContent.includes('11'), 'UI показывает успешную регистрацию');

    const profile = registrations.find(({tool}) => tool.name === 'training.get_profile').tool;
    const success = await profile.execute({}, {signal: new AbortController().signal});
    check(success.ok === true && success.data.timezone === 'Europe/Moscow' && success.meta.request_id === 'node-success', 'execute возвращает structured success');

    page.listeners.get('pagehide')();
    check(lifecycle.controller.signal.aborted && page.listeners.size === 0, 'pagehide aborts registration lifecycle');

    const unsupportedPage = fakePage(undefined);
    const unsupported = adapter.registerTools({...unsupportedPage, catalog});
    await unsupported.ready;
    check(unsupported.status === 'unsupported' && unsupportedPage.status.dataset.state === 'unsupported', 'нет capability — нет ошибок и регистраций');

    const apiError = await adapter.executeTool('training.get_progress_summary', {}, {
        locationHref: 'https://rhythm.example/assistant',
        fetchImpl: async () => response(422, {error: {code: 'validation_error', message: 'Проверьте даты.', request_id: 'node-error'}}),
    });
    check(apiError.ok === false && apiError.error.code === 'validation_error' && apiError.error.status === 422, 'API error остаётся структурированным');

    const networkError = await adapter.executeTool('training.get_profile', {}, {
        locationHref: 'https://rhythm.example/assistant',
        fetchImpl: async () => { throw new TypeError('offline'); },
    });
    check(networkError.ok === false && networkError.error.code === 'network_error', 'network error не создаёт unhandled rejection');

    const crossOrigin = await adapter.requestJson('https://evil.example/private', {
        locationHref: 'https://rhythm.example/assistant',
        fetchImpl: async () => { throw new Error('fetch не должен вызываться'); },
    });
    check(crossOrigin.ok === false && crossOrigin.error.code === 'origin_mismatch', 'cross-origin endpoint отклоняется до fetch');

    const invalid = await adapter.executeTool('training.get_plan', {program_id: 'safe', user_id: 2}, {
        locationHref: 'https://rhythm.example/assistant',
        fetchImpl: async () => response(200, {data: {}}),
    });
    check(invalid.ok === false && invalid.error.code === 'validation_error', 'execute отклоняет user_id и лишние аргументы');

    const abortError = new Error('cancelled');
    abortError.name = 'AbortError';
    let cancellationPropagated = false;
    try {
        await adapter.executeTool('training.get_profile', {}, {
            locationHref: 'https://rhythm.example/assistant',
            fetchImpl: async () => { throw abortError; },
        });
    } catch (error) {
        cancellationPropagated = error === abortError;
    }
    check(cancellationPropagated, 'execution AbortSignal cancellation пробрасывается вызывающему агенту');

    const failingPage = fakePage({registerTool(tool) {
        return tool.name === 'training.get_plan' ? Promise.reject(new Error('rejected')) : Promise.resolve();
    }});
    const failedLifecycle = adapter.registerTools({...failingPage, catalog});
    await failedLifecycle.ready;
    check(failedLifecycle.controller.signal.aborted && failingPage.status.dataset.state === 'error', 'частичная registration failure снимает весь каталог');

    console.log(`WebMCP registration checks passed (${checks}).`);
})().catch((error) => {
    console.error(error);
    process.exit(1);
});
