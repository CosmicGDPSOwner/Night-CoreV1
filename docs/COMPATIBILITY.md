# Compatibility policy

Night Core V1 keeps public Geometry Dash endpoint behavior stable while allowing the internal implementation to evolve.

## Profiles

The initial profile is `cvolton` and targets the request/response contract used by Cvolton/GMDprivateServer.

A deployment may customize server identity, base path, database credentials, table prefix, activation policy and proxy handling without editing PHP source files.

## Endpoint aliases

Where common GDPS deployments use both a root endpoint and a grouped endpoint, Night Core may expose thin aliases that call the same service. For example:

- `/loginGJAccount.php`
- `/accounts/loginGJAccount.php`

Both must have identical behavior.

## Existing databases

Existing Cvolton-compatible databases use an empty table prefix by default. Night Core must inspect optional tables/columns before using compatibility-only behavior instead of assuming that every upstream migration exists.

Fresh installations use ordered Night Core migrations.

## NightGDPS modules

Events, custom moderator commands, Creator Points policy and other NightGDPS-specific behavior belong in optional domain modules. They must not become mandatory for a generic installation.
