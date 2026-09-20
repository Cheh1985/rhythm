<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$files = [
    'view' => file_get_contents($root . '/views/sessions/workout.php'),
    'css' => file_get_contents($root . '/public/assets/workout.css'),
    'workout' => file_get_contents($root . '/public/assets/workout.js'),
    'wheel' => file_get_contents($root . '/public/assets/workout-wheel.js'),
    'layout' => file_get_contents($root . '/views/layout.php'),
    'worker' => file_get_contents($root . '/public/service-worker.js'),
    'client_i18n' => file_get_contents($root . '/public/assets/i18n.js'),
    'server_i18n' => file_get_contents($root . '/app/I18n/en.php'),
];
$failures = [];
$checks = 0;
$check = static function (bool $condition, string $message) use (&$failures, &$checks): void {
    $checks++;
    if (!$condition) $failures[] = $message;
};

$check(substr_count($files['view'], 'data-workout-wheel') === 3, 'форма содержит ровно три колёсика');
$check(str_contains($files['view'], 'data-wheel-kind="weight"') && str_contains($files['view'], 'data-wheel-kind="reps"') && str_contains($files['view'], 'data-wheel-kind="rir"'), 'колёсики отвечают за вес, повторы и RIR');
$check(substr_count($files['view'], 'role="spinbutton"') === 3 && substr_count($files['view'], 'data-wheel-selected') === 3, 'каждое выбранное значение доступно как spinbutton');
$check(substr_count($files['view'], 'data-wheel-adjust="-1"') === 3 && substr_count($files['view'], 'data-wheel-adjust="1"') === 3, 'у всех колёсиков есть явные кнопки уменьшения и увеличения');
$check(str_contains($files['view'], 'weight-input wheel-manual-input') && str_contains($files['view'], 'reps-input wheel-manual-input') && str_contains($files['view'], 'rir-input wheel-manual-input'), 'ручной ввод остаётся настоящими числовыми input');
$check(str_contains($files['view'], 'data-step="<?= e($weightStep) ?>"') && str_contains($files['view'], 'data-step="1"') && str_contains($files['view'], 'data-step="0.5"'), 'разметка передаёт шаги веса, повторов и RIR');
$check(str_contains($files['view'], 'class="weight-unit"') && str_contains($files['workout'], "queueMutation('exercise.weight-unit'"), 'переключатель kg/lb сохраняет серверную мутацию');
$check(!str_contains($files['view'], 'data-weight-direction') && !str_contains($files['view'], 'class="rir-picker"') && !str_contains($files['view'], 'class="quick-row weight-quick"'), 'старая быстрая форма чисел удалена');

$check(str_contains($files['wheel'], 'const ROW_COUNT = WINDOW_RADIUS * 2 + 1') && str_contains($files['wheel'], "root.document.createElement('span')"), 'виртуальное окно создаёт постоянное число строк');
$check(str_contains($files['wheel'], 'normalizeValue') && str_contains($files['wheel'], 'stepValue') && str_contains($files['wheel'], 'windowValues'), 'модель шага, округления и окна вынесена в тестируемый модуль');
$check(str_contains($files['wheel'], "ArrowUp") && str_contains($files['wheel'], "PageUp") && str_contains($files['wheel'], "Home") && str_contains($files['wheel'], "End"), 'колёсики имеют клавиатурные команды');
$check(str_contains($files['wheel'], "addEventListener('pointermove'") && str_contains($files['wheel'], "addEventListener('wheel'"), 'колёсики поддерживают вертикальный drag и колесо мыши');
$check(str_contains($files['wheel'], 'input.hidden = false') && str_contains($files['wheel'], 'normalizeValue(input.value'), 'центральное значение открывает и нормализует ручной input');
$check(str_contains($files['wheel'], 'async function runOnce') && str_contains($files['workout'], 'RhythmWorkoutWheel.runOnce(form'), 'повторная отправка блокируется общим guard');

$check(str_contains($files['workout'], 'if (expanded)') && str_contains($files['workout'], 'RhythmWorkoutWheel.mountWithin(body)'), 'DOM-контролы лениво создаются только при раскрытии карточки');
$check(str_contains($files['workout'], 'wheel._rhythmWorkoutWheel?.setStep(RhythmWeight.step(unit))'), 'смена единицы обновляет шаг активного колёсика');
$check(str_contains($files['workout'], 'RhythmWorkoutWheel.refreshWithin(form)') && str_contains($files['workout'], 'values.rir'), 'значения draft восстанавливаются в колёсики');
$check(str_contains($files['workout'], 'rirWheel._rhythmWorkoutWheel?.setValue(null)') && !str_contains($files['workout'], "form.querySelector('.weight-input').value = ''") && !str_contains($files['workout'], "form.querySelector('.reps-input').value = ''"), 'после подхода очищается только RIR');
$check(substr_count($files['workout'], "queueMutation('set.create'") === 1 && substr_count($files['workout'], 'await startTimer(Number(card.dataset.rest), setAction.id') === 1, 'submit создаёт один set.create и запускает один таймер');
$check(str_contains($files['workout'], "queueMutation('set.create'") && str_contains($files['workout'], 'applyOptimistic(action)') && str_contains($files['workout'], 'RhythmOffline.enqueue(action, sessionRecord)') && str_contains($files['workout'], 'captureDraft()'), 'offline queue и optimistic restore остаются в основном pipeline');

$check(str_contains($files['css'], '.wheel-inputs{') && str_contains($files['css'], 'grid-template-columns:repeat(3,minmax(0,1fr))'), 'выбранные значения стоят в одной трёхколоночной строке');
$check(str_contains($files['css'], 'touch-action:pan-x') && str_contains($files['css'], 'touch-action:manipulation'), 'вертикальный жест принадлежит колёсику, а кнопки защищены от double-tap zoom');
$check(!str_contains($files['layout'], 'user-scalable=no') && !str_contains($files['view'], 'user-scalable=no'), 'pinch zoom не запрещён');
$check(strpos($files['layout'], '/assets/workout-wheel.js') < strpos($files['layout'], '/assets/workout.js'), 'модуль колёсиков подключён до интеграционного скрипта');
$check(str_contains($files['worker'], "rhythm-shell-v10.9") && str_contains($files['worker'], "asset('./assets/workout-wheel.js')"), 'новый модуль включён в обновлённый app shell');
$check(str_contains($files['server_i18n'], "'Параметры подхода' => 'Set parameters'") && str_contains($files['client_i18n'], "'Не выбрано': 'Not selected'"), 'новые строки локализованы для RU/EN');
$check(!preg_match('~(?:cdn|unpkg|jsdelivr|cdnjs)~i', $files['wheel'] . $files['view']), 'колёсики не используют внешние зависимости');

if ($failures !== []) {
    fwrite(STDERR, "Workout UI stage 3 checks failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
fwrite(STDOUT, "Workout UI stage 3 checks passed ({$checks}).\n");
