# Dashboard account panel

`/dashboard.php` is the canonical public song and SFX dashboard. `/mediaAdmin.php` remains only as a compatibility redirect.

The top-right **Sign in / Register** button opens an account modal with:

- sign-in using an existing GDPS account;
- registration through the same account service, password hashing and activation policy as the game;
- the signed-in profile and sign-out action;
- scheduled account deletion controls.

Upload and registration protection remains enforced entirely on the server. The dashboard deliberately does not publish connection quotas, cooldown values, registration thresholds or network-derived identifiers.

Account deletion requires both the current password and the exact account username. The user can choose 7, 14, 30, 60 or 90 days and cancel the request before it becomes due. When the deadline is reached, the deletion worker disables the account and anonymizes its username, email and credentials. Published levels remain available under a deleted-user name. Bootstrap administrator IDs configured in `CORE_ADMIN_ACCOUNT_IDS` cannot schedule deletion from the public dashboard.

Run the deletion worker periodically, for example once per hour:

```bash
php /var/www/nightcore/bin/nightcore accounts:purge-due
```

Example cron entry:

```cron
17 * * * * cd /var/www/nightcore && /usr/bin/php bin/nightcore accounts:purge-due >/dev/null 2>&1
```

All account and dashboard database operations use parameterized PDO statements. User-provided values are bound as parameters rather than concatenated into SQL.

Recommended production registration settings remain private in `.env`:

```env
REGISTRATION_MAX_PER_IP=2
REGISTRATION_MAX_PER_SUBNET=10
REGISTRATION_WINDOW_SECONDS=86400
REGISTRATION_IP_HASH_KEY=long_random_secret
```
