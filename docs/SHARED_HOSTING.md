# Shared-hosting test deployment

This path is intended only for temporary Night Core validation when a cheap/free shared host forces the account web root to contain all application files.

Production deployments should still use the normal `public/` document root described in `docs/DEPLOYMENT.md`.

## Layout

Upload the repository contents directly into the hosting document root, for example `htdocs/`:

```text
htdocs/
  .htaccess
  .env
  autoload.php
  bootstrap.php
  migrations/
  public/
  src/
  data/
  ...
```

The root `.htaccess` exposes only files that exist below `public/`. Requests for `bootstrap.php`, `.env`, `src/`, `migrations/`, `data/`, and other repository files return HTTP 404.

Geometry Dash endpoints remain available at their normal root paths. For example, a request to `/health.php` is internally served from `public/health.php`.

## Configuration

Copy `.env.shared.example` to `.env` and set the host-provided database values:

```text
DB_HOST=...
DB_PORT=3306
DB_NAME=...
DB_USER=...
DB_PASS=...
```

Leave `LEVEL_STORAGE_PATH=` blank so Night Core uses `data/levels` below the repository root. The shared-host root guard prevents HTTP access to that directory.

Set a temporary long random `WEB_INSTALL_TOKEN`.

## Browser installer

Open `/install.php` in the browser. Enter `WEB_INSTALL_TOKEN` in the form and submit it.

The installer:

- creates/checks level storage;
- applies ordered Night Core SQL migrations;
- runs the same critical deployment checks used by the CLI installer.

After it reports `Installation checks: OK`, immediately edit `.env` and set:

```text
WEB_INSTALL_TOKEN=
```

With an empty token, `/install.php` returns 404 and cannot run migrations.

## Validation

Verify:

```text
/health.php  -> HTTP 200, ok
/ready.php   -> HTTP 200, ready
/info.php    -> Night Core metadata
```

Also verify that private paths such as `/bootstrap.php`, `/src/`, and `/migrations/` return 404.

Only after these checks should a test Geometry Dash 2.2 client be pointed at the shared-hosting domain.
