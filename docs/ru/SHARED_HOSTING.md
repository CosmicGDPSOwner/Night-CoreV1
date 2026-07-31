# Развёртывание Night Core на shared hosting

[English version](../SHARED_HOSTING.md)

Этот вариант используется, когда hosting не позволяет указать document root на `public/`.

Для отдельного VPS предпочтительнее `docs/ru/DEPLOYMENT.md`.

Shared hosting подходит только если provider предоставляет:

- PHP 8.1+;
- PDO MySQL;
- MariaDB или MySQL;
- `.htaccess`;
- Apache `mod_rewrite`;
- запись PHP в локальные каталоги;
- исходящие HTTPS-запросы для Newgrounds;
- отсутствие обязательного JavaScript challenge перед каждым PHP-запросом.

Geometry Dash не выполняет browser JavaScript challenge. Hosting с такой защитой непригоден для GDPS API.

## 1. Загрузка файлов

Загрузите содержимое репозитория прямо в hosting root, например:

```text
htdocs/
  .htaccess
  .env
  autoload.php
  bootstrap.php
  config/
  config2.php
  data/
  migrations/
  public/
  src/
```

Корневой `.htaccess` публикует только существующие файлы из `public/`.

Не удаляйте `.htaccess`.

## 2. Проверка rewrite

До установки откройте:

```text
https://gdps.example.com/health.php
```

Запрос должен обслуживаться файлом `public/health.php`.

Проверьте private paths:

```text
/.env
/bootstrap.php
/src/
/migrations/
/data/
/docs/
/tests/
```

Они должны возвращать 404.

Если private path открывается, немедленно закройте сайт. Такой hosting root использовать нельзя до исправления rewrite.

## 3. `.env`

Скопируйте:

```text
.env.shared.example -> .env
```

Заполните:

```env
APP_ENV=staging
APP_DEBUG=0

NIGHTCORE_SERVER_NAME=My Shared GDPS
SERVER_ID=my-shared-gdps
BASE_PATH=/

DB_HOST=CHANGE_ME
DB_PORT=3306
DB_NAME=CHANGE_ME
DB_USER=CHANGE_ME
DB_PASS=CHANGE_ME

REGISTRATION_IP_HASH_KEY=CHANGE_ME_RANDOM_KEY_1
PANEL_SECURITY_HASH_KEY=CHANGE_ME_RANDOM_KEY_2

CORE_ADMIN_ACCOUNT_IDS=
TRUST_PROXY_HEADERS=0

WEB_INSTALL_TOKEN=CHANGE_ME_LONG_RANDOM_ONE_TIME_TOKEN
```

Секреты можно создать локально:

```bash
openssl rand -hex 32
```

Если SSH недоступен, используйте password manager или локальный генератор случайных значений.

## 4. Storage

На shared hosting можно оставить пустыми:

```env
LEVEL_STORAGE_PATH=
CUSTOM_SONG_STORAGE_PATH=
CUSTOM_SFX_STORAGE_PATH=
```

Тогда используются:

```text
data/levels
data/songs
data/sfx
```

Корневой `.htaccess` должен блокировать HTTP-доступ к `data/`.

Provider должен разрешать PHP запись в эти каталоги.

Не устанавливайте права 777 без необходимости. Обычно достаточно provider-specific 750, 755 или 775.

## 5. `config2.php`

Скопируйте:

```text
config2.example.php -> config2.php
```

Пример:

```php
<?php

declare(strict_types=1);

return [
    'account_deletion_enabled' => false,
    'session_idle_timeout_seconds' => 1800,
    'session_absolute_timeout_seconds' => 28800,
];
```

## 6. Media

Скопируйте:

```text
config/media.php.example -> config/media.php
```

Для включения загрузки авторизованными аккаунтами:

```php
'public_uploads' => true,
```

Проверьте hosting limits:

- `upload_max_filesize`;
- `post_max_size`;
- максимальное время PHP;
- квоту диска;
- ограничения числа файлов.

Provider limits могут быть ниже настроек Night Core.

## 7. Browser installer

Откройте:

```text
https://gdps.example.com/install.php
```

Введите `WEB_INSTALL_TOKEN`.

Installer:

1. подключается к базе;
2. создаёт storage;
3. применяет миграции;
4. запускает readiness checks.

Успешный результат:

```text
Installation checks: OK
```

Сразу после установки очистите token:

```env
WEB_INSTALL_TOKEN=
```

При пустом token `/install.php` должен возвращать 404.

Не оставляйте installer включённым.

## 8. Финальная проверка

```text
/health.php -> HTTP 200, ok
/ready.php -> HTTP 200, ready
/info.php -> Night Core metadata
/dashboard.php -> HTTP 200
/staffAdmin.php -> HTTP 200
/eventAdmin.php -> HTTP 200
```

Повторно проверьте:

```text
/.env -> 404
/bootstrap.php -> 404
/src/ -> 404
/migrations/ -> 404
/data/ -> 404
```

## 9. Owner

Зарегистрируйте аккаунт и найдите его account ID через phpMyAdmin:

```sql
SELECT accountID, userName, isActive
FROM accounts
ORDER BY accountID;
```

Добавьте ID в `.env`:

```env
CORE_ADMIN_ACCOUNT_IDS=2
```

Используйте реальный ID.

## 10. Cron на shared hosting

Если provider предоставляет scheduler, запускайте:

```text
php /absolute/path/to/bin/nightcore accounts:purge-due
```

Если CLI PHP отсутствует, не включайте account deletion до появления безопасного worker-механизма.

## 11. Newgrounds

Newgrounds работает только при разрешённых исходящих HTTPS-запросах.

Нужен PHP cURL или `allow_url_fopen=1`.

Некоторые shared hosts блокируют Newgrounds или Boomlings и возвращают 403. В таком случае используйте локальную song library через `/dashboard.php`.

## 12. Обновление

Перед загрузкой новой версии:

- сделайте export базы через phpMyAdmin;
- скачайте `data/`;
- сохраните `.env`, `config2.php` и `config/media.php`;
- не заменяйте private config example-файлами;
- повторно откройте `/install.php` только с временным token;
- сразу очистите token после миграций.

Shared hosting без Git требует особенно внимательного контроля версий файлов.
