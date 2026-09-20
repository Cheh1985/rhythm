'use strict';
const assert = require('node:assert/strict');
const timer = require('../public/assets/rest-timer.js');

const start = Date.parse('2026-09-19T10:00:00Z');
const make = () => timer.create({id: 'rest:00000000-0000-4000-8000-000000000001', duration: 90, sourceSetActionId: 'set.create:00000001', sessionExerciseId: 7}, start);

let state = make();
assert.equal(state.version, 2);
assert.ok(state.revision >= start * 1000 && state.revision < start * 1000 + 1000);
assert.equal(timer.remainingSeconds(state, start + 89001), 1);
let transition = timer.finish(state, start + 89001, 'user');
assert.equal(transition.state.outcome, 'ended_early');
assert.equal(transition.state.trigger, 'user');

state = make();
transition = timer.finish(state, start + 90000, 'user');
assert.equal(transition.state.outcome, 'completed');
assert.equal(transition.state.trigger, 'timer_elapsed');
assert.equal(transition.state.endedAt, state.endAt);
assert.equal(timer.finish(transition.state, start + 91000).finalized, false);

state = timer.pause(make(), start + 30000).state;
const pausedRevision = state.revision;
assert.ok(pausedRevision >= (start + 30000) * 1000);
assert.equal(timer.remainingSeconds(state, start + 300000), 60);
state = timer.resume(state, start + 300000).state;
assert.ok(state.revision > pausedRevision);
const resumedRevision = state.revision;
assert.equal(state.endAt, start + 360000);
state = timer.add(state, 30, start + 310000).state;
assert.ok(state.revision > resumedRevision);
const addedRevision = state.revision;
assert.equal(state.endAt, start + 390000);
state = timer.reset(state, start + 320000).state;
assert.ok(state.revision > addedRevision);
assert.equal(state.endAt, start + 410000);

state = timer.reconcile(make(), start + 180000).state;
assert.equal(state.status, 'completed');
const payload = timer.eventPayload(state);
assert.equal(payload.outcome, 'completed');
assert.equal(payload.deadline_at_utc, '2026-09-19T10:01:30.000Z');
assert.equal(timer.restore(JSON.parse(JSON.stringify(state))).status, 'completed');
assert.equal(timer.restore({...make(), version: 1, revision: undefined}).revision, 1);
assert.equal(timer.restore({}), null);

console.log('Rest timer state checks passed.');
