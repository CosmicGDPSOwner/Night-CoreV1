<?php

declare(strict_types=1);

namespace NightCore\Domain\Content;

use NightCore\Core\Config;
use NightCore\Core\TableNames;
use NightCore\Security\AccountAuthenticator;
use PDO;

final class CommentDeletionService
{
    private CommentAccessPolicy $policy;

    public function __construct(
        private PDO $db,
        private TableNames $tables,
        private AccountAuthenticator $authenticator
    ) {
        $this->policy = new CommentAccessPolicy($db, $tables, $this->adminAccountIDs());
    }

    public function delete(
        int $accountID,
        string $gjp,
        string $gjp2,
        string $ip,
        int $commentID,
        int $targetType
    ): string {
        if ($commentID <= 0 || !in_array($targetType, [0, 1], true)) {
            return '-1';
        }
        if (!$this->authenticator->verify($accountID, $gjp, $gjp2, $ip)) {
            return '-1';
        }
        if (!$this->policy->canDelete($accountID, $commentID, $targetType)) {
            return '-1';
        }

        $this->db->beginTransaction();
        try {
            $deleteLikes = $this->db->prepare(
                'DELETE FROM ' . $this->tables->get('core_item_likes') .
                ' WHERE itemType IN (2, 3) AND itemID = :commentID'
            );
            $deleteLikes->execute([':commentID' => $commentID]);

            $delete = $this->db->prepare(
                'DELETE FROM ' . $this->tables->get('core_comments') .
                ' WHERE commentID = :commentID AND targetType = :targetType'
            );
            $delete->execute([
                ':commentID' => $commentID,
                ':targetType' => $targetType,
            ]);
            if ($delete->rowCount() !== 1) {
                $this->db->rollBack();
                return '-1';
            }

            $this->db->commit();
            return '1';
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /** @return array<int,int> */
    private function adminAccountIDs(): array
    {
        $raw = trim(Config::get('CORE_ADMIN_ACCOUNT_IDS', '') ?? '');
        if ($raw === '') {
            return [];
        }
        $ids = [];
        foreach (explode(',', $raw) as $part) {
            $part = trim($part);
            if ($part !== '' && ctype_digit($part) && (int) $part > 0) {
                $ids[] = (int) $part;
            }
        }
        return array_values(array_unique($ids));
    }
}
