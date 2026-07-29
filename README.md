# Night Core V1

**English** | [Русский](README.ru.md)

Night Core V1 is a **universal Geometry Dash private-server core** developed for NightGDPS but designed so other GDPS installations can configure and use the same shared core.

It keeps Geometry Dash/Cvolton compatibility at the protocol boundary while moving server behavior into smaller reusable modules.

## Current principles

- Geometry Dash 2.2-compatible endpoint behavior.
- Installation-neutral server name, base path and database settings.
- Optional database table prefix for fresh installations.
- Thin public endpoints; SQL and business logic live in `src/`.
- Explicit ordered SQL migrations.
- Shared GJP/GJP2 authentication instead of copy-pasted endpoint auth.
- MySQL/MariaDB production target.
- NightGDPS features are optional modules, not assumptions inside the common core.
- No production passwords, tokens or hosting credentials in Git.

## Upstream baseline

Compatibility reference: `Cvolton/GMDprivateServer` at commit `719dfe36c622a54c8162b07967241fce79b2497c`.

Night Core V1 is a modified/derived project and preserves the applicable GPLv3 requirements. See `LICENSE` and `docs/UPSTREAM.md`.

## Implemented

- reusable configuration and PDO database layer;
- safe table-prefix handling and schema inspection;
- ordered migration runner;
- production installer and deployment doctor;
- health and readiness endpoints;
- fresh-install account schema;
- account/profile, level, content, social, progress and moderation protocol modules;
- `registerGJAccount.php` and `loginGJAccount.php` compatibility paths;
- password + GJP2 hashing compatible with the Cvolton implementation;
- shared legacy GJP/GJP2 authenticator;
- login rate limiting;
- optional legacy UDID level ownership migration;
- automatic Newgrounds custom-song lookup and local caching;
- owner-managed server-hosted MP3 custom-song library with generated Song IDs;
- DB-free self-test plus MariaDB integration/baseline CI;
- PHP 8.1/8.2/8.3 CI checks.

Both `/accounts/loginGJAccount.php` and `/loginGJAccount.php` compatibility paths are provided, with the same rule for registration.

## Quick local test

With Docker Desktop installed:

```bash
docker compose up --build -d
docker compose exec web php bin/nightcore migrate
docker compose exec web php bin/nightcore doctor
docker compose exec web php bin/nightcore self-test
```

Then open `http://127.0.0.1:8080/health.php`.

Detailed test steps are in `docs/TESTING.md`.

## Production deployment

Start from `.env.production.example`, point the web-server document root to `public/`, and run:

```bash
php bin/nightcore install
```

Before accepting traffic, `php bin/nightcore doctor` must have no critical failures and `/ready.php` should return HTTP 200 with `ready`.

See `docs/DEPLOYMENT.md` for the deployment and update procedure.

## Staging rollout

Before production, deploy the exact candidate revision with `.env.staging.example` against a separate database and storage directory. Once the staging host is reachable, run:

```bash
php bin/nightcore-smoke https://staging-api.example.com
```

The smoke client checks `/health.php`, `/ready.php` and `/info.php` without creating game data. After that passes, validate the full flow with a real Geometry Dash 2.2 client.

See `docs/STAGING.md` for the complete rollout and promotion gate.

## Custom songs

Night Core has two independent custom-song paths:

- external Newgrounds/Boomlings lookup for unknown IDs when the upstream is reachable;
- a server-hosted local MP3 library managed through the token-protected `/songAdmin.php` uploader.

New local uploads use a configurable seven-digit Song ID range, `2000000..8999999` by default, and are served by Night Core itself. Older high-ID local songs remain valid but are not reused for new allocations.

See `docs/CUSTOM_SONGS.md` for local uploads and `docs/NEWGROUNDS.md` for external lookup.

## Safety

Do **not** point an untested build at a production GDPS database. Test against a fresh database or a disposable copy first.

## Documentation languages

English documentation lives in `README.md` and `docs/`. Russian documentation is maintained in `README.ru.md` and `docs/ru/`.
