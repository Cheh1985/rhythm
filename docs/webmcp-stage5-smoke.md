# Этап 5: ручной smoke WebMCP

## Перед проверкой

1. Развернуть приложение по HTTPS с `APP_ENV=production` и точным `APP_URL`.
2. Включить только:

   ```dotenv
   WEBMCP_ENABLED=true
   WEBMCP_READ_ENABLED=true
   WEBMCP_DRAFT_WRITE_ENABLED=false
   WEBMCP_INSTANCE_WRITE_ENABLED=false
   WEBMCP_ACTIVATION_ENABLED=false
   ```

3. Войти обычным пользователем и открыть `/assistant` напрямую.
4. В DevTools проверить ответы `/assistant` и `/api/assistant/*`: `Cache-Control: no-store`, `Permissions-Policy: tools=(self)`, `Origin-Agent-Cluster: ?1`.

## ChatGPT desktop built-in browser

1. Открыть `/assistant` во встроенном браузере ChatGPT и разрешить доступ к сайту.
2. Убедиться, что UI сообщает о регистрации 11 read-only tools, а список Site tools содержит только имена `training.*` из серверного каталога.
3. Попросить ChatGPT показать профиль, текущую программу, тренировку на дату, историю упражнения и сводку прогресса. Сопоставить результат с UI приложения.
4. Проверить пагинацию `training.list_workouts`, `training.get_exercise_history` и `training.search_exercises`, передав `next_cursor` без изменений.
5. Открыть login, Dashboard и Settings в отдельных вкладках: Site tools там отсутствуют.
6. Уйти с `/assistant`, закрыть вкладку и выполнить logout. Tool catalog должен исчезнуть; повторный вызов старого tool не должен работать.
7. Выключить `WEBMCP_READ_ENABLED`: `/assistant` остаётся обычной authenticated page, но каталог пуст и adapter не подключён.
8. Выключить `WEBMCP_ENABLED`: `/api/assistant/*` отвечает 404, обычная PWA продолжает работать.

## Ошибки и безопасность

1. Передать неизвестный аргумент, `user_id`, чужой stable ID, некорректную дату и испорченный cursor. Ожидаются структурированные validation/not-found errors без утечки данных другого пользователя.
2. Отключить сеть после загрузки страницы и вызвать tool. Ожидается `network_error`; `/assistant` и API не открываются из offline cache.
3. Проверить, что ответы с названиями, заметками и комментариями имеют `untrustedContentHint: true`, а все tools — `readOnlyHint: true`.
4. Убедиться, что в Network нет cross-origin запросов, request body и CSRF/write вызовов.
5. Проверить Console: при отсутствии `document.modelContext`, сетевой ошибке и уходе со страницы нет uncaught/unhandled ошибок.

## Chrome и регрессия PWA

Для локальной диагностики в совместимой версии Chrome включить `chrome://flags/#enable-webmcp-testing` либо использовать origin trial, затем повторить discovery/execute/abort проверки. После этого в Safari/iOS и установленной PWA проверить Dashboard, тренировку, offline fallback и logout: отсутствие WebMCP capability не должно менять обычные сценарии.
