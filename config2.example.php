<?php

declare(strict_types=1);

/**
 * Combined private Night Core settings.
 *
 * Copy this file to /var/www/nightcore/config2.php and edit it through SFTP.
 * config2.php is ignored by Git and is not overwritten by git pull.
 * Run `php -l config2.php` after every edit.
 */
return [
    // true: users may schedule deletion and the cron worker processes due requests.
    // false: new requests are rejected and existing scheduled deletions stay paused.
    'account_deletion_enabled' => true,

    // Browser session timeout after no activity, in seconds.
    // 0 disables the inactivity timeout.
    'session_idle_timeout_seconds' => 1800,

    // Maximum browser session lifetime from sign-in, in seconds.
    // 0 disables the absolute timeout. Setting both session values to 0 removes
    // automatic time expiry only; logout, account/permission checks and browser
    // fingerprint validation still invalidate access.
    'session_absolute_timeout_seconds' => 28800,
];
