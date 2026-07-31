# Dashboard account panel

`/dashboard.php` is the canonical public dashboard. Its account dialog uses the same GDPS accounts as the game.

## Available actions

Logged-out users can register or sign in. A signed-in user can:

- see the account name and numeric account ID;
- sign out;
- enable/disable repeated password confirmation for sensitive browser actions;
- schedule or cancel account deletion when the server owner enables the feature;
- upload local media when public authenticated uploads are enabled.

Changing the password-confirmation preference always requires the current password. When repeated confirmation is disabled, exact username confirmation is still required to schedule deletion.

## Account deletion

Available periods are 7, 14, 30, 60 and 90 days. Bootstrap administrators in `CORE_ADMIN_ACCOUNT_IDS` cannot schedule their own deletion through the public panel.

At the deadline, `php bin/nightcore accounts:purge-due` anonymizes credentials/email/name and disables the account while preserving published levels. The global switch is `account_deletion_enabled` in private `config2.php`. Disabling it pauses new and already scheduled deletion processing; re-enabling resumes stored schedules.

## Registration protection

Registration uses the shared account service and server-side validation. Private `.env` settings control registration limits and the HMAC key. The dashboard does not disclose those values.

## Web protection

The account panel is protected by the shared `src/Web/Security/` module: strict sessions, browser binding, configurable timeouts, CSRF, nonce CSP and account-state validation. See `WEB_SECURITY.md` and `CONFIG2.md`.
