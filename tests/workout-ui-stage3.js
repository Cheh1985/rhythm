'use strict';

const assert = require('node:assert/strict');
const weight = require('../public/assets/weight.js');
const wheel = require('../public/assets/workout-wheel.js');

let checks = 0;
const equal = (actual, expected) => { checks++; assert.equal(actual, expected); };
const deepEqual = (actual, expected) => { checks++; assert.deepEqual(actual, expected); };

equal(weight.step('kg'), 0.5);
equal(weight.step('lb'), 5);
equal(wheel.normalizeValue(72.26, {min: 0, max: 2000, step: weight.step('kg')}), 72.5);
equal(wheel.normalizeValue(72.24, {min: 0, max: 2000, step: weight.step('kg')}), 72);
equal(wheel.normalizeValue(102.6, {min: 0, max: 2000, step: weight.step('lb')}), 105);
equal(wheel.normalizeValue(-10, {min: 0, max: 2000, step: 0.5}), 0);
equal(wheel.normalizeValue(5000, {min: 0, max: 2000, step: 0.5}), 2000);
equal(wheel.stepValue(72.5, 1, {min: 0, max: 2000, step: 5}), 77.5);
equal(wheel.stepValue(72.5, -1, {min: 0, max: 2000, step: 5}), 67.5);
equal(wheel.stepValue(2000, 1, {min: 0, max: 2000, step: 5}), 2000);
equal(wheel.stepValue(1, -1, {min: 1, max: 1000, step: 1}), 1);

equal(wheel.normalizeValue(1.5, {min: 0, max: 10, step: 0.5, allowEmpty: true}), 1.5);
equal(wheel.normalizeValue(1.74, {min: 0, max: 10, step: 0.5, allowEmpty: true}), 1.5);
equal(wheel.normalizeValue(1.76, {min: 0, max: 10, step: 0.5, allowEmpty: true}), 2);
equal(wheel.coerceValue('', {min: 0, max: 10, step: 0.5, allowEmpty: true}), null);
equal(wheel.coerceValue('1.5', {min: 0, max: 10, step: 0.5, allowEmpty: true}), 1.5);
equal(wheel.formatValue(1.5, 'ru'), '1,5');
equal(wheel.formatValue(1.5, 'en'), '1.5');

deepEqual(wheel.windowValues(1.5, {min: 0, max: 10, step: 0.5}), [0.5, 1, 1.5, 2, 2.5]);
deepEqual(wheel.windowValues(0, {min: 0, max: 10, step: 0.5}), [null, null, 0, 0.5, 1]);
equal(wheel.windowValues(1000, {min: 1, max: 1000, step: 1}).length, wheel.ROW_COUNT);
equal(wheel.ROW_COUNT, 5);

(async () => {
    const form = {dataset: {}};
    let creates = 0;
    let timers = 0;
    let release;
    const gate = new Promise((resolve) => { release = resolve; });
    const first = wheel.runOnce(form, async () => {
        creates++;
        await gate;
        timers++;
    });
    const duplicate = wheel.runOnce(form, async () => { creates++; timers++; });
    equal(await duplicate, null);
    release();
    await first;
    equal(creates, 1);
    equal(timers, 1);
    equal(form.dataset.submitting, undefined);

    await wheel.runOnce(form, async () => { creates++; timers++; });
    equal(creates, 2);
    equal(timers, 2);

    console.log(`Workout UI stage 3 JS checks passed (${checks}).`);
})().catch((error) => {
    console.error(error);
    process.exit(1);
});
