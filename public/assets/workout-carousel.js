(function (root, factory) {
    const api = factory(root);
    if (typeof module === 'object' && module.exports) module.exports = api;
    if (root) root.RhythmWorkoutCarousel = api;
})(typeof globalThis !== 'undefined' ? globalThis : this, function (root) {
    'use strict';

    const INTERACTIVE_SELECTOR = [
        '[data-workout-wheel]', '[data-history-chart]', 'dialog',
        'a', 'button', 'input', 'select', 'textarea', 'summary', 'details',
        '[contenteditable="true"]', '[role="button"]', '[role="spinbutton"]', '[role="slider"]',
    ].join(',');

    function clampIndex(index, count) {
        const total = Math.max(0, Number(count) || 0);
        if (!total) return -1;
        return Math.min(total - 1, Math.max(0, Math.trunc(Number(index) || 0)));
    }

    function reduceIndex(index, action, count) {
        if (action === 'next') return clampIndex(Number(index) + 1, count);
        if (action === 'previous') return clampIndex(Number(index) - 1, count);
        if (typeof action === 'object' && action?.type === 'go') return clampIndex(action.index, count);
        return clampIndex(action, count);
    }

    function itemId(item) {
        return String(item?.dataset?.exerciseId ?? item?.id ?? '');
    }

    function itemStatus(item) {
        if (item?.dataset?.status) return String(item.dataset.status);
        if (item?.status) return String(item.status);
        for (const status of ['pending', 'active', 'waiting', 'completed', 'skipped']) {
            if (item?.classList?.contains?.(status)) return status;
        }
        return 'pending';
    }

    function initialIndex(items, savedExerciseId) {
        const list = Array.from(items || []);
        if (!list.length) return -1;
        const saved = savedExerciseId == null ? '' : String(savedExerciseId);
        const savedIndex = saved ? list.findIndex((item) => itemId(item) === saved) : -1;
        if (savedIndex >= 0) return savedIndex;
        const unfinished = list.findIndex((item) => !['completed', 'skipped'].includes(itemStatus(item)));
        return unfinished >= 0 ? unfinished : 0;
    }

    function gestureAxis(dx, dy, threshold = 10, dominance = 1.25) {
        const horizontal = Math.abs(Number(dx) || 0);
        const vertical = Math.abs(Number(dy) || 0);
        if (Math.max(horizontal, vertical) < threshold) return null;
        if (horizontal > vertical * dominance) return 'horizontal';
        if (vertical > horizontal * dominance) return 'vertical';
        return null;
    }

    function swipeAction(dx, dy, threshold = 44, dominance = 1.25) {
        if (Math.abs(Number(dx) || 0) < threshold || gestureAxis(dx, dy, threshold, dominance) !== 'horizontal') return null;
        return Number(dx) < 0 ? 'next' : 'previous';
    }

    function isIgnoredTarget(target) {
        return Boolean(target?.closest?.(INTERACTIVE_SELECTOR));
    }

    function createCarousel(element, options = {}) {
        if (!element || element._rhythmWorkoutCarousel) return element?._rhythmWorkoutCarousel || null;
        const stack = element.querySelector('[data-carousel-stack]');
        const cards = stack ? [...stack.querySelectorAll('.exercise-card')] : [];
        if (!stack || !cards.length) return null;

        const previousButton = element.querySelector('[data-carousel-previous]');
        const nextButton = element.querySelector('[data-carousel-next]');
        const currentOutput = element.querySelector('[data-carousel-current]');
        const totalOutput = element.querySelector('[data-carousel-total]');
        const positionButton = element.querySelector('[data-carousel-position]');
        const announcer = element.querySelector('[data-carousel-announcer]');
        const picker = root.document?.querySelector('#' + positionButton?.getAttribute('aria-controls'));
        const reduceMotion = root.matchMedia?.('(prefers-reduced-motion: reduce)')?.matches === true;
        let activeIndex = initialIndex(cards, options.activeExerciseId);
        let pointer = null;

        function animate(card, direction) {
            if (!card || !direction || reduceMotion || typeof card.animate !== 'function') return;
            card.getAnimations?.().forEach((animation) => animation.cancel());
            card.animate([
                {opacity: 0.72, transform: `translateX(${direction === 'next' ? 24 : -24}px) scale(.985)`},
                {opacity: 1, transform: 'translateX(0) scale(1)'},
            ], {duration: 210, easing: 'cubic-bezier(.2,.8,.2,1)'});
        }

        function render(previousIndex = activeIndex, source = 'initial', notify = false) {
            const direction = activeIndex === previousIndex ? null : (activeIndex > previousIndex ? 'next' : 'previous');
            cards.forEach((card, index) => {
                const active = index === activeIndex;
                card.classList.toggle('is-active', active);
                card.classList.toggle('is-previous', index === activeIndex - 1);
                card.classList.toggle('is-next', index === activeIndex + 1);
                card.setAttribute('aria-hidden', String(!active));
                card.inert = !active;
            });
            element.dataset.activeExerciseId = itemId(cards[activeIndex]);
            element.dataset.transitionDirection = direction || '';
            if (currentOutput) currentOutput.textContent = String(activeIndex + 1);
            if (totalOutput) totalOutput.textContent = String(cards.length);
            if (previousButton) previousButton.disabled = activeIndex <= 0;
            if (nextButton) nextButton.disabled = activeIndex >= cards.length - 1;
            picker?.querySelectorAll('[data-carousel-go]').forEach((button, index) => {
                button.classList.toggle('is-current', index === activeIndex);
                if (index === activeIndex) button.setAttribute('aria-current', 'true');
                else button.removeAttribute('aria-current');
            });
            animate(cards[activeIndex], direction);
            if (notify && announcer) announcer.textContent = cards[activeIndex].getAttribute('aria-label') || '';
            if (notify) options.onChange?.({index: activeIndex, exerciseId: itemId(cards[activeIndex]), source});
        }

        function go(action, source = 'control', notify = true) {
            const previousIndex = activeIndex;
            activeIndex = reduceIndex(activeIndex, action, cards.length);
            render(previousIndex, source, notify && activeIndex !== previousIndex);
            return activeIndex;
        }

        function restore(exerciseId) {
            const previousIndex = activeIndex;
            activeIndex = initialIndex(cards, exerciseId);
            render(previousIndex, 'restore', false);
            return activeIndex;
        }

        function syncCard(card) {
            const button = picker?.querySelector('[data-carousel-go="' + itemId(card) + '"]');
            if (!button) return;
            const name = card.querySelector('h2')?.textContent || '';
            const status = card.querySelector('.exercise-state')?.textContent || '';
            const icon = card.querySelector('.exercise-icon');
            const itemIcon = button.querySelector('img');
            const itemName = button.querySelector('[data-carousel-item-name]');
            const itemStatusNode = button.querySelector('[data-carousel-item-status]');
            if (itemName) itemName.textContent = name;
            if (itemStatusNode) itemStatusNode.textContent = status;
            if (itemIcon && icon) { itemIcon.src = icon.src; itemIcon.alt = icon.alt; }
        }

        previousButton?.addEventListener('click', () => go('previous', 'button'));
        nextButton?.addEventListener('click', () => go('next', 'button'));
        positionButton?.addEventListener('click', () => picker?.showModal());
        picker?.addEventListener('click', (event) => {
            const button = event.target.closest('[data-carousel-go]');
            if (!button) return;
            go({type: 'go', index: cards.findIndex((card) => itemId(card) === button.dataset.carouselGo)}, 'list');
            picker.close();
            stack.focus({preventScroll: true});
        });
        picker?.querySelector('[data-carousel-picker-close]')?.addEventListener('click', () => picker.close());

        stack.addEventListener('keydown', (event) => {
            if (event.altKey || event.ctrlKey || event.metaKey || isIgnoredTarget(event.target)) return;
            if (event.key === 'ArrowLeft') { event.preventDefault(); go('previous', 'keyboard'); }
            else if (event.key === 'ArrowRight') { event.preventDefault(); go('next', 'keyboard'); }
        });

        stack.addEventListener('pointerdown', (event) => {
            if ((event.pointerType === 'mouse' && event.button !== 0) || isIgnoredTarget(event.target)) return;
            pointer = {id: event.pointerId, x: event.clientX, y: event.clientY, dx: 0, dy: 0, axis: null};
            stack.setPointerCapture?.(event.pointerId);
        });
        stack.addEventListener('pointermove', (event) => {
            if (!pointer || pointer.id !== event.pointerId) return;
            pointer.dx = event.clientX - pointer.x;
            pointer.dy = event.clientY - pointer.y;
            if (!pointer.axis) pointer.axis = gestureAxis(pointer.dx, pointer.dy);
            if (pointer.axis !== 'horizontal') return;
            event.preventDefault();
            stack.classList.add('is-dragging');
            stack.style.setProperty('--carousel-drag-x', Math.max(-72, Math.min(72, pointer.dx)) + 'px');
        });
        const endPointer = (event, cancelled = false) => {
            if (!pointer || pointer.id !== event.pointerId) return;
            const action = !cancelled && pointer.axis === 'horizontal' ? swipeAction(pointer.dx, pointer.dy) : null;
            stack.releasePointerCapture?.(pointer.id);
            pointer = null;
            stack.classList.remove('is-dragging');
            stack.style.removeProperty('--carousel-drag-x');
            if (action) go(action, 'swipe');
        };
        stack.addEventListener('pointerup', (event) => endPointer(event));
        stack.addEventListener('pointercancel', (event) => endPointer(event, true));

        const controller = {
            activeId: () => itemId(cards[activeIndex]),
            activeIndex: () => activeIndex,
            go,
            restore,
            syncCard,
        };
        element._rhythmWorkoutCarousel = controller;
        render(activeIndex, 'initial', false);
        return controller;
    }

    return {INTERACTIVE_SELECTOR, clampIndex, reduceIndex, initialIndex, gestureAxis, swipeAction, isIgnoredTarget, createCarousel};
});
