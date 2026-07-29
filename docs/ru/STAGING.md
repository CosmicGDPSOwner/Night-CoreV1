# Staging-развёртывание

Используйте staging, чтобы проверить Night Core V1 настоящим клиентом Geometry Dash 2.2 до подключения production-трафика или production-базы.

[English version](../STAGING.md)

## Правила изоляции

Staging должен использовать:

- отдельный hostname, например `staging-api.example.com`;
- отдельную MariaDB/MySQL базу и отдельного пользователя;
- отдельную директорию хранения уровней;
- только тестовые staging-аккаунты и уровни;
- ту же ревизию Night Core, которая планируется для production.

Никогда не подключайте staging к production-базе или production-хранилищу уровней.

## 1. Подготовка конфигурации

На staging-origin:

```bash
cp .env.staging.example .env
chmod 600 .env
```

Замените все `CHANGE_ME`. Оставляйте `APP_DEBUG=0`, чтобы staging повторял публичное error-поведение production.

Для Docker/VPS `DB_PASS` и `MARIADB_ROOT_PASSWORD` должны быть разными сильными паролями. Root-пароль используется только для инициализации приватного контейнера MariaDB; Night Core подключается как `DB_USER`.

## 2. Рекомендуемый VPS-путь: Docker Compose

В репозитории есть `compose.staging.yml`. Он собирает образ Night Core, хранит MariaDB и level payloads в persistent volumes и по умолчанию привязывает HTTP только к localhost.

Соберите точно checkout-нутую ревизию:

```bash
docker compose -f compose.staging.yml build --pull
```

Запустите MariaDB:

```bash
docker compose -f compose.staging.yml up -d db
```

Запустите installer с приватной БД и persistent level volume:

```bash
docker compose -f compose.staging.yml run --rm web php bin/nightcore install
```

Затем запустите web-сервис:

```bash
docker compose -f compose.staging.yml up -d web
```

По умолчанию listener доступен только локально:

```text
127.0.0.1:8080
```

Проверьте его на VPS до публикации через proxy:

```bash
php bin/nightcore-smoke http://127.0.0.1:8080
```

Оба stateful-компонента staging переживают пересоздание контейнеров:

- `nightcore_staging_db` хранит данные MariaDB;
- `nightcore_staging_levels` хранит level payloads Geometry Dash.

Не используйте `docker compose down -v`, если staging-данные нужно сохранить: `-v` удаляет persistent volumes.

## 3. Ручная установка без Docker

Virtual host/document root должен указывать на:

```text
/path/to/Night-CoreV1/public
```

Корень репозитория не должен быть корнем сайта.

Рекомендуемое хранилище уровней:

```text
/var/lib/nightcore-staging/levels
```

Пользователю PHP/веб-сервера нужен доступ на запись.

Установка и проверка:

```bash
php bin/nightcore install
php bin/nightcore doctor
```

Не продолжайте, пока критическая проверка показывает `FAIL`.

## 4. Проверка origin до Cloudflare

Перед проксированием hostname через Cloudflare проверьте сам origin по предполагаемому publication path.

Для Docker VPS оставляйте Night Core на `127.0.0.1:8080`, а публичной точкой входа делайте только host reverse proxy/tunnel.

С машины, которая видит staging hostname:

```bash
php bin/nightcore-smoke https://staging-api.example.com
```

Ожидаемый результат:

```text
[OK] health.php
[OK] ready.php
[OK] info.php (...)
Night Core staging smoke: OK
```

Smoke-клиент проверяет только operational endpoints. Он не создаёт аккаунты, уровни, комментарии, saves, messages или другие игровые данные.

## 5. Cloudflare

После успешного origin smoke test:

1. создайте staging DNS/publication path;
2. публикуйте только локальный listener Night Core через выбранный reverse proxy/tunnel;
3. используйте HTTPS end-to-end;
4. повторите `php bin/nightcore-smoke` через публичный hostname;
5. по возможности закройте прямой доступ к origin;
6. держите `TRUST_PROXY_HEADERS=0`, пока обход proxy напрямую не запрещён.

Не применяйте агрессивный общий rate limit ко всем Geometry Dash endpoint-ам сразу. Auth/upload/abuse-sensitive endpoint-ы лучше настраивать отдельно после тестов настоящим клиентом.

## 6. Тест настоящим Geometry Dash 2.2

После успешных origin/public smoke-тестов подключите тестовый GD 2.2 клиент.

Порядок проверки:

1. регистрация и вход;
2. загрузка/обновление профиля;
3. upload/search/download уровня;
4. комментарии, лайки и reports;
5. friend request, friendship и block;
6. private/friends-only уровни;
7. личные сообщения;
8. cloud save/sync;
9. lists, daily/weekly/event, gauntlets и map packs;
10. moderator/rating actions;
11. кастомная Newgrounds-песня: получение metadata, download и повторная загрузка из локального кеша.

Фиксируйте любые client-visible protocol mismatch до перехода в production.

## 7. Promotion gate

Ревизия готова к production только когда:

- GitHub CI зелёный на точном commit;
- `php bin/nightcore doctor` не показывает критических ошибок;
- `/ready.php` отвечает HTTP 200;
- `php bin/nightcore-smoke` проходит через публичный staging hostname;
- настоящий Geometry Dash 2.2 завершает полный staging-сценарий;
- Newgrounds custom-song flow проверен на реальной песне;
- перед rollout подготовлены backup production-БД и level storage.
