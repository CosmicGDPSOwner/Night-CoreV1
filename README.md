# Night Core V1

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
- migration runner;
- health/info endpoints;
- fresh-install account schema;
- `registerGJAccount.php`;
- `loginGJAccount.php`;
- password + GJP2 hashing compatible with the Cvolton implementation;
- shared legacy GJP/GJP2 authenticator for future game endpoints;
- login rate limiting;
- optional legacy UDID level ownership migration;
- CLI `doctor`, `migrate`, and DB-free `self-test`;
- Docker-based local MariaDB test environment;
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

Then open `http://127.0.0.1:8080/health.php`. It should return `ok`.

Detailed test steps are in `docs/TESTING.md`.

## Safety

Do **not** point the development build at a production GDPS database. Test against a fresh database or a disposable copy first.

## Next milestone

Expand the universal protocol layer with account/profile endpoints, then port level upload/download/search as separate modules.
