<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$files = [
    'view' => file_get_contents($root . '/views/sessions/workout.php'),
    'css' => file_get_contents($root . '/public/assets/workout.css'),
    'workout' => file_get_contents($root . '/public/assets/workout.js'),
    'carousel' => file_get_contents($root . '/public/assets/workout-carousel.js'),
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

$check(str_contains($files['view'], 'data-exercise-carousel') && str_contains($files['view'], 'data-carousel-stack'), 'список упражнений преобразован в карусель');
$check(str_contains($files['view'], 'data-carousel-current') && str_contains($files['view'], 'data-carousel-total'), 'позиция упражнения отделена от общего прогресса');
$check(str_contains($files['view'], 'data-carousel-previous') && str_contains($files['view'], 'data-carousel-next'), 'есть явные кнопки назад и вперёд');
$check(str_contains($files['view'], 'id="exercise-picker-dialog"') && str_contains($files['view'], 'data-carousel-go='), 'позиция открывает список прямого перехода');
$check(str_contains($files['view'], 'data-carousel-item-name') && str_contains($files['view'], 'data-carousel-item-status') && str_contains($files['view'], '<img'), 'список содержит имя, статус и пиктограмму');
$check(str_contains($files['view'], 'data-status="<?= e($exercise[\'status\']) ?>"'), 'статус упражнения доступен модели карусели');
$check(strpos($files['view'], 'class="finish-card"') > strpos($files['view'], '</dialog>') && strpos($files['view'], 'id="rest-timer"') > strpos($files['view'], 'class="finish-card"'), 'финиш и общий таймер остаются вне карусели');

$check(str_contains($files['carousel'], 'function reduceIndex') && str_contains($files['carousel'], 'function initialIndex'), 'индекс, границы и начальный выбор вынесены в чистые функции');
$check(str_contains($files['carousel'], "['completed', 'skipped']") && str_contains($files['carousel'], 'savedIndex'), 'начальный выбор учитывает draft и первую незавершённую карточку');
$check(str_contains($files['carousel'], 'function gestureAxis') && str_contains($files['carousel'], 'function swipeAction'), 'направление и порог жеста тестируемы отдельно');
$check(str_contains($files['carousel'], 'horizontal > vertical * dominance') && str_contains($files['carousel'], 'vertical > horizontal * dominance'), 'намерение фиксируется только при явном преобладании оси');
$check(str_contains($files['carousel'], "addEventListener('pointerdown'") && str_contains($files['carousel'], "addEventListener('pointermove'") && str_contains($files['carousel'], "addEventListener('pointercancel'"), 'карусель использует Pointer Events с отменой');
$check(str_contains($files['carousel'], "'[data-workout-wheel]'") && str_contains($files['carousel'], "'[data-history-chart]'") && str_contains($files['carousel'], "'dialog'") && str_contains($files['carousel'], "'input'"), 'колёсики, график, диалог и inputs исключены из жеста карусели');
$check(str_contains($files['carousel'], "event.key === 'ArrowLeft'") && str_contains($files['carousel'], "event.key === 'ArrowRight'"), 'клавиатурная навигация использует тот же переход');
$check(str_contains($files['carousel'], "go('previous', 'button')") && str_contains($files['carousel'], "go('next', 'button')") && str_contains($files['carousel'], "'list'") && str_contains($files['carousel'], "'swipe'"), 'кнопки, список и свайп сходятся в единой функции go');
$check(str_contains($files['carousel'], "previousButton.disabled = activeIndex <= 0") && str_contains($files['carousel'], "nextButton.disabled = activeIndex >= cards.length - 1"), 'границы отражаются disabled-состояниями');
$check(str_contains($files['carousel'], "'(prefers-reduced-motion: reduce)'") && str_contains($files['css'], '@media(prefers-reduced-motion:reduce)'), 'анимация отключается при reduced motion');
$check(!str_contains($files['carousel'], 'queueMutation') && !str_contains($files['carousel'], 'startTimer') && !str_contains($files['carousel'], 'RhythmOffline'), 'переходы не создают мутаций, действий очереди и таймеров');

$check(str_contains($files['workout'], 'activeExerciseId: exerciseCarousel?.activeId()') && str_contains($files['workout'], 'exerciseCarousel?.restore(draft?.ui?.activeExerciseId)'), 'активная карточка сохраняется и восстанавливается в draft.ui');
$check(str_contains($files['workout'], 'expandedExerciseIds') && str_contains($files['workout'], 'selectedHistorySessionByExercise'), 'существующие раскрытия и выбор истории сохраняются рядом с активной карточкой');
$check(substr_count($files['workout'], "queueMutation('set.create'") === 1 && substr_count($files['workout'], 'await startTimer(Number(card.dataset.rest), setAction.id') === 1, 'карусель не дублирует set.create и запуск таймера');
$check(substr_count($files['workout'], 'exerciseCarousel?.syncCard(card)') >= 2, 'замена и изменение статуса синхронизируют список карусели');

$check(str_contains($files['css'], '.exercise-stack>.exercise-card.is-active') && str_contains($files['css'], '.exercise-card.is-previous') && str_contains($files['css'], '.exercise-card.is-next'), 'показывается одна активная карточка и декоративные края соседних');
$check(str_contains($files['css'], 'touch-action:pan-y') && str_contains($files['css'], '.workout-wheel{') && str_contains($files['css'], 'touch-action:pan-x'), 'вертикальная прокрутка карусели и вертикальный жест колёсика разведены через touch-action');
$check(strpos($files['layout'], '/assets/workout-carousel.js') < strpos($files['layout'], '/assets/workout.js'), 'модуль карусели подключён до интеграционного скрипта');
$check(str_contains($files['worker'], "rhythm-shell-v10.10") && str_contains($files['worker'], "asset('./assets/workout-carousel.js')"), 'модуль карусели добавлен в новую версию app shell');
$check(str_contains($files['server_i18n'], "'Карусель упражнений' => 'Exercise carousel'") && str_contains($files['client_i18n'], "'Следующее упражнение': 'Next exercise'"), 'новые строки локализованы для RU/EN');
$check(!preg_match('~(?:cdn|unpkg|jsdelivr|cdnjs)~i', $files['carousel'] . $files['view']), 'карусель не использует внешние зависимости');

if ($failures !== []) {
    fwrite(STDERR, "Workout UI stage 4 checks failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
fwrite(STDOUT, "Workout UI stage 4 checks passed ({$checks}).\n");
