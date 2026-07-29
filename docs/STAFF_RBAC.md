# Staff roles and permissions

Night Core includes per-installation staff management. Every GDPS owner controls roles, assignments and presentation in their own database.

## Bootstrap owner

Set `CORE_ADMIN_ACCOUNT_IDS` to one or more numeric Night Core account IDs. These accounts are treated as owners and always have all permissions. Owner accounts are not stored in the editable staff assignment table and cannot be demoted from the dashboard.

## Dashboard

Open `/staffAdmin.php` and sign in with a Night Core username/password that has `staff.manage`. Bootstrap owners always have this permission.

The dashboard can:

- create and edit custom roles;
- assign one role to an account by username or account ID;
- select individual permissions;
- choose the native Geometry Dash moderator badge level (none/moderator/elder);
- set dashboard/web badge text and presentation colors;
- remove assignments and roles.

Because this page accepts account passwords and changes privileges, deploy it behind HTTPS. On an HTTP-only test VPS, use a trusted local SSH tunnel rather than entering staff credentials over the public network.

## Permissions

Permissions are granular and stored in `core_staff_role_permissions`. Current keys include level suggestion/rating/feature/epic/demon controls, comment moderation, user moderation, reports, media management and `staff.manage`.

Existing `core_moderator_roles` rows remain a compatibility fallback for the legacy moderation endpoints while installations migrate to RBAC.

## Geometry Dash comment presentation

For 2.1+ comment responses (`binaryVersion > 31`), Night Core emits the standard Geometry Dash moderator fields:

- field `11`: moderator badge level (`0`, `1`, or `2`);
- field `12`: comment RGB color when a badge is enabled.

This follows the pinned Cvolton response shape. Custom `badgeText`, `badgeColor` and `usernameColor` are retained as Night Core presentation metadata; stock Geometry Dash only understands its native numeric moderator badge and comment RGB color.
