<?php

declare(strict_types=1);

namespace NightCore\Domain\Content;

use NightCore\Core\TableNames;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final class CustomSfxService
{
    public function __construct(
        private PDO $db,
        private TableNames $tables,
        private CustomSfxStorage $storage,
        private int $minID,
        private int $maxID
    ) {
        if ($this->minID <= 0 || $this->maxID < $this->minID) {
            throw new RuntimeException('Invalid local SFX ID range.');
        }
    }

    public function storage(): CustomSfxStorage
    {
        return $this->storage;
    }

    public function minSfxID(): int
    {
        return $this->minID;
    }

    public function maxSfxID(): int
    {
        return $this->maxID;
    }

    /** @return array{sfxID:int,name:string,bytes:int,sha256:string,download:string,extension:string} */
    public function import(string $sourcePath, string $originalName, string $name, string $publicBaseUrl): array
    {
        $name = $this->field($name, 255);
        $originalName = $this->field(basename($originalName), 255);
        if ($name === '') {
            throw new RuntimeException('SFX name is required.');
        }
        if ($originalName === '') {
            $originalName = 'sfx.ogg';
        }
        $publicBaseUrl = $this->publicBaseUrl($publicBaseUrl);

        $sfxID = $this->reserve($originalName);
        $stored = null;
        try {
            $stored = $this->storage->store($sfxID, $sourcePath, $originalName);
            $download = $publicBaseUrl . '/downloadCustomSfx.php?sfxID=' . $sfxID;
            $query = $this->db->prepare(
                'UPDATE ' . $this->tables->get('core_local_sfx')
                . ' SET name = :name, extension = :extension, sha256 = :sha256, bytes = :bytes, download = :download '
                . 'WHERE sfxID = :sfxID'
            );
            $query->execute([
                ':name' => $name,
                ':extension' => $stored['extension'],
                ':sha256' => $stored['sha256'],
                ':bytes' => $stored['bytes'],
                ':download' => $download,
                ':sfxID' => $sfxID,
            ]);
            if ($query->rowCount() === 0) {
                throw new RuntimeException('Reserved SFX row disappeared before finalization.');
            }

            return [
                'sfxID' => $sfxID,
                'name' => $name,
                'bytes' => $stored['bytes'],
                'sha256' => $stored['sha256'],
                'download' => $download,
                'extension' => $stored['extension'],
            ];
        } catch (Throwable $e) {
            if ($stored !== null) {
                try {
                    $this->storage->delete($sfxID, (string) $stored['extension']);
                } catch (Throwable) {
                }
            }
            try {
                $this->deleteRow($sfxID);
            } catch (Throwable) {
            }
            throw $e;
        }
    }

    /** @return list<array<string,mixed>> */
    public function list(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $query = $this->db->query(
            'SELECT sfxID, name, originalName, extension, sha256, bytes, download, uploadedAt '
            . 'FROM ' . $this->tables->get('core_local_sfx') . ' WHERE bytes > 0 ORDER BY sfxID DESC LIMIT ' . $limit
        );
        return $query->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function downloadRecord(int $sfxID): ?array
    {
        if ($sfxID <= 0) {
            return null;
        }
        $query = $this->db->prepare(
            'SELECT sfxID, name, originalName, extension, sha256, bytes, download, uploadedAt '
            . 'FROM ' . $this->tables->get('core_local_sfx') . ' WHERE sfxID = :sfxID AND bytes > 0 LIMIT 1'
        );
        $query->execute([':sfxID' => $sfxID]);
        $row = $query->fetch();
        if ($row === false) {
            return null;
        }
        $extension = (string) ($row['extension'] ?? 'ogg');
        if (!$this->storage->exists($sfxID, $extension)) {
            return null;
        }
        $row['path'] = $this->storage->path($sfxID, $extension);
        return $row;
    }

    public function delete(int $sfxID): bool
    {
        $row = $this->downloadRecord($sfxID);
        if ($row === null) {
            return false;
        }
        $this->storage->delete($sfxID, (string) $row['extension']);
        $this->deleteRow($sfxID);
        return true;
    }

    private function reserve(string $originalName): int
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            try {
                $query = $this->db->prepare(
                    'SELECT MAX(sfxID) FROM ' . $this->tables->get('core_local_sfx') . ' WHERE sfxID BETWEEN :minID AND :maxID'
                );
                $query->execute([':minID' => $this->minID, ':maxID' => $this->maxID]);
                $highest = $query->fetchColumn();
                $sfxID = $highest === false || $highest === null ? $this->minID : max($this->minID, (int) $highest + 1);
                if ($sfxID > $this->maxID) {
                    throw new RuntimeException('The local SFX ID range is exhausted.');
                }

                $insert = $this->db->prepare(
                    'INSERT INTO ' . $this->tables->get('core_local_sfx')
                    . ' (sfxID, name, originalName, extension, sha256, bytes, download, uploadedAt) '
                    . 'VALUES (:sfxID, :name, :originalName, :extension, :sha256, 0, :download, :uploadedAt)'
                );
                $insert->execute([
                    ':sfxID' => $sfxID,
                    ':name' => '',
                    ':originalName' => $originalName,
                    ':extension' => 'ogg',
                    ':sha256' => str_repeat('0', 64),
                    ':download' => '',
                    ':uploadedAt' => time(),
                ]);
                return $sfxID;
            } catch (PDOException $e) {
                if ((string) $e->getCode() !== '23000') {
                    throw $e;
                }
            }
        }
        throw new RuntimeException('Unable to allocate a collision-free custom SFX ID.');
    }

    private function deleteRow(int $sfxID): void
    {
        $query = $this->db->prepare('DELETE FROM ' . $this->tables->get('core_local_sfx') . ' WHERE sfxID = :sfxID');
        $query->execute([':sfxID' => $sfxID]);
    }

    private function publicBaseUrl(string $value): string
    {
        $value = rtrim(trim($value), '/');
        $parts = parse_url($value);
        if (!is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)) {
            throw new RuntimeException('Custom SFX public base URL must use http or https.');
        }
        if (trim((string) ($parts['host'] ?? '')) === '' || isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException('Custom SFX public base URL must contain a normal public host.');
        }
        return $value;
    }

    private function field(string $value, int $max): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F]/', '', trim($value)) ?? '';
        $value = str_replace(['~|~', '~:~', '#'], '', $value);
        return strlen($value) > $max ? substr($value, 0, $max) : $value;
    }
}
