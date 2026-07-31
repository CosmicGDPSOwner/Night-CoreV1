# Daily, Weekly and Event Levels

Night Core stores Daily, Weekly and Event slots in the native core rotation tables and exposes the stock Geometry Dash timely protocol.

## Public view

`/dashboard.php?tab=rotations` shows the currently active Daily, Weekly and Event levels as:

```text
name / author / #ID
```

The view is read-only and does not require account sign-in.

## Geometry Dash commands

Authorized staff can use level-comment commands:

- `!daily` / `!weekly` — queue the commented level on the normal UTC boundary;
- `!daily now` or `!daily force` — activate it immediately until the next UTC midnight;
- `!weekly now` or `!weekly force` — activate it immediately until the next Monday 00:00 UTC;
- `!event ...` — create an Event from the commented level;
- `!eventchange ...` — change the current Event according to command options;
- `!eventset ...` — explicitly set Event options.

Event options support validated start/duration values and reward snapshots. Stock-client reward encoding is limited to the item types implemented by the 2.207 response path: diamonds, orbs, keys and gold keys.

## Event protocol and claims

The Event path supports:

- Event timely ID and `downloadGJLevel22.php` special ID `-3`;
- timely field `41` and timely hash generation;
- signed 2.207 Event reward responses;
- current-Event search type `23`;
- a unique claim ledger keyed by Event + account;
- reward snapshots and Event audit rows.

The stock client applies the supported reward locally from the signed Event response. Night Core records the server-side claim ledger; it does not pretend to replace the client's local reward application.

## Event panel

`/eventAdmin.php` is a protected inspection/management page for owners and staff with Event permissions. It shows Event records, claims and audit entries and permits `end`/`cancel` for active or scheduled Events when the account has the required permission.

The panel uses the central web-security module and optional repeated password confirmation. Creation and scheduling remain command-driven.
