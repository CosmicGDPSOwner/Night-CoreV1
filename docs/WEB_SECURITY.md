# Central web-security module

Night Core browser panels share one protection layer under `src/Web/Security/` instead of maintaining separate copies of session, CSRF and header code.

## Components

- `PanelSecurity` starts and validates panel sessions, rotates session IDs, creates CSRF tokens and exposes the CSP nonce.
- `SecurityHeaders` sends the shared Content Security Policy and browser hardening headers.
- `RepositoryAccountStateProvider` rejects inactive, account-banned or deletion-due accounts on every authenticated request.
- `PanelLoginThrottle` limits repeated staff/Event sign-in failures with prepared PDO statements and a scope-separated hashed network identifier.

The module is used by:

- `/dashboard.php`;
- `/staffAdmin.php`;
- `/eventAdmin.php`.

## Session properties

Each panel has its own PHP session name. Sessions are cookie-only and use:

- `HttpOnly`;
- `SameSite=Strict`;
- `Secure` when the direct request or trusted proxy reports HTTPS;
- strict session mode and disabled URL session IDs;
- session ID rotation after sign-in and sign-out;
- a browser fingerprint derived from the user agent;
- inactivity and absolute expiry from `config2.php`.

An authenticated request also re-checks that the account exists, is active, is not account-banned, is not due for deletion, and still has the permission required by the private panel.

A timeout value of `0` disables only that timeout. It does not bypass account-state, permission, browser-binding or manual sign-out checks.

## Request integrity

Every state-changing browser request requires a per-panel CSRF token. Tokens are generated with `random_bytes()` and compared with `hash_equals()`.

The CSP uses a fresh nonce for script and style blocks. Arbitrary inline script attributes are disabled through `script-src-attr 'none'`. Existing presentation attributes are still allowed through `style-src-attr 'unsafe-inline'`; this is a narrower exception than allowing all inline script/style blocks.

Additional headers include frame denial, MIME-sniffing protection, same-origin referrer policy, cross-origin isolation controls, a restricted Permissions Policy and no-store caching. Staff/Event pages also receive `X-Robots-Tag`.

## Login throttling and network identifiers

Staff and Event panel failures use `core_staff_admin_login_attempts`. The hash input includes a panel scope, so failures in one panel are not silently counted as the same identifier in another panel.

Recommended production setting:

```env
PANEL_SECURITY_HASH_KEY=long_random_secret_different_from_database_password
```

The module uses HMAC-SHA256 when that key is set. It falls back to `REGISTRATION_IP_HASH_KEY`; if neither key exists, it uses a non-reversible SHA-256 hash. Raw addresses are not stored by this module.

`TRUST_PROXY_HEADERS=1` must be enabled only behind a trusted reverse proxy with the origin firewalled against direct access. Otherwise a client could forge forwarded network/protocol headers.

## Security boundary

This module protects Night Core browser panels. It does not replace:

- HTTPS termination;
- Cloudflare or another reverse proxy;
- an origin firewall;
- Nginx/Apache/PHP-FPM request limits;
- database access controls;
- operating-system patching;
- backups and incident response;
- DDoS protection.

Geometry Dash protocol endpoints continue to use their own request authentication, validation and application-level rate limits.
