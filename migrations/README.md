# Database migrations

Every schema change for Night Core V1 must be stored here as an ordered SQL migration.

Naming convention:

`NNNN_description.sql`

Examples:

- `0001_baseline.sql`
- `0002_events.sql`
- `0003_event_permissions.sql`

Do not place production passwords or phpMyAdmin exports in this directory.

The first real baseline migration will be generated from the NightGDPS schema after we finish comparing it with the pinned Cvolton schema. Existing live data must not be modified by guesswork.
