<?php

declare(strict_types=1);

namespace NightCore\Domain\Content;

use NightCore\Core\TableNames;
use PDO;

final class CommentAccessPolicy
{
    /** @param array<int,int> $adminAccountIDs */
    public function __construct(
        private PDO $db,
        private TableNames $tables,
        private array $adminAccountIDs
    ) {
    }

    public function canDelete(int $accountID, int $commentID, int $expectedTargetType): bool
    {
        if ($accountID <= 0 || $commentID <= 0 || !in_array($expectedTargetType, [0, 1], true)) {
            return false;
        }

        $query = $this->db->prepare(
            'SELECT accountID, targetType, targetID FROM ' . $this->tables->get('core_comments') .
            ' WHERE commentID = :commentID LIMIT 1'
        );
        $query->execute([':commentID' => $commentID]);
        $comment = $query->fetch();
        if ($comment === false || (int) $comment['targetType'] !== $expectedTargetType) {
            return false;
        }

        if ((int) $comment['accountID'] === $accountID) {
            return true;
        }
        if (in_array($accountID, $this->adminAccountIDs, true)) {
            return true;
        }

        $role = $this->db->prepare(
            'SELECT canModerateComments FROM ' . $this->tables->get('core_moderator_roles') .
            ' WHERE accountID = :accountID LIMIT 1'
        );
        $role->execute([':accountID' => $accountID]);
        if ((int) ($role->fetchColumn() ?: 0) === 1) {
            return true;
        }

        if ($expectedTargetType !== 0) {
            return false;
        }

        $level = $this->db->prepare(
            'SELECT 1 FROM ' . $this->tables->get('levels') .
            ' WHERE levelID = :levelID AND CAST(extID AS UNSIGNED) = :accountID LIMIT 1'
        );
        $level->execute([
            ':levelID' => (int) $comment['targetID'],
            ':accountID' => $accountID,
        ]);
        return $level->fetchColumn() !== false;
    }
}
