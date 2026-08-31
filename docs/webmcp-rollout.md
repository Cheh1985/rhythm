# WebMCP controlled rollout checklist

Заполняйте дату, окружение, commit SHA, оператора и ссылку на evidence для каждого запуска. Production flags до пункта «Rollout» остаются `false`.

## 1. Preflight

- [ ] Есть проверенный backup БД и документированное время восстановления.
- [ ] Deploy artifact соответствует одному commit SHA; worktree чистый.
- [ ] `APP_ENV=production`, `APP_DEBUG=false`, точный HTTPS `APP_URL`, доверенный proxy настроен явно.
- [ ] `WEBMCP_*` flags выключены; read/write limits и audit retention заданы.
- [ ] Выполнены `php tests/webmcp-e2e.php`, `php tests/smoke.php`, `php tests/stage8.php`.
- [ ] Проверены JSON import/export, training-report и обычный backup download/preview.

## 2. MySQL/MariaDB staging

- [ ] На disposable staging server прошёл `tests/mysql-webmcp-stage10.php`.
- [ ] На копии реальной схемы миграции 009 → 010 → 011 → 012 применены по порядку.
- [ ] `bin/reconcile-program-versions.php` dry-run не имеет необъяснённых ambiguous cases.
- [ ] Backup v1.1 экспортирован/проверен; v1.0 fallback восстановлен в отдельного тестового tenant.
- [ ] `bin/prune-assistant-audit.php` dry-run показывает ожидаемый threshold/count; `--apply` проверен только на staging.

## 3. Security acceptance

- [ ] Cross-user/IDOR matrix: reads, drafts, activation, scheduled/active instances и custom exercises возвращают 404/deny без утечки.
- [ ] Missing/wrong CSRF → 419; missing/wrong Origin → 403; `Sec-Fetch-Site: cross-site/same-site` → 403.
- [ ] Oversized query/body, неизвестные поля и `user_id` → 422; wrong content type → 415.
- [ ] Rate limit → 429 + `Retry-After`; другой tenant/tool не блокируется.
- [ ] Prompt-like comments/notes/instructions/custom names не меняют control flow; published строки остаются untrusted JSON data.
- [ ] Assistant audit не содержит prompts, payloads, credentials, cookies, CSRF или confirmation tokens.

## 4. Browser/PWA acceptance

- [ ] ChatGPT built-in browser: authenticated `/assistant` регистрирует ожидаемое число tools без console errors.
- [ ] A–F и I выполняются read-only; G создаёт draft и не активирует; H ждёт app confirmation; J меняет только выбранный instance.
- [ ] Cancel, navigation, stale token и network loss не активируют draft.
- [ ] Browser без capability показывает unsupported; обычные pages не публикуют tool catalog.
- [ ] Safari: login, dashboard, plan, workout, history, reports, backup и `/assistant` работают без Fetch Metadata header.
- [ ] iPhone standalone PWA: install/open/update/offline fallback/safe-area; `/assistant` и assistant API отсутствуют в Cache Storage.
- [ ] После logout пользовательские Cache/IndexedDB очищены.

## 5. Rollout

- [ ] Canary tenant/окно согласованы; оператор и rollback owner доступны.
- [ ] `WEBMCP_ENABLED=true` + `WEBMCP_READ_ENABLED=true`; writes остаются false.
- [ ] Проверены latency/error/denied/429 и отсутствие cross-tenant событий в первые 30–60 минут.
- [ ] Включён `WEBMCP_DRAFT_WRITE_ENABLED=true`; проверены idempotency и 409.
- [ ] Включён `WEBMCP_INSTANCE_WRITE_ENABLED=true`; проверены provenance и неизменность program template/version.
- [ ] `WEBMCP_ACTIVATION_ENABLED=true` включён последним; выполнены prepare/cancel и prepare/manual confirm.
- [ ] Запланирован ежедневный audit prune и мониторинг результата команды.

## 6. Rollback

При аномалии останавливайтесь на первом достаточном шаге:

```text
WEBMCP_ACTIVATION_ENABLED=false
  → WEBMCP_INSTANCE_WRITE_ENABLED=false
  → WEBMCP_DRAFT_WRITE_ENABLED=false
  → WEBMCP_READ_ENABLED=false
  → WEBMCP_ENABLED=false
```

- [ ] После каждого шага обычная PWA и JSON workflows продолжают работать.
- [ ] Pending activation tokens отменены/истекли; повторная activation требует нового preview.
- [ ] Incident evidence содержит request IDs, timestamps, tool/status и commit SHA без prompt/body/secrets.
- [ ] DB rollback выполняется только по отдельному проверенному плану; feature-flag rollback не требует удаления данных.
