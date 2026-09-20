'use strict';

const assert = require('node:assert/strict');
const carousel = require('../public/assets/workout-carousel.js');

let checks = 0;
const equal = (actual, expected) => { checks++; assert.equal(actual, expected); };

equal(carousel.clampIndex(2, 5), 2);
equal(carousel.clampIndex(-8, 5), 0);
equal(carousel.clampIndex(12, 5), 4);
equal(carousel.clampIndex(0, 0), -1);
equal(carousel.reduceIndex(0, 'previous', 4), 0);
equal(carousel.reduceIndex(0, 'next', 4), 1);
equal(carousel.reduceIndex(3, 'next', 4), 3);
equal(carousel.reduceIndex(2, {type: 'go', index: 0}, 4), 0);
equal(carousel.reduceIndex(0, {type: 'go', index: 99}, 4), 3);

const items = [
    {id: 11, status: 'completed'},
    {id: 12, status: 'skipped'},
    {id: 13, status: 'pending'},
    {id: 14, status: 'active'},
];
equal(carousel.initialIndex(items, 14), 3);
equal(carousel.initialIndex(items, 999), 2);
equal(carousel.initialIndex(items), 2);
equal(carousel.initialIndex([{id: 1, status: 'completed'}, {id: 2, status: 'skipped'}]), 0);
equal(carousel.initialIndex([]), -1);

equal(carousel.gestureAxis(9, 0), null);
equal(carousel.gestureAxis(40, 5), 'horizontal');
equal(carousel.gestureAxis(-40, 5), 'horizontal');
equal(carousel.gestureAxis(5, 40), 'vertical');
equal(carousel.gestureAxis(30, 27), null);
equal(carousel.swipeAction(-70, 8), 'next');
equal(carousel.swipeAction(70, -8), 'previous');
equal(carousel.swipeAction(35, 2), null);
equal(carousel.swipeAction(70, 65), null);
equal(carousel.swipeAction(8, 70), null);

equal(carousel.isIgnoredTarget({closest: () => ({})}), true);
equal(carousel.isIgnoredTarget({closest: () => null}), false);
equal(carousel.isIgnoredTarget(null), false);
equal(carousel.INTERACTIVE_SELECTOR.includes('[data-workout-wheel]'), true);
equal(carousel.INTERACTIVE_SELECTOR.includes('[data-history-chart]'), true);
equal(carousel.INTERACTIVE_SELECTOR.includes('dialog'), true);
equal(carousel.INTERACTIVE_SELECTOR.includes('input'), true);

equal(carousel.reduceIndex(1, 'next', 4), carousel.reduceIndex(1, carousel.swipeAction(-70, 0), 4));
equal(carousel.reduceIndex(1, 'previous', 4), carousel.reduceIndex(1, carousel.swipeAction(70, 0), 4));
equal(carousel.reduceIndex(1, {type: 'go', index: 3}, 4), 3);

console.log(`Workout UI stage 4 JS checks passed (${checks}).`);
