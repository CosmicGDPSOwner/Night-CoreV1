# Night Core deployment checklist

[Русская версия](ru/DEPLOYMENT_CHECKLIST.md)

Use this after completing `docs/DEPLOYMENT.md`.

## Server

- [ ] PHP 8.1+ is installed.
- [ ] `PDO` and `pdo_mysql` are available.
- [ ] PHP cURL is available.
- [ ] Nginx, PHP-FPM and MariaDB are active.
- [ ] MariaDB port 3306 is not public.

## Files

- [ ] Code is installed at `/var/www/nightcore`.
- [ ] Nginx root is `/var/www/nightcore/public`.
- [ ] `.env` exists with restricted permissions.
- [ ] `config2.php` passes `php -l`.
- [ ] Runtime storage is outside `public/`.
- [ ] PHP-FPM can write levels, songs and SFX.
- [ ] No runtime path uses `chmod 777`.

## Database

- [ ] A dedicated database exists.
- [ ] A dedicated non-root database user exists.
- [ ] `php bin/nightcore install` completed successfully.
- [ ] `php bin/nightcore doctor` has no critical failures.
- [ ] A database backup exists.

## Security

- [ ] `APP_DEBUG=0`.
- [ ] Registration and panel HMAC keys are configured.
- [ ] `CORE_ADMIN_ACCOUNT_IDS` contains only trusted owner IDs.
- [ ] `TRUST_PROXY_HEADERS=0` when the origin is directly reachable.
- [ ] HTTPS works.
- [ ] `.env`, `bootstrap.php` and `src/` return 404.

## HTTP and client

- [ ] `/health.php` returns `ok`.
- [ ] `/ready.php` returns `ready`.
- [ ] Dashboard, Staff and Event panels return HTTP 200.
- [ ] Registration and login work.
- [ ] Level search, download and upload work.
- [ ] Comments, Daily, Weekly and Event work.
- [ ] Local songs work.
- [ ] Newgrounds was tested separately.

## Operations

- [ ] Cron is installed when account deletion is enabled.
- [ ] Logs are known to the operator.
- [ ] Backups are stored outside the VPS.
- [ ] Restore procedure was tested.
- [ ] Current Git commit was recorded.
