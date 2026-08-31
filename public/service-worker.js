'use strict';
const SHELL_VERSION = 'rhythm-shell-v8.0';
const USER_PAGES = 'rhythm-user-pages-v1';
const scope = self.registration.scope;
const asset = (path) => new URL(path, scope).toString();
const APP_SHELL = [
    asset('./offline.html'),
    asset('./manifest.json'),
    asset('./assets/app.css'),
    asset('./assets/workout.css'),
    asset('./assets/summary.css'),
    asset('./assets/offline-queue.js'),
    asset('./assets/pwa.js'),
    asset('./assets/workout.js'),
    asset('./assets/swimming.js'),
    asset('./icons/icon.svg'),
    asset('./icons/icon-180.png'),
    asset('./icons/icon-192.png'),
    asset('./icons/icon-512.png'),
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
        if (response.ok && response.headers.get('X-Rhythm-Private') === '1' && /\/(?:sessions\/\d+|swimming(?:\/\d+)?|schedule)\/?$/.test(new URL(request.url).pathname)) {
            const cache = await caches.open(USER_PAGES);
            await cache.put(request, response.clone());
        }
        return response;
    } catch (_) {
        const privatePage = await caches.open(USER_PAGES).then((cache) => cache.match(request));
        return privatePage || caches.match(asset('./offline.html'));
    }
}
