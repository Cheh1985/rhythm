(function (root) {
    'use strict';

    const WINDOW_RADIUS = 2;
    const ROW_COUNT = WINDOW_RADIUS * 2 + 1;

    function decimalPlaces(value) {
        const text = String(value);
        if (text.includes('e-')) return Number(text.split('e-')[1]) || 0;
        return (text.split('.')[1] || '').length;
    }

    function rounded(value, step = 1) {
        const places = Math.min(6, Math.max(decimalPlaces(step), 2));
        return Number(Number(value).toFixed(places));
    }

    function settings(options = {}) {
        return {
            min: Number.isFinite(Number(options.min)) ? Number(options.min) : 0,
            max: Number.isFinite(Number(options.max)) ? Number(options.max) : 2000,
            step: Number(options.step) > 0 ? Number(options.step) : 1,
            allowEmpty: options.allowEmpty === true || options.allowEmpty === 'true',
        };
    }

    function coerceValue(value, options = {}) {
        const config = settings(options);
        if (value === '' || value === null || value === undefined) return config.allowEmpty ? null : config.min;
        const numeric = Number(value);
        if (!Number.isFinite(numeric)) return config.allowEmpty ? null : config.min;
        return rounded(Math.min(config.max, Math.max(config.min, numeric)), config.step);
    }

    function normalizeValue(value, options = {}) {
        const config = settings(options);
        const numeric = coerceValue(value, config);
        if (numeric === null) return null;
        const steps = Math.round((numeric - config.min) / config.step);
        return rounded(Math.min(config.max, Math.max(config.min, config.min + steps * config.step)), config.step);
    }

    function stepValue(value, direction, options = {}) {
        const config = settings(options);
        const numeric = coerceValue(value, {...config, allowEmpty: true});
        if (numeric === null) return config.min;
        const multiplier = Number(direction) < 0 ? -1 : 1;
        return rounded(Math.min(config.max, Math.max(config.min, numeric + config.step * multiplier)), config.step);
    }

    function windowValues(value, options = {}, radius = WINDOW_RADIUS) {
        const config = settings(options);
        const center = coerceValue(value, {...config, allowEmpty: true});
        const anchor = center === null ? config.min : center;
        const values = [];
        for (let offset = -radius; offset <= radius; offset++) {
            const candidate = rounded(anchor + offset * config.step, config.step);
            values.push(candidate < config.min || candidate > config.max ? null : candidate);
        }
        return values;
    }

    function formatValue(value, locale = root.document?.documentElement?.lang || 'ru') {
        if (value === null || value === undefined || value === '') return '—';
        const text = String(rounded(Number(value), 0.000001));
        return locale === 'ru' ? text.replace('.', ',') : text;
    }

    function translated(text) {
        return root.RhythmI18n?.t(text) || text;
    }

    function emit(input, type) {
        if (!input || typeof input.dispatchEvent !== 'function') return;
        const EventConstructor = root.Event || Event;
        input.dispatchEvent(new EventConstructor(type, {bubbles: true}));
    }

    function createControl(element) {
        if (!element || element._rhythmWorkoutWheel) return element?._rhythmWorkoutWheel || null;
        const input = element.querySelector('[data-wheel-input]');
        const selected = element.querySelector('[data-wheel-selected]');
        const track = element.querySelector('[data-wheel-values]');
        if (!input || !selected || !track) return null;

        let config = settings(element.dataset);
        let current = coerceValue(input.value, config);
        let beforeEdit = current;
        let pointerId = null;
        let pointerY = 0;
        let pointerDistance = 0;
        let suppressClick = false;
        let wheelLocked = false;
        const rows = [];

        for (let index = 0; index < ROW_COUNT; index++) {
            const row = root.document.createElement('span');
            row.className = 'wheel-value' + (index === WINDOW_RADIUS ? ' selected-slot' : '');
            row.setAttribute('aria-hidden', 'true');
            track.append(row);
            rows.push(row);
        }

        function valueText(value) {
            const formatted = value === null ? translated('Не выбрано') : formatValue(value);
            return value === null || !element.dataset.wheelUnit ? formatted : formatted + ' ' + element.dataset.wheelUnit;
        }

        function render() {
            const values = windowValues(current, config);
            rows.forEach((row, index) => {
                row.textContent = values[index] === null ? '' : formatValue(values[index]);
            });
            selected.textContent = formatValue(current);
            selected.setAttribute('aria-valuemin', String(config.min));
            selected.setAttribute('aria-valuemax', String(config.max));
            selected.setAttribute('aria-valuetext', valueText(current));
            if (current === null) selected.removeAttribute('aria-valuenow');
            else selected.setAttribute('aria-valuenow', String(current));
            input.value = current === null ? '' : String(current);
        }

        function write(value, notify = true, normalize = false) {
            current = (normalize ? normalizeValue : coerceValue)(value, config);
            render();
            if (notify) {
                emit(input, 'input');
                emit(input, 'change');
            }
            return current;
        }

        function adjust(direction, multiplier = 1) {
            let next = current;
            for (let count = 0; count < multiplier; count++) next = stepValue(next, direction, config);
            return write(next);
        }

        function openEditor() {
            beforeEdit = current;
            selected.hidden = true;
            input.hidden = false;
            input.value = current === null ? '' : String(current);
            input.focus();
            input.select();
        }

        function closeEditor(commit = true) {
            if (input.hidden) return current;
            const next = commit ? normalizeValue(input.value, config) : beforeEdit;
            input.hidden = true;
            selected.hidden = false;
            return write(next, commit);
        }

        function onSelectedClick(event) {
            if (suppressClick) {
                suppressClick = false;
                event.preventDefault();
                return;
            }
            openEditor();
        }

        function onSelectedKeydown(event) {
            const actions = {
                ArrowUp: [1, 1], ArrowRight: [1, 1], ArrowDown: [-1, 1], ArrowLeft: [-1, 1],
                PageUp: [1, 10], PageDown: [-1, 10], Home: ['home', 1], End: ['end', 1],
            };
            const action = actions[event.key];
            if (!action) return;
            event.preventDefault();
            if (action[0] === 'home') write(config.min);
            else if (action[0] === 'end') write(config.max, true, true);
            else adjust(action[0], action[1]);
        }

        function onInputKeydown(event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                closeEditor(true);
                selected.focus();
            } else if (event.key === 'Escape') {
                event.preventDefault();
                closeEditor(false);
                selected.focus();
            }
        }

        function onPointerDown(event) {
            if (event.pointerType === 'mouse' && event.button !== 0) return;
            if (event.target.closest('[data-wheel-adjust],input,select')) return;
            pointerId = event.pointerId;
            pointerY = event.clientY;
            pointerDistance = 0;
            element.setPointerCapture?.(pointerId);
        }

        function onPointerMove(event) {
            if (pointerId !== event.pointerId) return;
            const delta = pointerY - event.clientY;
            pointerY = event.clientY;
            pointerDistance += delta;
            while (Math.abs(pointerDistance) >= 28) {
                adjust(pointerDistance > 0 ? 1 : -1);
                pointerDistance += pointerDistance > 0 ? -28 : 28;
                suppressClick = true;
            }
        }

        function onPointerEnd(event) {
            if (pointerId !== event.pointerId) return;
            element.releasePointerCapture?.(pointerId);
            pointerId = null;
            pointerDistance = 0;
        }

        function onWheel(event) {
            if (Math.abs(event.deltaY) < Math.abs(event.deltaX) || wheelLocked) return;
            event.preventDefault();
            wheelLocked = true;
            adjust(event.deltaY < 0 ? 1 : -1);
            root.setTimeout(() => { wheelLocked = false; }, 80);
        }

        selected.addEventListener('click', onSelectedClick);
        selected.addEventListener('keydown', onSelectedKeydown);
        input.addEventListener('keydown', onInputKeydown);
        input.addEventListener('blur', () => closeEditor(true));
        element.querySelectorAll('[data-wheel-adjust]').forEach((button) => {
            button.addEventListener('click', () => adjust(Number(button.dataset.wheelAdjust)));
        });
        element.addEventListener('pointerdown', onPointerDown);
        element.addEventListener('pointermove', onPointerMove);
        element.addEventListener('pointerup', onPointerEnd);
        element.addEventListener('pointercancel', onPointerEnd);
        element.addEventListener('wheel', onWheel, {passive: false});

        const controller = {
            adjust,
            commit: () => closeEditor(true),
            refresh: () => { current = coerceValue(input.value, config); render(); return current; },
            setStep: (step) => { config = settings({...config, step}); element.dataset.step = String(config.step); render(); },
            setUnit: (unit) => { element.dataset.wheelUnit = unit || ''; render(); },
            setValue: (value, notify = false, normalize = false) => write(value, notify, normalize),
            value: () => current,
            rowCount: () => rows.length,
        };
        element._rhythmWorkoutWheel = controller;
        element.dataset.wheelReady = 'true';
        render();
        return controller;
    }

    function mountWithin(scope) {
        if (!scope?.querySelectorAll) return [];
        return [...scope.querySelectorAll('[data-workout-wheel]')].map(createControl).filter(Boolean);
    }

    function refreshWithin(scope) {
        if (!scope?.querySelectorAll) return [];
        return [...scope.querySelectorAll('[data-workout-wheel]')].map((element) => element._rhythmWorkoutWheel?.refresh()).filter((value) => value !== undefined);
    }

    function restoreValuesWithin(scope, values = {}, prepare = null) {
        if (!scope?.querySelector) return [];
        if (typeof prepare === 'function') prepare();
        const fields = [
            ['.weight-input', values.weight],
            ['.reps-input', values.reps],
            ['.rir-input', values.rir],
        ];
        fields.forEach(([selector, value]) => {
            const input = scope.querySelector(selector);
            if (input && value !== undefined && value !== null) input.value = String(value);
        });
        return refreshWithin(scope);
    }

    function commitWithin(scope) {
        if (!scope?.querySelectorAll) return;
        scope.querySelectorAll('[data-workout-wheel]').forEach((element) => element._rhythmWorkoutWheel?.commit());
    }

    async function runOnce(target, task) {
        if (!target?.dataset || target.dataset.submitting === 'true') return null;
        target.dataset.submitting = 'true';
        try {
            return await task();
        } finally {
            delete target.dataset.submitting;
        }
    }

    const api = {
        WINDOW_RADIUS,
        ROW_COUNT,
        coerceValue,
        normalizeValue,
        stepValue,
        windowValues,
        formatValue,
        createControl,
        mountWithin,
        refreshWithin,
        restoreValuesWithin,
        commitWithin,
        runOnce,
    };
    root.RhythmWorkoutWheel = api;
    if (typeof module !== 'undefined') module.exports = api;
})(globalThis);
