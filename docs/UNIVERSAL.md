# Universal core contract

Night Core V1 is designed to be reusable by more than one GDPS installation.

## What may be configured

- server name and identifier;
- public base path;
- MySQL/MariaDB connection;
- optional table prefix;
- account activation policy;
- authentication rate limits;
- trusted proxy handling;
- legacy Cvolton compatibility behavior.

NightGDPS-specific features must be implemented as optional modules rather than hard-coded assumptions in the shared core.

## Compatibility profile

The initial compatibility profile is `cvolton`. It preserves the request/response behavior required by Geometry Dash while allowing the internal implementation to be replaced.

Fresh installations can use Night Core migrations. Existing Cvolton-compatible installations may point the core at an existing schema after running `php bin/nightcore doctor`; migrations must not be applied to a production database without first testing a copy.

## Non-goals

Universal does not mean supporting arbitrary unrelated database schemas without an adapter. The stable contract is a Cvolton-compatible GDPS schema plus explicit Night Core migrations.
