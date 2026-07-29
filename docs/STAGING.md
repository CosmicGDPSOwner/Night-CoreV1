# Staging rollout

Use staging to validate Night Core V1 with a real Geometry Dash 2.2 client before any production traffic or production database is involved.

## Isolation rules

Staging must use:

- a dedicated hostname, for example `staging-api.example.com`;
- a dedicated MariaDB/MySQL database and database user;
- a dedicated level-storage directory;
- staging-only test accounts and levels;
- the same Night Core revision that is intended for production.

Never point staging at the production database or production level-storage directory.

## 1. Prepare configuration

On the staging origin:

```bash
cp .env.staging.example .env
chmod 600 .env
```

Replace every `CHANGE_ME` value. Keep `APP_DEBUG=0` so staging exercises the same public error behavior as production.

For the Docker/VPS path, `DB_PASS` and `MARIADB_ROOT_PASSWORD` must be different strong passwords. The root password is used only to initialize the private MariaDB container; Night Core connects as `DB_USER`.

## 2. Recommended VPS path: Docker Compose

The repository contains `compose.staging.yml`. It builds an immutable Night Core image, stores MariaDB and level payloads in persistent named volumes, and binds the HTTP service to localhost only.

Build the exact checked-out revision:

```bash
docker compose -f compose.staging.yml build --pull
```

Start MariaDB:

```bash
docker compose -f compose.staging.yml up -d db
```

Run the installer against the private database and persistent level volume:

```bash
docker compose -f compose.staging.yml run --rm web php bin/nightcore install
```

Then start the web service:

```bash
docker compose -f compose.staging.yml up -d web
```

The default listener is deliberately local-only:

```text
127.0.0.1:8080
```

Verify it on the VPS before publishing it through any proxy:

```bash
php bin/nightcore-smoke http://127.0.0.1:8080
```

Both staging stateful components survive container recreation:

- `nightcore_staging_db` stores MariaDB data;
- `nightcore_staging_levels` stores Geometry Dash level payloads.

Do not use `docker compose down -v` on a staging installation you want to keep; `-v` deletes these persistent volumes.

## 3. Manual web-server path

When Docker is not used, the virtual host/document root must point to:

```text
/path/to/Night-CoreV1/public
```

The repository root itself must not be exposed as the site root.

Recommended level storage:

```text
/var/lib/nightcore-staging/levels
```

The PHP/web-server user needs write access to this directory.

Install and verify:

```bash
php bin/nightcore install
php bin/nightcore doctor
```

Do not continue while a critical check reports `FAIL`.

## 4. Test the origin before Cloudflare

Before proxying the hostname through Cloudflare, verify that the origin itself works over the intended publication path.

For a Docker VPS, keep Night Core itself on `127.0.0.1:8080` and let the host reverse proxy or tunnel be the only public entry point.

From a machine that can reach the staging hostname:

```bash
php bin/nightcore-smoke https://staging-api.example.com
```

Expected result:

```text
[OK] health.php
[OK] ready.php
[OK] info.php (...)
Night Core staging smoke: OK
```

The smoke client checks only operational endpoints. It does not create accounts, levels, comments, saves, messages, or other game data.

## 5. Put Cloudflare in front

After the local/origin smoke test passes:

1. create the staging DNS/publication path in Cloudflare;
2. publish only the local Night Core listener through the chosen reverse-proxy/tunnel path;
3. use HTTPS end-to-end;
4. repeat `php bin/nightcore-smoke` against the public staging hostname;
5. restrict direct origin access where the hosting environment permits it;
6. keep `TRUST_PROXY_HEADERS=0` until direct-origin bypass is prevented and proxy-header handling is intentionally enabled.

Do not apply aggressive generic rate limits to all Geometry Dash endpoints at once. Authentication, upload and abuse-sensitive endpoints should be tuned separately after real-client testing so legitimate game traffic is not broken.

## 6. Real Geometry Dash 2.2 test

Only after both origin and Cloudflare smoke tests pass, point a test Geometry Dash 2.2 client at staging.

Test in this order:

1. register and login;
2. profile load/update;
3. level upload, search and download;
4. comments, likes and reports;
5. friend request, friendship and block behavior;
6. private/friends-only level access;
7. private messages;
8. cloud save/sync;
9. lists, daily/weekly/event, gauntlets and map packs;
10. moderator/rating actions with a staging moderator account.

Record any client-visible protocol mismatch before moving to production.

## 7. Promotion gate

A revision is eligible for production only when all of the following are true:

- GitHub CI is green for the exact revision;
- `php bin/nightcore doctor` has no critical failures on staging;
- `/ready.php` returns HTTP 200;
- `php bin/nightcore-smoke` succeeds through the public Cloudflare hostname;
- the real Geometry Dash 2.2 client completes the staging test flow;
- production database and level-storage backups are prepared before the production rollout.
