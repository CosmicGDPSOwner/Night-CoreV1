# Database migrations

Every Night Core schema change is an ordered SQL migration.

Naming convention:

`NNNN_description.sql`

The placeholder `{{prefix}}` is replaced with `CORE_TABLE_PREFIX` by the migration runner.

Compatibility migrations may also use one directive per line:

`-- @ensure-column users stars INT NOT NULL DEFAULT 0`

The migration runner checks the configured table first and only runs `ALTER TABLE ... ADD COLUMN` when the column is missing. This lets the same migration work against a fresh Night Core database and an existing compatible GDPS schema without relying on database-vendor-specific `ADD COLUMN IF NOT EXISTS` syntax.

Current migrations:

- `0001_accounts.sql` — minimal account/users tables for a fresh installation plus the optional authentication-attempt table.
- `0002_profiles.sql` — GD 2.2 user stats, cosmetics and account profile settings required by the profile endpoints.
- `0003_levels.sql` — universal level metadata plus hashed-IP download deduplication for upload/download/search endpoints.
- `0004_content_social.sql` — universal song catalog, comments, likes/reports, friendships, friend requests, blocks and private messages.
- `0005_progress_moderation.sql` — cloud saves, global/level scores, moderator roles and rating audit, Daily/Weekly/Event slots, Gauntlets, Map Packs, GD 2.2 Lists and hashed-IP list-download deduplication.

The `core_*` tables in the newer migrations are intentionally namespaced so existing Cvolton-compatible installations can keep their legacy tables while Night Core owns new normalized subsystems. Server-operated data such as the song catalog, rotations, Gauntlets, Map Packs and moderator roles is configuration/data-plane state and is not hardwired to any specific GDPS.

Existing Cvolton-compatible databases are not recreated: `CREATE TABLE IF NOT EXISTS` leaves compatible existing tables in place. Always run `php bin/nightcore doctor` against a disposable copy before applying migrations to a migrated GDPS.

Do not place database dumps, passwords or phpMyAdmin exports in this directory.
