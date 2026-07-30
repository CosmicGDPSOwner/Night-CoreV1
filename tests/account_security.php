<?php

declare(strict_types=1);

use NightCore\Core\Application;
use NightCore\Core\MediaAccountAccess;
use NightCore\Core\MigrationRunner;
use NightCore\Domain\Accounts\AccountDeletionService;

$root = dirname(__DIR__);
require_once $root . '/autoload.php';

$failures = [];
$assert = static function (bool $condition, string $label) use (&$failures): void {
    if (!$condition) {
        $failures[] = $label;
    }
};

$app = Application::boot();
(new MigrationRunner($app->db(), $app->tables()))->migrate($root . '/migrations');
$db = $app->db();
$tables = $app->tables();
$username = 'Sec' . bin2hex(random_bytes(4));
$password = 'NightCore-Test-Password-92';
$accountID = 0;

try {
    $injectionResult = $app->accounts()->register(
        "x' OR 1=1 --",
        $password,
        'invalid-injection@example.test',
        '192.0.2.210'
    );
    $assert($injectionResult === -1, 'registration rejects SQL-like username characters');

    $accountID = $app->accountRepository()->create(
        $username,
        $app->passwordService()->hashPassword($password),
        'security-test@example.test',
        1,
        $app->passwordService()->hashGjp2FromPassword($password)
    );
    $assert($accountID > 0, 'security test account created');
    $assert($app->accountRepository()->findByUsername("' OR 1=1 --") === null, 'prepared username lookup resists injection');

    $access = new MediaAccountAccess(
        $db,
        $tables,
        $app->schema(),
        $app->accountRepository(),
        $app->passwordService()
    );
    $injectionLoginRejected = false;
    try {
        $access->login("' OR 1=1 --", $password, '192.0.2.211');
    } catch (RuntimeException) {
        $injectionLoginRejected = true;
    }
    $assert($injectionLoginRejected, 'dashboard login resists injection username');

    $deletion = new AccountDeletionService(
        $db,
        $tables,
        $app->schema(),
        $app->accountRepository(),
        $app->passwordService()
    );

    $wrongPasswordRejected = false;
    try {
        $deletion->schedule($accountID, 'wrong-password', $username, 14);
    } catch (RuntimeException) {
        $wrongPasswordRejected = true;
    }
    $assert($wrongPasswordRejected, 'account deletion requires current password');

    $wrongUsernameRejected = false;
    try {
        $deletion->schedule($accountID, $password, "' OR 1=1 --", 14);
    } catch (RuntimeException) {
        $wrongUsernameRejected = true;
    }
    $assert($wrongUsernameRejected, 'account deletion requires exact username confirmation');

    $scheduled = $deletion->schedule($accountID, $password, $username, 14);
    $assert((int) $scheduled['retentionDays'] === 14, 'selected deletion period saved');
    $assert((int) $scheduled['deletionScheduledAt'] > time(), 'future deletion timestamp saved');

    $deletion->cancel($accountID);
    $cancelled = $deletion->status($accountID);
    $assert((int) $cancelled['deletionScheduledAt'] === 0, 'scheduled deletion can be cancelled');

    $deletion->schedule($accountID, $password, $username, 7);
    $forceDue = $db->prepare(
        'UPDATE ' . $tables->get('core_account_lifecycle')
        . ' SET deletionScheduledAt = :scheduledAt WHERE accountID = :accountID'
    );
    $forceDue->execute([
        ':scheduledAt' => time() - 1,
        ':accountID' => $accountID,
    ]);
    $assert($app->accountRepository()->isDeletionDue($accountID), 'due deletion blocks authentication paths');

    $dueLoginRejected = false;
    try {
        $access->login($username, $password, '192.0.2.212');
    } catch (RuntimeException) {
        $dueLoginRejected = true;
    }
    $assert($dueLoginRejected, 'due account cannot start dashboard session');

    $deleted = $deletion->purgeDue(100);
    $assert($deleted >= 1, 'due deletion worker anonymizes account');
    $anonymized = $app->accountRepository()->findById($accountID);
    $assert(is_array($anonymized) && (int) $anonymized['isActive'] === 0, 'anonymized account is inactive');
    $assert(is_array($anonymized) && (string) $anonymized['email'] === '', 'anonymized account email removed');
    $assert(is_array($anonymized) && str_starts_with((string) $anonymized['userName'], 'deleted_'), 'anonymized account username replaced');
    $assert(is_array($anonymized) && !$app->passwordService()->verifyPassword($password, (string) $anonymized['password']), 'original password no longer works');
} catch (Throwable $error) {
    $failures[] = 'exception: ' . $error->getMessage();
} finally {
    if ($accountID > 0) {
        foreach ([
            'core_account_deletion_audit',
            'core_account_lifecycle',
            'core_media_login_attempts',
            'core_media_upload_audit',
            'core_event_completion_state',
            'core_event_claims',
            'core_user_moderation',
        ] as $logicalTable) {
            if (!$app->schema()->tableExists($logicalTable)) {
                continue;
            }
            try {
                $cleanup = $db->prepare(
                    'DELETE FROM ' . $tables->get($logicalTable) . ' WHERE accountID = :accountID'
                );
                $cleanup->execute([':accountID' => $accountID]);
            } catch (Throwable) {
            }
        }
        if ($app->schema()->tableExists('users')) {
            try {
                $cleanupUsers = $db->prepare(
                    'DELETE FROM ' . $tables->get('users') . ' WHERE extID = :accountID'
                );
                $cleanupUsers->execute([':accountID' => (string) $accountID]);
            } catch (Throwable) {
            }
        }
        try {
            $cleanupAccount = $db->prepare(
                'DELETE FROM ' . $tables->get('accounts') . ' WHERE accountID = :accountID'
            );
            $cleanupAccount->execute([':accountID' => $accountID]);
        } catch (Throwable) {
        }
    }
}

if ($failures !== []) {
    fwrite(STDERR, 'ACCOUNT SECURITY TEST FAILED: ' . implode(', ', $failures) . PHP_EOL);
    exit(1);
}

echo "Night Core account security test: OK\n";
