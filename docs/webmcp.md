# WebMCP / ChatGPT Site tools в «Ритме»

## Назначение и границы

Страница `/assistant` публикует функции дневника как page-scoped WebMCP tools для вошедшего пользователя. Интеграция — progressive enhancement: `document.modelContext` → same-origin HTTP API → application services → существующие repository/domain операции → MySQL. Модель не получает `user_id`, не обращается к SQL и не имеет generic API/database tool.

Обычный PWA, импорт `training-plan` v1.0, экспорт `training-report` и backup/restore не зависят от WebMCP. Если browser capability отсутствует или master flag выключен, `/assistant` показывает понятный статус, остальные страницы продолжают работать.

## Feature flags

Все production defaults в `.env.example` выключены:

```dotenv
WEBMCP_ENABLED=false
WEBMCP_READ_ENABLED=false
WEBMCP_DRAFT_WRITE_ENABLED=false
WEBMCP_INSTANCE_WRITE_ENABLED=false
WEBMCP_ACTIVATION_ENABLED=false
# Пусто или * — все вошедшие пользователи; для canary — список numeric user IDs.
WEBMCP_ALLOWED_USER_IDS=
```

Дочерний flag действует только вместе с `WEBMCP_ENABLED=true`. Безопасная последовательность включения: master + reads → draft writes → instance writes → activation. Обратная последовательность используется для rollback.

`WEBMCP_ALLOWED_USER_IDS` применяется сервером и к `/assistant`, и ко всем read/write endpoints. Например, `WEBMCP_ALLOWED_USER_IDS=12,37` публикует и исполняет tools только для этих двух tenant owners. Пустое значение и `*` сохраняют поведение «для всех вошедших»; некорректное непустое значение закрывает доступ fail-closed. Это rollout-механизм, а не замена session authentication и repository ownership checks.

Лимиты и retention:

```dotenv
WEBMCP_READ_RATE_LIMIT=60
WEBMCP_READ_RATE_WINDOW_SECONDS=60
WEBMCP_WRITE_RATE_LIMIT=30
WEBMCP_WRITE_RATE_WINDOW_SECONDS=60
WEBMCP_AUDIT_RETENTION_DAYS=90
```

## Каталог tools

Все результаты помечены `untrustedContentHint=true`: имена, инструкции и другие строки из БД — данные пользователя, а не инструкции агенту.

| Tool | Класс | Назначение |
|---|---|---|
| `training.get_profile` | read | Минимальный профиль, timezone, локальная дата, ссылки на активные программы |
| `training.get_current_plan` | read | Current version с полными templates, упражнениями, targets и недельным расписанием либо явное empty/ambiguous state |
| `training.get_plan` | read | Безопасная полная проекция одной immutable версии, включая lifecycle metadata |
| `training.list_plan_versions` | read | Все версии программы, включая draft lifecycle/binding, без raw snapshot/source |
| `training.list_workouts` | read | Ограниченный date range, фильтры и cursor pagination |
| `training.get_workout` | read | Planned workout, recorded fact либо оба объекта |
| `training.get_exercise_history` | read | История упражнения, метрики и data-quality сигналы |
| `training.get_progress_summary` | read | Агрегаты strength/swimming и muscle balance без медицинских выводов |
| `training.get_scheduled_workout` | read | Конкретные планы и recurring expectation на локальную дату |
| `training.search_exercises` | read | Tenant-scoped каталог упражнений |
| `training.find_alternatives` | read | Детерминированные совпадения; не медицинская рекомендация |
| `training.create_plan_draft` | draft write | Новый draft или clone immutable версии |
| `training.update_plan_draft` | draft write | Одна typed operation с optimistic locking |
| `training.reschedule_workout` | instance write | Перенос одного ещё не начатого scheduled instance |
| `training.replace_exercise` | instance write | Замена в одном scheduled instance или active session с provenance |
| `training.activate_plan` | activation | Prepare preview → ожидание app confirmation → confirm/cancel |

Архивирование, удаление, правка исторических тренировок, backup/restore и account mutations намеренно не публикуются.

## Auth, tenant isolation и HTTP security

- Identity берётся только из серверной PHP-сессии; `user_id` отсутствует во входных JSON Schema и отклоняется как лишнее поле.
- Repository/query слои фильтруют по session `user_id`; чужие public IDs возвращают 404, чтобы не подтверждать существование объекта.
- `/assistant` и `/api/assistant/*` возвращают `no-store` и не кешируются Service Worker.
- Writes требуют exact `Origin`, session CSRF, `Content-Type: application/json`, закрытый JSON object, корректный `Idempotency-Key` и optimistic version.
- `Sec-Fetch-Site`, когда браузер его присылает, должен быть `same-origin`. Отсутствие заголовка допускается для Safari/старых клиентов; Origin остаётся обязательным для writes.
- Read/write rate limits считаются по database clock, пользователю и tool/operation. Размер GET query ограничен 4096 bytes; write body читается не более 1 MiB + 1 byte.
- Activation не завершается на основании ответа модели: сервер создаёт краткоживущий одноразовый token, а человек подтверждает показанный itemized impact preview в app dialog/form. Режим supersede отменяет только изменяемые будущие workout instances, созданные предыдущими версиями той же программы; ручные планы и instances других программ остаются неизменными и могут блокировать конфликтующие даты.
- `client_action_id` и `Idempotency-Key` связаны с payload. Повтор точного запроса возвращает receipt; повтор ключа с другим payload отклоняется.

## Input и output schemas

Источник истины — `app/WebMcp/ToolCatalog.php`. Каждая top-level и вложенная object schema имеет `additionalProperties=false`; маршрутизатор adapter повторно проверяет вход до fetch, а backend выполняет окончательную typed validation. Draft tools описывают полную структуру metadata/templates/exercises/schedule и отдельный payload для каждой разрешённой операции, поэтому модель не должна угадывать форму вложенного JSON.

Plan DTO не отдаёт `content_json`, `snapshot_json` или внутренние ID. Сервер декодирует сохранённый template aggregate и возвращает именованные поля упражнений и плановых показателей. Для draft version выдаются `lifecycle_status=draft` и `draft_binding` (`draft_id`, `lock_version`, `aggregate_hash`), достаточные для последующего typed update.

Успех API:

```json
{"data":{"…":"…"},"meta":{"request_id":"correlation-id"}}
```

Ошибка API:

```json
{"error":{"code":"validation_error","message":"…","request_id":"correlation-id"}}
```

Ожидаемые статусы: 401 unauthenticated, 403 Origin/Fetch Metadata, 404 disabled/foreign/not found, 409 optimistic/stale confirmation, 415 content type, 419 CSRF, 422 schema/domain validation, 429 rate limit. Cursor opaque; клиент не должен его изменять. Даты — локальные `YYYY-MM-DD`, timestamps в выдаче — UTC с явной семантикой.

## Prompt-injection и data minimization

Текст из comments, notes, exercise instructions и custom names не управляет adapter или backend. Read DTO выбираются явными колонками; чувствительные/raw поля (`password_hash`, email/login, `snapshot_json`, `source_json`, внутренние IDs) не выдаются. Некоторые notes/comments полностью исключены из minimized DTO; опубликованные строки остаются обычными JSON values под `untrustedContentHint=true`.

`assistant_tool_calls` не хранит prompt, query/body, comments, токены, cookies или credentials. Сохраняются correlation ID, tool, outcome, допустимый entity identifier, error code, duration и короткая allowlisted metadata. Domain mutations отдельно связываются с `audit_logs.source='webmcp'` и `request_id`.

Retention assistant audit по умолчанию — 90 дней. Команда сначала выполняет dry-run:

```bash
php bin/prune-assistant-audit.php
php bin/prune-assistant-audit.php --apply
php bin/prune-assistant-audit.php --apply --days=120
```

Business `audit_logs` команда не удаляет.

## Fallback и совместимость

- Нет `document.modelContext`: adapter ничего не регистрирует, показывает unsupported и не создаёт JS error.
- Flags выключены: server catalog пуст, adapter не подключается.
- Ошибка частичной registration: общий `AbortController` снимает весь page-scoped catalog.
- `pagehide`/navigation: registration и pending confirmation отменяются.
- Offline: writes не попадают в PWA outbox; возвращается structured network error, activation до confirmation endpoint не доходит.
- Используется только `document.modelContext.registerTool`; deprecated `navigator.modelContext` отсутствует.
- Safari/PWA остаются обычным приложением; отсутствие Fetch Metadata не блокирует session flow.

## Testing

Полный SQLite/HTTP/Node A–J и security runner:

```bash
php tests/webmcp-e2e.php
```

Он запускает foundation, read projections/API, draft, activation, instance writes, prompt-injection, capability fallback, registration, idempotency, backup v1.0/v1.1 и cross-user/IDOR suites.

MySQL/MariaDB staging test создаёт две случайные временные базы на указанном disposable server, проверяет fresh schema/seed, миграции 009–012 и backup restore v1.0/v1.1, затем удаляет только эти базы:

```bash
WEBMCP_TEST_MYSQL_DSN='mysql:host=127.0.0.1;port=3306;charset=utf8mb4' \
WEBMCP_TEST_MYSQL_USER='root' \
WEBMCP_TEST_MYSQL_PASSWORD='secret' \
php tests/mysql-webmcp-stage10.php
```

На Windows переменные задаются через `$env:WEBMCP_TEST_MYSQL_DSN=...`. Никогда не направляйте test на production server с учётной записью, имеющей доступ к чужим базам.

Ручные browser/device шаги и evidence-поля находятся в `docs/webmcp-rollout.md`.

## Debugging и operator guidance

1. Сверьте пять flags и убедитесь, что master включён.
2. Откройте authenticated `/assistant`: страница показывает число зарегистрированных tools и состояние capability.
3. Сопоставьте `X-Request-ID`/`meta.request_id` с `assistant_tool_calls.request_id`, `audit_logs.request_id` и `storage/logs/app.log`.
4. Для 403 проверьте точный `APP_URL`, scheme/port, reverse proxy и `TRUST_PROXY`; для 419 обновите страницу/сессию; для 429 дождитесь `Retry-After`.
5. Для 409 перечитайте актуальную version/hash и повторите как новое действие с новым `client_action_id`; stale activation требует нового preview.
6. При registration error сначала выключите write flags, затем reads; обычная PWA должна продолжить работу.

Не включайте `APP_DEBUG=true` в production и не копируйте cookies/CSRF/body в tickets. Для диагностики достаточно request ID, tool name, status, timestamp и безопасного entity public ID.

## Как добавить tool

1. Сначала добавьте узкую domain/application operation с tenant-scoped repository query; не добавляйте generic CRUD/SQL.
2. Создайте отдельный same-origin endpoint под `/api/assistant`, выберите read или semantic write guard.
3. Опишите закрытую JSON Schema в `ToolCatalog`, точное имя и side effect; установите корректный `readOnlyHint` и `untrustedContentHint`.
4. В thin adapter сопоставьте tool с одним endpoint. Writes не отправляйте в offline queue; для значимых действий проектируйте app-level confirmation.
5. Добавьте unit/API/IDOR/rate/size/prompt-injection/registration tests, rollout evidence и rollback flag.
6. Не расширяйте permissions и не включайте production flag до staging/browser acceptance.

## Текущие ограничения стандарта

WebMCP остаётся развивающимся browser API. Реализация ChatGPT использует imperative registration; доступность capability и UI могут отличаться по browser/host version. Annotations — подсказки агенту, а не security boundary. Нельзя полагаться на выдуманный `requestUserInteraction`: human confirmation реализовано приложением. Background execution, гарантированная offline-доставка и системные iOS уведомления WebMCP не обещает.
