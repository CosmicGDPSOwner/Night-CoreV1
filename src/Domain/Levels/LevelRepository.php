<?php

declare(strict_types=1);

namespace NightCore\Domain\Levels;

use NightCore\Core\TableNames;
use PDO;

final class LevelRepository
{
    private const WRITE_COLUMNS = [
        'levelName', 'gameVersion', 'binaryVersion', 'userName', 'levelDesc', 'levelVersion', 'levelLength',
        'audioTrack', 'auto', 'password', 'original', 'twoPlayer', 'songID', 'objects', 'coins', 'requestedStars',
        'extraString', 'levelString', 'levelInfo', 'secret', 'updateDate', 'unlisted', 'unlisted2', 'hostname', 'isLDM',
        'wt', 'wt2', 'settingsString', 'songIDs', 'sfxIDs', 'ts',
    ];

    public function __construct(private PDO $db, private TableNames $tables)
    {
    }

    public function recentUploadExists(int $userID, string $ip, int $since): bool
    {
        $query = $this->db->prepare(
            'SELECT COUNT(*) FROM ' . $this->tables->get('levels') .
            ' WHERE uploadDate > :since AND (userID = :userID OR hostname = :ip)'
        );
        $query->execute([':since' => $since, ':userID' => $userID, ':ip' => $ip]);
        return (int) $query->fetchColumn() > 0;
    }

    public function findExistingLevelId(int $userID, int $requestedLevelID, string $levelName): ?int
    {
        if ($requestedLevelID > 0) {
            $query = $this->db->prepare(
                'SELECT levelID FROM ' . $this->tables->get('levels') .
                ' WHERE levelID = :levelID AND userID = :userID LIMIT 1'
            );
            $query->execute([':levelID' => $requestedLevelID, ':userID' => $userID]);
            $value = $query->fetchColumn();
            if ($value !== false) {
                return (int) $value;
            }
        }

        $query = $this->db->prepare(
            'SELECT levelID FROM ' . $this->tables->get('levels') .
            ' WHERE levelName = :levelName AND userID = :userID ORDER BY levelID ASC LIMIT 1'
        );
        $query->execute([':levelName' => $levelName, ':userID' => $userID]);
        $value = $query->fetchColumn();
        return $value === false ? null : (int) $value;
    }

    /** @param array<string, int|string> $data */
    public function insert(int $userID, int $accountID, array $data): int
    {
        $columns = array_merge(self::WRITE_COLUMNS, ['uploadDate', 'userID', 'extID']);
        $values = [];
        foreach (self::WRITE_COLUMNS as $column) {
            $values[$column] = $data[$column];
        }
        $values['uploadDate'] = $data['updateDate'];
        $values['userID'] = $userID;
        $values['extID'] = (string) $accountID;

        $query = $this->db->prepare(
            'INSERT INTO ' . $this->tables->get('levels') .
            ' (`' . implode('`,`', $columns) . '`) VALUES (:' . implode(',:', $columns) . ')'
        );
        $query->execute($this->params($values));
        return (int) $this->db->lastInsertId();
    }

    /** @param array<string, int|string> $data */
    public function update(int $levelID, int $userID, array $data): bool
    {
        $assignments = [];
        $values = [];
        foreach (self::WRITE_COLUMNS as $column) {
            $assignments[] = '`' . $column . '` = :' . $column;
            $values[$column] = $data[$column];
        }
        $values['levelID'] = $levelID;
        $values['userID'] = $userID;

        $query = $this->db->prepare(
            'UPDATE ' . $this->tables->get('levels') . ' SET ' . implode(', ', $assignments) .
            ' WHERE levelID = :levelID AND userID = :userID'
        );
        $query->execute($this->params($values));
        return $query->rowCount() > 0;
    }

    public function findById(int $levelID): ?array
    {
        $query = $this->db->prepare(
            'SELECT levels.*, users.userName AS ownerName, users.extID AS ownerExtID FROM ' . $this->tables->get('levels') . ' levels ' .
            'LEFT JOIN ' . $this->tables->get('users') . ' users ON levels.userID = users.userID ' .
            'WHERE levels.levelID = :levelID LIMIT 1'
        );
        $query->execute([':levelID' => $levelID]);
        $row = $query->fetch();
        return $row === false ? null : $row;
    }

    public function incrementDownload(int $levelID, string $ip): void
    {
        $ipHash = hash('sha256', $ip);
        $this->db->beginTransaction();
        try {
            $query = $this->db->prepare(
                'SELECT COUNT(*) FROM ' . $this->tables->get('core_level_downloads') .
                ' WHERE levelID = :levelID AND ipHash = :ipHash'
            );
            $query->execute([':levelID' => $levelID, ':ipHash' => $ipHash]);
            if ((int) $query->fetchColumn() < 2) {
                $update = $this->db->prepare(
                    'UPDATE ' . $this->tables->get('levels') . ' SET downloads = downloads + 1 WHERE levelID = :levelID'
                );
                $update->execute([':levelID' => $levelID]);

                $insert = $this->db->prepare(
                    'INSERT INTO ' . $this->tables->get('core_level_downloads') .
                    ' (levelID, ipHash, downloadedAt) VALUES (:levelID, :ipHash, :downloadedAt)'
                );
                $insert->execute([':levelID' => $levelID, ':ipHash' => $ipHash, ':downloadedAt' => time()]);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $criteria
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public function search(array $criteria, int $offset, int $limit, string $orderKey, bool $descending = true): array
    {
        $where = [];
        $params = [];

        if (empty($criteria['includeUnlisted'])) {
            $where[] = 'levels.unlisted = 0';
        }
        if (isset($criteria['maxGameVersion'])) {
            $where[] = 'levels.gameVersion <= :maxGameVersion';
            $params['maxGameVersion'] = (int) $criteria['maxGameVersion'];
        }
        if (!empty($criteria['originalOnly'])) {
            $where[] = 'levels.original = 0';
        }
        if (!empty($criteria['verifiedCoins'])) {
            $where[] = 'levels.starCoins = 1 AND levels.coins <> 0';
        }
        if (!empty($criteria['twoPlayer'])) {
            $where[] = 'levels.twoPlayer = 1';
        }
        if (!empty($criteria['starred'])) {
            $where[] = 'levels.starStars <> 0';
        }
        if (!empty($criteria['unstarred'])) {
            $where[] = 'levels.starStars = 0';
        }
        if (isset($criteria['audioTrack'])) {
            $where[] = 'levels.audioTrack = :audioTrack AND levels.songID = 0';
            $params['audioTrack'] = (int) $criteria['audioTrack'];
        }
        if (isset($criteria['songID'])) {
            $where[] = 'levels.songID = :songID';
            $params['songID'] = (int) $criteria['songID'];
        }
        if (isset($criteria['levelID'])) {
            $where[] = 'levels.levelID = :levelID';
            $params['levelID'] = (int) $criteria['levelID'];
        }
        if (isset($criteria['name'])) {
            // Make level-name matching independent of the database/table collation.
            // This keeps search predictable on imported Cvolton databases that may
            // use a case-sensitive collation: "Test Level", "test level" and
            // "TEST LEVEL" all resolve through the same substring search.
            $where[] = 'LOWER(levels.levelName) LIKE LOWER(:levelName)';
            $params['levelName'] = '%' . $criteria['name'] . '%';
        }
        if (isset($criteria['userID'])) {
            $where[] = 'levels.userID = :userID';
            $params['userID'] = (int) $criteria['userID'];
        }
        if (isset($criteria['recentSince'])) {
            $where[] = 'levels.uploadDate > :recentSince';
            $params['recentSince'] = (int) $criteria['recentSince'];
        }
        if (!empty($criteria['featured'])) {
            $where[] = '(levels.starFeatured <> 0 OR levels.starEpic <> 0)';
        }
        if (!empty($criteria['epicOnly'])) {
            $where[] = 'levels.starEpic <> 0';
        }
        if (!empty($criteria['magic'])) {
            $where[] = 'levels.objects > 9999';
        }
        if (!empty($criteria['rated'])) {
            $where[] = 'levels.starStars <> 0';
        }
        if (!empty($criteria['starAuto'])) {
            $where[] = 'levels.starAuto = 1';
        }
        if (!empty($criteria['starDemon'])) {
            $where[] = 'levels.starDemon = 1';
        }
        if (isset($criteria['starDemonDiff'])) {
            $where[] = 'levels.starDemonDiff = :starDemonDiff';
            $params['starDemonDiff'] = (int) $criteria['starDemonDiff'];
        }
        if (isset($criteria['starDifficulty'])) {
            $where[] = 'levels.starDifficulty = :starDifficulty AND levels.starAuto = 0 AND levels.starDemon = 0';
            $params['starDifficulty'] = (int) $criteria['starDifficulty'];
        }

        foreach (['lengths' => 'levels.levelLength', 'difficulties' => 'levels.starDifficulty', 'epicValues' => 'levels.starEpic', 'ids' => 'levels.levelID', 'completedOnly' => 'levels.levelID'] as $key => $column) {
            if (!empty($criteria[$key]) && is_array($criteria[$key])) {
                [$sql, $bound] = $this->inClause($key, $criteria[$key]);
                $where[] = $column . ' IN (' . $sql . ')';
                $params += $bound;
            }
        }
        if (!empty($criteria['excludeCompleted']) && is_array($criteria['excludeCompleted'])) {
            [$sql, $bound] = $this->inClause('excludeCompleted', $criteria['excludeCompleted']);
            $where[] = 'levels.levelID NOT IN (' . $sql . ')';
            $params += $bound;
        }

        $orderMap = [
            'uploadDate' => 'levels.uploadDate',
            'likes' => 'levels.likes',
            'downloads' => 'levels.downloads',
            'rateDate' => 'levels.rateDate',
            'starStars' => 'levels.starStars',
            'levelID' => 'levels.levelID',
        ];
        $order = $orderMap[$orderKey] ?? $orderMap['uploadDate'];
        $from = ' FROM ' . $this->tables->get('levels') . ' levels LEFT JOIN ' . $this->tables->get('users') . ' users ON levels.userID = users.userID';
        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

        $query = $this->db->prepare(
            'SELECT levels.*, users.userName AS ownerName, users.extID AS ownerExtID' . $from . $whereSql .
            ' ORDER BY ' . $order . ($descending ? ' DESC' : ' ASC') . ', levels.levelID DESC LIMIT :limit OFFSET :offset'
        );
        foreach ($params as $key => $value) {
            $query->bindValue(':' . $key, $value, $this->pdoType($value));
        }
        $query->bindValue(':limit', $limit, PDO::PARAM_INT);
        $query->bindValue(':offset', $offset, PDO::PARAM_INT);
        $query->execute();
        $rows = $query->fetchAll();

        $count = $this->db->prepare('SELECT COUNT(*)' . $from . $whereSql);
        foreach ($params as $key => $value) {
            $count->bindValue(':' . $key, $value, $this->pdoType($value));
        }
        $count->execute();

        return ['rows' => $rows, 'total' => (int) $count->fetchColumn()];
    }

    /** @param array<string, int|string> $values @return array<string, int|string> */
    private function params(array $values): array
    {
        $params = [];
        foreach ($values as $key => $value) {
            $params[':' . $key] = $value;
        }
        return $params;
    }

    /** @param list<int> $values @return array{0:string,1:array<string,int>} */
    private function inClause(string $prefix, array $values): array
    {
        $names = [];
        $params = [];
        foreach (array_values(array_unique(array_map('intval', $values))) as $index => $value) {
            $name = $prefix . $index;
            $names[] = ':' . $name;
            $params[$name] = $value;
        }
        return [implode(',', $names), $params];
    }

    private function pdoType(mixed $value): int
    {
        return is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
    }
}
