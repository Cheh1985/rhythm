# Ритм

«Ритм» — завершённый многопользовательский mobile-first PWA-дневник тренировок на PHP 8.2+ и MySQL 8/MariaDB. Все 8 этапов MVP и 10 этапов WebMCP-интеграции реализованы: план, offline-выполнение, отчёт, аналитика, плавание, backup/restore, ChatGPT Site tools и controlled rollout hardening.

## Что уже работает

- front controller и небольшой маршрутизатор;
- конфигурация через `.env` без секретов в Git;
- PDO с настоящими prepared statements и транзакциями;
- production/development обработка ошибок и защитные HTTP-заголовки;
- регистрация, вход по логину или email и выход;
- `password_hash()` / `password_verify()`, CSRF, XSS escaping, regeneration сессии, безопасные cookie и rate limit входа;
- роли `user` / `admin` как архитектурная основа;
- нормализованная MySQL-схема, внешние ключи и индексы;
- изоляция пользовательских данных через обязательный `user_id` в запросах;
- адаптивный Dashboard: незавершённая, следующая и последняя тренировка, недельные показатели и быстрые действия;
- просмотр следующего плана, быстрый readiness и продолжение незавершённой сессии;
- глобальный и пользовательский справочник упражнений со статусом и шагом прогрессии;
- неизменяемые версии программ с родителями, причинами и шаблонами тренировок;
- строгий контракт `training-plan` v1.0, серверный upload, preview и подтверждение неизвестных упражнений;
- транзакционный импорт с защитой от дубликата `plan_id` и мобильный просмотр сохранённого плана.
- быстрый ввод подхода `вес → повторы → RIR`, раздельные warmup/working и online autosave;
- статусы упражнений, занятое оборудование, пропуск, замена, дискомфорт и оценка сложности;
- optimistic locking для нескольких вкладок, audit log редактирования и timestamp-таймер отдыха;
- завершение тренировки и мобильный summary с рабочим объёмом.
- installable PWA manifest, PNG/maskable/Apple icons и безопасное обновление Service Worker;
- IndexedDB-снимок активной сессии, формы и таймера, local-first outbox и восстановление после reload;
- идемпотентная последовательная синхронизация всех workout mutations с Web Locks/BroadcastChannel fallback;
- явные online/offline/pending/synced/error статусы и разрешение HTTP 409 без автоматического отбрасывания локальных данных;
- отдельный private navigation-cache активной тренировки с очисткой Cache/IndexedDB при logout; API не кешируются.
- `training-report` v1.0 с раздельными planned/fact/suggestion, UTC timestamps и audit trail;
- экспорт JSON, Markdown и ZIP с обоими файлами;
- double progression без автоматического изменения программы, e1RM Epley, PR и сравнение с прошлой тренировкой;
- редактирование завершённых подходов и итоговых оценок с пересчётом метрик и историей правок.
- пагинируемая история, недельная аналитика, адаптивные графики, страницы упражнений и измерения тела;
- отдельная модель плавания с блоками/интервалами, дистанцией, fatigue, самочувствием и безопасным редактированием;
- недельное расписание с defaults «Пн/Ср зал, Чт бассейн» и создание плавания из него;
- local-first autosave/outbox плавания, общая последовательность и `swimming-report` JSON/Markdown.
- полная пользовательская backup-копия v1.1 JSON/ZIP с versioned program schedule slots, checksum и строгим preview; restore совместим с v1.0;
- транзакционный restore в безопасном merge-режиме: без перезаписи/удаления, с remap внутренних ID, tenant isolation и идемпотентным receipt;
- подтверждаемая отмена незавершённой тренировки и soft delete подходов, измерений и плавания с audit trail;
- темы light/dark/system, safe-area, focus-visible, reduced motion и production security headers;
- плановый cleanup login attempts, offline receipts и старого файлового cache.

## Быстрый старт

Требуются PHP 8.2+ с `pdo_mysql`, MySQL 8 или MariaDB и права на уже созданную базу.

```bash
copy .env.example .env
php bin/install.php
php -S 127.0.0.1:8000 router.php
```

Откройте `http://127.0.0.1:8000/register`. Подробности и варианты установки — в [INSTALL.md](INSTALL.md).

## Проверки

```bash
php tests/smoke.php
php tests/stage2.php
php tests/stage3.php
php tests/stage4.php
php tests/stage5.php
php tests/stage6.php
php tests/stage7.php
php tests/stage8.php
node --preserve-symlinks --preserve-symlinks-main tests/stage4-queue.js
php tests/stage14-webmcp-page.php
node --preserve-symlinks --preserve-symlinks-main tests/webmcp-registration.js
php tests/stage17-webmcp-writes.php
node --preserve-symlinks --preserve-symlinks-main tests/webmcp-writes.js
php tests/stage18-webmcp-hardening.php
php tests/stage19-localization.php
php tests/webmcp-e2e.php
php -l public/index.php
php bin/cleanup.php
php bin/prune-assistant-audit.php
```

После входа импортируйте план через `/plans/import` или создайте плавание через `/swimming`; недельный ритм меняется на `/schedule`. Backup, restore, тема и язык интерфейса находятся на `/settings`. RU/EN можно выбрать и до входа: гостевой выбор хранится в cookie, а после входа применяется настройка профиля. Для offline smoke используйте сценарии из [docs/offline-first.md](docs/offline-first.md). Форматы импорта/экспорта описаны в [docs/json-format.md](docs/json-format.md).

ChatGPT Site tools доступны только на authenticated `/assistant`. Master/read/draft/instance/activation классы управляются отдельными `WEBMCP_*` flags; activation всегда ждёт ручного подтверждения в приложении. Полный каталог, security model, testing и operator guidance описаны в [docs/webmcp.md](docs/webmcp.md), controlled rollout — в [docs/webmcp-rollout.md](docs/webmcp-rollout.md). Ручные ранние smoke-сценарии сохранены в [docs/webmcp-stage5-smoke.md](docs/webmcp-stage5-smoke.md) и [docs/webmcp-stage9-smoke.md](docs/webmcp-stage9-smoke.md).

## Цикл с ChatGPT

1. Попросите ChatGPT подготовить `training-plan` v1.0 по схеме `docs/training-plan-v1.0.schema.json` и примеру `tests/fixtures/training-plan/full-body-a.json`.
2. Загрузите JSON на `/plans/import`, изучите preview и отдельно подтвердите создание неизвестных упражнений.
3. Проведите тренировку; после каждого действия приложение сохраняет данные локально и синхронизирует их с сервером.
4. На итоговом экране скачайте JSON или Markdown `training-report` (для плавания — `swimming-report`).
5. Передайте отчёт ChatGPT и попросите анализировать только фактические данные, сохраняя `planned`, `fact` и `suggestion` раздельно.
6. Новую программу импортируйте как новую неизменяемую версию с `parent_version` и `change_reason`; старую историю не редактируйте.
