<?php

declare(strict_types=1);

namespace NightCore\Domain\Moderation;

use DateTimeImmutable;
use DateTimeZone;
use NightCore\Core\SchemaInspector;
use NightCore\Core\TableNames;
use NightCore\Security\AccountAuthenticator;
use PDO;
use Throwable;

final class RotationEventService
{
    private const POPUP_PREFIX = 'temp_0_';

    public function __construct(
        private PDO $db,
        private TableNames $tables,
        private SchemaInspector $schema,
        private AccountAuthenticator $authenticator,
        private StaffAccessService $staff
    ) {
    }

    public function scheduleRotation(
        int $accountID,
        string $gjp,
        string $gjp2,
        string $ip,
        int $levelID,
        string $type
    ): string {
        $permission = $type === 'weekly' ? 'rotations.weekly' : 'rotations.daily';
        if (!$this->authorized($accountID, $gjp, $gjp2, $ip, $permission)) {
            return '-1';
        }
        if (!$this->schema->tableExists('core_daily_levels')) {
            return self::POPUP_PREFIX . 'Rotation migration is missing';
        }

        $slotType = $type === 'weekly' ? 1 : 0;
        $step = $slotType === 1 ? 604800 : 86400;
        $now = time();
        $utc = new DateTimeZone('UTC');
        $clock = (new DateTimeImmutable('@' . $now))->setTimezone($utc);
        $boundary = $slotType === 1
            ? $clock->modify('next monday')->setTime(0, 0)->getTimestamp()
            : $clock->modify('tomorrow')->setTime(0, 0)->getTimestamp();
        $table = $this->tables->get('core_daily_levels');

        $duplicate = $this->db->prepare('SELECT 1 FROM ' . $table . ' WHERE levelID = :levelID AND slotType = :slotType AND endsAt > :now LIMIT 1');
        $duplicate->execute([':levelID' => $levelID, ':slotType' => $slotType, ':now' => $now]);
        if ($duplicate->fetchColumn() !== false) {
            return self::POPUP_PREFIX . ucfirst($type) . ' already contains this level';
        }

        $latest = $this->db->prepare('SELECT slotID, endsAt FROM ' . $table . ' WHERE slotType = :slotType ORDER BY endsAt DESC, slotID DESC LIMIT 1');
        $latest->execute([':slotType' => $slotType]);
        $row = $latest->fetch(PDO::FETCH_ASSOC);
        $slotID = $row === false ? 1 : ((int) $row['slotID'] + 1);
        $startsAt = $row === false ? $boundary : max($boundary, (int) $row['endsAt']);
        $endsAt = $startsAt + $step;

        $insert = $this->db->prepare('INSERT INTO ' . $table . ' (slotType, slotID, levelID, startsAt, endsAt) VALUES (:slotType, :slotID, :levelID, :startsAt, :endsAt)');
        $insert->execute([
            ':slotType' => $slotType,
            ':slotID' => $slotID,
            ':levelID' => $levelID,
            ':startsAt' => $startsAt,
            ':endsAt' => $endsAt,
        ]);
        return self::POPUP_PREFIX . ucfirst($type) . ' scheduled for ' . gmdate('Y-m-d H:i', $startsAt) . ' UTC';
    }

    public function forceRotationNow(
        int $accountID,
        string $gjp,
        string $gjp2,
        string $ip,
        int $levelID,
        string $type
    ): string {
        $permission = $type === 'weekly' ? 'rotations.weekly.force' : 'rotations.daily.force';
        if (!$this->authorized($accountID, $gjp, $gjp2, $ip, $permission)) {
            return '-1';
        }
        if (!$this->schema->tableExists('core_daily_levels')) {
            return self::POPUP_PREFIX . 'Rotation migration is missing';
        }

        $slotType = $type === 'weekly' ? 1 : 0;
        $now = time();
        $utc = new DateTimeZone('UTC');
        $clock = (new DateTimeImmutable('@' . $now))->setTimezone($utc);
        $boundary = $slotType === 1
            ? $clock->modify('next monday')->setTime(0, 0)->getTimestamp()
            : $clock->modify('tomorrow')->setTime(0, 0)->getTimestamp();
        $table = $this->tables->get('core_daily_levels');

        try {
            $this->db->beginTransaction();

            $active = $this->db->prepare(
                'SELECT slotID, levelID FROM ' . $table
                . ' WHERE slotType = :slotType'
                . ' AND startsAt <= :startsAt'
                . ' AND endsAt > :endsAt'
                . ' ORDER BY startsAt DESC, slotID DESC LIMIT 1 FOR UPDATE'
            );
            $active->execute([
                ':slotType' => $slotType,
                ':startsAt' => $now,
                ':endsAt' => $now,
            ]);
            $activeRow = $active->fetch(PDO::FETCH_ASSOC);
            if ($activeRow !== false && (int) $activeRow['levelID'] === $levelID) {
                $this->db->rollBack();
                return self::POPUP_PREFIX . ucfirst($type) . ' already uses this level';
            }

            $future = $this->db->prepare(
                'SELECT MIN(startsAt) FROM ' . $table
                . ' WHERE slotType = :slotType AND startsAt > :startsAfter'
            );
            $future->execute([
                ':slotType' => $slotType,
                ':startsAfter' => $now,
            ]);
            $futureStartsAt = $future->fetchColumn();
            if ($futureStartsAt !== false && (int) $futureStartsAt > $now) {
                $boundary = min($boundary, (int) $futureStartsAt);
            }
            if ($boundary <= $now) {
                $this->db->rollBack();
                return self::POPUP_PREFIX . 'No current rotation window is available';
            }

            $close = $this->db->prepare(
                'UPDATE ' . $table . ' SET endsAt = :newEndsAt'
                . ' WHERE slotType = :slotType'
                . ' AND startsAt <= :activeStartsAt'
                . ' AND endsAt > :activeEndsAt'
            );
            $close->execute([
                ':newEndsAt' => $now,
                ':slotType' => $slotType,
                ':activeStartsAt' => $now,
                ':activeEndsAt' => $now,
            ]);

            $latest = $this->db->prepare(
                'SELECT slotID FROM ' . $table
                . ' WHERE slotType = :slotType ORDER BY slotID DESC LIMIT 1 FOR UPDATE'
            );
            $latest->execute([':slotType' => $slotType]);
            $latestSlotID = $latest->fetchColumn();
            $slotID = $latestSlotID === false ? 1 : ((int) $latestSlotID + 1);

            $insert = $this->db->prepare(
                'INSERT INTO ' . $table
                . ' (slotType, slotID, levelID, startsAt, endsAt)'
                . ' VALUES (:slotType, :slotID, :levelID, :startsAt, :endsAt)'
            );
            $insert->execute([
                ':slotType' => $slotType,
                ':slotID' => $slotID,
                ':levelID' => $levelID,
                ':startsAt' => $now,
                ':endsAt' => $boundary,
            ]);

            $this->db->commit();
            return self::POPUP_PREFIX . ucfirst($type) . ' forced active until ' . gmdate('Y-m-d H:i', $boundary) . ' UTC';
        } catch (Throwable) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return '-1';
        }
    }

    /** @param array<string,int> $rewards */
    public function createEvent(
        int $accountID,
        string $gjp,
        string $gjp2,
        string $ip,
        int $levelID,
        int $startsAt,
        int $endsAt,
        array $rewards
    ): string {
        if (!$this->authorized($accountID, $gjp, $gjp2, $ip, 'events.create')) {
            return '-1';
        }
        if (!$this->eventTablesAvailable()) {
            return self::POPUP_PREFIX . 'Event migration is missing';
        }
        $this->normalizeStatuses();
        if ($this->activeEventForLevel($levelID) !== null) {
            return self::POPUP_PREFIX . 'An event already exists for this level';
        }
        return $this->writeEvent($accountID, $levelID, $startsAt, $endsAt, $rewards, null, 'create');
    }

    /** @param array<string,int>|null $rewards */
    public function changeEvent(
        int $accountID,
        string $gjp,
        string $gjp2,
        string $ip,
        int $levelID,
        ?int $startsAt,
        ?int $duration,
        ?array $rewards,
        bool $replace
    ): string {
        $permission = $replace ? 'events.set' : 'events.change';
        if (!$this->authorized($accountID, $gjp, $gjp2, $ip, $permission)) {
            return '-1';
        }
        if (!$this->eventTablesAvailable()) {
            return self::POPUP_PREFIX . 'Event migration is missing';
        }

        $this->normalizeStatuses();
        $existing = $this->activeEventForLevel($levelID);
        if ($existing === null) {
            if (!$replace || $startsAt === null || $duration === null || $rewards === null) {
                return self::POPUP_PREFIX . 'No event exists for this level';
            }
            return $this->writeEvent($accountID, $levelID, $startsAt, $startsAt + $duration, $rewards, null, 'set-create');
        }

        $eventID = (int) $existing['eventID'];
        $newStartsAt = $startsAt ?? (int) $existing['startsAt'];
        $newEndsAt = $duration === null ? (int) $existing['endsAt'] : $newStartsAt + $duration;
        $newRewards = $rewards ?? $this->decodeRewards((string) $existing['rewardJson']);
        return $this->writeEvent($accountID, $levelID, $newStartsAt, $newEndsAt, $newRewards, $eventID, $replace ? 'set' : 'change');
    }

    /** @param array<string,int> $rewards */
    private function writeEvent(int $accountID, int $levelID, int $startsAt, int $endsAt, array $rewards, ?int $eventID, string $action): string
    {
        $now = time();
        if ($startsAt < $now - 300 || $endsAt <= $startsAt || $endsAt - $startsAt < 3600 || $endsAt - $startsAt > 7776000) {
            return self::POPUP_PREFIX . 'Invalid event time window';
        }
        if ($rewards === []) {
            return self::POPUP_PREFIX . 'At least one reward is required';
        }
        foreach ($rewards as $type => $amount) {
            if (!in_array($type, ['diamonds', 'orbs', 'stars', 'moons', 'keys'], true) || $amount < 1 || $amount > 1000000) {
                return self::POPUP_PREFIX . 'Invalid event reward';
            }
        }

        $json = json_encode($rewards, JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return '-1';
        }

        try {
            $this->db->beginTransaction();
            $status = $this->statusForWindow($startsAt, $endsAt, $now);
            if ($eventID === null) {
                $query = $this->db->prepare('INSERT INTO ' . $this->tables->get('core_events') . ' (levelID, startsAt, endsAt, rewardJson, status, createdBy, createdAt, updatedBy, updatedAt) VALUES (:levelID, :startsAt, :endsAt, :rewardJson, :status, :createdBy, :createdAt, :updatedBy, :updatedAt)');
                $query->execute([':levelID'=>$levelID,':startsAt'=>$startsAt,':endsAt'=>$endsAt,':rewardJson'=>$json,':status'=>$status,':createdBy'=>$accountID,':createdAt'=>$now,':updatedBy'=>$accountID,':updatedAt'=>$now]);
                $eventID = (int) $this->db->lastInsertId();
            } else {
                $query = $this->db->prepare('UPDATE ' . $this->tables->get('core_events') . ' SET startsAt=:startsAt, endsAt=:endsAt, rewardJson=:rewardJson, status=:status, updatedBy=:updatedBy, updatedAt=:updatedAt WHERE eventID=:eventID');
                $query->execute([':startsAt'=>$startsAt,':endsAt'=>$endsAt,':rewardJson'=>$json,':status'=>$status,':updatedBy'=>$accountID,':updatedAt'=>$now,':eventID'=>$eventID]);
            }

            $this->syncEventSlot($eventID, $levelID, $startsAt, $endsAt, $status);

            $audit = $this->db->prepare('INSERT INTO ' . $this->tables->get('core_event_audit') . ' (eventID, levelID, accountID, action, detailsJson, createdAt) VALUES (:eventID, :levelID, :accountID, :action, :detailsJson, :createdAt)');
            $audit->execute([':eventID'=>$eventID,':levelID'=>$levelID,':accountID'=>$accountID,':action'=>$action,':detailsJson'=>json_encode(['startsAt'=>$startsAt,'endsAt'=>$endsAt,'rewards'=>$rewards], JSON_UNESCAPED_SLASHES),':createdAt'=>$now]);
            $this->db->commit();
            return self::POPUP_PREFIX . 'Event saved (ID ' . $eventID . ')';
        } catch (Throwable) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return '-1';
        }
    }

    private function syncEventSlot(int $eventID, int $levelID, int $startsAt, int $endsAt, string $status): void
    {
        if (!$this->schema->tableExists('core_daily_levels')) {
            return;
        }
        $table = $this->tables->get('core_daily_levels');
        if ($status === 'ended' || $status === 'cancelled') {
            $delete = $this->db->prepare('DELETE FROM ' . $table . ' WHERE slotType = 2 AND slotID = :slotID');
            $delete->execute([':slotID' => $eventID]);
            return;
        }
        $query = $this->db->prepare('INSERT INTO ' . $table . ' (slotType, slotID, levelID, startsAt, endsAt) VALUES (2, :slotID, :levelID, :startsAt, :endsAt) ON DUPLICATE KEY UPDATE levelID=VALUES(levelID), startsAt=VALUES(startsAt), endsAt=VALUES(endsAt)');
        $query->execute([':slotID'=>$eventID,':levelID'=>$levelID,':startsAt'=>$startsAt,':endsAt'=>$endsAt]);
    }

    private function normalizeStatuses(): void
    {
        if (!$this->eventTablesAvailable()) {
            return;
        }
        $now = time();
        $table = $this->tables->get('core_events');
        $this->db->prepare("UPDATE {$table} SET status='ended', updatedAt=:updatedAt WHERE status IN ('scheduled','active') AND endsAt <= :endsAt")->execute([
            ':updatedAt' => $now,
            ':endsAt' => $now,
        ]);
        $this->db->prepare("UPDATE {$table} SET status='active', updatedAt=:updatedAt WHERE status='scheduled' AND startsAt <= :startsAt AND endsAt > :endsAt")->execute([
            ':updatedAt' => $now,
            ':startsAt' => $now,
            ':endsAt' => $now,
        ]);
        if ($this->schema->tableExists('core_daily_levels')) {
            $this->db->prepare('DELETE FROM ' . $this->tables->get('core_daily_levels') . ' WHERE slotType = 2 AND endsAt <= :now')->execute([':now'=>$now]);
        }
    }

    private function statusForWindow(int $startsAt, int $endsAt, int $now): string
    {
        if ($endsAt <= $now) {
            return 'ended';
        }
        return $startsAt > $now ? 'scheduled' : 'active';
    }

    private function authorized(int $accountID, string $gjp, string $gjp2, string $ip, string $permission): bool
    {
        return $accountID > 0
            && $this->authenticator->verify($accountID, $gjp, $gjp2, $ip)
            && $this->staff->has($accountID, $permission);
    }

    private function eventTablesAvailable(): bool
    {
        return $this->schema->tableExists('core_events')
            && $this->schema->tableExists('core_event_claims')
            && $this->schema->tableExists('core_event_audit');
    }

    /** @return array<string,mixed>|null */
    private function activeEventForLevel(int $levelID): ?array
    {
        $query = $this->db->prepare("SELECT eventID, startsAt, endsAt, rewardJson FROM " . $this->tables->get('core_events') . " WHERE levelID = :levelID AND status IN ('scheduled','active') AND endsAt > :now ORDER BY eventID DESC LIMIT 1");
        $query->execute([':levelID' => $levelID, ':now' => time()]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** @return array<string,int> */
    private function decodeRewards(string $json): array
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }
        $result = [];
        foreach ($decoded as $type => $amount) {
            if (is_string($type) && is_numeric($amount)) {
                $result[$type] = (int) $amount;
            }
        }
        return $result;
    }
}
