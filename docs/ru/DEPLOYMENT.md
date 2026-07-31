# Полное развёртывание Night Core на VPS

[English version](../DEPLOYMENT.md)

Эта инструкция описывает рекомендуемую production-установку Night Core на отдельный VPS с Debian или Ubuntu, Nginx, PHP-FPM и MariaDB.

Основной путь запроса:

```text
Интернет -> Cloudflare или напрямую -> Nginx -> PHP-FPM -> Night Core -> MariaDB
```

Document root веб-сервера должен указывать только на каталог `public/`.

Не публикуйте корень репозитория. Файлы `.env`, `config2.php`, `config/media.php`, исходный код, миграции, тесты и runtime-хранилища не должны скачиваться через HTTP.

## 1. Что нужно подготовить заранее

Перед установкой подготовьте:

- домен или поддомен для GDPS, например `gdps.example.com`;
- VPS с доступом `root` или `sudo`;
- Debian 12+, Ubuntu 22.04+ или совместимую систему;
- PHP 8.1 или новее;
- Nginx;
- MariaDB или MySQL;
- Git;
- TLS-сертификат;
- отдельную базу данных;
- отдельного пользователя базы данных;
- резервное хранилище вне VPS или хотя бы вне каталога сайта.

Рекомендуемый путь приложения:

```text
/var/www/nightcore
```

Рекомендуемые runtime-хранилища:

```text
/var/lib/nightcore/levels
/var/lib/nightcore/songs
/var/lib/nightcore/sfx
```

## 2. DNS

Создайте DNS-запись домена:

```text
Type: A
Name: gdps
Value: IP_ВАШЕГО_VPS
```

Если используется IPv6, дополнительно создайте `AAAA`.

До включения Cloudflare proxy сначала завершите установку на origin и убедитесь, что сайт отвечает по HTTPS.

## 3. Установка системных пакетов

Пример для Debian или Ubuntu:

```bash
sudo apt update
sudo apt install -y \
  git \
  nginx \
  mariadb-server \
  php-fpm \
  php-cli \
  php-mysql \
  php-curl \
  php-mbstring \
  php-xml \
  ca-certificates \
  curl \
  unzip
```

Проверьте PHP:

```bash
php -v
php -m | grep -Ei 'PDO|pdo_mysql|curl'
```

Обязательны:

```text
PDO
pdo_mysql
```

`curl` настоятельно рекомендуется для Newgrounds и других исходящих HTTPS-запросов.

Узнайте имя PHP-FPM service и сокета:

```bash
systemctl list-units --type=service 'php*-fpm.service'
ls -la /run/php/
```

Примеры:

```text
php8.2-fpm.service
/run/php/php8.2-fpm.sock
```

или:

```text
php8.5-fpm.service
/run/php/php8.5-fpm.sock
```

Во всех командах ниже заменяйте `<php-fpm-service>` и `<php-fpm-socket>` на реальные значения вашего сервера.

## 4. Загрузка Night Core

Клонирование через HTTPS:

```bash
sudo git clone https://github.com/CosmicGDPSOwner/Night-CoreV1.git /var/www/nightcore
sudo chown -R "$USER":www-data /var/www/nightcore
cd /var/www/nightcore
git switch main
git log -1 --oneline
```

Для приватного репозитория можно использовать SSH URL или deploy key.

Не запускайте ядро из случайного ZIP-каталога, если планируете обновлять его через Git. Git-копия упрощает проверку версии, обновление и откат.

## 5. Создание базы данных

Откройте MariaDB:

```bash
sudo mariadb
```

Выполните SQL, заменив имена и пароль:

```sql
CREATE DATABASE nightcore
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

CREATE USER 'nightcore'@'localhost'
  IDENTIFIED BY 'CHANGE_ME_LONG_RANDOM_DATABASE_PASSWORD';

GRANT ALL PRIVILEGES
  ON nightcore.*
  TO 'nightcore'@'localhost';

FLUSH PRIVILEGES;
EXIT;
```

Пользователь `nightcore` получает права только на базу `nightcore`. Глобальные права и root-доступ приложению не нужны.

Проверьте подключение:

```bash
mariadb -u nightcore -p -h 127.0.0.1 nightcore
```

После успешного подключения выполните:

```sql
SELECT 1;
EXIT;
```

## 6. Создание `.env`

Скопируйте production-шаблон:

```bash
cd /var/www/nightcore
cp .env.production.example .env
```

Создайте случайные ключи:

```bash
openssl rand -hex 32
openssl rand -hex 32
openssl rand -hex 32
```

Откройте `.env`:

```bash
nano .env
```

Минимально важные параметры:

```env
APP_ENV=production
APP_DEBUG=0

NIGHTCORE_SERVER_NAME=My GDPS
SERVER_ID=my-gdps
CORE_PROFILE=cvolton
BASE_PATH=/

DB_DSN=
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=nightcore
DB_USER=nightcore
DB_PASS=CHANGE_ME_LONG_RANDOM_DATABASE_PASSWORD
DB_CHARSET=utf8mb4

CORE_TABLE_PREFIX=

REGISTRATION_IP_HASH_KEY=CHANGE_ME_RANDOM_KEY_1
PANEL_SECURITY_HASH_KEY=CHANGE_ME_RANDOM_KEY_2

CORE_ADMIN_ACCOUNT_IDS=
TRUST_PROXY_HEADERS=0
```

Правила:

- `APP_DEBUG` в production должен быть `0`;
- `DB_USER` не должен быть `root`;
- секреты нельзя коммитить;
- `REGISTRATION_IP_HASH_KEY` и `PANEL_SECURITY_HASH_KEY` должны быть разными;
- `CORE_TABLE_PREFIX` обычно оставляют пустым для существующей Cvolton-совместимой базы;
- `TRUST_PROXY_HEADERS` должен оставаться `0`, пока origin доступен напрямую;
- `CORE_ADMIN_ACCOUNT_IDS` заполняется после создания owner-аккаунта.

Защитите файл:

```bash
sudo chown "$USER":www-data .env
sudo chmod 640 .env
```

## 7. Настройка `config2.php`

Файл создаётся вручную и не перезаписывается командой `git pull`:

```bash
cd /var/www/nightcore
cp config2.example.php config2.php
nano config2.php
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

Значения:

- `account_deletion_enabled` - разрешает или запрещает планирование и обработку удаления аккаунтов;
- `session_idle_timeout_seconds` - срок бездействия браузерной сессии;
- `session_absolute_timeout_seconds` - максимальная продолжительность браузерной сессии;
- `0` отключает только соответствующий timeout;
- два нулевых timeout не отключают проверку аккаунта, бана, прав и отпечатка браузера.

Проверьте синтаксис:

```bash
php -l config2.php
```

Защитите файл:

```bash
sudo chown "$USER":www-data config2.php
sudo chmod 640 config2.php
```

Подробности находятся в `docs/ru/CONFIG2.md`.

## 8. Настройка медиа-загрузок

Публичные библиотеки Songs и SFX доступны для чтения без входа. Загрузка разрешается только после входа активным и незаблокированным аккаунтом GDPS.

Чтобы включить формы загрузки:

```bash
cd /var/www/nightcore
cp config/media.php.example config/media.php
nano config/media.php
```

Пример:

```php
<?php

declare(strict_types=1);

return [
    'public_uploads' => true,
    'song_max_mib' => 25,
    'sfx_max_mib' => 10,
    'upload_cooldown_seconds' => 30,
    'uploads_per_hour_per_ip' => 10,
    'global_uploads_per_hour' => 200,
    'minimum_free_space_mib' => 512,
];
```

Несмотря на имя `public_uploads`, анонимная загрузка не разрешается. Параметр включает загрузку для авторизованных аккаунтов через `/dashboard.php`.

Приватные cooldown и quota значения не показываются посетителям.

Проверьте файл:

```bash
php -l config/media.php
sudo chown "$USER":www-data config/media.php
sudo chmod 640 config/media.php
```

Подробности находятся в `docs/ru/MEDIA_DASHBOARD.md`.

## 9. Создание runtime-хранилищ

Создайте каталоги вне `public/` и вне Git-репозитория:

```bash
sudo install -d -o www-data -g www-data -m 2770 /var/lib/nightcore
sudo install -d -o www-data -g www-data -m 2770 /var/lib/nightcore/levels
sudo install -d -o www-data -g www-data -m 2770 /var/lib/nightcore/songs
sudo install -d -o www-data -g www-data -m 2770 /var/lib/nightcore/sfx
```

Убедитесь, что `.env` содержит:

```env
LEVEL_STORAGE_PATH=/var/lib/nightcore/levels
CUSTOM_SONG_STORAGE_PATH=/var/lib/nightcore/songs
CUSTOM_SFX_STORAGE_PATH=/var/lib/nightcore/sfx
```

Проверка прав:

```bash
sudo -u www-data test -w /var/lib/nightcore/levels && echo "levels: writable"
sudo -u www-data test -w /var/lib/nightcore/songs && echo "songs: writable"
sudo -u www-data test -w /var/lib/nightcore/sfx && echo "sfx: writable"
```

Не выдавайте `chmod 777`.

## 10. Ограничения PHP для загрузок

Найдите активные файлы конфигурации:

```bash
php --ini
```

Для PHP-FPM путь может отличаться от CLI. Типичный файл:

```text
/etc/php/<version>/fpm/php.ini
```

Для стандартных лимитов Night Core установите значения не ниже:

```ini
upload_max_filesize = 25M
post_max_size = 32M
max_file_uploads = 10
max_execution_time = 60
```

Если вы увеличиваете `song_max_mib` или `sfx_max_mib`, одновременно увеличьте:

- `upload_max_filesize`;
- `post_max_size`;
- Nginx `client_max_body_size`.

Перезапуск:

```bash
sudo systemctl restart <php-fpm-service>
```

## 11. Установка схемы Night Core

Сначала проверьте синтаксис private config:

```bash
cd /var/www/nightcore
php -l config2.php
php -l config/media.php
```

Запустите installer от пользователя PHP-FPM:

```bash
sudo -u www-data php bin/nightcore install
```

Installer:

1. читает `.env`;
2. проверяет storage;
3. применяет новые миграции;
4. запускает deployment doctor;
5. возвращает ненулевой exit code при критической ошибке.

Успешный итог:

```text
Installation checks: OK
```

Повторный запуск безопасен. Уже применённые миграции пропускаются.

Отдельные команды:

```bash
sudo -u www-data php bin/nightcore migrate
sudo -u www-data php bin/nightcore doctor
```

## 12. Настройка Nginx

В репозитории находится готовый шаблон:

```text
deploy/nginx/nightcore.conf.example
```

Скопируйте его:

```bash
sudo cp /var/www/nightcore/deploy/nginx/nightcore.conf.example \
  /etc/nginx/sites-available/nightcore
```

Откройте:

```bash
sudo nano /etc/nginx/sites-available/nightcore
```

Замените:

```text
CHANGE_ME_GDPS_DOMAIN
CHANGE_ME_PHP_FPM_SOCKET
```

Пример socket:

```text
unix:/run/php/php8.5-fpm.sock
```

Включите сайт:

```bash
sudo ln -s /etc/nginx/sites-available/nightcore \
  /etc/nginx/sites-enabled/nightcore
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t
sudo systemctl reload nginx
```

Критически важная строка:

```nginx
root /var/www/nightcore/public;
```

В URL сайта не должно быть `/public`.

Правильный URL:

```text
https://gdps.example.com/dashboard.php
```

Неправильный URL:

```text
https://gdps.example.com/public/dashboard.php
```

## 13. HTTPS

Пример для Certbot на Debian или Ubuntu:

```bash
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d gdps.example.com
```

После выпуска сертификата:

```bash
sudo nginx -t
sudo systemctl reload nginx
curl -I https://gdps.example.com/health.php
```

Production-панели нельзя использовать через открытый HTTP.

## 14. Firewall

Минимальный пример UFW:

```bash
sudo ufw allow OpenSSH
sudo ufw allow 'Nginx Full'
sudo ufw enable
sudo ufw status
```

MariaDB не нужно открывать в Интернет, если приложение и база находятся на одном VPS.

Проверьте, что port `3306` недоступен извне.

## 15. Cloudflare и proxy headers

Сначала оставьте:

```env
TRUST_PROXY_HEADERS=0
```

При включении Cloudflare:

1. настройте HTTPS до origin;
2. ограничьте прямой доступ к origin;
3. разрешите к origin только доверенный proxy-трафик и административный доступ;
4. только после этого установите `TRUST_PROXY_HEADERS=1`;
5. перезапустите PHP-FPM.

Night Core при доверенных proxy headers читает `CF-Connecting-IP`, затем первый адрес из `X-Forwarded-For`.

Если origin доступен напрямую, клиент может подделать forwarding headers. Поэтому `TRUST_PROXY_HEADERS=1` без firewall-ограничения использовать нельзя.

Cloudflare не заменяет:

- резервные копии;
- обновления ОС;
- безопасные права файлов;
- ограничения PHP-FPM и Nginx;
- защиту базы данных;
- контроль свободного места.

## 16. Создание owner-аккаунта

Сначала зарегистрируйте обычный аккаунт:

```text
https://gdps.example.com/dashboard.php
```

или через client Geometry Dash.

Найдите account ID:

```bash
mariadb -u nightcore -p nightcore
```

```sql
SELECT accountID, userName, isActive
FROM accounts
ORDER BY accountID;
```

Запишите нужный ID в `.env`:

```env
CORE_ADMIN_ACCOUNT_IDS=2
```

Это только пример. Используйте реальный ID вашего owner-аккаунта.

Можно указать несколько ID:

```env
CORE_ADMIN_ACCOUNT_IDS=2,15
```

После изменения:

```bash
sudo systemctl restart <php-fpm-service>
```

Bootstrap owner:

- получает все права;
- не может быть понижен через Staff panel;
- не может запланировать удаление своего аккаунта.

## 17. Cron

Если удаление аккаунтов включено, добавьте worker.

Готовый пример:

```text
deploy/cron/nightcore.cron.example
```

Установка:

```bash
sudo cp /var/www/nightcore/deploy/cron/nightcore.cron.example \
  /etc/cron.d/nightcore
sudo chmod 644 /etc/cron.d/nightcore
```

Проверьте путь PHP:

```bash
command -v php
```

Ручной запуск:

```bash
sudo -u www-data php /var/www/nightcore/bin/nightcore accounts:purge-due
```

Если удаление отключено в `config2.php`, worker ничего не изменит и выведет:

```text
Account deletion is disabled. No pending requests were processed.
```

## 18. Проверка после установки

CLI:

```bash
cd /var/www/nightcore
sudo -u www-data php bin/nightcore doctor
```

HTTP:

```bash
curl -fsS https://gdps.example.com/health.php
curl -fsS https://gdps.example.com/ready.php
curl -fsS https://gdps.example.com/info.php
```

Ожидается:

```text
ok
ready
```

Панели:

```bash
for page in dashboard.php staffAdmin.php eventAdmin.php; do
  printf '%-20s ' "$page"
  curl -sS -o /dev/null -w '%{http_code}\n' \
    "https://gdps.example.com/$page"
done
```

Ожидается HTTP 200 для всех трёх страниц.

Private-файлы не должны открываться. При правильном document root они находятся вне сайта:

```bash
curl -I https://gdps.example.com/.env
curl -I https://gdps.example.com/bootstrap.php
curl -I https://gdps.example.com/src/Core/Application.php
```

Ожидается 404.

Проверьте заголовки:

```bash
curl -sS -D - -o /dev/null \
  https://gdps.example.com/staffAdmin.php |
  tr -d '\r' |
  grep -Ei '^(Content-Security-Policy|X-Frame-Options|X-Content-Type-Options|Cache-Control|X-Robots-Tag):'
```

## 19. Проверка Newgrounds

В `.env`:

```env
NEWGROUNDS_FETCH_ENABLED=1
NEWGROUNDS_USE_BOOMLINGS_METADATA=1
NEWGROUNDS_DIRECT_FALLBACK=1
NEWGROUNDS_TIMEOUT_SECONDS=5
NEWGROUNDS_NEGATIVE_TTL_SECONDS=3600
```

Проверьте исходящий HTTPS:

```bash
curl -I https://www.newgrounds.com/
```

Проверьте endpoint:

```bash
curl -sS -X POST \
  https://gdps.example.com/getGJSongInfo.php \
  -d 'songID=631860'
```

Успешный ответ начинается с:

```text
1~|~631860~|~
```

Ответ `-1` означает, что песня не получена. Возможные причины:

- ID не существует;
- Newgrounds или Boomlings вернул 403;
- provider VPS блокирует исходящий запрос;
- PHP cURL отсутствует;
- неудачный ID находится в negative cache.

Локальные песни и уже закешированные записи продолжают работать независимо от доступности Newgrounds.

## 20. Подключение client Geometry Dash

В client или launcher укажите базовый URL GDPS:

```text
https://gdps.example.com/
```

Не добавляйте `/public`.

Проверьте настоящим client:

- регистрацию;
- вход;
- загрузку профиля;
- поиск уровней;
- скачивание уровня;
- загрузку уровня;
- комментарии;
- песни;
- Daily;
- Weekly;
- Event.

Тестируйте новую установку до переноса реальных пользователей.

## 21. Логи и диагностика

Nginx:

```bash
sudo tail -n 200 /var/log/nginx/error.log
sudo tail -n 200 /var/log/nginx/access.log
```

PHP-FPM:

```bash
sudo journalctl -u <php-fpm-service> -n 200 --no-pager
```

MariaDB:

```bash
sudo journalctl -u mariadb -n 200 --no-pager
```

Night Core:

```bash
cd /var/www/nightcore
sudo -u www-data php bin/nightcore doctor
```

Типичные причины HTTP 500:

- неправильный пароль БД;
- не применены миграции;
- PHP-FPM использует другую версию PHP;
- отсутствует `pdo_mysql`;
- PHP не может читать `.env` или private config;
- storage недоступен для записи;
- синтаксическая ошибка в `config2.php` или `config/media.php`.

## 22. Обновление, backup и восстановление

Перед обновлением прочитайте:

- `docs/ru/UPDATING.md`;
- `docs/ru/BACKUP_AND_RECOVERY.md`;
- `docs/ru/DEPLOYMENT_CHECKLIST.md`.

Не выполняйте обновление при неизвестных локальных изменениях:

```bash
git status --short
```

Если команда показывает отслеживаемые изменённые файлы, сначала выясните их происхождение. Не используйте принудительный reset и не восстанавливайте случайный stash.

## Shared hosting

Если hosting не позволяет настроить document root на `public/`, используйте отдельную инструкцию:

```text
docs/ru/SHARED_HOSTING.md
```

Этот путь предназначен прежде всего для тестирования и ограниченных shared-hosting установок.
