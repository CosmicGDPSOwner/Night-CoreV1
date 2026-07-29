# Night Core V1

[English](README.md) | **Русский**

Night Core V1 — это **универсальное серверное ядро для приватных серверов Geometry Dash**, разработанное для NightGDPS, но спроектированное так, чтобы его можно было настраивать и использовать и в других GDPS.

На границе протокола ядро сохраняет совместимость с Geometry Dash/Cvolton, а серверная логика вынесена в небольшие переиспользуемые модули.

## Основные принципы

- Совместимое с Geometry Dash 2.2 поведение endpoint-ов.
- Независимые от конкретной установки имя сервера, base path и настройки базы данных.
- Необязательный префикс таблиц БД для новых установок.
- Тонкие публичные endpoint-файлы; SQL и бизнес-логика находятся в `src/`.
- Явные упорядоченные SQL-миграции.
- Общая GJP/GJP2-аутентификация вместо дублирования авторизации в каждом endpoint-е.
- Основная production-цель: MySQL/MariaDB.
- NightGDPS-специфичные возможности являются необязательными модулями и не зашиты в общее ядро.
- Production-пароли, токены и данные хостинга не хранятся в Git.

## Upstream-база совместимости

Эталон совместимости: `Cvolton/GMDprivateServer`, commit `719dfe36c622a54c8162b07967241fce79b2497c`.

Night Core V1 является модифицированным/производным проектом и сохраняет применимые требования GPLv3. См. `LICENSE` и `docs/ru/UPSTREAM.md`.

## Реализовано

- переиспользуемая конфигурация и PDO-слой БД;
- безопасная работа с префиксами таблиц и проверка схемы;
- упорядоченный migration runner;
- production installer и deployment doctor;
- health/readiness endpoint-ы;
- схема аккаунтов для новой установки;
- протокольные модули аккаунтов/профилей, уровней, контента, social, progress и moderation;
- совместимые пути `registerGJAccount.php` и `loginGJAccount.php`;
- password/GJP2 hashing, совместимый с Cvolton;
- общий legacy GJP/GJP2 authenticator;
- rate limiting для входа;
- необязательная миграция владельцев уровней по legacy UDID;
- автоматическое получение кастомных песен Newgrounds по `songID` с локальным кешированием;
- DB-free self-test и MariaDB integration/baseline CI;
- CI-проверки PHP 8.1/8.2/8.3.

Для login и registration одновременно поддерживаются пути `/accounts/...` и корневые compatibility-paths.

## Быстрый локальный тест

При установленном Docker Desktop:

```bash
docker compose up --build -d
docker compose exec web php bin/nightcore migrate
docker compose exec web php bin/nightcore doctor
docker compose exec web php bin/nightcore self-test
```

После этого откройте `http://127.0.0.1:8080/health.php`.

Подробные шаги: `docs/ru/TESTING.md`.

## Production-развёртывание

Возьмите `.env.production.example` за основу, направьте document root веб-сервера на `public/` и выполните:

```bash
php bin/nightcore install
```

Перед приёмом трафика `php bin/nightcore doctor` не должен показывать критических ошибок, а `/ready.php` должен отвечать HTTP 200 и `ready`.

Полная инструкция: `docs/ru/DEPLOYMENT.md`.

## Staging

Перед production разверните точно ту же ревизию с `.env.staging.example`, отдельной базой данных и отдельным хранилищем уровней. После публикации staging-хоста выполните:

```bash
php bin/nightcore-smoke https://staging-api.example.com
```

Smoke-клиент проверяет `/health.php`, `/ready.php` и `/info.php`, не создавая игровые данные. После этого протестируйте полный сценарий настоящим клиентом Geometry Dash 2.2.

Полная инструкция: `docs/ru/STAGING.md`.

## Newgrounds

Night Core умеет автоматически получать метаданные кастомной песни при первом запросе неизвестного `songID`, проверять ссылку скачивания и сохранять результат в `core_songs`. Ошибочные ID временно кешируются, чтобы не создавать лишнюю нагрузку на внешние сервисы.

Настройки и диагностика: `docs/ru/NEWGROUNDS.md`.

## Безопасность

**Не подключайте непроверенную сборку к production-базе GDPS.** Для ранних тестов используйте новую БД или одноразовую копию.

## Текущий статус

Night Core V1 уже проходит проверку реальным Geometry Dash 2.2 клиентом: регистрация, вход, профиль, уровни, комментарии, лайки и social-функции проверяются непосредственно через GDPS.
