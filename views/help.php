<section class="page-head help-head">
    <div>
        <p class="eyebrow">Простая инструкция</p>
        <h1>Как пользоваться «Ритмом»</h1>
        <p class="muted">«Ритм» запоминает ваши тренировки и показывает прогресс. Вот весь основной путь — шаг за шагом.</p>
    </div>
</section>

<nav class="help-shortcuts card" aria-label="Разделы инструкции">
    <strong>Быстрый переход</strong>
    <div>
        <a href="#first-workout">Первая тренировка</a>
        <a href="#during-workout">Во время занятия</a>
        <a href="#results">Результаты</a>
        <a href="#other-features">Другие возможности</a>
        <a href="#offline">Без интернета</a>
    </div>
</nav>

<section class="help-note">
    <span aria-hidden="true">★</span>
    <div><strong>Если хочется начать прямо сейчас</strong><p>Нужен файл плана в формате JSON. Его может дать тренер или подготовить ChatGPT. Затем нажмите кнопку «План» внизу экрана.</p></div>
</section>

<section class="section-block" id="first-workout">
    <div class="section-title"><div><p class="eyebrow">Главный путь</p><h2>Первая тренировка</h2></div></div>
    <ol class="help-steps">
        <li class="card">
            <span class="help-step-number">1</span>
            <div><h3>Добавьте план</h3><p>Нажмите <strong>«План»</strong> внизу. Выберите JSON-файл и нажмите <strong>«Проверить файл»</strong>.</p><p>Приложение сначала покажет дату, упражнения и количество подходов. Если всё верно, нажмите <strong>«Сохранить план и версию»</strong>.</p><a class="button button-quiet" href="<?= e(url('/plans/import')) ?>">Добавить план</a></div>
        </li>
        <li class="card">
            <span class="help-step-number">2</span>
            <div><h3>Откройте тренировку</h3><p>Перейдите на экран <strong>«Сегодня»</strong> и нажмите <strong>«Открыть тренировку»</strong>.</p><p>Перед стартом оцените сон, энергию и готовность от 1 до 5: <strong>1 — плохо, 5 — отлично</strong>. После этого нажмите <strong>«Начать тренировку»</strong>.</p><a class="button button-quiet" href="<?= e(url('/')) ?>">На экран «Сегодня»</a></div>
        </li>
        <li class="card">
            <span class="help-step-number">3</span>
            <div><h3>Записывайте подходы</h3><p>Выберите рабочий или разминочный подход. Укажите вес, повторы и RIR. Нажмите <strong>«Готово · запустить отдых»</strong>.</p><p>После сохранения включится таймер отдыха. Повторяйте это после каждого подхода.</p></div>
        </li>
        <li class="card">
            <span class="help-step-number">4</span>
            <div><h3>Завершите занятие</h3><p>Когда упражнение закончено, нажмите <strong>«Завершить»</strong>. В самом низу страницы оцените общую тяжесть и самочувствие, затем нажмите <strong>«Завершить и показать итоги»</strong>.</p></div>
        </li>
    </ol>
</section>

<section class="section-block" id="during-workout">
    <div class="section-title"><div><p class="eyebrow">Без сложных слов</p><h2>Что вводить во время занятия</h2></div></div>
    <div class="help-grid">
        <article class="card"><span class="help-icon coral" aria-hidden="true">кг</span><h3>Вес</h3><p>Вес снаряда в килограммах. Например: <strong>20 кг</strong>.</p></article>
        <article class="card"><span class="help-icon green" aria-hidden="true">×</span><h3>Повторы</h3><p>Сколько раз вы сделали движение. Например: <strong>10 повторов</strong>.</p></article>
        <article class="card"><span class="help-icon blue" aria-hidden="true">RIR</span><h3>Повторы в запасе</h3><p>Сколько повторов вы ещё смогли бы сделать: <strong>0</strong> — сил больше не осталось, <strong>2</strong> — смогли бы ещё два, <strong>5+</strong> — запас большой.</p></article>
        <article class="card"><span class="help-icon amber" aria-hidden="true">Р/П</span><h3>Разминка и работа</h3><p><strong>Разминка</strong> готовит тело. <strong>Рабочий</strong> — основной подход, который попадёт в расчёт объёма.</p></article>
    </div>

    <div class="help-actions card">
        <h3>Если что-то пошло не по плану</h3>
        <dl>
            <div><dt>Оборудование занято</dt><dd>Пометьте упражнение и вернитесь к нему позже.</dd></div>
            <div><dt>Пропустить</dt><dd>Выберите причину, если не будете делать упражнение.</dd></div>
            <div><dt>Заменить</dt><dd>Выберите другое упражнение и напишите причину.</dd></div>
            <div><dt>Дискомфорт</dt><dd>Запишите место и силу неприятного ощущения. При боли остановитесь и обратитесь к взрослому, тренеру или врачу.</dd></div>
        </dl>
    </div>
</section>

<section class="section-block" id="results">
    <div class="section-title"><div><p class="eyebrow">После занятия</p><h2>Где смотреть результаты</h2></div></div>
    <div class="action-list">
        <a href="<?= e(url('/history')) ?>"><span class="action-icon green">◷</span><span><strong>История</strong><small>Все прошлые силовые тренировки и плавание</small></span><b>›</b></a>
        <a href="<?= e(url('/analytics')) ?>"><span class="action-icon blue">⌁</span><span><strong>Аналитика</strong><small>Графики, объём занятий и личные рекорды</small></span><b>›</b></a>
        <a href="<?= e(url('/measurements')) ?>"><span class="action-icon coral">↗</span><span><strong>Тело</strong><small>Вес и размеры тела по датам</small></span><b>›</b></a>
    </div>
    <div class="help-note section-block">
        <span aria-hidden="true">⇩</span>
        <div><strong>Отчёт для тренера или ChatGPT</strong><p>Откройте завершённую тренировку в «Истории» и скачайте Markdown, JSON или оба файла. Markdown удобно читать человеку, JSON — использовать для точного анализа.</p></div>
    </div>
</section>

<section class="section-block" id="other-features">
    <div class="section-title"><div><p class="eyebrow">По желанию</p><h2>Другие возможности</h2></div></div>
    <div class="help-grid">
        <article class="card"><h3>Плавание</h3><p>Откройте <a href="<?= e(url('/swimming')) ?>">«Плавание»</a>, укажите дату, время, дистанцию и стиль. В блоках сумма повторов и метров должна равняться общей дистанции.</p></article>
        <article class="card"><h3>Расписание</h3><p>На странице <a href="<?= e(url('/schedule')) ?>">«Расписание»</a> отметьте дни занятий и выберите их тип: зал, бассейн или другое.</p></article>
        <article class="card"><h3>Измерения</h3><p>На странице <a href="<?= e(url('/measurements')) ?>">«Тело»</a> нажмите <strong>«Новое измерение»</strong>. Можно записать только те показатели, которые вы знаете.</p></article>
        <article class="card"><h3>Установка на телефон</h3><p>На iPhone откройте сайт в Safari, нажмите <strong>«Поделиться» → «На экран Домой»</strong>. На Android откройте меню браузера и выберите <strong>«Установить приложение»</strong>.</p></article>
    </div>
</section>

<section class="section-block" id="offline">
    <div class="section-title"><div><p class="eyebrow">Связь пропала</p><h2>Работа без интернета</h2></div></div>
    <div class="card help-offline">
        <p>Если интернет пропал во время тренировки, продолжайте записывать подходы. «Ритм» сохранит их на телефоне.</p>
        <ol>
            <li>Не выходите из аккаунта и не очищайте данные браузера.</li>
            <li>Когда интернет появится, снова откройте приложение.</li>
            <li>Подождите, пока на экране появится статус <strong>«Синхронизировано»</strong>.</li>
        </ol>
        <p>Если появится ошибка или конфликт, не удаляйте локальные данные. Прочитайте сообщение на экране и выберите, какие данные оставить.</p>
    </div>
</section>

<section class="section-block">
    <div class="section-title"><div><p class="eyebrow">Чтобы ничего не потерять</p><h2>Резервная копия</h2></div></div>
    <div class="card help-backup"><p>Иногда открывайте <strong>«Настройки»</strong> и скачивайте резервную копию. Это файл со всей вашей историей. Храните его в надёжном месте.</p><a class="button button-primary" href="<?= e(url('/settings')) ?>">Открыть настройки</a></div>
</section>
