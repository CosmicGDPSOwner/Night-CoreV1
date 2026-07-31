# Night Core on shared hosting

[Русская версия](ru/SHARED_HOSTING.md)

Use this path only when the provider cannot point the document root to `public/`.

A VPS deployment from `docs/DEPLOYMENT.md` is preferred.

The provider must support PHP 8.1+, PDO MySQL, `.htaccess`, Apache rewrite rules, writable local directories and a database.

Geometry Dash cannot pass a mandatory browser JavaScript challenge. A host that injects such a challenge is not suitable for a GDPS API.

## 1. Upload the repository

Upload all repository files into the hosting root, for example `htdocs/`.

Keep the root `.htaccess`. It exposes only files that exist under `public/`.

## 2. Verify private-path blocking

These paths must return 404:

```text
/.env
/bootstrap.php
/src/
/migrations/
/data/
/docs/
/tests/
```

Stop immediately if any private path is downloadable.

## 3. Configure `.env`

Copy `.env.shared.example` to `.env` and configure database credentials, unique registration and panel HMAC keys, and a temporary browser-installer token.

```env
APP_ENV=staging
APP_DEBUG=0

DB_HOST=CHANGE_ME
DB_PORT=3306
DB_NAME=CHANGE_ME
DB_USER=CHANGE_ME
DB_PASS=CHANGE_ME

REGISTRATION_IP_HASH_KEY=CHANGE_ME_RANDOM_KEY_1
PANEL_SECURITY_HASH_KEY=CHANGE_ME_RANDOM_KEY_2

TRUST_PROXY_HEADERS=0
WEB_INSTALL_TOKEN=CHANGE_ME_LONG_RANDOM_ONE_TIME_TOKEN
```

## 4. Storage

Blank storage paths use protected repository-local directories:

```text
data/levels
data/songs
data/sfx
```

PHP must be able to write them. Do not use permissions broader than the provider requires.

## 5. Private config

Copy:

```text
config2.example.php -> config2.php
config/media.php.example -> config/media.php
```

`public_uploads=true` enables uploads for authenticated active GDPS accounts, not anonymous visitors.

## 6. Browser installer

Open `/install.php`, enter `WEB_INSTALL_TOKEN` and run the installer.

After `Installation checks: OK`, immediately clear:

```env
WEB_INSTALL_TOKEN=
```

With an empty token, `/install.php` must return 404.

## 7. Validate

```text
/health.php -> HTTP 200, ok
/ready.php -> HTTP 200, ready
/info.php -> Night Core metadata
/dashboard.php -> HTTP 200
/staffAdmin.php -> HTTP 200
/eventAdmin.php -> HTTP 200
```

Verify private paths still return 404.

## 8. Owner and cron

Find the owner account ID in phpMyAdmin:

```sql
SELECT accountID, userName, isActive
FROM accounts
ORDER BY accountID;
```

Set `CORE_ADMIN_ACCOUNT_IDS` in `.env`.

When the provider has a scheduler, run:

```text
php /absolute/path/to/bin/nightcore accounts:purge-due
```

Keep account deletion disabled when no safe worker can run.

## 9. Newgrounds and updates

Outbound HTTPS and PHP cURL or `allow_url_fopen` are required for Newgrounds. Some shared hosts return 403.

Before every manual update, export the database, download `data/`, and preserve `.env`, `config2.php` and `config/media.php`.
