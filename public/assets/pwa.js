(() => {
    'use strict';
    const userId = document.querySelector('meta[name="rhythm-user-id"]')?.content || '';
    const activeLocale = document.querySelector('meta[name="rhythm-locale"]')?.content || 'ru';
    if (userId) localStorage.setItem('rhythm-active-user', userId);
    localStorage.setItem('rhythm-locale', activeLocale);

    async function register() {
        if (!('serviceWorker' in navigator) || !window.isSecureContext) return;
        const base = document.querySelector('meta[name="app-url"]')?.content || '';
        const registration = await navigator.serviceWorker.register(base + '/service-worker.js', {scope: base + '/'});
        const shareLocale = () => (registration.active || navigator.serviceWorker.controller)?.postMessage({type: 'SET_LOCALE', locale: activeLocale});
        shareLocale();
        navigator.serviceWorker.ready.then(shareLocale).catch(() => {});
        const announce = (worker) => {
            if (!worker || !navigator.serviceWorker.controller) return;
            window.dispatchEvent(new CustomEvent('rhythm-sw-update', {detail: {registration}}));
        };
        announce(registration.waiting);
        registration.addEventListener('updatefound', () => {
            registration.installing?.addEventListener('statechange', () => {
                if (registration.waiting) announce(registration.waiting);
            });
        });
    }
    register().catch(() => {});

    window.addEventListener('rhythm-sw-update', (event) => {
        const banner = document.querySelector('#sw-update');
        if (!banner) return;
        banner.hidden = false;
        banner.querySelector('button').onclick = async () => {
            if (typeof window.rhythmPersistBeforeUpdate === 'function') await window.rhythmPersistBeforeUpdate();
            window.dispatchEvent(new CustomEvent('rhythm-before-update'));
            const registration = event.detail.registration;
            registration.waiting?.postMessage({type: 'SKIP_WAITING'});
            let reloaded = false;
            navigator.serviceWorker.addEventListener('controllerchange', () => {
                if (!reloaded) {
                    reloaded = true;
                    location.reload();
                }
            });
        };
    });

    document.querySelectorAll('form[action$="/logout"]').forEach((form) => {
        form.addEventListener('submit', async (event) => {
            if (form.dataset.clearing === '1') return;
            event.preventDefault();
            form.dataset.clearing = '1';
            try {
                if (userId && window.RhythmOffline) await RhythmOffline.clearUser(userId);
                localStorage.removeItem('rhythm-active-user');
                const keys = await caches.keys();
                await Promise.all(keys.filter((key) => key.startsWith('rhythm-user-pages-')).map((key) => caches.delete(key)));
                navigator.serviceWorker.controller?.postMessage({type: 'CLEAR_USER_DATA'});
            } finally {
                form.submit();
            }
        });
    });
})();
