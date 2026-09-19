(function (root) {
    'use strict';
    const factor = 0.45359237;
    const api = {
        step: (unit) => unit === 'lb' ? 5 : 0.5,
        fromKg: (kg, unit) => kg == null ? null : Math.round((Number(kg) / (unit === 'lb' ? factor : 1) + Number.EPSILON) * 100) / 100,
        fields: (set) => ({value: set.weight_value ?? set.weight_kg ?? null, unit: set.weight_unit || 'kg'}),
        label: (unit) => unit === 'lb' ? 'lb' : (root.document?.documentElement.lang === 'en' ? 'kg' : 'кг'),
    };
    root.RhythmWeight = api;
    if (typeof module !== 'undefined') module.exports = api;
})(globalThis);
