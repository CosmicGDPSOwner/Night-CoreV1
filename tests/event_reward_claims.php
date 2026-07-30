<?php

declare(strict_types=1);

use NightCore\Core\Application;
use NightCore\Core\MigrationRunner;
use NightCore\Domain\Progress\EventRewardClaimService;

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
$events = $tables->get('core_events');
$claims = $tables->get('core_event_claims');
$state = $tables->get('core_event_completion_state');
$audit = $tables->get('core_event_audit');
$accountID = 990001;
$firstObservationAccountID = 990002;
$levelID = 999999;
$eventID = 0;

try {
    foreach ([$accountID, $firstObservationAccountID] as $cleanupAccountID) {
        $db->prepare('DELETE FROM ' . $claims . ' WHERE accountID = :accountID')->execute([':accountID' => $cleanupAccountID]);
        $db->prepare('DELETE FROM ' . $state . ' WHERE accountID = :accountID')->execute([':accountID' => $cleanupAccountID]);
        $db->prepare('DELETE FROM ' . $audit . ' WHERE accountID = :accountID')->execute([':accountID' => $cleanupAccountID]);
    }

    $now = time();
    $insert = $db->prepare(
        'INSERT INTO ' . $events
        . ' (levelID, startsAt, endsAt, rewardJson, status, createdBy, createdAt, updatedBy, updatedAt)'
        . ' VALUES (:levelID, :startsAt, :endsAt, :rewardJson, :status, :createdBy, :createdAt, :updatedBy, :updatedAt)'
    );
    $insert->execute([
        ':levelID' => $levelID,
        ':startsAt' => $now - 60,
        ':endsAt' => $now + 3600,
        ':rewardJson' => '{"diamonds":10,"orbs":50}',
        ':status' => 'active',
        ':createdBy' => 1,
        ':createdAt' => $now,
        ':updatedBy' => 1,
        ':updatedAt' => $now,
    ]);
    $eventID = (int) $db->lastInsertId();

    $service = new EventRewardClaimService($db, $tables, $app->schema());
    $assert($service->observe($accountID, 0, 0) === null, 'zero completion establishes state without claim');

    $claim = $service->observe($accountID, 1, 0);
    $assert(is_array($claim) && (int) ($claim['eventID'] ?? 0) === $eventID, 'new non-demon event completion records claim');
    $assert($service->observe($accountID, 1, 0) === null, 'same completion count does not claim twice');

    $count = $db->prepare('SELECT COUNT(*) FROM ' . $claims . ' WHERE eventID = :eventID AND accountID = :accountID');
    $count->execute([':eventID' => $eventID, ':accountID' => $accountID]);
    $assert((int) $count->fetchColumn() === 1, 'event and account unique claim count');

    $auditQuery = $db->prepare('SELECT action FROM ' . $audit . ' WHERE eventID = :eventID AND accountID = :accountID ORDER BY auditID DESC LIMIT 1');
    $auditQuery->execute([':eventID' => $eventID, ':accountID' => $accountID]);
    $assert($auditQuery->fetchColumn() === 'reward-claim', 'claim audit action');

    $firstClaim = $service->observe($firstObservationAccountID, 1, 0);
    $assert(is_array($firstClaim) && (int) ($firstClaim['eventID'] ?? 0) === $eventID, 'first observed count of one can claim current event');
} catch (Throwable $error) {
    $failures[] = 'exception: ' . $error->getMessage();
} finally {
    foreach ([$accountID, $firstObservationAccountID] as $cleanupAccountID) {
        try {
            $db->prepare('DELETE FROM ' . $claims . ' WHERE accountID = :accountID')->execute([':accountID' => $cleanupAccountID]);
            $db->prepare('DELETE FROM ' . $state . ' WHERE accountID = :accountID')->execute([':accountID' => $cleanupAccountID]);
            $db->prepare('DELETE FROM ' . $audit . ' WHERE accountID = :accountID')->execute([':accountID' => $cleanupAccountID]);
        } catch (Throwable) {
        }
    }
    if ($eventID > 0) {
        try {
            $db->prepare('DELETE FROM ' . $events . ' WHERE eventID = :eventID')->execute([':eventID' => $eventID]);
        } catch (Throwable) {
        }
    }
}

if ($failures !== []) {
    fwrite(STDERR, 'EVENT REWARD CLAIM TEST FAILED: ' . implode(', ', $failures) . PHP_EOL);
    exit(1);
}

echo "Night Core event reward claim test: OK\n";
