(function (root, factory) {
    const api = factory();
    if (typeof module === 'object' && module.exports) module.exports = api;
    if (root) root.RhythmWorkoutCard = api;
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
    'use strict';

    const ICON_IDS = Object.freeze([
        'leg_press_001', 'bench_press_001', 'incline_db_press_001', 'lat_pulldown_001',
        'seated_cable_row_001', 'leg_curl_001', 'biceps_curl_001', 'triceps_pushdown_001',
        'db_shoulder_press_001', 'hack_squat_001', 'romanian_deadlift_001', 'calf_raise_001',
        'lateral_raise_001', 'face_pull_001',
    ]);
    const ICON_SET = new Set(ICON_IDS);

    function exerciseId(value) {
        const id = typeof value === 'string' ? value : '';
        return /^[a-z0-9_]{1,80}$/.test(id) ? id : '';
    }

    function iconFilename(value) {
        const id = exerciseId(value);
        return id && ICON_SET.has(id) ? id + '.svg' : 'generic.svg';
    }

    function iconUrl(value, base) {
        return String(base || '').replace(/\/?$/, '/') + iconFilename(value);
    }

    function normalizeExpandedIds(values, validIds) {
        const valid = new Set((validIds || []).map(String));
        if (!Array.isArray(values)) return [];
        return [...new Set(values.map(String).filter((id) => valid.has(id)))];
    }

    function setExpanded(values, exerciseIdValue, expanded) {
        const id = String(exerciseIdValue);
        const result = new Set(Array.isArray(values) ? values.map(String) : []);
        if (expanded) result.add(id);
        else result.delete(id);
        return [...result];
    }

    return {ICON_IDS, exerciseId, iconFilename, iconUrl, normalizeExpandedIds, setExpanded};
});
