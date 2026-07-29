# Development status

## Implemented functional baseline

- universal configuration, database bootstrap, table-prefix support and schema inspection;
- ordered migrations with compatibility-safe `@ensure-column` directives;
- account registration/login, GJP/GJP2 authentication and authentication rate limiting;
- GD 2.2 profile lookup/search/stat updates/account settings;
- profile relationship state, unread-message/friend-request/new-friend notifications and moderator badge integration;
- authenticated level upload, download and broad GD 2.2 search/filter/pagination;
- configurable atomic level payload storage with database fallback;
- level download deduplication using SHA-256 IP identifiers rather than raw IP storage;
- owner/friend access control for private `unlisted2` levels, with blocks taking precedence;
- Friends/Followed, Daily/Weekly/Event, Gauntlet, List and Suggested level-search resolution;
- universal custom-song catalog lookup plus custom-song metadata inside level-search responses;
- level/account comments, comment pagination, likes/dislikes and level reports;
- comment deletion rules for the comment author, level creator and authorized moderators;
- cloud save backup/sync with a configurable payload cap;
- global and per-level leaderboards, including level-score updates from progress comments;
- friend requests, friendships, blocks, friend/blocked lists and private messages;
- moderator role storage, bootstrap administrators, star suggestions, star/demon rating and rating audit history;
- creator-point recalculation after server rating changes;
- data-driven Daily/Weekly/Event rotations;
- Gauntlets and Map Packs with GD-compatible response hashes;
- GD 2.2 level-list upload/update/delete/search, social list audiences, likes and hashed-IP download deduplication;
- local Docker/MariaDB test environment, CLI doctor/self-test and PHP compatibility CI inherited from the earlier milestones.

## Operator-managed universal data

Night Core deliberately does not hardwire NightGDPS-specific administration or external-provider policy into the universal core. The following data is expected to be provisioned by deployment/admin tooling or direct database management:

- `core_songs` — custom-song metadata/catalog. The core serves known songs but does not automatically mirror Newgrounds/Boomlings or another external provider;
- `core_daily_levels` — Daily/Weekly/Event schedules;
- `core_gauntlets` and `core_map_packs` — server pack definitions;
- `core_moderator_roles` — persistent moderator permissions. `CORE_ADMIN_ACCOUNT_IDS` can bootstrap the first administrators.

A NightGDPS-specific control panel, launcher integration or custom operational workflow belongs in a separate optional layer and is not part of this repository's universal protocol baseline.

## Validation still pending for this branch

The functional implementation is intentionally being completed before the new test pass. The next phase is to expand the automated self-test/MariaDB integration suite across the newly added modules, run the PHP matrix and real MariaDB workflow, then fix every regression found before this branch is considered production-ready.

Demon/star/platformer breakdown strings (`dinfo`, `sinfo`, `pinfo`) remain passive client/profile data. A trustworthy server-derived breakdown would require completion-proof/anti-cheat validation and is intentionally not fabricated by the universal core.
