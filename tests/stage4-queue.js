'use strict';
const queue = globalThis.RhythmOffline || require('../public/assets/offline-queue.js');
let checks = 0;
const check = (condition, label) => {
    checks += 1;
    if (!condition) throw new Error(label);
};

const a = queue.createAction({id: 'set.create:00000001', userId: 1, sessionId: 9, type: 'set.create', path: '/api/sessions/9/sets', method: 'POST', body: {session_version: 2}, createdAt: 10});
const b = queue.createAction({id: 'set.create:00000002', userId: 1, sessionId: 9, type: 'set.create', path: '/api/sessions/9/sets', method: 'POST', body: {session_version: 2}, dependsOn: [a.id], createdAt: 20});
const c = queue.createAction({id: 'session.finish:0003', userId: 1, sessionId: 9, type: 'session.finish', path: '/api/sessions/9/finish', method: 'POST', body: {session_version: 2}, dependsOn: [b.id], createdAt: 30});

check(a.body.client_action_id === a.id, 'UUID переносится в тело запроса');
check(queue.orderActions([c, b, a]).map((item) => item.id).join(',') === [a.id, b.id, c.id].join(','), 'зависимости сохраняют порядок');
check(queue.orderActions([{...b, dependsOn: []}, a])[0].id === a.id, 'равноправные действия сортируются стабильно');

const rebased = queue.rebaseAction({...b, body: {...b.body, session_exercise_id: 4, exercise_version: 1}}, {sessionVersion: 7, exerciseVersions: {4: 3}, setVersions: {}});
check(rebased.body.session_version === 7 && rebased.body.exercise_version === 3, 'версии зависимого действия обновляются');
check(b.body.session_version === 2, 'rebase не мутирует исходное действие');

let cycle = false;
try { queue.orderActions([{...a, dependsOn: [b.id]}, {...b, dependsOn: [a.id]}]); } catch (_) { cycle = true; }
check(cycle, 'цикл зависимостей отклоняется');

const versions = queue.versionsFromSession({version: 11, exercises: [{id: 5, version: 4, sets: [{id: 8, version: 2}]}]});
check(versions.sessionVersion === 11 && versions.exerciseVersions[5] === 4 && versions.setVersions[8] === 2, 'версии извлекаются из снимка');

console.log(`Stage 4 queue checks passed (${checks}).`);
