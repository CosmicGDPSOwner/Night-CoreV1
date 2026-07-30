<?php

declare(strict_types=1);

namespace NightCore\Domain\Progress;

use NightCore\Core\SchemaInspector;
use NightCore\Core\TableNames;
use PDO;
use Throwable;

final class EventRewardClaimService
{
    private const ENDED_GRACE_SECONDS = 900;

    public function __construct(
        private PDO $db,
        private TableNames $tables,
        private SchemaInspector $schema
    ) {
    }

    /**
     * Observe the official 2.207 event-completion counters sent by
     * updateGJUserScore22.php and record at most one claim for the matching
     * current/recent Event Level. Geometry Dash applies the reward to the local
     * save; this service provides the server-side duplicate-safe claim ledger.
     *
     * @return array<string,mixed>|null Newly recorded claim, or null.
     */
    public function observe(int $accountID, int $nonDemonCompleted, int $demonCompleted): ?array
    {
        if ($accountID <= 0 || !$this->tablesAvailable()) {
            return null;
        }

        $nonDemonCompleted = max(0, $nonDemonCompleted);
        $demonCompleted = max(0, $demonCompleted);
        $stateTable = $this->tables->get('core_event_completion_state');
        $claimsTable = $this->tables->get('core_event_claims');
        $eventsTable = $this->tables->get('core_events');
        $levelsTable = $this->tables->get('levels');
        $auditTable = $this->tables->get('core_event_audit');
        $now = time();

        try {
            $this->db->beginTransaction();

            $stateQuery = $this->db->prepare(
                'SELECT nonDemonCompleted, demonCompleted FROM ' . $stateTable
                . ' WHERE accountID = :accountID LIMIT 1 FOR UPDATE'
            );
            $stateQuery->execute([':accountID' => $accountID]);
            $state = $stateQuery->fetch(PDO::FETCH_ASSOC);

            $firstObservation = $state === false;
            $previousNonDemon = $firstObservation ? 0 : (int) $state['nonDemonCompleted'];
            $previousDemon = $firstObservation ? 0 : (int) $state['demonCompleted'];

            // A pre-existing player can arrive with a historical event count.
            // Only a first observed count of exactly one is safe to associate
            // with the current event; larger first values become the baseline.
            $nonDemonIncreased = $nonDemonCompleted > $previousNonDemon
                && (!$firstObservation || $nonDemonCompleted === 1);
            $demonIncreased = $demonCompleted > $previousDemon
                && (!$firstObservation || $demonCompleted === 1);

            $claim = null;
            if ($nonDemonIncreased || $demonIncreased) {
                $candidate = $this->db->prepare(
                    'SELECT e.eventID, e.levelID, e.rewardJson, COALESCE(l.starDemon, 0) AS starDemon'
                    . ' FROM ' . $eventsTable . ' e'
                    . ' LEFT JOIN ' . $levelsTable . ' l ON l.levelID = e.levelID'
                    . ' LEFT JOIN ' . $claimsTable . ' c ON c.eventID = e.eventID AND c.accountID = :claimAccountID'
                    . ' WHERE c.eventID IS NULL'
                    . " AND e.status IN ('scheduled','active','ended')"
                    . ' AND e.startsAt <= :startedBefore'
                    . ' AND e.endsAt > :endedAfter'
                    . ' ORDER BY CASE WHEN e.endsAt > :activeAt THEN 0 ELSE 1 END, e.startsAt DESC, e.eventID DESC'
                    . ' LIMIT 10 FOR UPDATE'
                );
                $candidate->execute([
                    ':claimAccountID' => $accountID,
                    ':startedBefore' => $now,
                    ':endedAfter' => $now - self::ENDED_GRACE_SECONDS,
                    ':activeAt' => $now,
                ]);

                foreach ($candidate->fetchAll(PDO::FETCH_ASSOC) as $event) {
                    $isDemon = (int) ($event['starDemon'] ?? 0) > 0;
                    if (($isDemon && !$demonIncreased) || (!$isDemon && !$nonDemonIncreased)) {
                        continue;
                    }

                    $insert = $this->db->prepare(
                        'INSERT IGNORE INTO ' . $claimsTable
                        . ' (eventID, accountID, claimedAt, rewardJson)'
                        . ' VALUES (:eventID, :accountID, :claimedAt, :rewardJson)'
                    );
                    $insert->execute([
                        ':eventID' => (int) $event['eventID'],
                        ':accountID' => $accountID,
                        ':claimedAt' => $now,
                        ':rewardJson' => (string) $event['rewardJson'],
                    ]);
                    if ($insert->rowCount() !== 1) {
                        continue;
                    }

                    $details = json_encode([
                        'source' => 'updateGJUserScore22',
                        'nonDemonCompleted' => $nonDemonCompleted,
                        'demonCompleted' => $demonCompleted,
                        'rewards' => json_decode((string) $event['rewardJson'], true),
                    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
                    $audit = $this->db->prepare(
                        'INSERT INTO ' . $auditTable
                        . ' (eventID, levelID, accountID, action, detailsJson, createdAt)'
                        . ' VALUES (:eventID, :levelID, :accountID, :action, :detailsJson, :createdAt)'
                    );
                    $audit->execute([
                        ':eventID' => (int) $event['eventID'],
                        ':levelID' => (int) $event['levelID'],
                        ':accountID' => $accountID,
                        ':action' => 'reward-claim',
                        ':detailsJson' => $details,
                        ':createdAt' => $now,
                    ]);

                    $claim = [
                        'eventID' => (int) $event['eventID'],
                        'levelID' => (int) $event['levelID'],
                        'rewardJson' => (string) $event['rewardJson'],
                    ];
                    break;
                }
            }

            $saveState = $this->db->prepare(
                'INSERT INTO ' . $stateTable
                . ' (accountID, nonDemonCompleted, demonCompleted, updatedAt)'
                . ' VALUES (:accountID, :nonDemonCompleted, :demonCompleted, :updatedAt)'
                . ' ON DUPLICATE KEY UPDATE'
                . ' nonDemonCompleted = GREATEST(nonDemonCompleted, VALUES(nonDemonCompleted)),'
                . ' demonCompleted = GREATEST(demonCompleted, VALUES(demonCompleted)),'
                . ' updatedAt = VALUES(updatedAt)'
            );
            $saveState->execute([
                ':accountID' => $accountID,
                ':nonDemonCompleted' => $nonDemonCompleted,
                ':demonCompleted' => $demonCompleted,
                ':updatedAt' => $now,
            ]);

            $this->db->commit();
            return $claim;
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('Night Core event claim observation failed: ' . $error->getMessage());
            return null;
        }
    }

    private function tablesAvailable(): bool
    {
        return $this->schema->tableExists('core_event_completion_state')
            && $this->schema->tableExists('core_event_claims')
            && $this->schema->tableExists('core_events')
            && $this->schema->tableExists('core_event_audit')
            && $this->schema->tableExists('levels');
    }
}
