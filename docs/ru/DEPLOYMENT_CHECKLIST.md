# Checklist развёртывания Night Core

[English version](../DEPLOYMENT_CHECKLIST.md)

Используйте этот файл после выполнения `docs/ru/DEPLOYMENT.md`.

## Сервер

- [ ] PHP 8.1+ установлен.
- [ ] `PDO` и `pdo_mysql` доступны.
- [ ] PHP cURL доступен.
- [ ] Nginx запущен.
- [ ] PHP-FPM запущен.
- [ ] MariaDB запущена.
- [ ] Firewall разрешает только нужные ports.
- [ ] MariaDB port 3306 не открыт в Интернет.

## Файлы

- [ ] Код находится в `/var/www/nightcore`.
- [ ] Nginx root указывает на `/var/www/nightcore/public`.
- [ ] `.env` существует и имеет ограниченные права.
- [ ] `config2.php` существует и проходит `php -l`.
- [ ] `config/media.php` существует или media-настройки намеренно оставлены по умолчанию.
- [ ] Runtime storage находится вне `public/`.
- [ ] PHP-FPM может записывать в levels, songs и sfx.
- [ ] Нет `chmod 777`.

## База

- [ ] Используется отдельная база.
- [ ] Используется отдельный non-root DB user.
- [ ] `php bin/nightcore install` завершился успешно.
- [ ] `php bin/nightcore doctor` не показывает critical FAIL.
- [ ] Backup базы создан до переноса production-данных.

## Безопасность

- [ ] `APP_DEBUG=0`.
- [ ] `REGISTRATION_IP_HASH_KEY` задан.
- [ ] `PANEL_SECURITY_HASH_KEY` задан и отличается.
- [ ] `CORE_ADMIN_ACCOUNT_IDS` содержит только owner ID.
- [ ] `TRUST_PROXY_HEADERS=0`, если origin доступен напрямую.
- [ ] HTTPS работает.
- [ ] `.env`, `bootstrap.php` и `src/` возвращают 404.
- [ ] Staff и Event panels получают защитные headers.

## HTTP

- [ ] `/health.php` отвечает `ok`.
- [ ] `/ready.php` отвечает `ready`.
- [ ] `/dashboard.php` отвечает HTTP 200.
- [ ] `/staffAdmin.php` отвечает HTTP 200.
- [ ] `/eventAdmin.php` отвечает HTTP 200.
- [ ] `/mediaAdmin.php` перенаправляет на `/dashboard.php`.

## GDPS

- [ ] Регистрация работает.
- [ ] Вход работает.
- [ ] Профиль загружается.
- [ ] Поиск уровней работает.
- [ ] Скачивание уровня работает.
- [ ] Загрузка уровня работает.
- [ ] Комментарии работают.
- [ ] Daily работает.
- [ ] Weekly работает.
- [ ] Event работает.
- [ ] Локальные песни работают.
- [ ] Newgrounds проверен отдельно.

## Эксплуатация

- [ ] Cron установлен, если удаление аккаунтов включено.
- [ ] Nginx и PHP-FPM logs известны оператору.
- [ ] Backup хранится вне VPS.
- [ ] Процедура восстановления проверена.
- [ ] Текущий Git commit записан.
