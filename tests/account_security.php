<?php

declare(strict_types=1);

use NightCore\Core\Application;
use NightCore\Core\MediaAccountAccess;
use NightCore\Core\MigrationRunner;
use NightCore\Domain\Accounts\AccountDeletionService;
use NightCore\Domain\Accounts\SensitiveActionConfirmationService;

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
$config2Path = $root . '/config2.php';
$config2Backup = is_file($config2Path) ? file_get_contents($config2Path) : null;

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

    $confirmation = new SensitiveActionConfirmationService(
        $db,
        $tables,
        $app->schema(),
        $app->accountRepository(),
        $app->passwordService()
    );
    $defaultSecurity = $confirmation->status($accountID);
    $assert($defaultSecurity['requirePassword'] === true, 'sensitive action password confirmation defaults on');
    $assert($confirmation->requiresPassword($accountID), 'default confirmation policy is fail-closed');

    $wrongSettingPasswordRejected = false;
    try {
        $confirmation->save($accountID, 'wrong-password', false);
    } catch (RuntimeException) {
        $wrongSettingPasswordRejected = true;
    }
    $assert($wrongSettingPasswordRejected, 'security setting itself always requires current password');

    $disabledSecurity = $confirmation->save($accountID, $password, false);
    $assert($disabledSecurity['requirePassword'] === false, 'account can disable repeated sensitive action prompts');
    $assert(!$confirmation->requiresPassword($accountID), 'disabled preference is returned to admin panels');

    $deletion = new AccountDeletionService(
        $db,
        $tables,
        $app->schema(),
        $app->accountRepository(),
        $app->passwordService()
    );

    $wrongUsernameRejectedWithoutPassword = false;
    try {
        $deletion->schedule($accountID, 'not-used', "' OR 1=1 --", 14, false);
    } catch (RuntimeException) {
        $wrongUsernameRejectedWithoutPassword = true;
    }
    $assert($wrongUsernameRejectedWithoutPassword, 'exact username remains mandatory when password prompts are disabled');

    $scheduledWithoutPassword = $deletion->schedule($accountID, 'wrong-password', $username, 14, false);
    $assert((int) $scheduledWithoutPassword['retentionDays'] === 14, 'deletion can be scheduled without repeated password when preference is off');
    $deletion->cancel($accountID, '', false);
    $assert((int) $deletion->status($accountID)['deletionScheduledAt'] === 0, 'deletion can be cancelled without repeated password when preference is off');

    $enabledSecurity = $confirmation->save($accountID, $password, true);
    $assert($enabledSecurity['requirePassword'] === true, 'account can re-enable sensitive action password confirmation');

    $wrongPasswordRejected = false;
    try {
        $deletion->schedule($accountID, 'wrong-password', $username, 14, true);
    } catch (RuntimeException) {
        $wrongPasswordRejected = true;
    }
    $assert($wrongPasswordRejected, 'account deletion requires current password when preference is on');

    $wrongUsernameRejected = false;
    try {
        $deletion->schedule($accountID, $password, "' OR 1=1 --", 14, true);
    } catch (RuntimeException) {
        $wrongUsernameRejected = true;
    }
    $assert($wrongUsernameRejected, 'account deletion requires exact username confirmation');

    $scheduled = $deletion->schedule($accountID, $password, $username, 14, true);
    $assert((int) $scheduled['retentionDays'] === 14, 'selected deletion period saved');
    $assert((int) $scheduled['deletionScheduledAt'] > time(), 'future deletion timestamp saved');

    $wrongCancelPasswordRejected = false;
    try {
        $deletion->cancel($accountID, 'wrong-password', true);
    } catch (RuntimeException) {
        $wrongCancelPasswordRejected = true;
    }
    $assert($wrongCancelPasswordRejected, 'deletion cancellation requires password when preference is on');
    $deletion->cancel($accountID, $password, true);
    $cancelled = $deletion->status($accountID);
    $assert((int) $cancelled['deletionScheduledAt'] === 0, 'scheduled deletion can be cancelled');

    $deletion->schedule($accountID, $password, $username, 7, true);
    $forceDue = $db->prepare(
        'UPDATE ' . $tables->get('core_account_lifecycle')
        . ' SET deletionScheduledAt = :scheduledAt WHERE accountID = :accountID'
    );
    $forceDue->execute([
        ':scheduledAt' => time() - 1,
        ':accountID' => $accountID,
    ]);

    file_put_contents(
        $config2Path,
        "<?php\nreturn [\n"
        . "'account_deletion_enabled' => false,\n"
        . "'session_idle_timeout_seconds' => 1800,\n"
        . "'session_absolute_timeout_seconds' => 28800,\n"
        . "];\n"
    );
    $assert(!$deletion->enabled(), 'config2 switch disables account deletion');
    $assert(!$app->accountRepository()->isDeletionDue($accountID), 'disabled deletion keeps due account usable');
    $assert($deletion->purgeDue(100) === 0, 'disabled deletion pauses anonymization worker');
    $disabledScheduleRejected = false;
    try {
        $deletion->schedule($accountID, $password, $username, 7, true);
    } catch (RuntimeException) {
        $disabledScheduleRejected = true;
    }
    $assert($disabledScheduleRejected, 'disabled deletion rejects new schedules');

    if ($config2Backup === null) {
        @unlink($config2Path);
    } else {
        file_put_contents($config2Path, $config2Backup);
    }
    $assert($deletion->enabled(), 'removing config2 override restores deletion');
    $assert($app->accountRepository()->isDeletionDue($accountID), 'due deletion blocks authentication paths after re-enable');

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

    $preferenceCount = $db->prepare(
        'SELECT COUNT(*) FROM ' . $tables->get('core_account_security_preferences')
        . ' WHERE accountID = :accountID'
    );
    $preferenceCount->execute([':accountID' => $accountID]);
    $assert((int) $preferenceCount->fetchColumn() === 0, 'account security preference removed during anonymization');
} catch (Throwable $error) {
    $failures[] = 'exception: ' . $error->getMessage();
} finally {
    if ($config2Backup === null) {
        @unlink($config2Path);
    } else {
        @file_put_contents($config2Path, $config2Backup);
    }
    if ($accountID > 0) {
        foreach ([
            'core_account_security_audit',
            'core_account_security_preferences',
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
