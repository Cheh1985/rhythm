# WebMCP / ChatGPT Site tools — карта поэтапной реализации

## Суть и цель разработки

Этот документ описывает поэтапное добавление WebMCP / ChatGPT Site tools в существующее PWA-приложение «Ритм» для ведения тренировок.

Целевая возможность выглядит так:

```text
Пользователь открывает «Ритм» во встроенном браузере ChatGPT
        ↓
авторизуется своей обычной учётной записью приложения
        ↓
ChatGPT обнаруживает Site tools текущей страницы
        ↓
получает только необходимые тренировочные данные текущего пользователя
        ↓
анализирует план, фактическое выполнение и прогресс
        ↓
по явной просьбе создаёт новую draft-версию программы
        ↓
после отдельного подтверждения пользователя активирует её
```

WebMCP должен быть тонким адаптером над существующей серверной бизнес-логикой:

```text
ChatGPT
  → WebMCP tool на странице /assistant
  → same-origin frontend adapter
  → semantic backend endpoint
  → application service
  → repository/domain logic
  → MySQL
```

Нельзя предоставлять ChatGPT прямой SQL, generic database tools, полный backup или произвольный вызов backend endpoint. Все операции должны быть семантическими: получить историю упражнения, получить прогресс, создать draft, заменить упражнение только в конкретной тренировке и т. п.

## Архитектурные ограничения, обязательные для всех этапов

1. WebMCP — progressive enhancement. Приложение должно полностью работать без `document.modelContext`, в Safari и как PWA на iPhone.
2. Используется только актуальный `document.modelContext`. Deprecated `navigator.modelContext` не добавляется даже как production fallback.
3. WebMCP JavaScript не содержит SQL, расчётов метрик, ownership logic или основной validation.
4. Текущий пользователь определяется серверной PHP-сессией. `user_id` никогда не принимается в tool input.
5. Каждый entity lookup обязательно ограничивается текущим пользователем.
6. Read responses минимизируются: ranges, filters, cursors, limits, summary/detail и явные пределы размера.
7. Все writes используют CSRF, same-origin checks, optimistic locking, idempotency и audit.
8. Activation программы всегда требует дополнительного подтверждения в интерфейсе приложения.
9. Удаление данных, backup/restore, произвольное изменение истории и generic database/API tools через WebMCP не предоставляются.
10. `training-plan` v1.0 сохраняется как legacy portable contract одной датированной тренировки.
11. Для draft целой программы создаётся отдельный `training-program-draft` v1.0, переиспользующий существующие exercise/range rules.
12. Старые версии программ, история тренировок и JSON import/export не удаляются и не перезаписываются.
13. Feature flags по умолчанию выключены; rollout идёт от reads к drafts, затем к instance writes и activation.
14. Каждый этап выполняется строго в своих границах и не начинает следующий этап.

## Фактическая модель проекта, которую нужно сохранять

```text
users
  └─ training_programs
       └─ program_versions
            └─ workout_templates
                 └─ workout_plans — конкретные тренировки с датой
                      ├─ workout_exercises — план
                      └─ workout_sessions — факт
                           └─ session_exercises
                                └─ exercise_sets
```

Ключевые особенности текущего проекта:

- PHP 8.2+ без framework и Composer;
- MySQL 8/MariaDB через PDO;
- server-rendered PHP views и нативный JavaScript;
- собственный Router и autoload;
- `TrainingRepository` содержит значительную часть SQL и business rules;
- отдельные services уже существуют для импорта, отчётов, прогрессии и backup;
- авторизация построена на PHP session, CSRF и обязательном `user_id` в запросах;
- PWA Service Worker не кеширует `/api/*`;
- tests — самостоятельные PHP-скрипты на SQLite и Node-тест offline queue;
- migration runner отсутствует: migration SQL нужно поддерживать вместе с `database/schema.sql`.

## Почему реализация разделена на этапы

Read-only интеграцию можно построить почти полностью поверх существующей БД. Write operations требуют предварительно устранить неоднозначность текущей программы, добавить mutable drafts, формализовать activation и обеспечить human-in-the-loop.

Разделение снижает риск:

- сначала создаются общие security и contract primitives;
- затем read DTO и API тестируются без WebMCP;
- только после этого подключается browser adapter;
- draft/version lifecycle реализуется отдельно от Site tools;
- значимые writes сначала проверяются обычным HTTP/UI workflow;
- WebMCP write tools подключаются последними;
- production rollout выполняется только после полного security/E2E этапа.

## Как использовать этот документ в новых чатах Codex

### Рекомендуемый способ

Для каждого нового чата лучше вставлять три части:

1. раздел **«Общий контекст для нового чата»** ниже;
2. полное описание выбранного этапа;
3. стартовый промт выбранного этапа.

Это надёжнее, чем вставлять только короткий промт: новый чат видит не только задачу, но и причины границ, зависимости, rollback и архитектурные запреты.

Если этап простой и предыдущие этапы уже хорошо задокументированы в репозитории, допустимо вставить только его стартовый промт. Все стартовые промты ниже специально написаны достаточно самодостаточно. Однако для этапов 4, 6, 7 и 9 рекомендуется всегда передавать полное описание этапа: там особенно важны модель версий и правила подтверждения.

### Общий контекст для нового чата

Скопируйте этот блок перед описанием этапа:

```text
Мы поэтапно добавляем WebMCP / ChatGPT Site tools в существующий PHP/PWA-дневник тренировок «Ритм».

WebMCP должен быть тонким progressive-enhancement adapter: ChatGPT → document.modelContext на /assistant → same-origin semantic API → application services → существующая domain/repository logic → MySQL. Никакого прямого SQL, generic database/API tools или передачи user_id от модели.

Текущий пользователь определяется только серверной PHP-сессией. Обязательны tenant isolation, CSRF для writes, same-origin checks, optimistic locking, idempotency, data minimization и audit. Значимые операции, особенно activation программы, требуют app-level human confirmation.

Legacy training-plan v1.0, training-report export, backup и обычная PWA должны остаться рабочими. Без document.modelContext приложение не должно ломаться. Использовать deprecated navigator.modelContext нельзя.

Работай только в границах указанного этапа. Сначала проверь фактическое состояние репозитория и результаты предыдущих этапов, затем реализуй, протестируй и остановись. Не переходи к следующему этапу.
```

## Feature flags целевой реализации

```dotenv
WEBMCP_ENABLED=false
WEBMCP_READ_ENABLED=false
WEBMCP_DRAFT_WRITE_ENABLED=false
WEBMCP_INSTANCE_WRITE_ENABLED=false
WEBMCP_ACTIVATION_ENABLED=false
```

## Карта зависимостей

```text
1
├── 2
│   └── 3
├── 4
│   ├── 5  ← также зависит от 3
│   └── 6
│       └── 7
└── 8

5 + 7 + 8
    └── 9
        └── 10
```

Этапы 2–3, 4 и 8 могут выполняться параллельно после этапа 1 только в отдельных ветках. При объединении особенно внимательно проверяются `database/schema.sql`, `public/index.php`, controllers и shared services.

## Текущий статус реализации

Актуально на 31 августа 2026 года. Статус обновляется после завершения локальных проверок; commit указывается после фиксации соответствующего этапа в Git.

| Этап | Статус | Commit | Примечание |
|---|---|---|---|
| 1 | Реализован, локально проверен и зафиксирован | `2e87af5` | Foundation готов; MySQL/MariaDB migration dry run на staging ещё не выполнен |
| 2 | Реализован, локально проверен и зафиксирован | `453868d` | MySQL/MariaDB integration check ещё не выполнен |
| 3 | Реализован, локально проверен и зафиксирован | `b5032be` | Read-only Assistant API зафиксирован; MySQL/MariaDB integration check ещё не выполнен |
| 4 | Реализован, локально проверен и зафиксирован | `aab598c` | Lifecycle migration и backup compatibility зафиксированы; MySQL/MariaDB migration dry run ещё не выполнен |
| 5 | Реализован, локально проверен и зафиксирован | `a0a64ea` | `/assistant` и 11 read-only Site tools зафиксированы; внешний browser smoke ещё не выполнен |
| 6 | Реализован, локально проверен и зафиксирован | `a0a64ea` | Draft schema/application service зафиксированы; MySQL/MariaDB integration check ещё не выполнен |
| 7 | Реализован, локально проверен и зафиксирован | `9639092` | Draft API и app-confirmed activation зафиксированы; MySQL/MariaDB integration check ещё не выполнен |
| 8 | Реализован, локально проверен и зафиксирован | `e428aae` | Instance-only reschedule/replacement зафиксированы; MySQL/MariaDB migration check ещё не выполнен |
| 9 | Реализован, локально проверен и зафиксирован | `cc60bab` | Пять WebMCP write tools и обязательный app-confirmed activation зафиксированы; внешний browser smoke ещё не выполнен |
| 10 | Доступен | — | Этап 9 завершён; можно выполнять security hardening, E2E, rollout и финальную документацию |

Сводка: этапы 1–9 реализованы, локально проверены и зафиксированы в Git; этапы 5–6 объединены в commit `a0a64ea`, этап 7 зафиксирован в commit `9639092`, этап 8 — в commit `e428aae`, этап 9 — в commit `cc60bab`. Следующим доступен этап 10: security hardening, E2E, rollout и финальная документация. Для этапов 1–4 и 6–8 остаются открытыми integration/migration проверки на MySQL 8 и MariaDB staging; для этапов 5 и 9 — ручные smoke/E2E во встроенном браузере ChatGPT, Chrome с WebMCP flag/origin trial и Safari/PWA; для activation и instance writes также остаётся HTTPS/MySQL staging-проверка реальных session, locking и rollback scenarios.

### Результат этапа 1

- commit: `2e87af54258ca29aaba582a7495f694ab0d4f802` — «Вынеси валидацию плана и добавь трассировку запросов»;
- добавлены единый request/correlation ID, безопасный API error envelope и строгие helpers для ID, JSON body и ожидаемых типов;
- все WebMCP feature flags добавлены в `.env.example` со значением `false`;
- validation и canonical JSON вынесены в `TrainingPlanContractValidator`, а `PlanImportService` делегирует ему без изменения legacy `training-plan` v1.0;
- добавлены compact `assistant_tool_calls`, redaction/allowlist метаданных и nullable `source`/`request_id` в domain audit;
- `database/schema.sql` согласован с `database/migrations/009_webmcp_foundation.sql`;
- `tests/stage10-webmcp-foundation.php`: 38 проверок пройдены;
- полный PHP test suite и Node offline queue test: пройдены;
- WebMCP routes, `/assistant`, read API и WebMCP JavaScript не добавлялись;
- открытая проверка перед production rollout: применить migration `009_webmcp_foundation.sql` на MySQL 8/MariaDB staging, поскольку локально доступны только SQLite и статическая проверка SQL.

### Результат этапа 2

- созданы tenant-scoped `TrainingQueryRepository` и `TrainingQueryService`;
- добавлены минимизированные DTO для profile context, программ и версий, списка и деталей тренировок, истории упражнения, progress summary, тренировки по локальной дате, поиска упражнений и кандидатов на замену;
- публичные DTO используют `external_plan_id`, `public_id` и `exercise_id`, не содержат внутренних numeric ID, login/email/password fields, raw source/snapshot JSON или audit rows;
- реализованы строгие date ranges, hard limits и cursor pagination для списка тренировок, истории упражнения и поиска;
- backend детерминированно считает working sets, tonnage, duration, average RIR, session RPE, Epley e1RM, target rep-range compliance, planned-vs-actual, substitutions/skipped/pending, weekly trends и per-exercise/per-muscle aggregates;
- силовые и плавательные показатели разделены; muscle aggregates содержат double-count caveat, `pending` не считается `skipped`, а `past_due_planned` не называется доказанным пропуском;
- добавлены явные `data_quality`, аналитические caveats и эвристические plateau signals без диагностических выводов;
- `tests/stage11-training-query.php`: 42 проверки пройдены, включая timezone boundaries, cursors, IDOR, метрики, data quality, empty и oversized ranges;
- полный PHP regression suite, smoke и Node offline queue test: пройдены;
- HTTP routes, Site tool registration, WebMCP frontend, drafts, activation и схема БД не изменялись;
- открытая проверка перед объединением: выполнить integration tests новых read queries на MySQL 8/MariaDB staging; локальные проверки выполнены на SQLite.

### Результат этапа 3

- commit: `b5032bec7a3dd758e2e072a8a9e9052231367bd5` — «Добавить защищённые read-only API для ассистента»;
- добавлены authenticated read-only semantic endpoints под `/api/assistant/*` для profile, программ и версий, списка/деталей тренировок, истории упражнения, progress, расписания, поиска и альтернатив;
- `SiteToolRequestGuard` централизует `Auth::requireUser(true)`, feature flag, строгий allowlist query-параметров, лимиты query/body, `no-store`, correlation ID, per-user/tool rate limit и error mapping;
- все ответы используют стабильные success/error envelopes; отсутствующий или чужой entity одинаково возвращает 404, а `user_id` не входит ни в один входной контракт;
- read calls пишут только компактный `assistant_tool_calls` audit без raw query/body/credentials; domain state GET-запросы не изменяют;
- добавлена безопасная tenant-scoped проекция списка версий программы без immutable snapshot payload;
- на момент завершения этапа 3 `tests/stage12-site-tools-api.php` содержал 33 HTTP-проверки реального CSRF login/session cookie flow, 401/404/422/429, IDOR, strict route/query/body, cursors, headers и audit; после этапа 5 набор расширен до 40 проверок, см. результат этапа 5;
- полный PHP regression suite, smoke и Node offline queue test: пройдены;
- `/assistant`, `document.modelContext`, frontend tool catalog, writes и activation не добавлялись; схема БД не изменялась;
- открытая проверка перед production rollout: выполнить HTTP/query integration tests на MySQL 8/MariaDB staging.

### Результат этапа 4

- commit: `aab598c29911d3cd34b05f7538f4a13ccafc04c5` — «Обнови поддержку жизненного цикла версий программ»;
- добавлена additive migration `010_program_version_lifecycle.sql` и согласованный fresh schema: lifecycle, `lock_version`, `aggregate_hash`, timestamps и защищённый `active_version_id`;
- existing versions backfill-ятся как `published`; автоматически связываются только программы с одной версией, multiple-version cases остаются `ambiguous`;
- `program_schedule_slots` хранит version → template → weekday, защищён unique weekday и составным FK от template другой версии;
- effective current version во read layer определяется pointer, а `/programs` показывает resolved/reconcilable/ambiguous/invalid состояния;
- добавлены dry-run/apply command `bin/reconcile-program-versions.php` и tenant-scoped reconciliation service; `--apply` не выбирает неоднозначные версии;
- backup export обновлён до v1.1, restore читает v1.0/v1.1, remap-ит active pointer и slots, проверяет ownership и исключает `assistant_tool_calls`;
- `tests/stage13-program-lifecycle.php`: 19 проверок пройдены; полный PHP regression suite и Node offline queue test пройдены;
- drafts, activation transaction, WebMCP page/tools и автоматическое удаление версий не добавлялись;
- открытая проверка: применить migration/fresh schema на MySQL 8 и MariaDB staging; локально доступны только PDO SQLite, без mysql/mariadb/Docker/Podman.

### Результат этапа 5

- commit: `a0a64eabfeb02fe0cf5186b9a93d0dee409477b1` — «Добавить WebMCP-инструменты и черновики программ»; этапы 5–6 зафиксированы вместе;
- добавлена authenticated page `/assistant`; без сессии она перенаправляет на login, а при выключенных feature flags показывает статус и не подключает adapter;
- создан серверный `App\WebMcp\ToolCatalog` с 11 стабильными read-only tools, их titles, descriptions, закрытыми input schemas и без `user_id` или write operations;
- все tools имеют `readOnlyHint: true` и `untrustedContentHint: true`, поскольку безопасные DTO могут содержать пользовательский текст из БД;
- thin adapter использует только актуальный `document.modelContext.registerTool`; deprecated `navigator.modelContext` отсутствует;
- registration page-scoped: один `AbortController` снимает весь каталог при `pagehide`/`beforeunload`, а partial registration failure закрывает уже зарегистрированные tools; cancellation отдельного execute передаётся в `fetch`;
- tool execution использует только семантические `/api/assistant/*` endpoints, `GET`, `credentials: same-origin`, `cache: no-store` и явную проверку origin до `fetch`;
- success, HTTP/API, validation и network results возвращаются структурированно; `training.get_current_plan` не угадывает ambiguous active program, а `training.get_workout` явно принимает `workout_id`, `session_id` или оба ID;
- adapter подключается условно только в layout `/assistant`; login, Dashboard и остальные страницы не публикуют catalog и не загружают `webmcp.js`;
- добавлены `Permissions-Policy: tools=(self)` и `Origin-Agent-Cluster: ?1`; `/assistant` и `/api/assistant/*` имеют `no-store` и исключены из Service Worker/offline cache;
- `tests/stage14-webmcp-page.php`: 42 проверки server catalog, schemas, annotations, security headers, conditional registration и offline boundaries пройдены;
- `tests/webmcp-registration.js`: 15 Node-проверок fake `document.modelContext`, metadata, no-capability, lifecycle abort, structured API/network errors, origin rejection, cancellation и all-or-nothing registration пройдены;
- `tests/stage12-site-tools-api.php`: 40 HTTP-проверок пройдены, включая unauthenticated redirect, authenticated catalog, headers и отсутствие tools на обычной странице;
- полный PHP regression suite этапов 2–13, smoke и Node offline queue test пройдены; PHP/JS syntax и `git diff --check` пройдены;
- добавлен ручной staging checklist `docs/webmcp-stage5-smoke.md` для ChatGPT built-in browser, Chrome flag/origin trial, offline и Safari/PWA regression;
- write tool registration, drafts, activation UI, archive/delete/history edits и global registration не добавлялись;
- открытые внешние проверки: HTTPS staging во встроенном браузере ChatGPT, Chrome с WebMCP testing flag/origin trial и Safari/установленная PWA; локальное окружение не позволяет подтвердить эти browser/account/device scenarios.

### Результат этапа 6

- commit: `a0a64eabfeb02fe0cf5186b9a93d0dee409477b1` — «Добавить WebMCP-инструменты и черновики программ»; этапы 5–6 зафиксированы вместе;
- добавлен отдельный закрытый контракт `training-program-draft` v1.0 с `templates[]`, `schedule_slots[]`, обязательной причиной и явной parent provenance; legacy `training-plan` v1.0 root не изменён;
- exercise/range validation вынесена в переиспользуемый публичный метод legacy validator без ослабления его корневых правил;
- `ProgramVersionService` создаёт новую draft-программу, клонирует active или выбранную immutable old version и всегда назначает следующий version number на сервере;
- реализованы семь typed operations, полная повторная aggregate validation, tenant-scoped active exercise references и schedule → template integrity;
- semantic arrays канонизируются по template ID, exercise order и weekday; `snapshot_json`, `snapshot_hash` и `aggregate_hash` обновляются согласованно;
- optimistic update требует точный `lock_version`; stale write выбрасывает `VersionConflictException`, а invalid aggregate/SQL откатывает transaction;
- published, active и archived versions не редактируются; source `webmcp` поддержан как данные, но service не зависит от WebMCP;
- `tests/stage14-program-drafts.php`: 25 проверок create/current clone/old clone, всех operations, hash stability, conflicts, invalid references/ranges/schedules, tenant isolation и rollback пройдены;
- HTTP endpoints, Site tool writes, activation и materialization future workout plans не добавлялись;
- открытая внешняя проверка: прогнать workflow на MySQL 8/MariaDB staging; локально доступен PDO SQLite, без MySQL/MariaDB runtime.

### Результат этапа 7

- commit: `963909285fd7ea1898829daa45acbae97f3739d0` — «Добавить безопасную активацию черновиков программ»;
- добавлены semantic endpoints создания новой программы или клонирования active/выбранной published version, typed update draft и prepare activation; WebMCP write tools при этом не регистрируются;
- write boundary централизует session authentication, exact same-origin, CSRF, strict JSON/shape/size validation, отсутствие model-supplied `user_id`, feature flags, безопасные API envelopes и минимизированный tool-call audit;
- create/clone/update используют payload-bound `Idempotency-Key`: `assistant_write_receipts` связывает ключ с user, action и SHA-256 канонического request; technical receipts исключены из backup и очищаются через 90 дней;
- migration `011_program_activation.sql` и fresh schema согласованы для `assistant_write_receipts`;
- prepare activation не меняет active state и полностью показывает effective window 1–12 недель, `keep`/`supersede`, новые, сохраняемые, отменяемые, protected и blocked планы, а также программы, которые будут paused;
- подтверждение хранится только в текущей PHP-сессии, живёт пять минут, одноразово потребляется даже при ошибке и связано с user, draft ID, `lock_version`, `aggregate_hash`, `effective_from`, horizon, policy и hash полного impact preview;
- обычная страница `/assistant` показывает impact и даёт ручные CSRF/same-origin confirm/cancel; cancel не меняет БД, expiry/replay/stale confirmation отклоняются;
- activation повторно строит impact под блокировками и выполняется одной transaction: draft становится immutable `published`, active pointer переключается, другие active programs становятся `paused`, старая версия сохраняется, mutable future plans мягко supersede-ятся только по выбранной policy, а новые workout plans/exercises материализуются из version schedule slots;
- completed/in-progress workouts, их sessions и history не меняются; protected dates блокируют materialization; domain audit и `assistant_tool_calls` audit разделены и не содержат raw payload/token;
- `tests/stage15-plan-activation.php`: 39 проверок prepare/confirm/cancel/expiry/replay, stale hash/lock/impact, ownership, multiple active programs, `keep`/`supersede`, idempotency, Origin/CSRF, dual audit и transaction rollback пройдены;
- полный PHP regression suite и PHP lint пройдены; Node offline queue — 7 проверок, WebMCP registration — 15 проверок пройдены;
- открытые внешние проверки: применить migration/fresh schema и прогнать transaction/locking на MySQL 8/MariaDB staging, затем проверить реальный HTTPS session flow prepare → preview → confirm/cancel в поддерживаемом браузере.

### Результат этапа 8

- commit: `e428aae55621dc65ea2dfeefa059fa6213671daa` — «Добавить безопасные изменения экземпляров тренировок»;
- добавлен `WorkoutInstanceService` и два semantic endpoint: перенос конкретной planned-тренировки и замена упражнения в `scheduled_instance` или `active_session`; WebMCP write tools при этом не регистрируются;
- target определяется только stable `workout_plans.external_plan_id` или `workout_sessions.public_id` текущего пользователя; упражнение выбирается по стабильному внутри instance `sequence_no`, внутренние DB ID и `user_id` API не принимает;
- каждый запрос требует явный `scope`, optimistic `instance_version`/`exercise_version`, body `client_action_id` и совпадающий `Idempotency-Key`; payload-bound receipt предотвращает повторную mutation и отклоняет переиспользование ключа с другим запросом;
- перенос использует существующую `reschedulePlan`, а active-session replacement — существующую `replaceExercise`, сохраняя прежние status/version rules обычной PWA;
- planned replacement меняет только строку конкретного `workout_exercises`, увеличивает версии instance/exercise и не изменяет `training_programs`, `program_versions`, `workout_templates`, их hashes или active pointer;
- additive migration `012_workout_instance_substitutions.sql` добавляет planned provenance `original_exercise_id`, `substitution_reason`, `substituted_at` и optimistic `version`; номер `011` не переиспользован, поскольку занят migration активации этапа 7;
- `startSession()` переносит original/actual/reason/time в immutable session snapshot; read DTO planned workout публикует exercise version и provenance, а session/history projection сохраняет исходное упражнение после старта;
- write boundary наследует exact same-origin, CSRF, strict JSON shape, feature flag и безопасные error envelopes; foreign target скрывается как 404, completed history не редактируется, domain audit связан с request ID без raw prompt/payload;
- backup v1.1 round-trip переносит actual/original provenance, reason/time и version; restore повторно проверяет доступность как actual, так и original exercise текущему tenant, а старые backup остаются совместимыми;
- `tests/stage16-workout-instance-writes.php`: 37 проверок ownership, scopes, planned/active/completed rules, conflicts, idempotency, provenance, snapshot/history, no program mutation, CSRF/Origin и route/registration boundaries пройдены;
- полный PHP regression suite, 7 Node-проверок offline queue, 15 Node-проверок WebMCP registration, PHP lint и `git diff --check` пройдены;
- открытые внешние проверки: применить migration `012` и fresh schema на MySQL 8/MariaDB staging, проверить row locking/rollback и выполнить HTTPS HTTP smoke двух semantic endpoints; на момент фиксации этапа 8 WebMCP write registration ещё не было добавлено.

### Результат этапа 9

- commit: `cc60babbb397e5360575b95dac50adf8b14f43f9` — «Добавить WebMCP-инструменты записи»;
- серверный `ToolCatalog` дополнен пятью стабильными write tools: `training.create_plan_draft`, `training.update_plan_draft`, `training.activate_plan`, `training.reschedule_workout` и `training.replace_exercise`;
- read, draft writes, instance writes и activation собираются в page-scoped catalog независимо по существующим feature flags; при read-only конфигурации write tools отсутствуют, а master flag остаётся общим выключателем;
- все write tools имеют `readOnlyHint: false`, закрытые top-level input schemas и не принимают `user_id`; archive/delete/history edit/backup/account/generic tools не добавлены;
- thin frontend adapter валидирует tool input и обращается только к same-origin semantic `/api/assistant/*` endpoints с session credentials, `no-store`, CSRF и payload-bound `Idempotency-Key`; writes не используют offline outbox;
- create/clone/update draft и instance mutations переиспользуют backend workflows этапов 7–8; повтор exact request с тем же `client_action_id` безопасно возвращает receipt, а reuse ключа для другого payload отклоняется сервером;
- `training.activate_plan` сначала вызывает prepare без mutation, показывает in-page `<dialog>` с программой, версией, effective window, policy и counts created/superseded/kept/protected/blocked/paused, затем держит WebMCP execute pending до ручного confirm/cancel;
- confirm/cancel добавлены как узкие JSON semantic endpoints поверх существующих session-bound confirmation store и activation service; confirm повторно проверяет token/draft/lock/hash/impact, cancel потребляет token и возвращает structured `USER_CANCELLED` с `mutated=false`;
- deprecated `navigator.modelContext` и отсутствующий в актуальном IDL `requestUserInteraction` не используются; регистрация остаётся на `document.modelContext.registerTool`, execution cancellation передаётся через `AbortSignal`, а весь каталог снимается при уходе со страницы;
- success, 401/404/409/419/422/429, validation, network/offline и stale confirmation ответы остаются структурированными; successful/error/cancel calls попадают в минимизированный tool-call audit без raw payload, CSRF или confirmation token;
- `tests/stage17-webmcp-writes.php`: 50 проверок flags matrix, catalog composition, schemas, annotations, forbidden operations, routes, modal и offline boundaries пройдены;
- `tests/webmcp-writes.js`: 23 Node-проверки create/update/reschedule/replace, CSRF/idempotency headers, duplicate calls, fake `document.modelContext`, modal pending confirm, cancel, stale confirmation, navigation abort, offline и HTTP error mapping пройдены;
- полный regression suite: 19 PHP-наборов и 3 Node-набора пройдены; legacy training-plan v1.0, reports, backup/restore и обычная PWA не сломаны;
- добавлен ручной checklist `docs/webmcp-stage9-smoke.md` для ChatGPT built-in browser, Chrome origin trial/Inspector, cancel/Escape/navigation/offline, audit, rollback и Safari/PWA regression;
- открытые внешние проверки: выполнить manual/E2E на HTTPS staging во встроенном браузере ChatGPT и Chrome, проверить Safari/установленную PWA без WebMCP и прогнать write/activation locking на MySQL 8/MariaDB; локально проверены fake modelContext, SQLite workflows и deterministic regressions.

---

# Этап 1 — общие контракты, request security и audit foundation

## Цель

Подготовить общий безопасный слой до появления WebMCP routes и endpoints. Устранить дублирование validation, формализовать correlation ID, feature flags, error envelope и tool-call audit.

## Предпосылки

Нет. Это первый этап.

## Что именно реализовать

- единый request/correlation ID для response header, API envelope и exception log;
- feature flags WebMCP, выключенные по умолчанию;
- единый безопасный API error envelope;
- helpers для строгой проверки ID, JSON body size и ожидаемых типов;
- извлечение validation/canonical JSON из `PlanImportService` в общий контрактный слой без изменения legacy поведения;
- компактный `assistant_tool_calls` audit;
- nullable `source` и `request_id` в существующем domain audit;
- redaction правил: не логировать prompts, CSRF, cookies, комментарии и полные tool payloads.

## Какие файлы предполагается создать

- `app/Core/RequestContext.php`;
- `app/Core/FeatureFlags.php`;
- `app/Core/ApiError.php`;
- `app/Service/TrainingPlanContractValidator.php`;
- `app/Service/AssistantAuditService.php`;
- `database/migrations/009_webmcp_foundation.sql`;
- `tests/stage10-webmcp-foundation.php`.

## Какие файлы предполагается изменить

- `bootstrap.php`;
- `.env.example`;
- `database/schema.sql`;
- `app/Service/PlanImportService.php`;
- при необходимости `app/helpers.php`;
- существующие tests, только если требуется обновить bootstrap fixtures.

## Что не входит в этап

- новые routes;
- `/assistant`;
- WebMCP JavaScript;
- read query services;
- draft/version lifecycle;
- activation.

## Acceptance criteria

- legacy `training-plan` v1.0 импортируется и отклоняется точно по прежним правилам;
- feature flags по умолчанию выключены;
- один request ID используется в header, envelope и exception log;
- audit не хранит raw prompt/input/output и secrets;
- migration и `schema.sql` согласованы;
- весь существующий test suite остаётся зелёным.

## Тесты

- parity старого и нового validator;
- unknown fields, whitespace-only strings, range invariants и canonical hash;
- body-size и strict ID helpers;
- audit redaction;
- correlation ID;
- SQLite unit/integration tests;
- MySQL/MariaDB migration dry run на staging.

## Возможный rollback

Выключить WebMCP flags. Additive audit table/columns после production deployment не удалять. Validator delegation откатывать только при доказанной regression импорта.

## Ожидаемый результат

Безопасная foundation, не меняющая пользовательское поведение и не открывающая новые API.

## Стартовый промт для этапа 1

```text
Реализуй этап 1 WebMCP-интеграции: общие контракты, request security и audit foundation. Сначала проверь текущее состояние bootstrap.php, PlanImportService, schema.sql, helpers и тестов. Извлеки общий validator/canonicalization без изменения поведения legacy training-plan v1.0; добавь единый correlation ID, выключенные по умолчанию feature flags, безопасный API error envelope и compact assistant tool-call audit. Не добавляй routes, WebMCP JavaScript, read API или version drafts. Сохрани все существующие тесты и добавь проверки parity, redaction, body/ID limits и request ID. После работы проверь git diff, существующий полный test suite и миграцию. Не переходи к этапу 2.
```

---

# Этап 2 — read query layer и детерминированная аналитика

## Цель

Создать tenant-safe application/query layer со стабильными DTO для будущих read tools, не возвращая raw DB rows.

## Предпосылки

Этап 1 завершён.

## Что именно реализовать

- минимальный training profile context;
- current/specific program version projections;
- список тренировок с ranges, filters и cursor pagination;
- planned/fact workout detail;
- историю упражнения с `from/to/limit/cursor`;
- progress summary;
- scheduled workout по локальной дате;
- поиск упражнений и подбор кандидатов на замену;
- явный `data_quality` и аналитические caveats;
- разделение strength и swimming;
- вычисление метрик на backend.

Backend должен считать:

- counts/statuses;
- working sets;
- tonnage;
- duration;
- average RIR и session RPE;
- Epley e1RM;
- target rep-range compliance;
- substitutions, skipped и pending;
- planned-vs-actual;
- weekly trends;
- per-exercise/per-muscle aggregates;
- past-due concrete plans;
- heuristic plateau signals.

## Какие файлы предполагается создать

- `app/Repository/TrainingQueryRepository.php`;
- `app/Service/TrainingQueryService.php`;
- `tests/stage11-training-query.php`.

## Какие файлы предполагается изменить

- `app/Domain/TrainingMetrics.php` — только backward-compatible additions;
- `app/Domain/Analytics.php` — только backward-compatible additions;
- возможно `app/Service/ReportService.php` для безопасного повторного использования DTO.

## Что не входит в этап

- HTTP routes;
- Site tool registration;
- WebMCP frontend;
- database lifecycle migration;
- drafts и activation.

## Acceptance criteria

- DTO не содержат email, login, password fields, raw source JSON, full audit или ненужные numeric IDs;
- каждый query tenant-scoped;
- list endpoints имеют cursor/limit и hard date ranges;
- `pending` не считается автоматически skipped;
- past-due planned не называется доказанным missed;
- muscle aggregates имеют double-count caveat;
- swimming не смешивается со strength tonnage/RIR.

## Тесты

- timezone/date boundaries;
- cursor pagination;
- cross-user plan/session/exercise IDs;
- tonnage, RIR, e1RM и duration;
- planned-vs-actual;
- substituted/skipped/pending;
- data-quality flags;
- empty и oversized ranges.

## Возможный rollback

Удалить новые query/service classes. Существующий UI продолжает использовать прежний `TrainingRepository`.

## Ожидаемый результат

Полностью тестируемый read application layer, пригодный и для Site tools, и для будущего UI/API.

## Стартовый промт для этапа 2

```text
Реализуй только этап 2: read query layer и детерминированную тренировочную аналитику. Предполагается, что этап 1 уже добавил общие validators/security/audit helpers. Сначала перепроверь TrainingRepository, ReportService, TrainingMetrics, Analytics и фактическую схему. Создай tenant-scoped TrainingQueryRepository/TrainingQueryService с DTO для profile context, programs/versions, workouts, exercise history, progress summary, scheduled workout и exercise alternatives. Добавь ranges, cursors, hard limits и data_quality. Не создавай HTTP routes и не регистрируй WebMCP tools. Не возвращай raw rows, email/login/source_json/audit. Покрой IDOR, timezone, pagination и метрики тестами. Не переходи к этапу 3.
```

---

# Этап 3 — authenticated read-only Assistant API

## Цель

Открыть query services через узкие semantic endpoints, не подключая WebMCP frontend.

## Предпосылки

Этапы 1–2 завершены.

## Что именно реализовать

Semantic endpoints под `/api/assistant/*` для:

- profile context;
- current/specific plan;
- list plan versions;
- list/get workouts;
- exercise history;
- progress summary;
- scheduled workout;
- exercise search/alternatives.

Controller boundary должен централизовать:

- `Auth::requireUser(true)`;
- strict route/body inputs;
- ranges, limits и pagination;
- no-store;
- per-user/tool rate limits;
- request ID;
- error mapping 401/404/409/419/422/429;
- tool-call audit.

## Какие файлы предполагается создать

- `app/Controller/SiteToolsApiController.php`;
- `app/Core/SiteToolRequestGuard.php`;
- `tests/stage12-site-tools-api.php`;
- при необходимости отдельный HTTP test harness.

## Какие файлы предполагается изменить

- `public/index.php`;
- возможно `app/helpers.php`.

## Что не входит в этап

- `/assistant` page;
- `document.modelContext`;
- frontend tool catalog;
- любые writes;
- activation.

## Acceptance criteria

- без сессии — 401;
- чужой или несуществующий entity — 404;
- invalid inputs — 422;
- превышение rate limit — 429;
- GET endpoints действительно side-effect free;
- все ответы `no-store`;
- `user_id` отсутствует во входных контрактах;
- никакие raw repository arrays не выдаются.

## Тесты

- HTTP session cookie flow;
- auth и IDOR;
- filters/cursors/ranges;
- oversized body/query;
- route value `1abc`;
- invalid enums/dates;
- rate limit;
- response envelope и correlation ID.

## Возможный rollback

Выключить `WEBMCP_READ_ENABLED` или убрать новые routes. Query layer остаётся безопасно неиспользуемым.

## Ожидаемый результат

Безопасный read-only API, независимый от экспериментального browser API.

## Стартовый промт для этапа 3

```text
Реализуй только этап 3: authenticated read-only Assistant API поверх готового TrainingQueryService. Сначала проверь результат этапов 1–2 и текущий Router/Auth/Csrf/json_response. Добавь отдельные semantic endpoints под /api/assistant для profile, plans, workouts, exercise history, progress, scheduled workout и exercise search/alternatives. Централизуй auth, strict inputs, ranges, rate limits, no-store, correlation ID и error mapping. user_id не должен приниматься. Добавь HTTP-level тесты 401/404/422/429, foreign IDs, oversized input и pagination. Не добавляй /assistant page, modelContext или writes. Не переходи к этапу 4/5.
```

---

# Этап 4 — lifecycle active version и backup compatibility

## Цель

Устранить неоднозначность «текущей программы» и подготовить модель к draft/activation.

## Предпосылки

Этап 1 завершён. Этап может выполняться параллельно этапам 2–3 в отдельной ветке.

## Что именно реализовать

Добавить в `program_versions`:

- `lifecycle_status` со значениями `draft/published/archived`;
- `lock_version`;
- `aggregate_hash`;
- `updated_at`;
- `activated_at`;
- `archived_at`.

Добавить в `training_programs`:

- `active_version_id`.

Добавить versioned schedule mapping:

- `program_schedule_slots`;
- version → template → weekday;
- unique slot на weekday в рамках текущей простой недельной модели.

Реализовать conservative backfill и reconciliation:

- существующие versions → published;
- однозначные single-version programs можно связать автоматически;
- неоднозначные cases нельзя угадывать;
- `/programs` должен позволять увидеть необходимость reconciliation.

Обновить backup:

- export v1.1 включает schedule slots;
- restore принимает v1.0 и v1.1;
- technical `assistant_tool_calls` не входит в backup.

## Какие файлы предполагается создать

- `database/migrations/010_program_version_lifecycle.sql`;
- `bin/reconcile-program-versions.php`;
- `tests/stage13-program-lifecycle.php`.

## Какие файлы предполагается изменить

- `database/schema.sql`;
- `app/Service/BackupService.php`;
- `views/programs.php`;
- `app/Controller/WebController.php`;
- database/backup documentation.

## Что не входит в этап

- create/update draft service;
- activation transaction;
- WebMCP page или tools;
- автоматическое удаление старых версий;
- автоматический выбор неоднозначной программы.

## Acceptance criteria

- existing versions остаются immutable и получают published lifecycle;
- effective active version определяется pointer, а не `MAX(version_number)`;
- ambiguous current plan возвращает явное состояние;
- migration и fresh schema совпадают;
- backup v1.0 восстанавливается;
- backup v1.1 round-trip сохраняет slots;
- ownership version/template/slot проверяется.

## Тесты

- single/multiple version backfill;
- ambiguous active programs;
- invalid cross-program active pointer;
- duplicate weekday slots;
- tenant isolation;
- backup 1.0/1.1;
- реальная MySQL/MariaDB migration.

## Возможный rollback

Выключить использование active pointer feature flag. Additive columns/table в production не удалять. Pointer остаётся nullable, старый UI продолжает работать.

## Ожидаемый результат

Формализованный current plan и историческое расписание версии без включения write workflow.

## Стартовый промт для этапа 4

```text
Реализуй только этап 4: явный lifecycle версии программы, active_version_id, versioned schedule slots и backup compatibility. Предполагается наличие foundation этапа 1. Сначала проверь фактические program_versions, training_programs, workout_templates, schedules и BackupService. Сделай additive migration и обнови schema.sql. Существующие versions должны стать published; неоднозначный current plan нельзя выбирать молча — добавь dry-run/reconciliation command и понятное UI-состояние. Обнови backup до v1.1 с чтением v1.0. Не реализуй create/update draft, activation transaction или WebMCP. Проверь SQLite tests и миграцию на MySQL/MariaDB. Не переходи к этапу 5/6.
```

---

# Этап 5 — `/assistant` и read-only WebMCP tools

## Цель

Подключить read-only Site tools как безопасное progressive enhancement.

## Предпосылки

Этапы 3–4 завершены.

## Что именно реализовать

- authenticated page `/assistant`;
- серверный tool catalog с names, descriptions и input schemas;
- thin JavaScript adapter;
- feature detection через `document.modelContext`;
- registration lifecycle через AbortSignal;
- capability/feature-flag status в UI;
- read-only annotations;
- `untrustedContentHint` для DB text;
- same-origin fetch к готовым API;
- structured success/error results;
- явную page-scoped модель: tools доступны, пока открыта `/assistant`.

Read catalog:

- `training.get_profile`;
- `training.get_current_plan`;
- `training.get_plan`;
- `training.list_plan_versions`;
- `training.list_workouts`;
- `training.get_workout`;
- `training.get_exercise_history`;
- `training.get_progress_summary`;
- `training.get_scheduled_workout`;
- `training.search_exercises`;
- `training.find_alternatives`.

## Какие файлы предполагается создать

- `app/Controller/AssistantController.php`;
- `app/WebMcp/ToolCatalog.php`;
- `views/assistant.php`;
- `public/assets/webmcp.js`;
- `tests/webmcp-registration.js`.

## Какие файлы предполагается изменить

- `public/index.php`;
- `views/layout.php` для условного подключения adapter;
- `bootstrap.php` для `Permissions-Policy: tools=(self)` и при необходимости origin isolation;
- `.env.example`.

## Что не входит в этап

- write tool registration;
- draft/activation frontend;
- archive/delete/history edits;
- global registration на всех страницах;
- deprecated `navigator.modelContext` fallback.

## Acceptance criteria

- tools discoverable только на authenticated `/assistant`;
- tools отсутствуют на login и обычных страницах;
- без `document.modelContext` нет console errors;
- используются текущие актуальные WebMCP APIs;
- page navigation/logout aborts registration;
- writes отсутствуют независимо от backend availability;
- `/assistant` и API не работают из offline cache.

## Тесты

- fake `document.modelContext` в Node;
- names/descriptions/input schemas/annotations;
- no-capability behavior;
- abort lifecycle;
- API errors/network errors;
- Chrome origin trial/flag;
- ChatGPT built-in browser smoke;
- Safari/PWA regression.

## Возможный rollback

Установить `WEBMCP_ENABLED=false`. Read API и обычная PWA продолжают работать.

## Ожидаемый результат

Первый staging rollout, позволяющий ChatGPT безопасно анализировать данные без writes.

## Стартовый промт для этапа 5

```text
Реализуй только этап 5: authenticated /assistant и read-only WebMCP Site tools. Предполагается, что read API и active-version model уже готовы. Сначала проверь актуальную официальную документацию OpenAI/Chrome/WebMCP и текущее API; используй document.modelContext, не navigator.modelContext. Создай отдельную страницу, thin same-origin adapter, PHP tool catalog, feature detection, AbortSignal lifecycle и актуальные annotations. Регистрируй только read tools и только при включённых flags. Без capability приложение должно работать без ошибок; /assistant и API не должны кешироваться offline. Добавь Node registration tests и manual smoke checklist для ChatGPT built-in browser. Не реализуй ни одного write tool.
```

---

# Этап 6 — program draft schema и application service

## Цель

Реализовать mutable drafts поверх существующих immutable program versions, не связывая их пока с HTTP/WebMCP.

## Предпосылки

Этап 4 завершён.

## Что именно реализовать

- отдельный `training-program-draft` v1.0;
- `templates[]` и `schedule_slots[]`;
- повторное использование exercise/range rules из `training-plan` v1.0;
- clone active или выбранной old version;
- создание новой программы без client-assigned version number;
- server-assigned version number;
- mandatory reason и parent provenance;
- typed update operations вместо JSON Patch;
- optimistic `lock_version`;
- full aggregate canonicalization и `aggregate_hash`;
- validation всех templates/exercises/schedule slots после каждой операции;
- immutable published/active/archived versions.

Typed operations:

- `set_program_metadata`;
- `upsert_template`;
- `remove_template`;
- `upsert_exercise`;
- `remove_exercise`;
- `set_schedule_slot`;
- `remove_schedule_slot`.

## Какие файлы предполагается создать

- `docs/training-program-draft-v1.0.schema.json`;
- `app/Service/ProgramDraftValidator.php`;
- `app/Service/ProgramVersionService.php`;
- при необходимости `app/Repository/ProgramVersionRepository.php`;
- `tests/stage14-program-drafts.php`.

## Какие файлы предполагается изменить

- shared contract validators/canonicalization;
- JSON contract documentation.

## Что не входит в этап

- HTTP endpoints;
- Site tool writes;
- activation;
- materialization future workout plans;
- редактирование published versions;
- изменение legacy `training-plan` v1.0 root.

## Acceptance criteria

- draft можно создать/клонировать и менять только при правильном lock version;
- version number назначает сервер;
- published content нельзя изменить;
- каждый update валидирует полный агрегат;
- canonical hash стабилен;
- invalid template/exercise/schedule откатывает всю transaction;
- `source=webmcp` поддержан, но service не зависит от WebMCP.

## Тесты

- clone current и old version;
- new program draft;
- typed operations;
- hash stability;
- concurrent update 409;
- invalid references/ranges/schedules;
- tenant isolation;
- transaction rollback.

## Возможный rollback

Поскольку endpoints ещё нет, service можно оставить неиспользуемым. Существующие draft rows не удалять автоматически; при необходимости пометить archived отдельной административной процедурой.

## Ожидаемый результат

Независимый и тестируемый domain workflow draft creation/update.

## Стартовый промт для этапа 6

```text
Реализуй только этап 6: schema и application service для program drafts. Предполагается готовая lifecycle migration. Сначала проверь legacy training-plan v1.0 и shared validator: его root нельзя переопределять как целую программу. Добавь отдельный training-program-draft v1.0 с templates[] и schedule_slots[], переиспользуя exercise/range rules. Реализуй create/clone/update draft, server-assigned version number, typed operations, lock_version и aggregate_hash. Published/active/archived content должно быть immutable. Не добавляй HTTP endpoints, activation или WebMCP writes. Покрой transactions, hash stability, conflicts, invalid schedules и tenant isolation. Не переходи к этапу 7.
```

---

# Этап 7 — Draft API и activation с app confirmation

## Цель

Предоставить безопасные backend writes и обычный UI/HITL activation workflow до подключения WebMCP write tools.

## Предпосылки

Этап 6 завершён.

## Что именно реализовать

- semantic endpoints create/update draft;
- prepare activation без изменения active state;
- impact preview;
- session-bound, short-lived, single-use confirmation;
- binding confirmation к user, draft ID, `aggregate_hash`, effective date и policy;
- manual confirm/cancel в интерфейсе приложения;
- atomic activation;
- перевод новой программы в active и предыдущей в paused;
- сохранение old version как immutable published;
- materialization future workout plans по version schedule slots;
- explicit `effective_from`, horizon 1–12 недель и future-plan policy;
- запрет изменения completed/in-progress/history;
- idempotency и dual audit.

## Какие файлы предполагается создать

- controller/application classes для Draft API и activation;
- activation preview/confirmation view или partial;
- `tests/stage15-plan-activation.php`.

## Какие файлы предполагается изменить

- `public/index.php`;
- `ProgramVersionService.php`;
- program/assistant views;
- feature flag configuration.

## Что не входит в этап

- WebMCP write registration;
- planned-instance exercise replacement;
- archive/delete tools;
- изменение historical completed data.

## Acceptance criteria

- prepare activation ничего не активирует;
- без ручного app confirm activation невозможна;
- confirmation нельзя повторно использовать;
- stale draft/hash/lock отклоняется;
- old version и история сохраняются;
- completed и in-progress workouts не меняются;
- future-plan impact полностью показан до подтверждения;
- отмена не меняет DB.

## Тесты

- prepare/confirm/cancel/expiry/replay;
- stale hash и version conflicts;
- multiple active programs;
- future-plan keep/supersede policies;
- ownership;
- CSRF/Origin;
- idempotency;
- domain и tool-call audit;
- transaction rollback.

## Возможный rollback

Выключить draft/activation flags. Drafts сохранить. Уже активированные версии не откатывать автоматически; rollback программы выполняется созданием нового draft на основе старой версии и новой activation.

## Ожидаемый результат

Полностью проверенный обычный HTTP/UI workflow draft → preview → confirm → active.

## Стартовый промт для этапа 7

```text
Реализуй только этап 7: Draft API и activation workflow с обязательным app-level confirmation. Предполагается готовый ProgramVersionService. Сначала проверь его contracts и текущий CSRF/session preview pattern импорта. Добавь semantic create/update draft endpoints, prepare activation, impact preview и single-use session-bound confirmation, привязанное к draft hash. Activation должна быть транзакционной, сохранять старую версию и историю, не трогать completed/in-progress workouts и материализовать future instances только по явно показанной policy/horizon. Добавь idempotency, audit, Origin/CSRF и conflict tests. WebMCP write tools пока не регистрируй. Не переходи к этапу 8/9.
```

---

# Этап 8 — безопасные изменения workout instance

## Цель

Поддержать сценарий «изменить только сегодняшнюю тренировку» без изменения training program/template.

## Предпосылки

Этап 1 завершён. Этап можно выполнять параллельно этапам 6–7 в отдельной ветке.

## Что именно реализовать

- `WorkoutInstanceService`;
- reschedule planned workout по stable external ID;
- replacement в active session через существующую бизнес-операцию;
- replacement в ещё не начатом planned instance;
- provenance original/actual exercise и reason;
- наследование original exercise при создании session snapshot;
- explicit scope `scheduled_instance` или `active_session`;
- optimistic versions;
- `client_action_id` и idempotency;
- semantic API endpoints для этих двух операций;
- запрет изменения template/program version.

## Какие файлы предполагается создать

- `database/migrations/011_workout_instance_substitutions.sql`;
- `app/Service/WorkoutInstanceService.php`;
- `tests/stage16-workout-instance-writes.php`.

## Какие файлы предполагается изменить

- `database/schema.sql`;
- `app/Repository/TrainingRepository.php`;
- API controller/routes;
- `startSession()`;
- `BackupService` при появлении новых переносимых полей.

## Что не входит в этап

- изменение program version/template;
- редактирование completed history;
- finish session;
- WebMCP registration;
- archive/delete.

## Acceptance criteria

- scope обязателен и однозначен;
- planned replacement сохраняет original exercise;
- session snapshot правильно наследует provenance;
- program/template/hash не меняются;
- active replacement сохраняет существующее поведение;
- чужой target всегда 404;
- duplicate action id не дублирует mutation.

## Тесты

- planned/active/completed status rules;
- cross-user IDs;
- optimistic conflicts;
- idempotency;
- provenance в последующей session/history;
- no program mutation;
- CSRF/Origin;
- backup compatibility.

## Возможный rollback

Выключить instance-write flag. Additive provenance columns оставить. Старое active-session UI продолжает работать.

## Ожидаемый результат

Две узкие reversible operations для конкретного workout instance.

## Стартовый промт для этапа 8

```text
Реализуй только этап 8: безопасные workout-instance mutations. Сначала проверь существующие reschedulePlan, replaceExercise, startSession и таблицы workout_exercises/session_exercises. Создай WorkoutInstanceService и, если необходимо, additive provenance migration для original exercise/reason/time. Поддержи перенос planned workout и замену упражнения в planned instance или active session, всегда без изменения template/program version. Используй external/public IDs, optimistic versions, client_action_id, CSRF, Origin и audit. Historical completed workouts не редактировать. WebMCP registration не добавлять. Покрой ownership, status rules, conflicts, idempotency и provenance тестами. Не переходи к этапу 9.
```

---

# Этап 9 — WebMCP write tools

## Цель

Подключить уже протестированные backend write workflows к Site tools.

## Предпосылки

Этапы 5, 7 и 8 завершены.

## Что именно реализовать

Зарегистрировать:

- `training.create_plan_draft`;
- `training.update_plan_draft`;
- `training.activate_plan`;
- `training.reschedule_workout`;
- `training.replace_exercise`.

Требования:

- каждый класс writes включается отдельным feature flag;
- no `readOnlyHint=true` для writes;
- activation показывает in-page app modal с impact preview;
- execute не завершает activation до ручного confirm;
- отмена возвращает structured `USER_CANCELLED` без mutation;
- не полагаться на `requestUserInteraction`, которого нет в актуальном WebMCP IDL;
- tool adapter остаётся thin и использует только semantic APIs;
- writes не сохраняются в offline outbox;
- все calls auditируются;
- network/conflict/CSRF/rate errors возвращаются структурированно.

## Какие файлы предполагается создать

- fixtures/tests write registration и confirmation interaction;
- при необходимости отдельный frontend modal module.

## Какие файлы предполагается изменить

- `app/WebMcp/ToolCatalog.php`;
- `public/assets/webmcp.js`;
- `views/assistant.php`;
- feature flag configuration.

## Что не входит в этап

- archive plan;
- delete;
- historical workout edits;
- backup/restore;
- account mutations;
- generic tool;
- новые backend business operations.

## Acceptance criteria

- в read-only mode ни один write tool не регистрируется;
- activation всегда требует app modal;
- cancel/navigation/network loss не активируют plan;
- repeated calls безопасны благодаря idempotency;
- write inputs не содержат `user_id`;
- ordinary PWA и Safari не ломаются;
- tools снимаются при уходе со страницы.

## Тесты

- fake modelContext registration;
- flags matrix;
- confirm/cancel/stale confirmation;
- duplicate calls;
- page navigation;
- offline/network loss;
- 401/404/409/419/422/429;
- ChatGPT built-in browser manual/E2E.

## Возможный rollback

Последовательно выключить `WEBMCP_ACTIVATION_ENABLED`, `WEBMCP_INSTANCE_WRITE_ENABLED`, `WEBMCP_DRAFT_WRITE_ENABLED`. Read tools остаются доступными.

## Ожидаемый результат

Полный пользовательский flow analysis → draft → discussion → activation и безопасные instance-only changes.

## Стартовый промт для этапа 9

```text
Реализуй только этап 9: WebMCP write tools поверх уже готовых и протестированных backend APIs. Сначала перечитай актуальные официальные OpenAI/Chrome/WebMCP docs и проверь результаты этапов 5, 7 и 8. Добавь create_plan_draft, update_plan_draft, activate_plan, reschedule_workout и replace_exercise в ToolCatalog и thin adapter. Writes должны включаться отдельными flags. Activation обязана показывать app modal с impact preview и завершаться только после ручного confirm; не полагайся на несуществующий requestUserInteraction API. Не добавляй archive/delete/history edit/backup tools. Добавь registration, cancellation, navigation, conflict, offline и ChatGPT browser tests. Не переходи к этапу 10.
```

---

# Этап 10 — security hardening, E2E, rollout и документация

## Цель

Проверить всю интеграцию перед controlled production rollout и создать эксплуатационную документацию.

## Предпосылки

Этап 9 завершён.

## Что именно реализовать

- полный A–J E2E suite;
- cross-user/IDOR matrix;
- prompt-injection fixtures в comments/notes/instructions/custom names;
- CSRF/Origin/Fetch-Metadata/rate/size tests;
- audit retention и prune command;
- MySQL/MariaDB migration validation;
- backup v1.0/v1.1 validation;
- capability-disabled, Safari и PWA regressions;
- ChatGPT built-in browser acceptance;
- staging/production rollout checklist;
- debugging и operator guidance.

Сценарии A–J:

- анализ 6–8 недель;
- факт против current plan;
- прогресс конкретного упражнения;
- часто невыполняемые упражнения;
- анализ muscle balance с data-quality caveats;
- оценка необходимости смены программы;
- создание draft без activation;
- activation после подтверждения;
- поиск альтернативы;
- замена только в текущем workout instance.

## Какие файлы предполагается создать

- `docs/webmcp.md`;
- security/E2E test scripts;
- `bin/prune-assistant-audit.php`;
- staging/rollout checklist при необходимости отдельным документом.

## Какие файлы предполагается изменить

- `README.md`;
- `INSTALL.md`;
- `.env.example`;
- `docs/work-status.md`;
- test runner/documented commands.

## Что не входит в этап

- новые business tools;
- расширение training profile;
- archive/delete WebMCP operations;
- рефакторинг приложения, не связанный с WebMCP.

## Acceptance criteria

- все A–J scenarios проходят;
- все cross-user attempts отклоняются;
- prompt-like DB text остаётся только untrusted data;
- приложение полностью работает без capability;
- normal Safari/iPhone PWA не имеет regression;
- JSON import/export и backup fallback работают;
- production flags безопасно выключены до явного включения;
- migrations проверены на целевой DB;
- `docs/webmcp.md` покрывает purpose, tools, auth, security, schemas, fallback, debugging, testing, добавление tool и текущие ограничения WebMCP.

## Тесты

- unit;
- API/integration;
- WebMCP discovery/registration;
- security;
- compatibility;
- MySQL/MariaDB staging;
- ChatGPT built-in browser;
- обычный browser и standalone PWA.

## Возможный rollback

Отключать capability по слоям:

```text
activation off
  → instance writes off
  → draft writes off
  → reads off
  → WEBMCP_ENABLED=false
```

Обычная PWA и JSON workflow должны продолжать работать на каждом шаге.

## Ожидаемый результат

Готовая к controlled production rollout WebMCP-интеграция с документацией, тестами и безопасным fallback.

## Стартовый промт для этапа 10

```text
Реализуй только этап 10: security hardening, E2E, rollout и документацию WebMCP. Функциональность read/write уже должна существовать. Не добавляй новые business tools. Проведи A–J E2E, полный cross-user/IDOR matrix, prompt-injection fixtures, CSRF/Origin/rate/size tests, capability-disabled и Safari/PWA regressions. Проверь migrations и backup v1.0/v1.1 на MySQL/MariaDB staging, затем ChatGPT built-in browser. Создай docs/webmcp.md с каталогом tools, auth/security, schemas, fallback, debugging, testing, добавлением tool и текущими ограничениями стандарта. Обнови README, INSTALL, .env.example и rollout checklist. Production flags оставь безопасно выключенными до явного включения.
```

---

# Практический порядок запуска

Рекомендуемый последовательный порядок:

```text
1 → 2 → 3 → 4 → 5 → 6 → 7 → 8 → 9 → 10
```

Хотя карта допускает параллельность, для одного разработчика последовательный порядок безопаснее: меньше merge-конфликтов и проще проверять предпосылки.

Перед каждым новым этапом:

1. Убедиться, что предыдущие обязательные этапы завершены.
2. Проверить `git status` и текущую ветку.
3. Вставить в новый чат общий контекст, описание этапа и его промт.
4. Попросить новый чат сначала проверить репозиторий, а не верить предположениям промта.
5. После реализации проверить diff, tests, migrations и фактическое соблюдение границ.
6. Зафиксировать результат отдельным commit с русским сообщением.
7. Не начинать следующий этап в том же чате, если контекст уже велик или этап затронул много файлов.

## Что вставлять в новый чат: итоговая рекомендация

Оптимальный вариант:

```text
[Общий контекст для нового чата]

[Полное описание выбранного этапа от заголовка до ожидаемого результата]

[Стартовый промт для выбранного этапа]
```

Только стартовый промт допустим, если:

- все предыдущие этапы уже находятся в текущей ветке;
- `docs/webmcp.md` или этот файл актуальны;
- этап не меняет модель данных или confirmation flow;
- новый чат всё равно начинает с проверки фактического репозитория.

Для этапов 4, 6, 7 и 9 рекомендуется всегда вставлять полную карточку этапа вместе с промтом.
