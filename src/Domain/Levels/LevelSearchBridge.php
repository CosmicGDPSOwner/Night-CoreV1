<?php

declare(strict_types=1);

namespace NightCore\Domain\Levels;

use NightCore\Core\TableNames;
use NightCore\Security\AccountAuthenticator;
use PDO;

final class LevelSearchBridge
{
    public function __construct(
        private PDO $db,
        private TableNames $tables,
        private LevelService $levels,
        private AccountAuthenticator $authenticator
    ) {
    }

    /** @param array<string,string> $input */
    public function search(array $input, int $accountID, string $gjp, string $gjp2, string $ip): string
    {
        if (($input['gauntlet'] ?? '') !== '' && (int) $input['gauntlet'] > 0) {
            $ids = $this->gauntletLevelIDs((int) $input['gauntlet']);
            if ($ids === []) {
                return '-1';
            }
            return $this->delegateIds($input, $ids, $accountID, $gjp, $gjp2, $ip, true);
        }

        $type = $this->int($input['type'] ?? '0', 0, 100);
        $ids = null;
        switch ($type) {
            case 12: // Followed: account/ext IDs are supplied by the client, matching the legacy protocol.
                $followed = $this->idList($input['followed'] ?? '', 1000);
                if ($followed === []) {
                    return '-1';
                }
                $ids = $this->levelIDsByAccounts($followed, 100);
                break;

            case 13: // Friends.
                if ($accountID <= 0 || !$this->authenticator->verify($accountID, $gjp, $gjp2, $ip)) {
                    return '-1';
                }
                $friends = $this->friendAccountIDs($accountID);
                if ($friends === []) {
                    return '-1';
                }
                $ids = $this->levelIDsByAccounts($friends, 100);
                break;

            case 21: // Daily history.
            case 22: // Weekly history.
            case 23: // Event history.
                $ids = $this->rotationLevelIDs($type - 21, 100);
                break;

            case 25: // Levels belonging to a server-side 2.2 list.
                $listID = $this->int(trim($input['str'] ?? ''), 1);
                if ($listID <= 0) {
                    return '-1';
                }
                $ids = $this->listLevelIDs($listID);
                break;

            case 27: // Levels sent/suggested to moderators.
                $ids = $this->suggestedLevelIDs(100);
                break;
        }

        if ($ids !== null) {
            if ($ids === []) {
                return '-1';
            }
            return $this->delegateIds($input, $ids, $accountID, $gjp, $gjp2, $ip, false);
        }

        return $this->levels->search($input, $accountID, $gjp, $gjp2, $ip);
    }

    /** @param array<string,string> $input @param array<int,int> $ids */
    private function delegateIds(array $input, array $ids, int $accountID, string $gjp, string $gjp2, string $ip, bool $gauntlet): string
    {
        $input['type'] = count($ids) > 10 ? '26' : '10';
        $input['str'] = implode(',', array_slice(array_values(array_unique($ids)), 0, 100));
        $input['gauntlet'] = '';
        if ($gauntlet) {
            // The legacy query orders gauntlet results itself; LevelService provides the same level set and hashes.
            $input['page'] = '0';
        }
        return $this->levels->search($input, $accountID, $gjp, $gjp2, $ip);
    }

    /** @return array<int,int> */
    private function gauntletLevelIDs(int $gauntletID): array
    {
        $query = $this->db->prepare('SELECT levelIDs FROM ' . $this->tables->get('core_gauntlets') . ' WHERE gauntletID = :gauntletID LIMIT 1');
        $query->execute([':gauntletID' => $gauntletID]);
        $value = $query->fetchColumn();
        return $value === false ? [] : $this->idList((string) $value, 5);
    }

    /** @param array<int,int> $accountIDs @return array<int,int> */
    private function levelIDsByAccounts(array $accountIDs, int $limit): array
    {
        $accountIDs = array_values(array_unique(array_filter(array_map('intval', $accountIDs), static fn(int $id): bool => $id > 0)));
        if ($accountIDs === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($accountIDs), '?'));
        $query = $this->db->prepare(
            'SELECT levelID FROM ' . $this->tables->get('levels') .
            ' WHERE unlisted = 0 AND CAST(extID AS UNSIGNED) IN (' . $placeholders . ')' .
            ' ORDER BY uploadDate DESC, levelID DESC LIMIT ' . max(1, min(100, $limit))
        );
        $query->execute($accountIDs);
        return array_map('intval', array_column($query->fetchAll(), 'levelID'));
    }

    /** @return array<int,int> */
    private function friendAccountIDs(int $accountID): array
    {
        $query = $this->db->prepare(
            'SELECT CASE WHEN accountLow = :me THEN accountHigh ELSE accountLow END AS accountID FROM ' .
            $this->tables->get('core_friendships') . ' WHERE accountLow = :me OR accountHigh = :me'
        );
        $query->execute([':me' => $accountID]);
        return array_map('intval', array_column($query->fetchAll(), 'accountID'));
    }

    /** @return array<int,int> */
    private function rotationLevelIDs(int $slotType, int $limit): array
    {
        $query = $this->db->prepare(
            'SELECT levelID FROM ' . $this->tables->get('core_daily_levels') .
            ' WHERE slotType = :slotType ORDER BY slotID DESC LIMIT ' . max(1, min(100, $limit))
        );
        $query->execute([':slotType' => $slotType]);
        return array_map('intval', array_column($query->fetchAll(), 'levelID'));
    }

    /** @return array<int,int> */
    private function listLevelIDs(int $listID): array
    {
        $query = $this->db->prepare('SELECT levelIDs FROM ' . $this->tables->get('core_level_lists') . ' WHERE listID = :listID LIMIT 1');
        $query->execute([':listID' => $listID]);
        $value = $query->fetchColumn();
        return $value === false ? [] : $this->idList((string) $value, 100);
    }

    /** @return array<int,int> */
    private function suggestedLevelIDs(int $limit): array
    {
        $query = $this->db->query(
            'SELECT levelID FROM ' . $this->tables->get('core_star_suggestions') .
            ' ORDER BY createdAt DESC LIMIT ' . max(1, min(100, $limit))
        );
        return array_map('intval', array_column($query->fetchAll(), 'levelID'));
    }

    /** @return array<int,int> */
    private function idList(string $value, int $limit): array
    {
        $ids = [];
        foreach (preg_split('/[:,;\s]+/', $value) ?: [] as $part) {
            $part = trim($part);
            if ($part !== '' && ctype_digit($part)) {
                $id = (int) $part;
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
        }
        return array_slice(array_values(array_unique($ids)), 0, $limit);
    }

    private function int(string $value, int $min = 0, int $max = PHP_INT_MAX): int
    {
        if (!preg_match('/^-?\d+$/', trim($value))) {
            return $min;
        }
        return max($min, min($max, (int) $value));
    }
}
