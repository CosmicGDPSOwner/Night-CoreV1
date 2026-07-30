# `config2.php` private policy

`config2.php` is the combined server-local policy for account deletion and browser-session lifetime.

## Location and creation

The file belongs in the project root:

```text
/var/www/nightcore/config2.php
```

Create it from the tracked example:

```bash
cd /var/www/nightcore
cp config2.example.php config2.php
php -l config2.php
```

The real file is ignored by Git and is not replaced by a normal `git pull`.

## Supported values

```php
<?php

declare(strict_types=1);

return [
    'account_deletion_enabled' => true,
    'session_idle_timeout_seconds' => 1800,
    'session_absolute_timeout_seconds' => 28800,
];
```

### `account_deletion_enabled`

- `true`: users may schedule/cancel deletion and the worker processes due accounts.
- `false`: new schedules are rejected, due requests do not block authentication, and the worker performs no anonymization.

Existing scheduled dates stay stored while disabled. Re-enabling the feature resumes them; already-due requests may be processed by the next worker run.

### `session_idle_timeout_seconds`

Maximum inactivity before the dashboard, staff and Event sessions expire. `0` disables inactivity expiry.

### `session_absolute_timeout_seconds`

Maximum total lifetime from sign-in. `0` disables absolute expiry.

Setting both session values to `0` removes automatic time expiry only. Manual sign-out, account deactivation, account ban, due deletion, browser-fingerprint mismatch or removed panel permission still invalidate access.

## Validation and fallback

Boolean values accept PHP booleans, integer `0`/`1` and normal boolean strings. Timeout values must be non-negative integers no greater than 315,360,000 seconds.

When the file is missing, Night Core uses:

```text
account deletion: enabled
idle timeout: 1800 seconds
absolute timeout: 28800 seconds
```

When the file returns invalid data or has a runtime error, Night Core logs the problem and uses the safe defaults instead of intentionally producing an HTTP 500. A PHP syntax error can still prevent PHP from loading the file, so always run `php -l config2.php` after editing.

Legacy `config/account.php` is read only when `config2.php` does not exist.

## Applying a change

PHP normally reads the file on each request. Restarting PHP-FPM is recommended after production edits so opcode caches cannot retain an old copy:

```bash
sudo systemctl restart php8.5-fpm
sudo systemctl is-active php8.5-fpm
```
