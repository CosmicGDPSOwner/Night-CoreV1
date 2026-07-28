# Database migrations

Every Night Core schema change is an ordered SQL migration.

Naming convention:

`NNNN_description.sql`

The placeholder `{{prefix}}` is replaced with `CORE_TABLE_PREFIX` by the migration runner.

Current bootstrap migration:

- `0001_accounts.sql` — minimal account/users tables for a fresh installation plus the optional authentication-attempt table.

Existing Cvolton-compatible databases are not recreated: `CREATE TABLE IF NOT EXISTS` leaves compatible existing tables in place. Always run `php bin/nightcore doctor` against a disposable copy before applying migrations to a migrated GDPS.

Do not place database dumps, passwords or phpMyAdmin exports in this directory.
