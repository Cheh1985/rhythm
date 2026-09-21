(function (root, factory) {
    const api = factory();
    if (typeof module === 'object' && module.exports) module.exports = api;
    if (root) root.RhythmWorkoutCard = api;
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
    'use strict';

    const ICON_FILES = Object.freeze({
        leg_press_001: 'leg_press_001.png',
        bench_press_001: 'bench_press_001.png',
        incline_db_press_001: 'incline_db_press_001.png',
        lat_pulldown_001: 'lat_pulldown_001.png',
        seated_cable_row_001: 'seated_cable_row_001.png',
        leg_curl_001: 'leg_curl_001.png',
        biceps_curl_001: 'biceps_curl_001.png',
        triceps_pushdown_001: 'triceps_pushdown_001.png',
        db_shoulder_press_001: 'db_shoulder_press_001.png',
        hack_squat_001: 'hack_squat_001.png',
        romanian_deadlift_001: 'romanian_deadlift_001.png',
        calf_raise_001: 'calf_raise_001.png',
        lateral_raise_001: 'lateral_raise_001.png',
        face_pull_001: 'face_pull_001.png',
        rhythm_demo_bench_press: 'bench_press_001.png',
        rhythm_demo_face_pull: 'face_pull_001.png',
        rhythm_demo_lateral_raise: 'lateral_raise_001.png',
        rhythm_demo_shoulder_press: 'db_shoulder_press_001.png',
        rhythm_demo_lat_pulldown: 'lat_pulldown_001.png',
        rhythm_demo_leg_press: 'leg_press_001.png',
        rhythm_demo_leg_curl: 'leg_curl_001.png',
        rhythm_demo_romanian_deadlift: 'romanian_deadlift_001.png',
        rhythm_demo_cable_row: 'seated_cable_row_001.png',
    });
    const ICON_IDS = Object.freeze(Object.keys(ICON_FILES));

    function exerciseId(value) {
        const id = typeof value === 'string' ? value : '';
        return /^[a-z0-9_]{1,80}$/.test(id) ? id : '';
    }

    function iconFilename(value) {
        const id = exerciseId(value);
        return id && ICON_FILES[id] ? ICON_FILES[id] : 'generic.svg';
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

    return {ICON_FILES, ICON_IDS, exerciseId, iconFilename, iconUrl, normalizeExpandedIds, setExpanded};
});
