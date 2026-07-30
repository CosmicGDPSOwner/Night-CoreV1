# Staff roles and permissions

Night Core stores roles, assignments and presentation separately for each GDPS installation.

## Bootstrap owner

Set `CORE_ADMIN_ACCOUNT_IDS` in the private `.env` to one or more numeric account IDs. Bootstrap owners always have every permission, are not stored in the editable assignment table, cannot be demoted through `/staffAdmin.php`, and cannot schedule their own account deletion through the public dashboard.

## Staff panel

Open `/staffAdmin.php` and sign in with an active, non-banned GDPS account that has `staff.manage`. The panel can:

- create, edit and delete roles;
- select granular permissions;
- assign one role to an account by username or account ID;
- remove assignments;
- set the native Geometry Dash moderator badge level;
- store Night Core badge text and presentation colors.

Non-owner staff cannot edit, delete or assign roles at or above their own priority. Only bootstrap owners can grant `staff.manage`.

## Sensitive-action confirmation

Staff-panel login always requires the account password. The per-account preference in `/dashboard.php` controls whether the current password must be entered again for role and assignment changes. Changing that preference always requires the current password.

## Shared protection

`/staffAdmin.php` uses the central `src/Web/Security/` module for strict sessions, CSRF, browser binding, configurable timeouts, account-state checks, nonce CSP and security headers. Failed panel logins are limited through the existing staff-login-attempt table using a scope-separated hashed network identifier.

Use a long private `PANEL_SECURITY_HASH_KEY` in production. When it is absent, Night Core falls back to `REGISTRATION_IP_HASH_KEY`, then to a plain one-way hash. No raw address is written to the staff login-attempt or audit tables.

The panel changes privileges and accepts passwords, so production access must use HTTPS. Reverse-proxy headers should be trusted only when the origin is protected from direct access.

## Permissions and commands

Permissions are stored in `core_staff_role_permissions`. The catalog includes level rating/tier/demon controls, comment and user moderation, leaderboard bans, reports, media management, rotations, Events and `staff.manage`.

In-game comment commands are authenticated through the Geometry Dash account protocol and RBAC. The browser preference for repeated password confirmation does not affect commands entered inside Geometry Dash because the game cannot display an additional web re-authentication field.

## Geometry Dash comment presentation

For 2.1+ comment responses (`binaryVersion > 31`), Night Core emits:

- field `11`: native moderator badge level (`0`, `1`, or `2`);
- field `12`: comment RGB color when a badge is enabled.

`badgeText`, `badgeColor` and `usernameColor` remain Night Core presentation metadata; the stock client understands only its native badge level and comment RGB field.
