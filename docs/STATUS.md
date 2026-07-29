# Development status

## Implemented

- universal configuration and database bootstrap;
- table-prefix support;
- schema inspection;
- migrations with compatibility-safe `@ensure-column` directives;
- account registration/login;
- GJP/GJP2 authentication primitives;
- GD 2.2 profile lookup (`getGJUserInfo20.php`);
- GD 2.2 user search (`getGJUsers20.php`);
- authenticated profile/stat updates (`updateGJUserScore22.php`);
- authenticated account profile settings (`updateGJAccSettings20.php`);
- authenticated level upload (`uploadGJLevel21.php`);
- level download with GD hashes and copy-password encoding (`downloadGJLevel22.php`);
- core level search/filter/pagination (`getGJLevels21.php`);
- configurable atomic level payload storage with database fallback;
- duplicate-download protection using hashed IP identifiers;
- local Docker test environment;
- CLI doctor/self-test;
- PHP compatibility CI.

## Not yet production-ready

Songs, comments, save data, leaderboards, social relationships/notifications, moderation, Gauntlets, Daily/Weekly/Event rotation and optional NightGDPS modules are still pending.

The current level search intentionally returns `-1` for query types that depend on those pending modules (Friends/Followed, Daily/Weekly/Event, Lists/Suggested). Private `unlisted2` levels are owner-only until the social relationship module exists.

Demon/star/platformer breakdown data (`dinfo`, `sinfo`, `pinfo`) remains passive until level completion validation is implemented.
