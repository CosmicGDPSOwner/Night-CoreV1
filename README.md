# Night Core V1

**English** | [Русский](README.ru.md)

Night Core V1 is a universal Geometry Dash private-server core derived from the Cvolton-compatible protocol surface and reorganized into reusable PHP modules. It targets PHP 8.1+ and MySQL/MariaDB, keeps the game-facing wire format compatible, and adds first-party account, staff, Event Level and media administration.

## Current architecture

- thin public endpoint files under `public/`;
- reusable application, protocol, security and domain services under `src/`;
- prepared PDO statements and validated internal table names;
- ordered SQL migrations under `migrations/`;
- shared legacy GJP/GJP2 authentication;
- installation-local settings through `.env`, `config/media.php` and `config2.php`;
- CLI installer, migration runner, diagnostics and account-deletion worker;
- DB-free and MariaDB-backed test suites.

The compatibility reference remains `Cvolton/GMDprivateServer` at commit `719dfe36c622a54c8162b07967241fce79b2497c`. Night Core is a modified/derived GPLv3 project; see `LICENSE` and `docs/UPSTREAM.md`.

## Implemented game/server features

- account registration and login through both root and `/accounts/` compatibility paths;
- account/profile, level, social, progress, comment and moderation endpoints;
- stock moderator-panel rating compatibility;
- reusable role-based access control with native Geometry Dash moderator badges;
- in-game staff commands for rating, demon difficulty, account bans and leaderboard bans;
- queued and forced Daily/Weekly rotation commands;
- Geometry Dash 2.207 Event Level response, timely download and reward claim ledger;
- Newgrounds/Boomlings song lookup and local MP3 caching;
- server-hosted MP3 song library and separate Ogg SFX library;
- account lifecycle controls with optional scheduled anonymization.

## Web interfaces

### `/dashboard.php`

The canonical public dashboard provides two tabs:

- **Songs / SFX** — public read-only libraries; uploads appear only after an active, non-banned GDPS account signs in;
- **Daily / Weekly / Event** — public current rotation cards in `name / author / #ID` format.

The account dialog supports registration, sign-in, profile security preferences, scheduled account deletion when enabled, and sign-out. `/mediaAdmin.php` remains a compatibility redirect to `/dashboard.php`.

### `/staffAdmin.php`

Accounts with `staff.manage` can create roles, choose granular permissions, configure native moderator badges, assign staff and remove assignments. Bootstrap owners from `CORE_ADMIN_ACCOUNT_IDS` always retain all permissions and cannot be demoted through the panel.

### `/eventAdmin.php`

Authorized owners/staff can inspect Event records, reward claims and audit rows, and end or cancel scheduled/active Events. Event creation and rotation changes are performed through the protected Geometry Dash comment commands.

## Central web-security module

`src/Web/Security/` is the shared protection layer for the three browser panels. It provides:

- strict cookie-only PHP sessions;
- session ID rotation after authentication and logout;
- configurable inactivity and absolute timeouts;
- active/ban/deletion account-state validation on every authenticated request;
- browser fingerprint binding;
- per-panel CSRF tokens;
- nonce-based Content Security Policy for scripts and style blocks;
- frame denial, MIME-sniffing protection, referrer, permissions and cross-origin headers;
- private-page cache and indexing controls;
- shared hashed login-throttle identifiers for staff/Event panel attempts.

See `docs/WEB_SECURITY.md`.

## Private configuration

### `config2.php`

Copy the tracked example to the project root:

```bash
cp config2.example.php config2.php
```

It controls:

```php
return [
    'account_deletion_enabled' => true,
    'session_idle_timeout_seconds' => 1800,
    'session_absolute_timeout_seconds' => 28800,
];
```

A timeout value of `0` disables that timeout. Setting both session values to `0` keeps a panel session until sign-out or another security check invalidates it. `config2.php` is ignored by Git. See `docs/CONFIG2.md`.

### `config/media.php`

Copy `config/media.php.example` to configure public authenticated uploads, per-file limits and private upload safeguards. The dashboard does not disclose connection/cooldown/quota values. See `docs/MEDIA_DASHBOARD.md`.

### `.env`

Start from `.env.production.example`. Keep database credentials, hash keys and `CORE_ADMIN_ACCOUNT_IDS` private. Never commit the production `.env`.

## Production commands

```bash
php bin/nightcore install
php bin/nightcore migrate
php bin/nightcore doctor
```

Before accepting traffic, `php bin/nightcore doctor` should report no critical failures and `/ready.php` should return HTTP 200 with `ready`.

When account deletion is enabled, run the worker periodically:

```bash
php bin/nightcore accounts:purge-due
```

Example hourly cron:

```cron
17 * * * * cd /var/www/nightcore && /usr/bin/php bin/nightcore accounts:purge-due >/dev/null 2>&1
```

## Local test

```bash
docker compose up --build -d
docker compose exec web php bin/nightcore migrate
docker compose exec web php bin/nightcore doctor
docker compose exec web php bin/nightcore self-test
```

The CI baseline additionally checks syntax, web security, account-comment wire format, MariaDB integration, custom songs, the media dashboard, account security/deletion and Event reward claims.

## Compatibility boundaries

- Night Core primarily targets Geometry Dash 2.2/2.207 behavior; it does not claim the complete multi-version client coverage of every historical Cvolton endpoint.
- Browser SFX upload/storage/download is implemented, but final discovery by an unmodified stock Geometry Dash SFX library still requires request-path validation.
- Cloudflare, Nginx/firewall rules, PHP-FPM limits and backups remain infrastructure responsibilities; application rate limits are not DDoS protection.

## Documentation

- `docs/WEB_SECURITY.md` — shared browser-panel protection;
- `docs/CONFIG2.md` — private deletion/session policy;
- `docs/DASHBOARD_ACCOUNT_PANEL.md` — account profile and lifecycle;
- `docs/MEDIA_DASHBOARD.md` — authenticated media library;
- `docs/STAFF_RBAC.md` — roles, permissions and commands;
- `docs/EVENTS.md` — Daily/Weekly/Event behavior.

Russian translations live in `docs/ru/`.
