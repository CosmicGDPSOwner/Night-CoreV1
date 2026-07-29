<?php

declare(strict_types=1);

namespace NightCore\Domain\Progress;

use NightCore\Core\TableNames;
use PDO;

final class ProgressRepository
{
    public function __construct(private PDO $db, private TableNames $tables)
    {
    }

    public function saveAccount(int $accountID, string $saveData, string $saveExtra): void
    {
        $query = $this->db->prepare('INSERT INTO ' . $this->tables->get('core_account_saves') . ' (accountID, saveData, saveExtra, payloadBytes, updatedAt) VALUES (:accountID, :saveData, :saveExtra, :bytes, :updatedAt) ON DUPLICATE KEY UPDATE saveData = VALUES(saveData), saveExtra = VALUES(saveExtra), payloadBytes = VALUES(payloadBytes), updatedAt = VALUES(updatedAt)');
        $query->execute([
            ':accountID' => $accountID,
            ':saveData' => $saveData,
            ':saveExtra' => $saveExtra,
            ':bytes' => strlen($saveData) + strlen($saveExtra),
            ':updatedAt' => time(),
        ]);
    }

    public function accountSave(int $accountID): ?array
    {
        $query = $this->db->prepare('SELECT saveData, saveExtra, updatedAt FROM ' . $this->tables->get('core_account_saves') . ' WHERE accountID = :accountID LIMIT 1');
        $query->execute([':accountID' => $accountID]);
        $row = $query->fetch();
        return $row === false ? null : $row;
    }

    public function upsertLevelScore(int $accountID, int $userID, int $levelID, int $percent, int $coins, int $attempts, int $scoreTime): void
    {
        $query = $this->db->prepare('INSERT INTO ' . $this->tables->get('core_level_scores') . ' (accountID, userID, levelID, percent, coins, attempts, scoreTime, updatedAt) VALUES (:accountID, :userID, :levelID, :percent, :coins, :attempts, :scoreTime, :updatedAt) ON DUPLICATE KEY UPDATE userID = VALUES(userID), percent = GREATEST(percent, VALUES(percent)), coins = GREATEST(coins, VALUES(coins)), attempts = GREATEST(attempts, VALUES(attempts)), scoreTime = CASE WHEN VALUES(percent) >= percent THEN VALUES(scoreTime) ELSE scoreTime END, updatedAt = VALUES(updatedAt)');
        $query->execute([
            ':accountID' => $accountID,
            ':userID' => $userID,
            ':levelID' => $levelID,
            ':percent' => $percent,
            ':coins' => $coins,
            ':attempts' => $attempts,
            ':scoreTime' => $scoreTime,
            ':updatedAt' => time(),
        ]);
    }

    /** @return array<int,array<string,mixed>> */
    public function globalScores(int $limit = 100): array
    {
        $limit = max(1, min(100, $limit));
        $query = $this->db->query('SELECT userID, extID, userName, stars, demons, coins, userCoins, diamonds, creatorPoints, icon, color1, color2, color3, iconType, special FROM ' . $this->tables->get('users') . ' WHERE isRegistered = 1 AND isBanned = 0 ORDER BY stars DESC, diamonds DESC, userCoins DESC, userID ASC LIMIT ' . $limit);
        return $query->fetchAll();
    }

    /** @return array<int,array<string,mixed>> */
    public function relativeScores(int $accountID, int $radius = 50): array
    {
        $find = $this->db->prepare('SELECT stars FROM ' . $this->tables->get('users') . ' WHERE extID = :accountID ORDER BY isRegistered DESC LIMIT 1');
        $find->execute([':accountID' => (string) $accountID]);
        $stars = $find->fetchColumn();
        if ($stars === false) {
            return [];
        }
        $radius = max(1, min(1000, $radius));
        $query = $this->db->prepare('SELECT userID, extID, userName, stars, demons, coins, userCoins, diamonds, creatorPoints, icon, color1, color2, color3, iconType, special FROM ' . $this->tables->get('users') . ' WHERE isRegistered = 1 AND isBanned = 0 AND stars BETWEEN :minStars AND :maxStars ORDER BY stars DESC, diamonds DESC, userID ASC LIMIT 100');
        $query->execute([':minStars' => max(0, (int) $stars - $radius), ':maxStars' => (int) $stars + $radius]);
        return $query->fetchAll();
    }

    /** @return array<int,array<string,mixed>> */
    public function levelScores(int $levelID, int $limit = 100): array
    {
        $limit = max(1, min(100, $limit));
        $query = $this->db->prepare('SELECT s.accountID, s.userID, s.percent, s.coins, s.attempts, s.scoreTime, s.updatedAt, u.userName, u.icon, u.color1, u.color2, u.iconType, u.special, u.extID FROM ' . $this->tables->get('core_level_scores') . ' s LEFT JOIN ' . $this->tables->get('users') . ' u ON u.userID = s.userID WHERE s.levelID = :levelID ORDER BY s.percent DESC, s.scoreTime ASC, s.updatedAt ASC LIMIT ' . $limit);
        $query->execute([':levelID' => $levelID]);
        return $query->fetchAll();
    }

    public function currentRotation(int $slotType, int $now): ?array
    {
        $query = $this->db->prepare('SELECT slotType, slotID, levelID, startsAt, endsAt FROM ' . $this->tables->get('core_daily_levels') . ' WHERE slotType = :slotType AND startsAt <= :now AND (endsAt = 0 OR endsAt > :now) ORDER BY startsAt DESC, slotID DESC LIMIT 1');
        $query->execute([':slotType' => $slotType, ':now' => $now]);
        $row = $query->fetch();
        return $row === false ? null : $row;
    }

    public function rotationLevelId(int $slotType, int $slotID): ?int
    {
        $query = $this->db->prepare('SELECT levelID FROM ' . $this->tables->get('core_daily_levels') . ' WHERE slotType = :slotType AND slotID = :slotID LIMIT 1');
        $query->execute([':slotType' => $slotType, ':slotID' => $slotID]);
        $value = $query->fetchColumn();
        return $value === false ? null : (int) $value;
    }

    /** @return array<int,array<string,mixed>> */
    public function gauntlets(): array
    {
        $query = $this->db->query('SELECT gauntletID, levelIDs FROM ' . $this->tables->get('core_gauntlets') . ' ORDER BY gauntletID ASC');
        return $query->fetchAll();
    }

    /** @return array<int,int> */
    public function gauntletLevelIds(int $gauntletID): array
    {
        $query = $this->db->prepare('SELECT levelIDs FROM ' . $this->tables->get('core_gauntlets') . ' WHERE gauntletID = :gauntletID LIMIT 1');
        $query->execute([':gauntletID' => $gauntletID]);
        $value = $query->fetchColumn();
        if ($value === false) {
            return [];
        }
        return $this->idList((string) $value, 100);
    }

    /** @return array{rows:array<int,array<string,mixed>>,total:int} */
    public function mapPacks(int $page): array
    {
        $offset = max(0, $page) * 10;
        $total = (int) $this->db->query('SELECT COUNT(*) FROM ' . $this->tables->get('core_map_packs'))->fetchColumn();
        $query = $this->db->query('SELECT packID, name, levelIDs, stars, coins, difficulty, color1, color2 FROM ' . $this->tables->get('core_map_packs') . ' ORDER BY packID ASC LIMIT 10 OFFSET ' . $offset);
        return ['rows' => $query->fetchAll(), 'total' => $total];
    }

    public function saveList(int $accountID, int $userID, int $listID, string $name, string $description, string $levelIDs, int $reward, int $unlisted): int
    {
        $now = time();
        if ($listID > 0) {
            $query = $this->db->prepare('UPDATE ' . $this->tables->get('core_level_lists') . ' SET listName = :name, listDesc = :description, levelIDs = :levelIDs, reward = :reward, unlisted = :unlisted, updatedAt = :updatedAt WHERE listID = :listID AND accountID = :accountID');
            $query->execute([':name' => $name, ':description' => $description, ':levelIDs' => $levelIDs, ':reward' => $reward, ':unlisted' => $unlisted, ':updatedAt' => $now, ':listID' => $listID, ':accountID' => $accountID]);
            return $query->rowCount() > 0 ? $listID : 0;
        }
        $query = $this->db->prepare('INSERT INTO ' . $this->tables->get('core_level_lists') . ' (accountID, userID, listName, listDesc, levelIDs, reward, unlisted, createdAt, updatedAt) VALUES (:accountID, :userID, :name, :description, :levelIDs, :reward, :unlisted, :createdAt, :updatedAt)');
        $query->execute([':accountID' => $accountID, ':userID' => $userID, ':name' => $name, ':description' => $description, ':levelIDs' => $levelIDs, ':reward' => $reward, ':unlisted' => $unlisted, ':createdAt' => $now, ':updatedAt' => $now]);
        return (int) $this->db->lastInsertId();
    }

    public function deleteList(int $accountID, int $listID): bool
    {
        $query = $this->db->prepare('DELETE FROM ' . $this->tables->get('core_level_lists') . ' WHERE listID = :listID AND accountID = :accountID');
        $query->execute([':listID' => $listID, ':accountID' => $accountID]);
        return $query->rowCount() > 0;
    }

    /** @return array{rows:array<int,array<string,mixed>>,total:int} */
    public function lists(string $search, int $page, int $type, int $accountID): array
    {
        $offset = max(0, $page) * 10;
        $where = ['(unlisted = 0 OR accountID = :accountID)'];
        $params = [':accountID' => $accountID];
        if ($search !== '') {
            if (ctype_digit($search)) {
                $where[] = 'listID = :listID';
                $params[':listID'] = (int) $search;
            } else {
                $where[] = 'listName LIKE :search';
                $params[':search'] = '%' . $search . '%';
            }
        }
        if ($type === 5 && $accountID > 0) {
            $where[] = 'accountID = :owner';
            $params[':owner'] = $accountID;
        }
        $whereSql = implode(' AND ', $where);
        $count = $this->db->prepare('SELECT COUNT(*) FROM ' . $this->tables->get('core_level_lists') . ' WHERE ' . $whereSql);
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $order = $type === 1 ? 'downloads DESC, listID DESC' : ($type === 2 ? 'likes DESC, listID DESC' : 'listID DESC');
        $query = $this->db->prepare('SELECT listID, accountID, userID, listName, listDesc, levelIDs, downloads, likes, reward, unlisted, createdAt, updatedAt FROM ' . $this->tables->get('core_level_lists') . ' WHERE ' . $whereSql . ' ORDER BY ' . $order . ' LIMIT 10 OFFSET ' . $offset);
        $query->execute($params);
        return ['rows' => $query->fetchAll(), 'total' => $total];
    }

    /** @return array<int,int> */
    private function idList(string $value, int $max): array
    {
        $ids = [];
        foreach (preg_split('/[,;\s]+/', $value) ?: [] as $part) {
            if ($part !== '' && ctype_digit($part)) {
                $id = (int) $part;
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
        }
        return array_slice(array_values(array_unique($ids)), 0, $max);
    }
}
