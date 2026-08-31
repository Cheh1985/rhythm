# Установка Ритма

## Требования

- PHP 8.2 или новее;
- расширения `pdo_mysql`, `mbstring`, `json`, `openssl`, `session`;
- MySQL 8 либо актуальная MariaDB;
- web root, направленный на каталог `public/`;
- HTTPS для production.

## Локальный запуск

1. Создайте пустую базу и пользователя, например:

```sql
CREATE DATABASE training_diary CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'training_user'@'localhost' IDENTIFIED BY 'replace-with-a-long-password';
GRANT ALL PRIVILEGES ON training_diary.* TO 'training_user'@'localhost';
```

2. Скопируйте `.env.example` в `.env` и задайте подключение. Для локальной диагностики можно временно установить `APP_ENV=development` и `APP_DEBUG=true`. Не публикуйте `.env` и не добавляйте его в Git.

3. Установите схему и seed:

```bash
php bin/install.php
```

Если база уже была создана на предыдущем этапе, не запускайте `schema.sql` повторно: после резервной копии примените по порядку недостающие миграции `database/migrations/002_*.sql` … `010_program_version_lifecycle.sql` штатным MySQL/MariaDB-клиентом. После `010` сначала выполните dry-run `php bin/reconcile-program-versions.php`; `--apply` связывает только однозначные single-version программы и не выбирает ambiguous cases.

Для ручной установки последовательно импортируйте `database/schema.sql`, затем `database/seed.sql`.

4. Запустите встроенный сервер из корня проекта:

```bash
php -S 127.0.0.1:8000 router.php
```

Откройте `http://127.0.0.1:8000/register`.

## Apache, Nginx и FASTPANEL

Укажите `public/` как корневой каталог сайта. Для Apache включите `mod_rewrite`; файл `public/.htaccess` уже маршрутизирует запросы через front controller. Для Nginx используйте `try_files $uri $uri/ /index.php?$query_string;` и стандартную передачу PHP в PHP-FPM.

В production:

- задайте `APP_ENV=production`, `APP_DEBUG=false`; `SESSION_SECURE=auto` автоматически включает Secure на HTTPS (или задайте `true` принудительно);
- включайте `TRUST_PROXY=true` только за доверенным reverse proxy, который корректно задаёт `X-Forwarded-Proto`;
- установите точный HTTPS URL в `APP_URL`;
- запретите web-доступ ко всему, кроме `public/`;
- разрешите PHP запись только в `storage/logs/` и `storage/cache/`;
- храните время сервера и БД в UTC; пользовательская timezone задаётся отдельно.

Service Worker работает только в secure context: production должен обслуживаться по HTTPS (localhost разрешён браузерами для разработки). Не задавайте агрессивный долгий HTTP-cache для `service-worker.js` и `manifest.json`. После deploy новой версии откройте приложение: оно предложит обновление и активирует новый Worker только после явного подтверждения, когда локальная тренировка уже записана в IndexedDB.

### FASTPANEL

1. Создайте сайт, базу MySQL/MariaDB и отдельного пользователя базы; запишите имя БД, логин, пароль и host из панели.
2. Загрузите проект вне публичного каталога, выберите PHP 8.2+ и включите `pdo_mysql`, `mbstring`, `fileinfo`, `json`, `openssl`, `session`, `zip`.
3. В настройках сайта задайте корень ровно на каталог `public/`; PHP-FPM должен запускаться от пользователя сайта.
4. Создайте `.env` из `.env.example`, укажите HTTPS `APP_URL` и параметры БД. `TRUST_PROXY=true` задавайте только если HTTPS действительно завершается на доверенном proxy FASTPANEL.
5. Дайте пользователю PHP запись только в `storage/logs/` и `storage/cache/`, выполните `php bin/install.php`, откройте `/register` и создайте первого пользователя.
6. Выпустите сертификат Let's Encrypt, принудительно включите HTTPS и проверьте установку PWA с `/manifest.json`.

### VPS (Nginx + PHP-FPM)

Создайте отдельного системного пользователя приложения и отдельного пользователя БД. Разместите код, например, в `/var/www/rhythm`, задайте Nginx `root /var/www/rhythm/public;` и `try_files $uri $uri/ /index.php?$query_string;`, запретите выполнение PHP вне `index.php`, включите TLS и передайте запросы в сокет PHP-FPM 8.2+. Владелец PHP-FPM должен писать только в `storage/logs` и `storage/cache`; `.env`, `database/`, `docs/` и `storage/` не должны обслуживаться web-сервером.

Добавьте ежедневный cron после проверки команды вручную:

```bash
15 3 * * * cd /var/www/rhythm && /usr/bin/php bin/cleanup.php >> storage/logs/cleanup.log 2>&1
30 3 * * * cd /var/www/rhythm && /usr/bin/php bin/prune-assistant-audit.php --apply >> storage/logs/assistant-audit-prune.log 2>&1
```

`prune-assistant-audit.php` удаляет только технический `assistant_tool_calls` старше `WEBMCP_AUDIT_RETENTION_DAYS` (по умолчанию 90 дней) и не затрагивает domain `audit_logs`. Перед cron обязательно выполните dry-run без `--apply`.

Первого пользователя создайте через HTTPS-страницу `/register`. После этого при необходимости ограничьте публичную регистрацию на уровне reverse proxy до появления отдельной admin-настройки.

## Диагностика

```bash
php -m
php tests/smoke.php
php tests/stage2.php
php tests/stage3.php
php tests/stage4.php
php tests/stage5.php
php tests/stage6.php
php tests/stage7.php
php tests/stage8.php
node --preserve-symlinks --preserve-symlinks-main tests/stage4-queue.js
php tests/webmcp-e2e.php
```

Если установщик сообщает об отсутствии `pdo_mysql`, включите расширение в активном `php.ini`. Ошибки приложения записываются в `storage/logs/app.log`; подробности не показываются пользователю при `APP_DEBUG=false`.

## WebMCP / ChatGPT Site tools

На production оставьте все пять `WEBMCP_*_ENABLED=false`, пока staging checklist не закрыт. Включайте слоями: master + reads, затем draft writes, instance writes и activation последней. Настройте read/write rate limits и retention из `.env.example`.

Для disposable MySQL/MariaDB staging server выполните:

```bash
WEBMCP_TEST_MYSQL_DSN='mysql:host=127.0.0.1;port=3306;charset=utf8mb4' \
WEBMCP_TEST_MYSQL_USER='staging_admin' \
WEBMCP_TEST_MYSQL_PASSWORD='secret' \
php tests/mysql-webmcp-stage10.php
```

Тест создаёт и удаляет две случайно названные временные базы, поэтому запускайте его только отдельной staging-учётной записью с правами `CREATE/DROP DATABASE`, никогда не production credentials. Полная настройка, диагностика и rollback: [docs/webmcp.md](docs/webmcp.md) и [docs/webmcp-rollout.md](docs/webmcp-rollout.md).
