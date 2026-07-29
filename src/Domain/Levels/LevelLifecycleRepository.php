<?php

declare(strict_types=1);

namespace NightCore\Domain\Levels;

use NightCore\Core\TableNames;
use PDO;
use Throwable;

final class LevelLifecycleRepository
{
    public function __construct(private PDO $db, private TableNames $tables)
    {
    }

    public function deleteOwnedUnrated(int $levelID, int $accountID): bool
    {
        if ($levelID <= 0 || $accountID <= 0) {
            return false;
        }

        $this->db->beginTransaction();
        try {
            $find = $this->db->prepare(
                'SELECT levelID FROM ' . $this->tables->get('levels') .
                ' WHERE levelID = :levelID AND CAST(extID AS UNSIGNED) = :accountID AND starStars = 0 LIMIT 1 FOR UPDATE'
            );
            $find->execute([':levelID' => $levelID, ':accountID' => $accountID]);
            if ($find->fetchColumn() === false) {
                $this->db->rollBack();
                return false;
            }

            $commentIDs = $this->db->prepare(
                'SELECT commentID FROM ' . $this->tables->get('core_comments') .
                ' WHERE targetType = 0 AND targetID = :levelID'
            );
            $commentIDs->execute([':levelID' => $levelID]);
            $ids = array_map('intval', array_column($commentIDs->fetchAll(), 'commentID'));
            if ($ids !== []) {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $deleteCommentLikes = $this->db->prepare(
                    'DELETE FROM ' . $this->tables->get('core_item_likes') .
                    ' WHERE itemType = 2 AND itemID IN (' . $placeholders . ')'
                );
                $deleteCommentLikes->execute($ids);
            }

            $cleanup = [
                ['core_comments', 'targetType = 0 AND targetID = :levelID'],
                ['core_level_scores', 'levelID = :levelID'],
                ['core_level_downloads', 'levelID = :levelID'],
                ['core_star_suggestions', 'levelID = :levelID'],
                ['core_rate_log', 'levelID = :levelID'],
                ['core_reports', 'itemType = 1 AND itemID = :levelID'],
                ['core_item_likes', 'itemType = 1 AND itemID = :levelID'],
                ['core_daily_levels', 'levelID = :levelID'],
            ];
            foreach ($cleanup as [$table, $where]) {
                $delete = $this->db->prepare('DELETE FROM ' . $this->tables->get($table) . ' WHERE ' . $where);
                $delete->execute([':levelID' => $levelID]);
            }

            $deleteLevel = $this->db->prepare(
                'DELETE FROM ' . $this->tables->get('levels') .
                ' WHERE levelID = :levelID AND CAST(extID AS UNSIGNED) = :accountID AND starStars = 0'
            );
            $deleteLevel->execute([':levelID' => $levelID, ':accountID' => $accountID]);
            if ($deleteLevel->rowCount() !== 1) {
                $this->db->rollBack();
                return false;
            }

            $this->db->commit();
            return true;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function updateDescription(int $levelID, int $accountID, string $description): bool
    {
        if ($levelID <= 0 || $accountID <= 0) {
            return false;
        }
        $query = $this->db->prepare(
            'UPDATE ' . $this->tables->get('levels') .
            ' SET levelDesc = :description, updateDate = :updateDate '
            . 'WHERE levelID = :levelID AND CAST(extID AS UNSIGNED) = :accountID'
        );
        $query->execute([
            ':description' => $description,
            ':updateDate' => time(),
            ':levelID' => $levelID,
            ':accountID' => $accountID,
        ]);
        return $query->rowCount() === 1;
    }
}
