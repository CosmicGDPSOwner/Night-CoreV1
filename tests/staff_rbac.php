<?php

declare(strict_types=1);

use NightCore\Core\Application;
use NightCore\Core\MigrationRunner;

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

$username = 'StaffRbac' . random_int(100000, 999999);
$password = 'staff-rbac-test-password';
$roleID = 0;
$accountID = 0;

try {
    $assert($app->accounts()->register($username, $password, 'staff@example.invalid') === 1, 'test staff account registration');
    $account = $app->accountRepository()->findByUsername($username);
    $assert($account !== null, 'test staff account lookup');
    if ($account === null) {
        throw new RuntimeException('Unable to create staff test account.');
    }
    $accountID = (int) $account['accountID'];

    $repository = $app->staffAccess()->repository();
    $roleID = $repository->saveRole(
        null,
        'Integration Moderator ' . $accountID,
        25,
        1,
        'MOD',
        '#7c3aed',
        '#a78bfa',
        '#c4b5fd',
        ['levels.suggest', 'comments.moderate']
    );
    $assert($roleID > 0, 'staff role creation');

    $repository->assignRole($accountID, $roleID, 0);
    $staff = $app->staffAccess();
    $assert($staff->has($accountID, 'levels.suggest'), 'assigned permission granted');
    $assert($staff->has($accountID, 'comments.moderate'), 'comment moderation permission granted');
    $assert(!$staff->has($accountID, 'levels.rate'), 'unassigned permission denied');
    $assert($staff->nativeBadgeLevel($accountID) === 1, 'native moderator badge level');
    $assert($staff->nativeCommentColor($accountID) === '167,139,250', 'native comment RGB conversion');

    $identity = $staff->identity($accountID);
    $assert($identity !== null && (string) $identity['badgeText'] === 'MOD', 'staff badge text');
    $assert($identity !== null && (string) $identity['usernameColor'] === '#c4b5fd', 'staff username color');

    $repository->removeAssignment($accountID);
    $assert(!$staff->has($accountID, 'levels.suggest'), 'permission removed with assignment');
    $assert($repository->deleteRole($roleID), 'staff role deletion');
    $roleID = 0;
} catch (Throwable $e) {
    $failures[] = 'exception: ' . $e->getMessage();
} finally {
    try {
        if ($accountID > 0) {
            $app->staffAccess()->repository()->removeAssignment($accountID);
        }
        if ($roleID > 0) {
            $app->staffAccess()->repository()->deleteRole($roleID);
        }
        $deleteUsers = $app->db()->prepare('DELETE FROM ' . $app->tables()->get('users') . ' WHERE extID = :accountID');
        $deleteUsers->execute([':accountID' => (string) $accountID]);
        $deleteAccount = $app->db()->prepare('DELETE FROM ' . $app->tables()->get('accounts') . ' WHERE accountID = :accountID');
        $deleteAccount->execute([':accountID' => $accountID]);
    } catch (Throwable $cleanupError) {
        $failures[] = 'cleanup: ' . $cleanupError->getMessage();
    }
}

if ($failures !== []) {
    fwrite(STDERR, 'STAFF RBAC TEST FAILED: ' . implode(', ', $failures) . PHP_EOL);
    exit(1);
}

echo "Night Core staff RBAC test: OK\n";
