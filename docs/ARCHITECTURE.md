# Architecture

Night Core V1 is organized around a strict boundary between Geometry Dash compatibility and NightGDPS business logic.

## Layout

- `public/` — public HTTP entry points. Geometry Dash endpoint filenames will live here as thin wrappers only.
- `src/Core/` — configuration, database, responses, authentication and shared infrastructure.
- `src/Protocol/` — Geometry Dash request parsing and protocol serialization.
- `src/Domain/` — accounts, levels, moderation, Events, Creator Points and other NightGDPS logic.
- `migrations/` — ordered database migrations. Schema changes must never be hidden inside request handlers.
- `storage/` — runtime logs/cache; production data and secrets are not committed.

## Rules

1. Public endpoint files should contain almost no SQL or business logic.
2. Database access uses PDO prepared statements.
3. Secrets come from environment configuration, never source control.
4. Existing NightGDPS database compatibility is preserved first; schema cleanup happens through migrations later.
5. Geometry Dash responses remain protocol-compatible even when internals are rewritten.
6. NightGDPS-specific behavior should be explicit and documented instead of mixed into upstream code invisibly.

## Migration order

1. Bootstrap / DB / config / health.
2. Authentication and accounts.
3. Level upload, download and search.
4. Comments and songs required by the game client.
5. Saves and leaderboard data.
6. Roles, permissions and moderator commands.
7. Daily / Weekly / Event.
8. Creator Points and shared CP.
9. Compatibility audit against the live NightGDPS server before cutover.

The current GlowHosting server remains the production reference until a module passes compatibility testing on the new VDS environment.
