'use strict';

const assert = require('assert');
const history = require('../public/assets/workout-history.js');
const card = require('../public/assets/workout-card.js');

assert.strictEqual(history.classifyRir(1, 2, 2), 'below');
assert.strictEqual(history.classifyRir(2, 2, 2), 'within');
assert.strictEqual(history.classifyRir(3, 2, 2), 'above');
assert.strictEqual(history.classifyRir(1.5, 1.5, 2.5), 'within');
assert.strictEqual(history.classifyRir(2.5, 1.5, 2.5), 'within');
assert.strictEqual(history.classifyRir(null, 1, 3), 'unknown');
assert.strictEqual(history.classifyRir(2, null, null), 'unknown');

assert.deepStrictEqual(history.visibleWindow(320, 6, 5), {start: 4, end: 6, count: 2});
assert.deepStrictEqual(history.visibleWindow(390, 6, 5), {start: 4, end: 6, count: 2});
assert.deepStrictEqual(history.visibleWindow(430, 6, 5), {start: 3, end: 6, count: 3});
assert.deepStrictEqual(history.visibleWindow(320, 1, 0), {start: 0, end: 1, count: 1});
assert.deepStrictEqual(history.visibleWindow(320, 6, 1), {start: 0, end: 2, count: 2});

const session = {scheduled_date: '2026-09-08', target_rir_min: 1.5, target_rir_max: 2.5};
const set = {weight_value: 100, weight_unit: 'lb', reps: 8, rir: 1.5};
assert.strictEqual(history.setAccessibleText(session, set, 0), '2026-09-08, подход 1: 100 lb × 8, RIR 1,5, цель RIR 1,5–2,5, в цели');
assert.ok(history.historySummary([]).includes('пока нет'));

assert.deepStrictEqual(card.normalizeExpandedIds(['20', 10, 20, 'missing'], [10, 20]), ['20', '10']);
assert.deepStrictEqual(card.normalizeExpandedIds(undefined, [10]), []);
assert.deepStrictEqual(card.setExpanded(['10'], 20, true), ['10', '20']);
assert.deepStrictEqual(card.setExpanded(['10', '20'], 10, false), ['20']);
assert.strictEqual(card.iconFilename('bench_press_001'), 'bench_press_001.svg');
assert.strictEqual(card.iconFilename('custom-user-exercise'), 'generic.svg');
assert.strictEqual(card.iconFilename('../bench_press_001'), 'generic.svg');
assert.strictEqual(card.iconFilename('bench_press_001.svg'), 'generic.svg');
assert.strictEqual(card.iconUrl('bench_press_001', '/assets/exercises'), '/assets/exercises/bench_press_001.svg');

console.log('Workout UI stage 2 JS checks passed (27).');
