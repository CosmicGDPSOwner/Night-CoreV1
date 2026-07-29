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
- local Docker test environment;
- CLI doctor/self-test;
- PHP compatibility CI.

## Not yet production-ready

Level APIs, save data, leaderboards, social relationships/notifications, moderation, Daily/Weekly and optional NightGDPS modules are still pending.

Demon/star/platformer breakdown data (`dinfo`, `sinfo`, `pinfo`) remains passive until the level module can validate and derive it from actual rated levels.
