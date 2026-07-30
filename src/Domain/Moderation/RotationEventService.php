<?php

declare(strict_types=1);

namespace NightCore\Domain\Moderation;

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
        if (!$this->authorized($accountID, $gjp, $gjp2, $ip, $permission) || !$this->schema->tableExists('dailyfeatures')) {
            return '-1';
        }

        $rotationType = $type === 'weekly' ? 1 : 0;
        $step = $rotationType === 1 ? 604800 : 86400;
        $start = $rotationType === 1 ? strtotime('next monday 00:00:00') : strtotime('tomorrow 00:00:00');
        $table = $this->tables->get('dailyfeatures');

        $duplicate = $this->db->prepare('SELECT 1 FROM ' . $table . ' WHERE levelID = :levelID AND type = :type LIMIT 1');
        $duplicate->execute([':levelID' => $levelID, ':type' => $rotationType]);
        if ($duplicate->fetchColumn() !== false) {
            return self::POPUP_PREFIX . ucfirst($type) . ' already contains this level';
        }

        $latest = $this->db->prepare('SELECT timestamp FROM ' . $table . ' WHERE type = :type AND timestamp >= :start ORDER BY timestamp DESC LIMIT 1');
        $latest->execute([':type' => $rotationType, ':start' => $start]);
        $last = $latest->fetchColumn();
        $timestamp = $last === false ? $start : ((int) $last + $step);

        $insert = $this->db->prepare('INSERT INTO ' . $table . ' (levelID, timestamp, type) VALUES (:levelID, :timestamp, :type)');
        $insert->execute([':levelID' => $levelID, ':timestamp' => $timestamp, ':type' => $rotationType]);
        return self::POPUP_PREFIX . ucfirst($type) . ' scheduled for ' . gmdate('Y-m-d H:i', $timestamp) . ' UTC';
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
        if (!$this->authorized($accountID, $gjp, $gjp2, $ip, 'events.create') || !$this->eventTablesAvailable()) {
            return '-1';
        }
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
        ?int $endsAt,
        ?array $rewards,
        bool $replace
    ): string {
        $permission = $replace ? 'events.set' : 'events.change';
        if (!$this->authorized($accountID, $gjp, $gjp2, $ip, $permission) || !$this->eventTablesAvailable()) {
            return '-1';
        }

        $existing = $this->activeEventForLevel($levelID);
        if ($existing === null) {
            if (!$replace || $startsAt === null || $endsAt === null || $rewards === null) {
                return self::POPUP_PREFIX . 'No event exists for this level';
            }
            return $this->writeEvent($accountID, $levelID, $startsAt, $endsAt, $rewards, null, 'set-create');
        }

        $eventID = (int) $existing['eventID'];
        $newStartsAt = $startsAt ?? (int) $existing['startsAt'];
        $newEndsAt = $endsAt ?? (int) $existing['endsAt'];
        $newRewards = $rewards ?? $this->decodeRewards((string) $existing['rewardJson']);
        return $this->writeEvent($accountID, $levelID, $newStartsAt, $newEndsAt, $newRewards, $eventID, $replace ? 'set' : 'change');
    }

    /** @param array<string,int> $rewards */
    private function writeEvent(int $accountID, int $levelID, int $startsAt, int $endsAt, array $rewards, ?int $eventID, string $action): string
    {
        if ($startsAt < time() - 300 || $endsAt <= $startsAt || $endsAt - $startsAt < 3600 || $endsAt - $startsAt > 7776000) {
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

        $now = time();
        $json = json_encode($rewards, JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return '-1';
        }

        try {
            $this->db->beginTransaction();
            if ($eventID === null) {
                $query = $this->db->prepare('INSERT INTO ' . $this->tables->get('core_events') . ' (levelID, startsAt, endsAt, rewardJson, status, createdBy, createdAt, updatedBy, updatedAt) VALUES (:levelID, :startsAt, :endsAt, :rewardJson, :status, :createdBy, :createdAt, :updatedBy, :updatedAt)');
                $query->execute([':levelID'=>$levelID,':startsAt'=>$startsAt,':endsAt'=>$endsAt,':rewardJson'=>$json,':status'=>$startsAt <= $now ? 'active' : 'scheduled',':createdBy'=>$accountID,':createdAt'=>$now,':updatedBy'=>$accountID,':updatedAt'=>$now]);
                $eventID = (int) $this->db->lastInsertId();
            } else {
                $query = $this->db->prepare('UPDATE ' . $this->tables->get('core_events') . ' SET startsAt=:startsAt, endsAt=:endsAt, rewardJson=:rewardJson, status=:status, updatedBy=:updatedBy, updatedAt=:updatedAt WHERE eventID=:eventID');
                $query->execute([':startsAt'=>$startsAt,':endsAt'=>$endsAt,':rewardJson'=>$json,':status'=>$startsAt <= $now && $endsAt > $now ? 'active' : 'scheduled',':updatedBy'=>$accountID,':updatedAt'=>$now,':eventID'=>$eventID]);
            }
            $audit = $this->db->prepare('INSERT INTO ' . $this->tables->get('core_event_audit') . ' (eventID, levelID, accountID, action, detailsJson, createdAt) VALUES (:eventID, :levelID, :accountID, :action, :detailsJson, :createdAt)');
            $audit->execute([':eventID'=>$eventID,':levelID'=>$levelID,':accountID'=>$accountID,':action'=>$action,':detailsJson'=>json_encode(['startsAt'=>$startsAt,'endsAt'=>$endsAt,'rewards'=>$rewards], JSON_UNESCAPED_SLASHES),':createdAt'=>$now]);
            $this->db->commit();
            return self::POPUP_PREFIX . 'Event saved (ID ' . $eventID . ')';
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return '-1';
        }
    }

    private function authorized(int $accountID, string $gjp, string $gjp2, string $ip, string $permission): bool
    {
        return $accountID > 0
            && $this->authenticator->verify($accountID, $gjp, $gjp2, $ip)
            && $this->staff->has($accountID, $permission);
    }

    private function eventTablesAvailable(): bool
    {
        return $this->schema->tableExists('core_events') && $this->schema->tableExists('core_event_audit');
    }

    /** @return array<string,mixed>|null */
    private function activeEventForLevel(int $levelID): ?array
    {
        $query = $this->db->prepare("SELECT eventID, startsAt, endsAt, rewardJson FROM " . $this->tables->get('core_events') . " WHERE levelID = :levelID AND status IN ('scheduled','active') ORDER BY eventID DESC LIMIT 1");
        $query->execute([':levelID' => $levelID]);
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
