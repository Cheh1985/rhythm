(function (root, factory) {
    const api = factory();
    if (typeof module === 'object' && module.exports) module.exports = api;
    if (root) root.RhythmWorkoutHistory = api;
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
    'use strict';

    const colors = {below: '#df625f', within: '#48ad83', above: '#65afe4', unknown: '#89938e'};
    const svgNs = 'http://www.w3.org/2000/svg';
    const tr = (text) => typeof window !== 'undefined' && window.RhythmI18n ? window.RhythmI18n.t(text) : text;
    const number = (value) => {
        const result = String(Number(value));
        const english = typeof window !== 'undefined' && window.RhythmI18n?.locale === 'en';
        return Number.isInteger(Number(value)) || english ? result : result.replace('.', ',');
    };

    function classifyRir(rir, minimum, maximum) {
        if (rir === null || rir === undefined || minimum === null || minimum === undefined || maximum === null || maximum === undefined) return 'unknown';
        const value = Number(rir), min = Number(minimum), max = Number(maximum);
        if (![value, min, max].every(Number.isFinite)) return 'unknown';
        if (value < Math.min(min, max)) return 'below';
        if (value > Math.max(min, max)) return 'above';
        return 'within';
    }

    function visibleCount(width, total, minimumGroupWidth = 142) {
        if (total < 1) return 0;
        const usable = Math.max(1, Number(width) || minimumGroupWidth);
        return Math.max(1, Math.min(total, Math.floor(usable / minimumGroupWidth)));
    }

    function visibleWindow(width, total, selectedIndex, minimumGroupWidth = 142) {
        const count = visibleCount(width, total, minimumGroupWidth);
        if (!count) return {start: 0, end: 0, count: 0};
        const selected = Math.max(0, Math.min(total - 1, Number.isFinite(Number(selectedIndex)) ? Number(selectedIndex) : total - 1));
        const start = Math.max(0, Math.min(selected - count + 1, total - count));
        return {start, end: start + count, count};
    }

    function setAccessibleText(session, set, setIndex) {
        const target = session.target_rir_min === null || session.target_rir_max === null
            ? tr('цель RIR неизвестна')
            : tr('цель RIR') + ' ' + number(session.target_rir_min) + (Number(session.target_rir_min) === Number(session.target_rir_max) ? '' : '–' + number(session.target_rir_max));
        const rir = set.rir === null || set.rir === undefined ? tr('не указан') : number(set.rir);
        const category = classifyRir(set.rir, session.target_rir_min, session.target_rir_max);
        const categoryText = tr({below: 'ниже цели', within: 'в цели', above: 'выше цели', unknown: 'сравнение недоступно'}[category]);
        const unit = set.weight_unit === 'kg' ? tr('кг') : set.weight_unit;
        return session.scheduled_date + ', ' + tr('подход') + ' ' + (setIndex + 1) + ': ' + number(set.weight_value) + ' ' + unit + ' × ' + number(set.reps) + ', RIR ' + rir + ', ' + target + ', ' + categoryText;
    }

    function historySummary(sessions) {
        if (!sessions.length) return tr('Завершённых тренировок с этим упражнением пока нет.');
        const totalSets = sessions.reduce((sum, session) => sum + session.sets.length, 0);
        return tr('Показана история') + ': ' + sessions.length + ' ' + tr('тренировок') + ', ' + totalSets + ' ' + tr('рабочих подходов') + ', ' + sessions[0].scheduled_date + ' — ' + sessions[sessions.length - 1].scheduled_date + '.';
    }

    function el(name, attributes = {}, text = '') {
        const node = document.createElementNS(svgNs, name);
        for (const [key, value] of Object.entries(attributes)) node.setAttribute(key, String(value));
        if (text !== '') node.textContent = text;
        return node;
    }

    function parseSessions(rootNode) {
        try {
            const value = JSON.parse(rootNode.querySelector('.history-data')?.textContent || '[]');
            return Array.isArray(value) ? value : [];
        } catch (_) { return []; }
    }

    function renderChart(rootNode, sessions, selectedIndex) {
        const canvas = rootNode.querySelector('.history-canvas');
        if (!canvas || !sessions.length || canvas.clientWidth < 1) return;
        const windowRange = visibleWindow(canvas.clientWidth, sessions.length, selectedIndex);
        const visible = sessions.slice(windowRange.start, windowRange.end);
        const allSets = visible.flatMap((session) => session.sets || []);
        const maxReps = Math.max(3, ...allSets.map((set) => Number(set.reps) || 0));
        const width = Math.max(142, canvas.clientWidth), height = 218;
        const left = 25, right = 8, top = 31, baseline = 159, dateY = 207;
        const groupWidth = (width - left - right) / visible.length;
        const svg = el('svg', {viewBox: `0 0 ${width} ${height}`, 'aria-hidden': 'true', focusable: 'false'});

        svg.append(el('text', {x: left, y: 13, class: 'history-unit'}, tr('Вес') + ', ' + tr('кг')));
        for (let mark = 3; mark <= maxReps; mark += 3) {
            const y = baseline - mark / maxReps * 104;
            svg.append(el('line', {x1: left, y1: y, x2: width - right, y2: y, class: 'history-grid'}));
            svg.append(el('text', {x: left - 5, y: y + 3, class: 'history-grid-label', 'text-anchor': 'end'}, mark));
        }
        svg.append(el('text', {x: left - 5, y: baseline + 18, class: 'history-axis-label', 'text-anchor': 'end'}, 'RIR'));

        visible.forEach((session, sessionOffset) => {
            const sets = session.sets || [];
            const center = left + groupWidth * (sessionOffset + .5);
            const available = Math.min(groupWidth - 14, Math.max(26, sets.length * 34));
            const columnWidth = Math.min(25, Math.max(15, (available - Math.max(0, sets.length - 1) * 6) / Math.max(1, sets.length)));
            const gap = sets.length > 1 ? Math.min(9, (available - sets.length * columnWidth) / (sets.length - 1)) : 0;
            const blockWidth = sets.length * columnWidth + Math.max(0, sets.length - 1) * gap;
            if (sessionOffset > 0) svg.append(el('line', {x1: left + groupWidth * sessionOffset, y1: 19, x2: left + groupWidth * sessionOffset, y2: dateY - 12, class: 'history-separator'}));
            sets.forEach((set, setOffset) => {
                const x = center - blockWidth / 2 + setOffset * (columnWidth + gap);
                const reps = Math.max(0, Math.min(1000, Number(set.reps) || 0));
                const topY = baseline - reps / maxReps * 104;
                const category = classifyRir(set.rir, session.target_rir_min, session.target_rir_max);
                svg.append(el('text', {x: x + columnWidth / 2, y: 27, class: 'history-weight', 'text-anchor': 'middle'}, number(set.weight_kg)));
                svg.append(el('line', {x1: x + columnWidth / 2, y1: 31, x2: x + columnWidth / 2, y2: Math.max(34, topY - 2), class: 'history-stem'}));
                const brickGap = 2;
                const brickHeight = Math.max(2, (baseline - topY - Math.max(0, reps - 1) * brickGap) / Math.max(1, reps));
                for (let repetition = 0; repetition < reps; repetition++) {
                    const y = baseline - (repetition + 1) * (brickHeight + brickGap) + brickGap;
                    svg.append(el('rect', {x, y, width: columnWidth, height: brickHeight, rx: 1, fill: colors[category], class: 'history-brick'}));
                }
                svg.append(el('text', {x: x + columnWidth / 2, y: baseline + 18, class: 'history-rir', 'text-anchor': 'middle'}, set.rir === null ? '—' : number(set.rir)));
            });
            svg.append(el('text', {x: center, y: dateY, class: 'history-date', 'text-anchor': 'middle'}, session.scheduled_date.slice(8, 10) + '.' + session.scheduled_date.slice(5, 7)));
        });
        canvas.replaceChildren(svg);

        const hitGrid = document.createElement('div');
        hitGrid.className = 'history-hit-grid';
        hitGrid.style.gridTemplateColumns = `repeat(${visible.length}, minmax(0, 1fr))`;
        visible.forEach((session) => {
            const group = document.createElement('div');
            group.className = 'history-hit-session';
            (session.sets || []).forEach((set, index) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'history-set-button';
                button.dataset.category = classifyRir(set.rir, session.target_rir_min, session.target_rir_max);
                button.setAttribute('aria-label', setAccessibleText(session, set, index));
                button.title = button.getAttribute('aria-label');
                button.addEventListener('click', () => {
                    rootNode.querySelector('.history-detail').textContent = button.getAttribute('aria-label');
                    rootNode.querySelectorAll('.history-set-button').forEach((item) => item.setAttribute('aria-pressed', String(item === button)));
                });
                group.append(button);
            });
            hitGrid.append(group);
        });
        canvas.append(hitGrid);
        rootNode.dataset.windowStart = String(windowRange.start);
        rootNode.dataset.windowEnd = String(windowRange.end);
        rootNode.querySelector('[data-history-previous]').disabled = windowRange.start === 0;
        rootNode.querySelector('[data-history-next]').disabled = windowRange.end === sessions.length;
    }

    function initialise(rootNode) {
        if (rootNode.dataset.historyInitialised === 'true') return;
        rootNode.dataset.historyInitialised = 'true';
        const sessions = parseSessions(rootNode);
        const summary = rootNode.querySelector('.history-summary');
        if (summary) summary.textContent = historySummary(sessions);
        if (!sessions.length) return;
        const restoredSessionId = Number(rootNode.dataset.selectedSessionId || 0);
        let selected = sessions.findIndex((session) => Number(session.session_id) === restoredSessionId);
        if (selected < 0) selected = sessions.length - 1;
        const dates = rootNode.querySelector('.history-dates');
        sessions.forEach((session, index) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.textContent = session.scheduled_date.slice(8, 10) + '.' + session.scheduled_date.slice(5, 7);
            button.setAttribute('aria-label', tr('Показать тренировку за') + ' ' + session.scheduled_date);
            button.addEventListener('click', () => { selected = index; draw(); });
            dates.append(button);
        });
        function draw() {
            [...dates.children].forEach((button, index) => button.setAttribute('aria-pressed', String(index === selected)));
            rootNode.dataset.selectedSessionId = String(sessions[selected].session_id);
            renderChart(rootNode, sessions, selected);
        }
        rootNode.querySelector('[data-history-previous]').addEventListener('click', () => {
            const start = Number(rootNode.dataset.windowStart || 0);
            selected = Math.max(0, start - 1); draw();
        });
        rootNode.querySelector('[data-history-next]').addEventListener('click', () => {
            const end = Number(rootNode.dataset.windowEnd || sessions.length);
            selected = Math.min(sessions.length - 1, end); draw();
        });
        rootNode.addEventListener('rhythm-history-select', (event) => {
            const next = sessions.findIndex((session) => Number(session.session_id) === Number(event.detail));
            if (next >= 0) { selected = next; draw(); }
        });
        if ('ResizeObserver' in window) new ResizeObserver(draw).observe(rootNode.querySelector('.history-canvas'));
        else window.addEventListener('resize', draw);
        draw();
    }

    function initialiseAll(scope = document) {
        scope.querySelectorAll('[data-history-chart]').forEach((rootNode) => initialise(rootNode));
    }

    if (typeof document !== 'undefined') {
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => initialiseAll());
        else initialiseAll();
    }

    return {colors, classifyRir, visibleCount, visibleWindow, setAccessibleText, historySummary, initialiseAll};
});
