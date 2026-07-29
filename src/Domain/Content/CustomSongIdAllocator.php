<?php

declare(strict_types=1);

namespace NightCore\Domain\Content;

use NightCore\Core\TableNames;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final class CustomSongIdAllocator
{
    public function __construct(
        private PDO $db,
        private TableNames $tables,
        private int $minID,
        private int $maxID
    ) {
        if ($this->minID <= 0 || $this->maxID < $this->minID) {
            throw new RuntimeException('Invalid local custom-song ID range.');
        }
    }

    public function minID(): int
    {
        return $this->minID;
    }

    public function maxID(): int
    {
        return $this->maxID;
    }

    public function reserve(string $originalName, int $uploadedAt): int
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $this->db->beginTransaction();
            try {
                $query = $this->db->prepare(
                    'SELECT MAX(songID) FROM ('
                    . 'SELECT songID FROM ' . $this->tables->get('core_local_songs') . ' WHERE songID BETWEEN :localMin AND :localMax '
                    . 'UNION ALL '
                    . 'SELECT songID FROM ' . $this->tables->get('core_songs') . ' WHERE songID BETWEEN :songMin AND :songMax'
                    . ') occupied'
                );
                $query->execute([
                    ':localMin' => $this->minID,
                    ':localMax' => $this->maxID,
                    ':songMin' => $this->minID,
                    ':songMax' => $this->maxID,
                ]);
                $highest = $query->fetchColumn();
                $songID = $highest === false || $highest === null
                    ? $this->minID
                    : max($this->minID, (int) $highest + 1);

                if ($songID > $this->maxID) {
                    throw new RuntimeException('The local custom-song ID range is exhausted.');
                }

                $insert = $this->db->prepare(
                    'INSERT INTO ' . $this->tables->get('core_local_songs')
                    . ' (songID, originalName, sha256, bytes, uploadedAt) '
                    . 'VALUES (:songID, :originalName, :sha256, 0, :uploadedAt)'
                );
                $insert->execute([
                    ':songID' => $songID,
                    ':originalName' => $originalName,
                    ':sha256' => str_repeat('0', 64),
                    ':uploadedAt' => $uploadedAt,
                ]);
                $this->db->commit();
                return $songID;
            } catch (PDOException $e) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                if ((string) $e->getCode() !== '23000') {
                    throw $e;
                }
            } catch (Throwable $e) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                throw $e;
            }
        }

        throw new RuntimeException('Unable to allocate a collision-free custom song ID.');
    }
}
