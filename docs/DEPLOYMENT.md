# Production deployment

This guide describes the first production-ready deployment path for Night Core V1.

## Requirements

- PHP 8.1 or newer.
- `pdo` and `pdo_mysql` PHP extensions.
- MariaDB/MySQL reachable from the application host.
- A dedicated database and non-root database user.
- A writable level-storage directory outside the public document root.
- A web server whose document root points to `public/`.

Do not expose the repository root as the web document root. The `.env`, migrations, source code and runtime data must not be directly downloadable over HTTP.

## 1. Configure the environment

Copy the production template and edit every `CHANGE_ME` value:

```bash
cp .env.production.example .env
chmod 600 .env
```

Important production rules:

- keep `APP_DEBUG=0`;
- use a dedicated database user rather than `root`;
- keep `LEVEL_STORAGE_PATH` outside `public/`;
- set `CORE_ADMIN_ACCOUNT_IDS` only to trusted bootstrap administrator account IDs;
- keep `TRUST_PROXY_HEADERS=0` until the origin is restricted to a trusted reverse proxy.

## 2. Prepare the database

Create a dedicated database and user. The application user needs normal read/write permissions plus the schema-change permissions required by Night Core migrations on this database. Avoid global privileges.

Night Core will not create the database server account itself.

## 3. Install Night Core

Run:

```bash
php bin/nightcore install
```

The command:

1. verifies that `.env` exists;
2. creates/checks the level-storage directory;
3. applies all pending ordered SQL migrations;
4. runs the deployment doctor;
5. exits non-zero if a critical readiness check fails.

The installer is safe to run again during an update. Already-applied migrations are skipped.

## 4. Web-server layout

Point the site document root to:

```text
/path/to/Night-CoreV1/public
```

Do not point it to `/path/to/Night-CoreV1`.

The level-storage path should normally be outside the repository, for example:

```text
/var/lib/nightcore/levels
```

Grant the PHP/web-server process write access to that directory and no broader filesystem access than necessary.

## 5. Health and readiness

Night Core exposes two operational checks:

- `/health.php` checks that the application can bootstrap and reach the database;
- `/ready.php` additionally verifies required PHP extensions, core tables, migration state, writable level storage and production-critical configuration.

The public readiness response is intentionally minimal:

```text
ready
```

or HTTP 503 with:

```text
not_ready
```

Detailed failed check names are written to the server error log rather than returned to clients.

For an operator-readable report, run:

```bash
php bin/nightcore doctor
```

## 6. Cloudflare / reverse proxy

When Cloudflare or another trusted reverse proxy is placed in front of Night Core:

1. keep the origin inaccessible to arbitrary clients where possible;
2. restrict inbound traffic to the proxy/origin path used for the deployment;
3. only then enable `TRUST_PROXY_HEADERS=1` if the deployment requires proxy client-IP headers;
4. keep TLS enabled between clients and Cloudflare and, where supported, between Cloudflare and the origin.

Do not enable trusted proxy headers on an origin that clients can reach directly, because spoofed forwarding headers could otherwise be accepted as client identity information by code that uses them.

## 7. Update procedure

For each new release:

```bash
# deploy the new application revision first
php bin/nightcore migrate
php bin/nightcore doctor
```

Before switching real GDPS traffic to a new revision, verify `/ready.php` returns HTTP 200 and run a test client against a disposable or staging database when the release includes protocol or schema changes.

## Rollback note

Application-code rollback and database rollback are separate concerns. SQL migrations are forward-only at this stage, so take a database backup before production upgrades that include new migrations.
