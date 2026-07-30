<?php

declare(strict_types=1);

/**
 * Private account-feature switches.
 *
 * Copy this file to config/account.php and edit it through SFTP.
 * config/account.php is ignored by Git and is not overwritten by git pull.
 */
return [
    // true: users may schedule account deletion and the cron worker processes due requests.
    // false: new deletion requests are rejected, due requests are paused, and due accounts remain usable.
    'account_deletion_enabled' => true,
];
