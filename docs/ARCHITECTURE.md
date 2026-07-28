# Architecture

Night Core V1 separates **Geometry Dash protocol compatibility**, **shared GDPS services**, and **installation-specific modules**.

## Layout

- `public/` — public HTTP entry points. These are thin wrappers only.
- `src/Core/` — configuration, database, migrations, request/response helpers and schema inspection.
- `src/Protocol/` — Geometry Dash encodings and protocol-specific helpers.
- `src/Security/` — password, GJP/GJP2 and shared authentication logic.
- `src/Domain/` — reusable GDPS business modules such as Accounts and Levels.
- `migrations/` — ordered schema changes for fresh installations and core-owned tables.
- `docs/` — compatibility, upstream and testing documentation.

## Universal-core rules

1. Server name, public path, database connection and table prefix are configuration, not source edits.
2. Public endpoint files contain almost no SQL or business logic.
3. Shared services use PDO prepared statements.
4. Secrets come from environment configuration and are never committed.
5. Existing Cvolton-compatible installations are inspected before optional behavior is used.
6. Geometry Dash responses stay compatible even when internals are rewritten.
7. NightGDPS-only behavior is added as an optional module and may not become a dependency of the common core.
8. Database changes are represented by migrations rather than hidden `ALTER TABLE` calls inside endpoints.

## Development order

1. Core / DB / config / health — implemented.
2. Authentication and account registration/login — implemented.
3. Remaining account/profile endpoints.
4. Level upload, download and search.
5. Comments and songs required by the game client.
6. Saves and leaderboard data.
7. Generic roles, permissions and moderator commands.
8. Daily / Weekly.
9. Optional Event module.
10. Generic Creator Points service with installation-configurable policy.
11. NightGDPS-specific modules.
12. Compatibility audit against a copied production database before cutover.

The current NightGDPS/GlowHosting server remains a behavior reference only. The universal core must also work with a fresh disposable database that contains no NightGDPS-specific tables.
