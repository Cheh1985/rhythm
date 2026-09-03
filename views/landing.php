<section class="landing-hero" aria-labelledby="landing-title">
    <div class="landing-hero-copy">
        <p class="landing-kicker"><span></span> Дневник, который даёт ИИ контекст</p>
        <h1 id="landing-title">Тренируйтесь не наугад.<br><em>Опирайтесь на свои данные.</em></h1>
        <p class="landing-lead">«Ритм» сохраняет план, каждый выполненный подход и ваше самочувствие, чтобы ChatGPT или другой ИИ мог стать внимательным тренером — увидеть реальную нагрузку, объяснить прогресс и подготовить следующую тренировку.</p>
        <div class="landing-actions">
            <a class="button button-coral" href="<?= e(url('/register')) ?>">Начать вести дневник <span aria-hidden="true">→</span></a>
            <a class="button button-outline" href="<?= e(url('/login')) ?>">У меня уже есть аккаунт</a>
        </div>
        <ul class="landing-trust" aria-label="Ключевые свойства">
            <li><span aria-hidden="true">✓</span> Подходит новичкам</li>
            <li><span aria-hidden="true">✓</span> Данные остаются у вас</li>
            <li><span aria-hidden="true">✓</span> Работает в зале без сети</li>
        </ul>
    </div>

    <div class="landing-product" aria-label="Пример работы сервиса">
        <div class="landing-orbit landing-orbit-one"></div>
        <div class="landing-orbit landing-orbit-two"></div>
        <article class="workout-preview">
            <header><div><small>Сегодня · Верх тела</small><strong>Жим гантелей</strong></div><span>2 / 4</span></header>
            <div class="preview-set preview-set-done"><b>1</b><span><strong>12 кг × 12</strong><small>RIR 3 · разминка</small></span><i>✓</i></div>
            <div class="preview-set preview-set-done"><b>2</b><span><strong>18 кг × 10</strong><small>RIR 2 · рабочий</small></span><i>✓</i></div>
            <div class="preview-set preview-set-active"><b>3</b><span><strong>18 кг × 9</strong><small>RIR 1 · рабочий</small></span><i>●</i></div>
            <div class="preview-rest"><span>Отдых</span><strong>01:24</strong></div>
        </article>
        <article class="ai-preview">
            <div class="ai-preview-head"><span>AI</span><strong>Разбор тренировки</strong></div>
            <p>Вес подобран верно: техника стабильна, запас снизился плавно.</p>
            <div class="ai-suggestion"><small>Следующий шаг</small><strong>18 кг · 3 × 10</strong></div>
        </article>
        <div class="format-chip format-json">JSON</div><div class="format-chip format-md">MD</div>
    </div>
</section>

<section class="landing-problem" aria-labelledby="problem-title">
    <div><p class="landing-section-label">Знакомая ситуация?</p><h2 id="problem-title">В начале сложно понять, сколько вы действительно можете</h2></div>
    <div class="problem-list">
        <p><span>01</span> Какой вес взять, чтобы нагрузка была полезной, а не случайной?</p>
        <p><span>02</span> Сколько подходов и повторов делать именно сейчас?</p>
        <p><span>03</span> Когда повышать вес, а когда лучше восстановиться?</p>
    </div>
    <p class="problem-answer">«Ритм» превращает ощущения и цифры из зала в понятную историю. ИИ анализирует не абстрактный шаблон из интернета, а ваши фактические подходы, запас повторений, сложность и динамику.</p>
</section>

<section class="landing-how" id="how-it-works" aria-labelledby="how-title">
    <div class="landing-section-heading">
        <p class="landing-section-label">Один понятный цикл</p>
        <h2 id="how-title">Записали. Обсудили с ИИ. Стали сильнее.</h2>
        <p>Приложение отвечает за точные записи и удобство в зале. ИИ — за анализ, объяснения и адаптацию плана.</p>
    </div>
    <ol class="landing-steps">
        <li><span class="step-number">01</span><div class="step-icon coral">↘</div><h3>Проведите тренировку</h3><p>Отмечайте вес, повторы, RIR, самочувствие и замены упражнений. Незавершённая тренировка сохраняется даже при нестабильном интернете.</p></li>
        <li><span class="step-number">02</span><div class="step-icon amber">⇧</div><h3>Передайте факты в ИИ</h3><p>Экспортируйте структурированный отчёт в JSON или читаемый Markdown и отправьте его в привычный чат с ChatGPT или другим ИИ.</p></li>
        <li><span class="step-number">03</span><div class="step-icon blue">✦</div><h3>Получите разбор и план</h3><p>ИИ увидит динамику, заметит перегрузку или недоработку и предложит следующий план с понятной логикой изменений.</p></li>
        <li><span class="step-number">04</span><div class="step-icon green">↙</div><h3>Импортируйте и тренируйтесь</h3><p>Проверьте новый план перед импортом, загрузите его в «Ритм» и используйте как удобную пошаговую карточку на следующей тренировке.</p></li>
    </ol>
</section>

<section class="landing-file-exchange" id="data-exchange" aria-labelledby="file-exchange-title">
    <div class="landing-section-heading file-exchange-heading">
        <p class="landing-section-label">Основной способ · JSON + Markdown</p>
        <h2 id="file-exchange-title">Вы сами переносите контекст между «Ритмом» и ИИ</h2>
        <p>Надёжный и прозрачный сценарий, который работает с ChatGPT и любым другим ИИ, умеющим читать файлы. На каждом этапе вы видите, какие данные передаются и какой план возвращается.</p>
    </div>

    <ol class="file-flow" aria-label="Схема обмена данными через JSON и Markdown">
        <li class="file-flow-card">
            <span class="file-flow-number">01</span>
            <div class="file-flow-icon file-flow-app">Р</div>
            <small>В приложении</small>
            <strong>Завершите тренировку</strong>
            <p>«Ритм» собирает план, выполненные подходы, RIR, замены и самочувствие.</p>
        </li>
        <li class="file-flow-arrow" aria-hidden="true"><span>Экспорт</span><b>→</b></li>
        <li class="file-flow-card file-flow-files">
            <span class="file-flow-number">02</span>
            <div class="file-pair"><span>JSON</span><span>MD</span></div>
            <small>Два представления</small>
            <strong>Скачайте отчёт</strong>
            <p>JSON хранит точную структуру, Markdown удобно читать и обсуждать.</p>
        </li>
        <li class="file-flow-arrow" aria-hidden="true"><span>В чат</span><b>→</b></li>
        <li class="file-flow-card file-flow-ai">
            <span class="file-flow-number">03</span>
            <div class="file-flow-icon">AI</div>
            <small>В выбранном ИИ</small>
            <strong>Получите разбор</strong>
            <p>ИИ сопоставляет план и факт, объясняет динамику и готовит новый JSON-план.</p>
        </li>
        <li class="file-flow-arrow" aria-hidden="true"><span>Импорт</span><b>→</b></li>
        <li class="file-flow-card file-flow-result">
            <span class="file-flow-number">04</span>
            <div class="file-flow-icon">✓</div>
            <small>Снова в «Ритме»</small>
            <strong>Проверьте новый план</strong>
            <p>Посмотрите превью, подтвердите импорт и используйте план на тренировке.</p>
        </li>
    </ol>

    <div class="file-format-notes">
        <article><span>{ }</span><div><strong>JSON — для точности</strong><p>Вес, повторы, даты и связи остаются в строгой структуре без потерь при переносе.</p></div></article>
        <article><span>¶</span><div><strong>Markdown — для диалога</strong><p>Человекочитаемый отчёт легко просмотреть, дополнить вопросом и сохранить рядом с перепиской.</p></div></article>
        <article><span>◇</span><div><strong>Вы контролируете обмен</strong><p>Файл отправляется только тогда, когда вы сами решите передать его выбранному ИИ.</p></div></article>
    </div>
</section>

<section class="landing-webmcp" id="webmcp" aria-labelledby="webmcp-title">
    <div class="webmcp-heading">
        <div>
            <p class="landing-section-label">Экспериментально · WebMCP</p>
            <h2 id="webmcp-title">ИИ работает с дневником напрямую — без ручной пересылки файлов</h2>
        </div>
        <p>WebMCP позволяет странице «Ритма» безопасно показать поддерживаемому ИИ ограниченный набор инструментов. ИИ получает актуальные данные, готовит изменения, а важные действия остаются под вашим контролем.</p>
    </div>

    <div class="webmcp-comparison">
        <article class="manual-route">
            <span class="webmcp-card-label">Стандартный путь</span>
            <h3>Шесть ручных действий</h3>
            <div class="manual-route-steps" aria-label="Стандартный обмен файлами">
                <span>Экспорт</span><i>→</i><span>Скачать</span><i>→</i><span>Открыть чат</span><i>→</i><span>Загрузить</span><i>→</i><span>Скачать план</span><i>→</i><span>Импортировать</span>
            </div>
            <p>Файловый способ остаётся надёжной основой, но повторяющиеся действия приходится выполнять вручную.</p>
        </article>

        <div class="webmcp-replaces" aria-hidden="true"><span>WebMCP заменяет цепочку</span><b>→</b></div>

        <article class="direct-route">
            <span class="webmcp-card-label">С WebMCP</span>
            <h3>Один связанный диалог</h3>
            <div class="direct-route-diagram" role="img" aria-label="ИИ взаимодействует с Ритмом через разрешённые инструменты WebMCP">
                <div class="direct-node"><span>Р</span><strong>Ритм</strong><small>ваши данные</small></div>
                <div class="direct-connection"><span>разрешённые инструменты</span><b>↔</b></div>
                <div class="direct-node direct-node-ai"><span>AI</span><strong>ИИ-тренер</strong><small>анализ и черновик</small></div>
            </div>
            <p>Откройте авторизованную страницу помощника и обсуждайте тренировки: ИИ сам запросит нужный контекст через доступные инструменты.</p>
        </article>
    </div>

    <div class="webmcp-details">
        <ol>
            <li><span>1</span><div><strong>Страница регистрирует инструменты</strong><p>Только на странице помощника и только для вошедшего пользователя.</p></div></li>
            <li><span>2</span><div><strong>ИИ запрашивает нужные данные</strong><p>Например, профиль, текущий план, историю упражнений или прогресс — без доступа к базе целиком.</p></div></li>
            <li><span>3</span><div><strong>Изменения сначала становятся черновиком</strong><p>ИИ может подготовить новую версию программы, не меняя активный план незаметно.</p></div></li>
            <li><span>4</span><div><strong>Решение остаётся за вами</strong><p>Перед активацией приложение показывает последствия и ждёт явного подтверждения.</p></div></li>
        </ol>
        <aside>
            <p class="webmcp-aside-kicker">Почему это удобно</p>
            <ul>
                <li><span>✓</span> Не нужно скачивать и прикреплять отчёт после каждой тренировки</li>
                <li><span>✓</span> ИИ получает свежие данные, а не случайно выбранную старую версию файла</li>
                <li><span>✓</span> Меньше риска потерять поля при копировании или пересказе</li>
                <li><span>✓</span> Черновики и подтверждение сохраняют контроль за человеком</li>
            </ul>
            <p class="webmcp-progressive"><strong>Progressive enhancement:</strong> если браузер не поддерживает WebMCP или функция выключена, JSON + Markdown продолжают работать как обычно.</p>
        </aside>
    </div>
</section>

<section class="landing-benefits" id="benefits" aria-labelledby="benefits-title">
    <div class="landing-section-heading"><p class="landing-section-label">Не просто заметки</p><h2 id="benefits-title">Вся ретроспектива работает на следующую тренировку</h2></div>
    <div class="benefit-grid">
        <article><span>↗</span><h3>Видимый прогресс</h3><p>История рабочих весов, объёма и личных рекордов помогает замечать рост, даже когда он идёт небольшими шагами.</p></article>
        <article><span>◎</span><h3>План по вашим силам</h3><p>ИИ получает фактическую картину нагрузки и может точнее предложить вес, диапазон повторов и темп прогрессии.</p></article>
        <article><span>≋</span><h3>План и факт раздельно</h3><p>Всегда видно, что было задумано, что получилось в зале и что предложено изменить. Ничего не теряется задним числом.</p></article>
        <article><span>◷</span><h3>Контекст без пересказов</h3><p>Не нужно каждый раз вспоминать цифры и объяснять историю с нуля — структурированный отчёт уже содержит важные детали.</p></article>
        <article><span>⌁</span><h3>Удобно прямо в зале</h3><p>Mobile-first интерфейс, таймер отдыха и быстрый ввод подходов помогают сосредоточиться на тренировке, а не на таблицах.</p></article>
        <article><span>◇</span><h3>Данные переносимы</h3><p>Открытые файлы JSON и Markdown не запирают историю внутри сервиса и позволяют работать с подходящим вам ИИ.</p></article>
    </div>
</section>

<section class="landing-audience" aria-labelledby="audience-title">
    <div><p class="landing-section-label">Для кого</p><h2 id="audience-title">Для тех, кто хочет понимать свой прогресс</h2></div>
    <div class="audience-tags" aria-label="Кому полезен сервис"><span>Начинаю заниматься в зале</span><span>Не знаю свои рабочие веса</span><span>Тренируюсь самостоятельно</span><span>Хочу осознанную прогрессию</span><span>Использую ChatGPT как помощника</span><span>Не хочу терять историю</span></div>
</section>

<section class="landing-cta" aria-labelledby="cta-title">
    <p class="landing-section-label">Ваш следующий подход — уже с контекстом</p>
    <h2 id="cta-title">Начните собирать историю, на которой ИИ сможет учиться вместе с вами</h2>
    <p>Первая запись займёт меньше времени, чем попытка вспомнить прошлую тренировку.</p>
    <div class="landing-actions landing-actions-center"><a class="button button-coral" href="<?= e(url('/register')) ?>">Создать аккаунт <span aria-hidden="true">→</span></a><a class="button button-outline-dark" href="<?= e(url('/login')) ?>">Войти</a></div>
    <small>ИИ помогает анализировать данные, но не заменяет врача или очного специалиста. Учитывайте самочувствие и технику выполнения.</small>
</section>

<footer class="landing-footer">
    <a class="brand" href="<?= e(url('/')) ?>"><span class="brand-mark">Р</span><span>Ритм</span></a>
    <p>Дневник тренировок, который помнит больше.</p>
    <a href="<?= e(url('/login')) ?>">Войти в сервис →</a>
</footer>
