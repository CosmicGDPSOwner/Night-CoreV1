# Тестовое развёртывание на shared hosting

Этот путь предназначен только для временной проверки Night Core на дешёвом/бесплатном shared hosting, где document root аккаунта вынужденно содержит все файлы приложения.

[English version](../SHARED_HOSTING.md)

Для production всё равно рекомендуется обычный document root `public/`, описанный в `docs/ru/DEPLOYMENT.md`.

## Структура

Загрузите содержимое репозитория прямо в document root хостинга, например `htdocs/`:

```text
htdocs/
  .htaccess
  .env
  autoload.php
  bootstrap.php
  migrations/
  public/
  src/
  data/
  ...
```

Корневой `.htaccess` публикует только файлы, существующие внутри `public/`. Запросы к `bootstrap.php`, `.env`, `src/`, `migrations/`, `data/` и другим приватным файлам репозитория должны возвращать HTTP 404.

Geometry Dash endpoint-ы остаются доступны по обычным корневым путям. Например, `/health.php` внутренне обслуживается из `public/health.php`.

## Конфигурация

Скопируйте `.env.shared.example` в `.env` и укажите значения БД от хостинга:

```text
DB_HOST=...
DB_PORT=3306
DB_NAME=...
DB_USER=...
DB_PASS=...
```

Оставьте `LEVEL_STORAGE_PATH=` пустым, чтобы Night Core использовал `data/levels` внутри репозитория. Root-guard в `.htaccess` должен блокировать HTTP-доступ к этой директории.

Установите временный длинный случайный `WEB_INSTALL_TOKEN`.

Для автоматического получения Newgrounds-песен shared host должен разрешать исходящие HTTPS-запросы. При отсутствии PHP cURL нужен `allow_url_fopen=1`.

## Browser installer

Откройте `/install.php` в браузере, введите `WEB_INSTALL_TOKEN` и отправьте форму.

Installer:

- создаёт/проверяет level storage;
- применяет SQL-миграции Night Core по порядку;
- выполняет те же критические deployment checks, что и CLI installer.

После `Installation checks: OK` сразу измените `.env`:

```text
WEB_INSTALL_TOKEN=
```

При пустом token `/install.php` возвращает 404 и не может запускать миграции.

## Проверка

Проверьте:

```text
/health.php  -> HTTP 200, ok
/ready.php   -> HTTP 200, ready
/info.php    -> metadata Night Core
```

Также убедитесь, что приватные пути `/bootstrap.php`, `/src/`, `/migrations/` и т. п. возвращают 404.

Только после этих проверок подключайте тестовый клиент Geometry Dash 2.2.

> Важно: некоторые бесплатные хостинги вставляют перед PHP JavaScript/browser challenge. Обычный браузер может такой сайт открыть, но Geometry Dash не выполняет JavaScript challenge. Такой хостинг непригоден для реального GDPS API даже при полностью рабочем Night Core.
