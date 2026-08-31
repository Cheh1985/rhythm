# WebMCP stage 9 — manual smoke

Ручная проверка выполняется на staging с HTTPS, отдельным тестовым пользователем и актуальной версией ChatGPT desktop. Site tools доступны во встроенном браузере ChatGPT; Chrome используется отдельно с WebMCP origin trial/flag и Model Context Tool Inspector.

## Подготовка

1. Выполнить миграции и включить `WEBMCP_ENABLED=true`, `WEBMCP_READ_ENABLED=true`.
2. Поочерёдно включать `WEBMCP_DRAFT_WRITE_ENABLED`, `WEBMCP_INSTANCE_WRITE_ENABLED`, `WEBMCP_ACTIVATION_ENABLED`; остальные write flags на каждом шаге оставлять выключенными.
3. Войти в приложение непосредственно во встроенном браузере и открыть `/assistant`.
4. В панели Site tools проверить названия, схемы и `readOnlyHint=false` ровно у включённых write tools. Входы не должны содержать `user_id`.

## Сценарии

1. Создать новый draft и повторить тот же вызов с тем же `client_action_id`: должен вернуться исходный draft без дубля.
2. Клонировать active/выбранную published version, применить typed update и повторить вызов с тем же action ID. Новый payload с использованным ID должен получить 422.
3. Отправить update со stale `lock_version`: ожидается structured 409 без mutation.
4. Перенести один `scheduled_instance`; completed/foreign/stale instance должны дать 422/404/409. Повтор с тем же action ID не должен переносить тренировку второй раз.
5. Заменить упражнение отдельно в `scheduled_instance` и `active_session`; проверить original/actual provenance. Historical workout не меняется.
6. Вызвать `training.activate_plan`: до ручного действия должен появиться modal с программой, версией, периодом, policy, количеством created/superseded/kept/protected/blocked и paused programs. Пока modal открыт, active version не меняется.
7. Нажать «Отменить» и повторить через Escape: tool возвращает `USER_CANCELLED`, modal закрывается, draft и планы не меняются.
8. Открыть новый preview, изменить draft в другой вкладке и подтвердить: ожидается structured 409, activation не происходит.
9. Открыть preview и уйти со страницы/закрыть вкладку: tools снимаются, activation не происходит.
10. Перевести браузер offline до prepare и отдельно до confirm: ожидается structured network error, автоматической activation нет. Writes не появляются в IndexedDB offline outbox и не воспроизводятся после восстановления сети.
11. Проверить ответы 401/404/409/419/422/429, отсутствие raw payload/token в `assistant_tool_calls` и наличие audit для каждого вызова.
12. Выключить write flags: остаются только 11 read tools. Выключить master flag: tools исчезают полностью. Обычные PWA-страницы и Safari продолжают работать без `document.modelContext`.

Rollback выполняется в порядке `WEBMCP_ACTIVATION_ENABLED=false`, `WEBMCP_INSTANCE_WRITE_ENABLED=false`, `WEBMCP_DRAFT_WRITE_ENABLED=false`.
