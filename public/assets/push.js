(() => {
    'use strict';
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const base = document.querySelector('meta[name="app-url"]')?.content || '';
    const userId = document.querySelector('meta[name="rhythm-user-id"]')?.content || '';
    const settings = document.querySelector('[data-push-settings]');
    const statusNode = settings?.querySelector('[data-push-status]') || null;
    const enableButton = settings?.querySelector('[data-push-enable]') || null;
    const disableButton = settings?.querySelector('[data-push-disable]') || null;
    const storageKey = 'rhythm-push-subscription-' + userId;
    let configPromise = null;
    let readyPromise = null;

    const translate = (value) => window.RhythmI18n?.t?.(value) || value;
    const supported = () => Boolean(userId && window.isSecureContext && 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window);
    const setStatus = (message, state = 'idle') => {
        if (!statusNode) return;
        statusNode.textContent = translate(message);
        statusNode.dataset.state = state;
    };
    async function request(path, options = {}) {
        const response = await fetch(base + path, {credentials: 'same-origin', ...options, headers: {Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': csrf, ...(options.headers || {})}});
        const payload = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(payload.error || translate('Не удалось изменить настройку уведомлений.'));
        return payload.data || payload;
    }
    function getConfig() {
        return configPromise ||= request('/api/push/config');
    }
    function publicKeyBytes(value) {
        const padding = '='.repeat((4 - value.length % 4) % 4);
        const raw = atob((value + padding).replace(/-/g, '+').replace(/_/g, '/'));
        return Uint8Array.from(raw, (character) => character.charCodeAt(0));
    }
    async function workerSupportsPush(registration) {
        const worker = registration.active || navigator.serviceWorker.controller;
        if (!worker || typeof MessageChannel === 'undefined') return false;
        return new Promise((resolve) => {
            const channel = new MessageChannel();
            const timeout = setTimeout(() => resolve(false), 1200);
            channel.port1.onmessage = (event) => {
                clearTimeout(timeout);
                resolve(Array.isArray(event.data?.capabilities) && event.data.capabilities.includes('push-v1'));
            };
            worker.postMessage({type: 'GET_CAPABILITIES'}, [channel.port2]);
        });
    }
    async function registerOnServer(subscription) {
        const result = await request('/api/push/subscriptions', {method: 'POST', body: JSON.stringify({subscription: subscription.toJSON()})});
        localStorage.setItem(storageKey, result.subscription_id);
        return result.subscription_id;
    }
    async function ensureSubscription(requestPermission = false) {
        if (!supported()) throw new Error(translate('Этот браузер не поддерживает Web Push. На iPhone установите PWA на экран «Домой».'));
        let permission = Notification.permission;
        if (permission === 'default' && requestPermission) permission = await Notification.requestPermission();
        if (permission !== 'granted') {
            throw new Error(permission === 'denied'
                ? translate('Уведомления запрещены системой. Разрешите их в настройках браузера или приложения.')
                : translate('Нажмите «Включить уведомления», чтобы подтвердить системный запрос.'));
        }
        const config = await getConfig();
        if (!config.enabled || !config.public_key) throw new Error(translate('Push-уведомления пока не настроены на сервере.'));
        const registration = await navigator.serviceWorker.ready;
        if (!(await workerSupportsPush(registration))) {
            registration.update().catch(() => {});
            throw new Error(translate('Сначала установите доступное обновление приложения.'));
        }
        let subscription = await registration.pushManager.getSubscription();
        if (!subscription) subscription = await registration.pushManager.subscribe({userVisibleOnly: true, applicationServerKey: publicKeyBytes(config.public_key)});
        const subscriptionId = await registerOnServer(subscription);
        setStatus('Уведомления об окончании отдыха включены на этом устройстве.', 'enabled');
        if (enableButton) enableButton.hidden = true;
        if (disableButton) disableButton.hidden = false;
        return subscriptionId;
    }
    async function disable(options = {}) {
        if (!supported()) { localStorage.removeItem(storageKey); return; }
        const registration = await navigator.serviceWorker.ready.catch(() => null);
        const subscription = await registration?.pushManager.getSubscription().catch(() => null);
        const subscriptionId = localStorage.getItem(storageKey);
        if (options.server !== false && subscriptionId) {
            await request('/api/push/subscriptions/' + encodeURIComponent(subscriptionId), {method: 'DELETE', body: '{}'}).catch(() => {});
        }
        await subscription?.unsubscribe().catch(() => false);
        localStorage.removeItem(storageKey);
        setStatus('Уведомления на этом устройстве отключены.', 'disabled');
        if (enableButton) enableButton.hidden = false;
        if (disableButton) disableButton.hidden = true;
    }
    async function initialise() {
        if (!userId) return null;
        if (!supported()) {
            setStatus('Этот браузер не поддерживает Web Push. На iPhone установите PWA на экран «Домой».', 'unsupported');
            if (enableButton) enableButton.disabled = true;
            return null;
        }
        const config = await getConfig().catch(() => null);
        if (!config?.enabled) {
            setStatus('Push-уведомления пока не настроены на сервере.', 'unsupported');
            if (enableButton) enableButton.disabled = true;
            return null;
        }
        const registration = await navigator.serviceWorker.ready;
        if (!(await workerSupportsPush(registration))) {
            registration.update().catch(() => {});
            setStatus('Сначала установите доступное обновление приложения.', 'error');
            if (enableButton) enableButton.disabled = true;
            return null;
        }
        if (enableButton) enableButton.disabled = false;
        if (Notification.permission === 'denied') {
            setStatus('Уведомления запрещены системой. Разрешите их в настройках браузера или приложения.', 'denied');
            if (enableButton) enableButton.hidden = true;
            return null;
        }
        if (Notification.permission === 'granted') {
            if (await registration.pushManager.getSubscription()) return ensureSubscription(false);
        }
        setStatus('Уведомления выключены. Разрешение будет запрошено только после нажатия кнопки.', 'disabled');
        return null;
    }
    const needsInitialisation = Boolean(settings || document.querySelector('.workout-page'));
    readyPromise = needsInitialisation
        ? initialise().catch((error) => { setStatus(error.message, 'error'); return null; })
        : Promise.resolve(localStorage.getItem(storageKey));
    enableButton?.addEventListener('click', async () => {
        enableButton.disabled = true;
        setStatus('Включаем уведомления…', 'pending');
        try { await ensureSubscription(true); }
        catch (error) { setStatus(error.message, 'error'); }
        finally { if (!enableButton.hidden) enableButton.disabled = false; }
    });
    disableButton?.addEventListener('click', async () => {
        disableButton.disabled = true;
        try { await disable(); }
        catch (error) { setStatus(error.message, 'error'); }
        finally { disableButton.disabled = false; }
    });

    window.RhythmPush = {
        ready: () => readyPromise,
        ensureSubscription,
        disable,
        getSubscriptionId: async () => (await readyPromise) || localStorage.getItem(storageKey),
    };
})();
