(function (root, factory) {
    const api = factory();
    if (typeof module === 'object' && module.exports) module.exports = api;
    root.RhythmRestTimer = api;
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
    'use strict';

    const TERMINAL = new Set(['completed', 'ended_early']);
    const validId = (value) => typeof value === 'string' && /^[a-zA-Z0-9._:-]{8,80}$/.test(value);
    const finite = (value) => Number.isFinite(Number(value));
    const copy = (state, changes = {}) => ({...state, ...changes});

    function create(input, now = Date.now()) {
        const duration = Math.trunc(Number(input?.duration));
        if (!validId(input?.id) || !Number.isInteger(duration) || duration < 1 || duration > 3600 || !finite(now)) {
            throw new TypeError('Некорректные параметры таймера отдыха.');
        }
        return {
            version: 1,
            id: input.id,
            eventActionId: input.eventActionId || ('rest.finish:' + input.id),
            sourceSetActionId: input.sourceSetActionId || null,
            sessionExerciseId: Number(input.sessionExerciseId) || null,
            duration,
            remaining: duration,
            startedAt: Number(now),
            endAt: Number(now) + duration * 1000,
            paused: false,
            status: 'running',
            outcome: null,
            trigger: null,
            endedAt: null,
            eventEnqueued: false,
            notified: false,
        };
    }

    function restore(value) {
        if (!value || value.version !== 1 || !validId(value.id) || !validId(value.eventActionId)
            || !Number.isInteger(Number(value.duration)) || Number(value.duration) < 1 || Number(value.duration) > 3600
            || !finite(value.startedAt) || !finite(value.endAt) || !finite(value.remaining)
            || !['running', 'paused', 'completed', 'ended_early'].includes(value.status)) return null;
        const terminal = TERMINAL.has(value.status);
        if (terminal && (!finite(value.endedAt) || value.outcome !== value.status)) return null;
        return {
            ...value,
            duration: Number(value.duration),
            remaining: Math.max(0, Number(value.remaining)),
            startedAt: Number(value.startedAt),
            endAt: Number(value.endAt),
            sessionExerciseId: Number(value.sessionExerciseId) || null,
            paused: value.status === 'paused',
            outcome: terminal ? value.status : null,
            trigger: terminal ? String(value.trigger || (value.status === 'completed' ? 'timer_elapsed' : 'user')) : null,
            endedAt: terminal ? Number(value.endedAt) : null,
            eventEnqueued: value.eventEnqueued === true,
            notified: value.notified === true,
        };
    }

    function isTerminal(state) {
        return Boolean(state && TERMINAL.has(state.status));
    }

    function remainingSeconds(state, now = Date.now()) {
        if (!state || isTerminal(state)) return 0;
        if (state.paused) return Math.max(0, Math.ceil(Number(state.remaining)));
        return Math.max(0, Math.ceil((Number(state.endAt) - Number(now)) / 1000));
    }

    function complete(state, endedAt, trigger = 'timer_elapsed') {
        return copy(state, {
            remaining: 0,
            paused: false,
            status: 'completed',
            outcome: 'completed',
            trigger,
            endedAt: Number(endedAt),
        });
    }

    function reconcile(state, now = Date.now()) {
        if (!state || isTerminal(state) || state.paused || Number(now) < Number(state.endAt)) {
            return {state, finalized: false};
        }
        return {state: complete(state, state.endAt), finalized: true};
    }

    function finish(state, now = Date.now(), trigger = 'user') {
        const checked = reconcile(state, now);
        if (!checked.state || isTerminal(checked.state)) return checked;
        return {
            state: copy(checked.state, {
                remaining: remainingSeconds(checked.state, now),
                paused: false,
                status: 'ended_early',
                outcome: 'ended_early',
                trigger,
                endedAt: Number(now),
            }),
            finalized: true,
        };
    }

    function pause(state, now = Date.now()) {
        const checked = reconcile(state, now);
        if (!checked.state || isTerminal(checked.state)) return checked;
        return {state: copy(checked.state, {remaining: remainingSeconds(checked.state, now), paused: true, status: 'paused'}), finalized: false};
    }

    function resume(state, now = Date.now()) {
        if (!state || isTerminal(state) || !state.paused) return {state, finalized: false};
        const remaining = remainingSeconds(state, now);
        if (remaining <= 0) return {state: complete(state, now), finalized: true};
        return {state: copy(state, {remaining, endAt: Number(now) + remaining * 1000, paused: false, status: 'running'}), finalized: false};
    }

    function reset(state, now = Date.now()) {
        const checked = reconcile(state, now);
        if (!checked.state || isTerminal(checked.state)) return checked;
        return {state: copy(checked.state, {remaining: checked.state.duration, endAt: Number(now) + checked.state.duration * 1000, paused: false, status: 'running'}), finalized: false};
    }

    function add(state, seconds, now = Date.now()) {
        const checked = reconcile(state, now);
        const delta = Math.trunc(Number(seconds));
        if (!checked.state || isTerminal(checked.state) || !Number.isInteger(delta) || delta < 1 || delta > 3600) return checked;
        if (checked.state.paused) return {state: copy(checked.state, {remaining: remainingSeconds(checked.state, now) + delta}), finalized: false};
        return {state: copy(checked.state, {endAt: Number(checked.state.endAt) + delta * 1000}), finalized: false};
    }

    function eventPayload(state) {
        if (!isTerminal(state)) throw new TypeError('Незавершённый отдых нельзя записать в журнал.');
        return {
            timer_id: state.id,
            session_exercise_id: state.sessionExerciseId,
            source_set_client_action_id: state.sourceSetActionId,
            duration_seconds: state.duration,
            started_at_utc: new Date(state.startedAt).toISOString(),
            deadline_at_utc: new Date(state.endAt).toISOString(),
            ended_at_utc: new Date(state.endedAt).toISOString(),
            outcome: state.outcome,
            trigger: state.trigger,
        };
    }

    return {create, restore, isTerminal, remainingSeconds, reconcile, finish, pause, resume, reset, add, eventPayload};
});
