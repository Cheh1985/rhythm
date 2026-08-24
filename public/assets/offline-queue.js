(function (root, factory) {
    const api = factory();
    if (typeof module === 'object' && module.exports) module.exports = api;
    root.RhythmOffline = api;
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
    'use strict';
    const DB_NAME = 'rhythm-offline-v1';
    const DB_VERSION = 1;

    function uuid(prefix = 'action') {
        const value = globalThis.crypto?.randomUUID?.() || Date.now() + '-' + Math.random().toString(16).slice(2);
        return prefix + ':' + value;
    }

    function createAction(input) {
        if (!input || !input.userId || !input.sessionId || !input.path || !input.method || !input.type) throw new TypeError('Некорректное offline-действие.');
        const id = input.id || uuid(input.type);
        return {
            key: 'user:' + input.userId + ':action:' + id,
            id,
            userId: String(input.userId),
            sessionId: String(input.sessionId),
            type: input.type,
            path: input.path,
            method: input.method.toUpperCase(),
            body: {...(input.body || {}), client_action_id: id},
            dependsOn: [...new Set(input.dependsOn || [])],
            status: input.status || 'pending',
            attempts: Number(input.attempts || 0),
            createdAt: Number(input.createdAt || Date.now()),
            error: input.error || null,
        };
    }

    function orderActions(actions) {
        const items = [...actions].sort((a, b) => a.createdAt - b.createdAt || a.id.localeCompare(b.id));
        const byId = new Map(items.map((item) => [item.id, item]));
        const indegree = new Map(items.map((item) => [item.id, 0]));
        const children = new Map(items.map((item) => [item.id, []]));
        for (const item of items) {
            for (const parent of item.dependsOn || []) {
                if (!byId.has(parent)) continue;
                indegree.set(item.id, indegree.get(item.id) + 1);
                children.get(parent).push(item.id);
            }
        }
        const ready = items.filter((item) => indegree.get(item.id) === 0);
        const result = [];
        while (ready.length) {
            const item = ready.shift();
            result.push(item);
            for (const childId of children.get(item.id)) {
                indegree.set(childId, indegree.get(childId) - 1);
                if (indegree.get(childId) === 0) {
                    ready.push(byId.get(childId));
                    ready.sort((a, b) => a.createdAt - b.createdAt || a.id.localeCompare(b.id));
                }
            }
        }
        if (result.length !== items.length) throw new Error('В outbox обнаружен цикл зависимостей.');
        return result;
    }

    function rebaseAction(action, versions) {
        const next = {...action, body: {...action.body}};
        if (Number.isInteger(versions.sessionVersion)) next.body.session_version = versions.sessionVersion;
        const exerciseId = next.body.session_exercise_id;
        if (exerciseId && versions.exerciseVersions?.[exerciseId] != null && 'exercise_version' in next.body) {
            next.body.exercise_version = Number(versions.exerciseVersions[exerciseId]);
        }
        const setMatch = next.path.match(/\/api\/sets\/(\d+)$/);
        if (setMatch && versions.setVersions?.[setMatch[1]] != null && 'version' in next.body) {
            next.body.version = Number(versions.setVersions[setMatch[1]]);
        }
        return next;
    }

    function versionsFromSession(session) {
        const exerciseVersions = {};
        const setVersions = {};
        for (const exercise of session?.exercises || []) {
            exerciseVersions[exercise.id] = Number(exercise.version);
            for (const set of exercise.sets || []) setVersions[set.id] = Number(set.version);
        }
        return {sessionVersion: Number(session?.version || 0), exerciseVersions, setVersions};
    }

    function openDb() {
        if (!globalThis.indexedDB) return Promise.reject(new Error('IndexedDB недоступна.'));
        return new Promise((resolve, reject) => {
            const request = indexedDB.open(DB_NAME, DB_VERSION);
            request.onupgradeneeded = () => {
                const db = request.result;
                if (!db.objectStoreNames.contains('sessions')) db.createObjectStore('sessions', {keyPath: 'key'});
                if (!db.objectStoreNames.contains('outbox')) {
                    const store = db.createObjectStore('outbox', {keyPath: 'key'});
                    store.createIndex('byUserSession', ['userId', 'sessionId']);
                    store.createIndex('byUser', 'userId');
                }
                if (!db.objectStoreNames.contains('meta')) db.createObjectStore('meta', {keyPath: 'key'});
            };
            request.onsuccess = () => resolve(request.result);
            request.onerror = () => reject(request.error);
        });
    }

    function requestPromise(request) {
        return new Promise((resolve, reject) => {
            request.onsuccess = () => resolve(request.result);
            request.onerror = () => reject(request.error);
        });
    }

    async function saveSession(userId, sessionId, snapshot, draft = null) {
        const db = await openDb();
        const tx = db.transaction('sessions', 'readwrite');
        tx.objectStore('sessions').put({key: 'user:' + userId + ':session:' + sessionId, userId: String(userId), sessionId: String(sessionId), snapshot, draft, updatedAt: Date.now()});
        await transactionDone(tx);
        db.close();
    }

    async function getSession(userId, sessionId) {
        const db = await openDb();
        const result = await requestPromise(db.transaction('sessions').objectStore('sessions').get('user:' + userId + ':session:' + sessionId));
        db.close();
        return result || null;
    }

    async function enqueue(action, sessionRecord = null) {
        const db = await openDb();
        const tx = db.transaction(sessionRecord ? ['outbox', 'sessions'] : ['outbox'], 'readwrite');
        tx.objectStore('outbox').put(action);
        if (sessionRecord) tx.objectStore('sessions').put(sessionRecord);
        await transactionDone(tx);
        db.close();
        return action;
    }

    async function listActions(userId, sessionId) {
        const db = await openDb();
        const store = db.transaction('outbox').objectStore('outbox');
        const result = await requestPromise(store.index('byUserSession').getAll([String(userId), String(sessionId)]));
        db.close();
        return orderActions(result || []);
    }

    async function putAction(action) {
        const db = await openDb();
        const tx = db.transaction('outbox', 'readwrite');
        tx.objectStore('outbox').put(action);
        await transactionDone(tx);
        db.close();
    }

    async function removeAction(key) {
        const db = await openDb();
        const tx = db.transaction('outbox', 'readwrite');
        tx.objectStore('outbox').delete(key);
        await transactionDone(tx);
        db.close();
    }

    async function clearUser(userId) {
        if (!globalThis.indexedDB) return;
        const db = await openDb();
        const prefix = 'user:' + userId + ':';
        for (const name of ['sessions', 'outbox', 'meta']) {
            const tx = db.transaction(name, 'readwrite');
            const store = tx.objectStore(name);
            store.openCursor().onsuccess = (event) => {
                const cursor = event.target.result;
                if (!cursor) return;
                if (String(cursor.key).startsWith(prefix)) cursor.delete();
                cursor.continue();
            };
            await transactionDone(tx);
        }
        db.close();
    }

    function transactionDone(tx) {
        return new Promise((resolve, reject) => {
            tx.oncomplete = () => resolve();
            tx.onerror = () => reject(tx.error);
            tx.onabort = () => reject(tx.error || new Error('IndexedDB transaction aborted'));
        });
    }

    return {DB_NAME, uuid, createAction, orderActions, rebaseAction, versionsFromSession, saveSession, getSession, enqueue, listActions, putAction, removeAction, clearUser};
});
