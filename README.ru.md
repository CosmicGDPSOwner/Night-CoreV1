# Night Core V1

[English](README.md) | **Русский**

Night Core V1 - универсальное ядро приватного сервера Geometry Dash, основанное на Cvolton-совместимом протоколе и переработанное в набор повторно используемых PHP-модулей. Ядро рассчитано на PHP 8.1+ и MySQL/MariaDB, сохраняет игровой wire-format и добавляет собственные подсистемы аккаунтов, персонала, Event Level и локальных media.

## Текущая архитектура

- тонкие публичные endpoint-файлы в `public/`;
- прикладная, протокольная, защитная и доменная логика в `src/`;
- подготовленные PDO-запросы и проверяемые внутренние имена таблиц;
- последовательные SQL-миграции в `migrations/`;
- единая авторизация legacy GJP/GJP2;
- локальные настройки установки через `.env`, `config/media.php` и `config2.php`;
- CLI-установка, миграции, диагностика и worker удаления аккаунтов;
- тесты без БД и интеграционные тесты MariaDB.

Точкой совместимости остаётся `Cvolton/GMDprivateServer` на commit `719dfe36c622a54c8162b07967241fce79b2497c`. Night Core является изменённым и производным GPLv3-проектом. См. `LICENSE` и `docs/UPSTREAM.md`.

## Реализованные функции

- регистрация и вход через корневые и `/accounts/`-совместимые пути;
- endpoint-ы аккаунтов, профилей, уровней, социальных функций, прогресса, комментариев и модерации;
- совместимость со штатной панелью оценки уровней Geometry Dash;
- RBAC-роли с детальными правами и стандартными бейджами модераторов;
- внутриигровые команды оценки, demon difficulty, банов аккаунта и лидерборда;
- очередь и принудительная активация Daily/Weekly;
- протокол Event Level Geometry Dash 2.207, timely-download и журнал получения наград;
- поиск песен Newgrounds/Boomlings и локальное кеширование;
- локальная MP3-библиотека и отдельная Ogg SFX-библиотека;
- жизненный цикл аккаунта с опциональным запланированным обезличиванием.

## Веб-интерфейсы

### `/dashboard.php`

Основной публичный dashboard содержит две вкладки:

- **Songs / SFX** - публичные библиотеки только для просмотра; формы загрузки появляются после входа активным и незаблокированным аккаунтом GDPS;
- **Daily / Weekly / Event** - текущие ротации в формате `название / автор / #ID`.

Окно аккаунта поддерживает регистрацию, вход, настройку повторного подтверждения паролем, планирование удаления при включённой функции и выход. `/mediaAdmin.php` оставлен как совместимый redirect на `/dashboard.php`.

### `/staffAdmin.php`

Аккаунты с `staff.manage` могут создавать роли, выбирать детальные права, настраивать стандартные бейджи Geometry Dash, назначать персонал и снимать назначения. Bootstrap owner из `CORE_ADMIN_ACCOUNT_IDS` всегда сохраняет все права и не может быть понижен через panel.

### `/eventAdmin.php`

Уполномоченные owner и staff просматривают Event-записи, выдачи наград и audit, а также завершают или отменяют активные и запланированные Events. Создание Event и изменение ротаций выполняются защищёнными командами в комментариях Geometry Dash.

## Единый модуль веб-защиты

`src/Web/Security/` - общий защитный слой для трёх browser panels. Он обеспечивает:

- строгие cookie-only PHP-сессии;
- смену ID сессии после входа и выхода;
- настраиваемый timeout бездействия и абсолютный срок;
- проверку активности, бана и наступившего удаления на каждом запросе;
- привязку сессии к отпечатку browser;
- отдельные CSRF-токены каждой panel;
- Content Security Policy с nonce для script и style blocks;
- запрет iframe, MIME-sniffing, защитные referrer, permissions и cross-origin headers;
- запрет кеширования и индексации private panels;
- общие хешированные идентификаторы для ограничения входов в Staff и Event.

Подробности: `docs/ru/WEB_SECURITY.md`.

## Private config

### `config2.php`

Скопируйте example в корень проекта:

```bash
cp config2.example.php config2.php
```

Файл управляет:

```php
return [
    'account_deletion_enabled' => true,
    'session_idle_timeout_seconds' => 1800,
    'session_absolute_timeout_seconds' => 28800,
];
```

Значение timeout `0` отключает соответствующее ограничение. Если оба значения равны `0`, сессия действует до выхода или другой защитной причины. `config2.php` исключён из Git. См. `docs/ru/CONFIG2.md`.

### `config/media.php`

Скопируйте `config/media.php.example`, чтобы настроить авторизованные public uploads, размеры файлов и private ограничения загрузчика. Dashboard не раскрывает cooldown и quota значения. См. `docs/ru/MEDIA_DASHBOARD.md`.

### `.env`

Начните с `.env.production.example`. Пароли БД, ключи хеширования и `CORE_ADMIN_ACCOUNT_IDS` должны оставаться private. Production `.env` нельзя коммитить.

## Production deployment

Полная инструкция VPS находится в `docs/ru/DEPLOYMENT.md`. В ней описаны:

- DNS и установка системных пакетов;
- создание базы MariaDB;
- `.env`, `config2.php` и media config;
- права runtime storage;
- Nginx и PHP-FPM;
- HTTPS, firewall и Cloudflare;
- создание owner и cron;
- health checks и проверка настоящим client;
- диагностика Newgrounds;
- обновление, backup и восстановление.

Готовые operator examples находятся в `deploy/`.

Основные команды:

```bash
php bin/nightcore install
php bin/nightcore migrate
php bin/nightcore doctor
```

Перед открытием трафика `doctor` не должен показывать critical errors, а `/ready.php` должен отвечать HTTP 200 и `ready`.

При включённом удалении аккаунтов запускайте worker периодически:

```bash
php bin/nightcore accounts:purge-due
```

## Локальная проверка

```bash
docker compose up --build -d
docker compose exec web php bin/nightcore migrate
docker compose exec web php bin/nightcore doctor
docker compose exec web php bin/nightcore self-test
```

CI дополнительно проверяет синтаксис, модуль веб-защиты, wire-format комментариев, MariaDB-интеграцию, песни, media dashboard, безопасность и удаление аккаунтов, Event rewards.

## Границы совместимости

- Основная цель Night Core - Geometry Dash 2.2/2.207. Ядро не заявляет полное покрытие всех старых clients Cvolton.
- Server-side загрузка, хранение и скачивание SFX готовы, но discovery-путь неизменённого stock client ещё требует окончательной проверки.
- Cloudflare, firewall, Nginx, PHP-FPM limits и backups остаются задачами инфраструктуры. Прикладные limits не являются DDoS-защитой.

## Документация

Deployment и эксплуатация:

- `docs/ru/DEPLOYMENT.md` - полная установка на VPS;
- `docs/ru/SHARED_HOSTING.md` - установка на ограниченный shared hosting;
- `docs/ru/UPDATING.md` - безопасное production-обновление;
- `docs/ru/BACKUP_AND_RECOVERY.md` - backup и восстановление;
- `docs/ru/DEPLOYMENT_CHECKLIST.md` - финальный operator checklist.

Функции и безопасность:

- `docs/ru/WEB_SECURITY.md` - единая защита browser panels;
- `docs/ru/CONFIG2.md` - private policy удаления и сессий;
- `docs/ru/DASHBOARD_ACCOUNT_PANEL.md` - профиль и жизненный цикл аккаунта;
- `docs/ru/MEDIA_DASHBOARD.md` - авторизованная media library;
- `docs/ru/STAFF_RBAC.md` - роли, права и команды;
- `docs/ru/EVENTS.md` - поведение Daily/Weekly/Event.

Английские версии находятся в `docs/`.
