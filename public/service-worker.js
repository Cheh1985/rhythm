'use strict';
const SHELL_VERSION = 'rhythm-shell-v10.11';
const USER_PAGES = 'rhythm-user-pages-v1';
const LOCALE_META = 'rhythm-locale-meta-v1';
const scope = self.registration.scope;
const asset = (path) => new URL(path, scope).toString();
const APP_SHELL = [
    asset('./offline.html'),
    asset('./offline.en.html'),
    asset('./manifest.json'),
    asset('./manifest.en.json'),
    asset('./assets/app.css'),
    asset('./assets/workout.css'),
    asset('./assets/summary.css'),
    asset('./assets/offline-queue.js'),
    asset('./assets/i18n.js'),
    asset('./assets/pwa.js'),
    asset('./assets/weight.js'),
    asset('./assets/rest-timer.js'),
    asset('./assets/push.js'),
    asset('./assets/workout-card.js'),
    asset('./assets/workout-history.js'),
    asset('./assets/workout-wheel.js'),
    asset('./assets/workout-carousel.js'),
    asset('./assets/workout.js'),
    asset('./assets/swimming.js'),
    asset('./icons/icon.svg'),
    asset('./icons/icon-en.svg'),
    asset('./icons/icon-180.png'),
    asset('./icons/icon-192.png'),
    asset('./icons/icon-512.png'),
    asset('./icons/icon-en-180.png'),
    asset('./icons/icon-en-192.png'),
    asset('./icons/icon-en-512.png'),
    asset('./assets/exercises/generic.svg'),
    asset('./assets/exercises/leg_press_001.svg'),
    asset('./assets/exercises/bench_press_001.svg'),
    asset('./assets/exercises/incline_db_press_001.svg'),
    asset('./assets/exercises/lat_pulldown_001.svg'),
    asset('./assets/exercises/seated_cable_row_001.svg'),
    asset('./assets/exercises/leg_curl_001.svg'),
    asset('./assets/exercises/biceps_curl_001.svg'),
    asset('./assets/exercises/triceps_pushdown_001.svg'),
    asset('./assets/exercises/db_shoulder_press_001.svg'),
    asset('./assets/exercises/hack_squat_001.svg'),
    asset('./assets/exercises/romanian_deadlift_001.svg'),
    asset('./assets/exercises/calf_raise_001.svg'),
    asset('./assets/exercises/lateral_raise_001.svg'),
    asset('./assets/exercises/face_pull_001.svg'),
];

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(SHELL_VERSION).then((cache) => cache.addAll(APP_SHELL)));
});

self.addEventListener('activate', (event) => {
    event.waitUntil(caches.keys().then((keys) => Promise.all(keys.filter((key) => key.startsWith('rhythm-shell-') && key !== SHELL_VERSION).map((key) => caches.delete(key)))).then(() => self.clients.claim()));
});

self.addEventListener('message', (event) => {
    if (event.data?.type === 'SKIP_WAITING') self.skipWaiting();
    if (event.data?.type === 'CLEAR_USER_DATA') event.waitUntil(caches.delete(USER_PAGES));
    if (event.data?.type === 'GET_CAPABILITIES') event.ports?.[0]?.postMessage({capabilities: ['push-v1']});
    if (event.data?.type === 'SET_LOCALE' && ['ru', 'en'].includes(event.data.locale)) {
        event.waitUntil(setLocale(event.data.locale));
    }
});

self.addEventListener('push', (event) => {
    event.waitUntil(handlePush(event));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    event.waitUntil(openNotificationTarget(event.notification.data?.url));
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    if (request.method !== 'GET') return;
    const url = new URL(request.url);
    if (url.origin !== location.origin || url.pathname.includes('/api/') || /^\/assistant\/?$/.test(url.pathname)) return;
    if (request.mode === 'navigate') {
        event.respondWith(networkFirstNavigation(request));
        return;
    }
    event.respondWith(caches.match(request).then((cached) => cached || fetch(request)));
});

async function networkFirstNavigation(request) {
    try {
        const response = await fetch(request);
        if (response.ok && response.headers.get('X-Rhythm-Private') === '1' && /\/(?:help|sessions\/\d+|swimming(?:\/\d+)?|schedule)\/?$/.test(new URL(request.url).pathname)) {
            const cache = await caches.open(USER_PAGES);
            await cache.put(request, response.clone());
        }
        return response;
    } catch (_) {
        const privatePage = await caches.open(USER_PAGES).then((cache) => cache.match(request));
        return privatePage || offlineFallback();
    }
}

async function setLocale(locale) {
    const cache = await caches.open(LOCALE_META);
    const key = asset('./__active_locale__');
    const previous = await cache.match(key).then((response) => response ? response.text() : 'ru');
    await cache.put(key, new Response(locale, {headers: {'Content-Type': 'text/plain'}}));
    if (previous !== locale) await caches.delete(USER_PAGES);
}

async function offlineFallback() {
    const locale = await caches.open(LOCALE_META)
        .then((cache) => cache.match(asset('./__active_locale__')))
        .then((response) => response ? response.text() : 'ru');
    return caches.match(asset(locale === 'en' ? './offline.en.html' : './offline.html'));
}

async function handlePush(event) {
    let payload;
    try { payload = event.data?.json(); } catch (_) { return; }
    if (!payload || payload.type !== 'rest-complete' || !/^[a-zA-Z0-9._:-]{8,80}$/.test(payload.timerId || '') || !Number.isInteger(payload.revision)) return;
    const match = String(payload.url || '').match(/\/sessions\/(\d+)\/?$/);
    if (!match || !/^\d+$/.test(String(payload.userId || ''))) return;
    const state = await readTimerMeta(String(payload.userId), match[1]);
    if (!state || state.id !== payload.timerId || Number(state.revision) !== payload.revision || state.status !== 'running' || state.paused) return;
    const target = safeNotificationUrl(payload.url);
    if (!target) return;
    await self.registration.showNotification(String(payload.title || 'Ритм'), {
        body: String(payload.body || 'Отдых завершён — пора к следующему подходу'),
        icon: asset('./icons/icon-192.png'),
        badge: asset('./icons/icon-192.png'),
        tag: 'rest-' + payload.timerId,
        renotify: false,
        data: {url: target.href, timerId: payload.timerId, revision: payload.revision},
    });
}

function safeNotificationUrl(value) {
    try {
        const target = new URL(String(value || ''), scope);
        const scopeUrl = new URL(scope);
        if (target.origin !== scopeUrl.origin || !target.pathname.startsWith(scopeUrl.pathname) || !/\/sessions\/\d+\/?$/.test(target.pathname)) return null;
        return target;
    } catch (_) { return null; }
}

async function openNotificationTarget(value) {
    const target = safeNotificationUrl(value);
    if (!target) return;
    const windows = await clients.matchAll({type: 'window', includeUncontrolled: true});
    const exact = windows.find((client) => client.url === target.href);
    if (exact) return exact.focus();
    const existing = windows.find((client) => client.url.startsWith(scope));
    if (existing) {
        if ('navigate' in existing) await existing.navigate(target.href);
        return existing.focus();
    }
    return clients.openWindow(target.href);
}

function readTimerMeta(userId, sessionId) {
    return new Promise((resolve) => {
        const request = indexedDB.open('rhythm-offline-v1', 1);
        request.onupgradeneeded = () => {
            const db = request.result;
            if (!db.objectStoreNames.contains('meta')) db.createObjectStore('meta', {keyPath: 'key'});
        };
        request.onerror = () => resolve(null);
        request.onsuccess = () => {
            const db = request.result;
            if (!db.objectStoreNames.contains('meta')) { db.close(); resolve(null); return; }
            const query = db.transaction('meta').objectStore('meta').get('user:' + userId + ':meta:rest:' + sessionId);
            query.onerror = () => { db.close(); resolve(null); };
            query.onsuccess = () => { const value = query.result?.value || null; db.close(); resolve(value); };
        };
    });
}
