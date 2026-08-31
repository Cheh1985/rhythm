# Состояние разработки

## Статус на 24.08.2026

**Этап 8 из 8 завершён. MVP проекта завершён.** Этапы 1–7 сохранены; добавлены backup/restore, полный audit/soft delete, security review и production/mobile polish.

После MVP добавлено управление ещё не начатым импортированным планом: дату можно перенести без изменения исходного JSON, а ошибочно импортированный план — мягко удалить с подтверждением и audit trail. Оба действия изолированы по пользователю и защищены optimistic locking.

Готово:

- структура приложения, front controller, `.env` и единая обработка ошибок;
- PDO, транзакции, production/development режимы и защитные HTTP-заголовки;
- регистрация, вход по логину/email, выход, роли `user`/`admin`;
- password hashing, regeneration сессии, CSRF, escaping, secure cookie flags и rate limit по аккаунту/IP;
- нормализованная схема MySQL/MariaDB, внешние ключи, индексы и seed упражнений;
- строгая серверная фильтрация Dashboard и планов по `user_id`;
- mobile-first Dashboard с незавершённой, следующей и последней тренировкой;
- экран плана с readiness за 10–15 секунд и продолжением незавершённой сессии;
- CLI-установщик, README, INSTALL, CHANGELOG и документация БД;
- smoke-проверки без зависимости от БД;
- глобальные и пользовательские упражнения со стабильным ID, active/inactive и шагом прогрессии;
- программы, неизменяемые версии с родителем/причиной и неизменяемые workout templates;
- закрытый контракт и JSON Schema `training-plan` v1.0;
- upload с лимитом/расширением/MIME, строгая validation, preview и явное подтверждение неизвестных упражнений;
- транзакционный историчный импорт с дубликатами по пользователю, audit log и просмотром на мобильном экране;
- пример Full Body A, негативные fixtures и 27 SQLite integration/unit проверок, включая rollback и изоляцию.
- раздельные `workout_plan` и `workout_session`, снимок `session_exercises` и быстрый ввод подходов;
- предзаполнение из плана/последнего/предыдущего результата, быстрые дельты веса и повторов, RIR 0…5+;
- отдельные warmup/working, online autosave и идемпотентный повтор `client_action_id`;
- статусы pending/active/waiting/completed/skipped, занятое оборудование и пропуск с причиной;
- замена с original/actual/reason/time, дискомфорт 1–10 без медицинских выводов и оценка too_easy/normal/too_hard;
- редактирование подходов с audit log и optimistic locking с HTTP 409 для session/set/session-exercise;
- timestamp-таймер pause/reset/+30/раннее завершение с визуальным, vibration/audio-сигналом;
- завершение и mobile summary; 30 новых SQLite integration-проверок критических переходов и user isolation;
- ручной smoke на viewport 390×844 от readiness до двух упражнений и summary без горизонтального overflow.
- installable PWA manifest, standalone/theme/Apple metadata, PNG 180/192/512 и maskable icon;
- версионируемый Service Worker с app shell, offline fallback, private workout navigation-cache и безопасным waiting-update flow;
- IndexedDB-снимок активной сессии/черновиков и local-first outbox до сетевого запроса;
- последовательная доставка зависимых действий при online/open/manual retry, Web Locks + lease fallback и BroadcastChannel между вкладками;
- общий `offline_action_receipts` для идемпотентного replay старта, подходов, статусов, замены, дискомфорта, редактирования и завершения;
- online/offline/pending/synced/error статусы, восстановление локальных подходов и явное разрешение 409 без тихого удаления очереди;
- logout очищает пользовательские Cache/IndexedDB через клиент и `Clear-Site-Data`; Service Worker не кеширует API;
- 7 pure JS проверок очереди и 17 SQLite integration/static проверок этапа 4;
- browser smoke подтвердил manifest, Apple metadata, согласованный theme, отсутствие console errors и offline fallback при полностью остановленном сервере.
- завершение требует session RPE 1–10, самочувствие 1–5 и сохраняет комментарий отдельно от readiness/упражнений;
- summary считает длительность, выполненные/пропущенные упражнения, working sets, tonnage только working и average RIR;
- подключён экспорт `training-report` v1.0 с независимыми `planned`, `fact`, `suggestion`, UTC timestamps, заменами, пропусками, дискомфортом, комментариями и audit trail;
- доступны JSON, читаемый Markdown для прямой вставки в ChatGPT и ZIP с обоими файлами;
- double progression создаёт предложение только при точном числе рабочих подходов, верхней границе повторов во всех подходах и RIR внутри целевого диапазона;
- абсолютный и процентный шаг упражнения поддержаны; current/suggested/accepted сохраняются отдельно, программа автоматически не меняется;
- e1RM рассчитывается по Epley, а новый `best_e1rm` показывается как ненавязчивый аналитический ориентир только при улучшении прошлого результата;
- итог сравнивается с прошлой тренировкой того же шаблона (или имени/типа для старых ручных планов);
- завершённые подходы, session RPE, самочувствие и комментарий можно исправить с `edited_at`, `edited_after_completion`, optimistic locking и before/after audit;
- после правки производные метрики, ожидающая прогрессия и PR пересчитываются без дублей;
- 37 новых проверок покрывают метрики, границы прогрессии, report shape/Markdown, tenant isolation, повторное завершение, правки и JSON round-trip;
- HTTP smoke на viewport 390×844 подтвердил отсутствие horizontal overflow и console errors; JSON/Markdown/ZIP скачаны, JSON повторно распарсен, ZIP содержит оба файла.
- этап 6 добавил пагинируемую серверную историю, фильтры, недельные агрегаты/графики, страницы упражнений, расширенные PR, мягкие сигналы и измерения тела;
- 15 проверок этапа 6 покрывают timezone-границы недель, агрегации, пагинацию, изоляцию и ряды измерений;
- плавание хранится в `swimming_sessions` и `swimming_intervals`, не использует `exercise_sets` и строго сверяет общую дистанцию с блоками;
- запись содержит дату, UTC-точку локального полудня, длительность, бассейн, стиль, интенсивность, fatigue рук/спины/ног, самочувствие и комментарий;
- создание вручную или из принадлежащего пользователю расписания, безопасное редактирование с `version`, HTTP 409 и before/after audit;
- недельное расписание изменяемо; для нового пользователя и ленивого backfill используются defaults: понедельник/среда зал, четверг бассейн;
- общая история и последовательность объединяют силовые и плавательные события, не смешивая их метрики и не формируя физиологических выводов;
- `swimming-report` v1.0 выгружается как JSON/Markdown; backup включает сессии, интервалы, расписание и training sequence;
- форма плавания сохраняет IndexedDB-черновик и кладёт create/update в идемпотентный outbox до Fetch; приватные страницы плавания доступны после посещения offline и очищаются при logout;
- 21 проверка этапа 7 покрывает дистанции/интервалы, timezone, расписание, tenant isolation, offline replay, optimistic locking, audit, общую хронологию и report shape.
- backup v1.1 выгружает полную пользовательскую историю и versioned program slots в JSON/ZIP, имеет `backup_id`, UTC-время и SHA-256 checksum; restore читает v1.0;
- restore использует строгую validation и preview, безопасный транзакционный merge без overwrite/delete, old→new ID remap и принудительный текущий tenant;
- `(user_id, checksum_sha256)` делает повтор restore идемпотентным; итог и событие `restore_merge` сохраняются в БД;
- audit дополнен созданием program version, restore, отменой и soft delete; подходы, измерения и плавание удаляются только мягко после подтверждения;
- незавершённую тренировку можно продолжить, завершить или явно отменить с optimistic locking без потери истории;
- добавлены настройки light/dark/system, focus-visible, reduced motion, safe-area и улучшенные touch/keyboard состояния;
- CSP, HSTS на production HTTPS, COOP/CORP, Permissions Policy, no-store приватных страниц и request-id logging проверены статически;
- `bin/cleanup.php`, новые индексы и миграция 008 закрывают cleanup/production обслуживание;
- 24 проверки этапа 8 покрывают checksum, preview, idempotency, tenant isolation, rollback, cancel/soft delete, маршруты и security/static контракты.

WebMCP этап 4 добавил явный lifecycle `program_versions`, защищённый `active_version_id`, versioned `program_schedule_slots`, conservative reconciliation и backup v1.1 с чтением v1.0. SQLite-набор `tests/stage13-program-lifecycle.php` покрывает single/multiple reconciliation, tenant isolation, cross-program pointer, duplicate/cross-version slots и backup v1.0/v1.1 round-trip.

WebMCP этап 5 добавил authenticated `/assistant`, серверный каталог из 11 read-only Site tools и thin same-origin adapter на актуальном `document.modelContext`. Регистрация page-scoped и снимается через `AbortSignal`; DB-текст помечен `untrustedContentHint`, все tools — `readOnlyHint`, а `/assistant` и `/api/assistant/*` исключены из offline cache. Проверки: `tests/stage14-webmcp-page.php`, `tests/webmcp-registration.js`; ручной staging checklist — `docs/webmcp-stage5-smoke.md`.

WebMCP этап 6 добавил отдельный `training-program-draft` v1.0, полный canonical aggregate с templates/schedule slots, server-assigned version и parent provenance. `ProgramVersionService` создаёт/клонирует и меняет draft только typed operations с tenant-scoped exercise references, транзакциями, optimistic `lock_version` и стабильным `aggregate_hash`; published/active/archived content остаётся immutable. HTTP/WebMCP writes и activation не добавлялись. `tests/stage14-program-drafts.php` покрывает workflow, все операции, current/old clone, conflicts, rollback, invalid references/ranges/schedules и tenant isolation.

Ограничение проверки окружения: локальный PHP 8.3 доступен с `pdo_sqlite`, но без `pdo_mysql`; MySQL/MariaDB client/server, Docker и Podman не найдены. Потоки этапов 1–8 и lifecycle этапа 4 прогнаны на SQLite, а MySQL `schema.sql`, seed и миграции проверены статически. Миграции `003`–`010`, импорт schema/seed и полный smoke всё ещё нужно выполнить в целевом MySQL/MariaDB окружении. Фактическая установка через Add to Home Screen и visual/safe-area проверка на физическом iPhone в этом окружении недоступны. JS assertions очереди прошли с Node-флагами `--preserve-symlinks --preserve-symlinks-main`, обходящими sandbox `EPERM` на родительском каталоге.

Ограничения iOS: WebKit может приостанавливать JavaScript в фоне, запрещать vibration и блокировать audio без предшествующего пользовательского жеста или в беззвучном режиме. Таймер считает остаток от абсолютного timestamp и корректируется после возврата, но не обещает системное фоновое уведомление на заблокированном iPhone.

## Итоговая матрица MVP (15 критериев)

| № | Критерий | Статус | Проверка |
|---:|---|---|---|
| 1 | PHP 8.2+ / MySQL-MariaDB установка | Готово с ограничением среды | installer/schema статически; целевая MySQL нужна |
| 2 | Многопользовательская авторизация | Готово | CSRF, sessions, throttling, user_id tests |
| 3 | Справочник упражнений | Готово | stage2/stage3 |
| 4 | Версионируемые программы и импорт | Готово | stage2 + audit version |
| 5 | Быстрый mobile workout flow | Готово | stage3 |
| 6 | Offline-first PWA/outbox | Готово | stage4 PHP + 7 Node assertions |
| 7 | Завершение/отмена/продолжение | Готово | repository + routes + optimistic lock |
| 8 | Отчёты JSON/Markdown/ZIP | Готово | stage5 |
| 9 | Прогрессия, e1RM и PR | Готово | stage5 |
| 10 | История, аналитика, измерения | Готово | stage6 |
| 11 | Плавание и расписание | Готово | stage7 |
| 12 | Backup/restore | Готово | stage8 checksum/rollback/idempotency |
| 13 | Audit и soft delete | Готово | stage3/5/7/8 |
| 14 | Security/production/privacy | Готово с deployment checks | static/smoke; HTTPS host нужен |
| 15 | Accessibility/iPhone/themes/docs | Готово с device limitation | static CSS/docs; физический iPhone нужен |
