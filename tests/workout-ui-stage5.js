'use strict';

const assert = require('node:assert/strict');
const wheel = require('../public/assets/workout-wheel.js');

let checks = 0;
const equal = (actual, expected) => { checks++; assert.equal(actual, expected); };
const deepEqual = (actual, expected) => { checks++; assert.deepEqual(actual, expected); };

const inputs = {
    '.weight-input': {value: '52.5'},
    '.reps-input': {value: '8'},
    '.rir-input': {value: ''},
};
const refreshes = [];
const wheels = ['weight', 'reps', 'rir'].map((kind) => ({
    _rhythmWorkoutWheel: {
        refresh: () => {
            const selector = kind === 'weight' ? '.weight-input' : (kind === 'reps' ? '.reps-input' : '.rir-input');
            refreshes.push([kind, inputs[selector].value]);
            return inputs[selector].value;
        },
    },
}));
const scope = {
    querySelector: (selector) => inputs[selector] || null,
    querySelectorAll: (selector) => selector === '[data-workout-wheel]' ? wheels : [],
};

let prepared = false;
const restored = wheel.restoreValuesWithin(scope, {weight: 53, reps: 9, rir: 1.5}, () => {
    prepared = true;
    inputs['.weight-input'].value = '52.5';
});
equal(prepared, true);
equal(inputs['.weight-input'].value, '53');
equal(inputs['.reps-input'].value, '9');
equal(inputs['.rir-input'].value, '1.5');
deepEqual(restored, ['53', '9', '1.5']);
deepEqual(refreshes, [['weight', '53'], ['reps', '9'], ['rir', '1.5']]);

wheel.restoreValuesWithin(scope, {reps: 10});
equal(inputs['.weight-input'].value, '53');
equal(inputs['.reps-input'].value, '10');
equal(inputs['.rir-input'].value, '1.5');
deepEqual(wheel.restoreValuesWithin(null, {weight: 70}), []);

console.log(`Workout UI stage 5 JS checks passed (${checks}).`);
