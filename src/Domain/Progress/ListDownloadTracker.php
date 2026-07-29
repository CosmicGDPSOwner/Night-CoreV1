<?php

declare(strict_types=1);

namespace NightCore\Domain\Progress;

use NightCore\Core\TableNames;
use PDO;
use Throwable;

final class ListDownloadTracker
{
    public function __construct(private PDO $db, private TableNames $tables)
    {
    }

    public function incrementOnce(int $listID, string $ip): bool
    {
        if ($listID <= 0 || $ip === '') {
            return false;
        }

        $ipHash = hash('sha256', $ip);
        $this->db->beginTransaction();
        try {
            $insert = $this->db->prepare(
                'INSERT IGNORE INTO ' . $this->tables->get('core_list_downloads') .
                ' (listID, ipHash, downloadedAt) '
                . 'SELECT listID, :ipHash, :downloadedAt FROM ' . $this->tables->get('core_level_lists') .
                ' WHERE listID = :listID LIMIT 1'
            );
            $insert->execute([
                ':ipHash' => $ipHash,
                ':downloadedAt' => time(),
                ':listID' => $listID,
            ]);

            if ($insert->rowCount() !== 1) {
                $this->db->rollBack();
                return false;
            }

            $update = $this->db->prepare(
                'UPDATE ' . $this->tables->get('core_level_lists') .
                ' SET downloads = downloads + 1 WHERE listID = :listID'
            );
            $update->execute([':listID' => $listID]);
            $this->db->commit();
            return true;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
}
