<?php

declare(strict_types=1);

namespace NightCore\Domain\Content;

use NightCore\Core\TableNames;
use PDO;
use Throwable;

final class MediaSettingsRepository
{
    public const SONG_MAX_BYTES = 'song_max_bytes';
    public const SFX_MAX_BYTES = 'sfx_max_bytes';

    public function __construct(private PDO $db, private TableNames $tables)
    {
    }

    public function int(string $key, int $fallback, int $minimum = 1): int
    {
        try {
            $query = $this->db->prepare(
                'SELECT settingValue FROM ' . $this->tables->get('core_media_settings') . ' WHERE settingKey = :settingKey LIMIT 1'
            );
            $query->execute([':settingKey' => $key]);
            $value = $query->fetchColumn();
            if ($value === false || !is_numeric($value)) {
                return max($minimum, $fallback);
            }
            return max($minimum, (int) $value);
        } catch (Throwable) {
            // Before migration 0010 is installed the application must continue
            // using the environment-configured fallback.
            return max($minimum, $fallback);
        }
    }

    public function setInt(string $key, int $value, int $minimum = 1): void
    {
        $value = max($minimum, $value);
        $query = $this->db->prepare(
            'INSERT INTO ' . $this->tables->get('core_media_settings') . ' (settingKey, settingValue, updatedAt) '
            . 'VALUES (:settingKey, :settingValue, :updatedAt) '
            . 'ON DUPLICATE KEY UPDATE settingValue = VALUES(settingValue), updatedAt = VALUES(updatedAt)'
        );
        $query->execute([
            ':settingKey' => $key,
            ':settingValue' => (string) $value,
            ':updatedAt' => time(),
        ]);
    }

    /** @return array{songMaxBytes:int,sfxMaxBytes:int} */
    public function uploadLimits(int $songFallback, int $sfxFallback): array
    {
        return [
            'songMaxBytes' => $this->int(self::SONG_MAX_BYTES, $songFallback, 1024),
            'sfxMaxBytes' => $this->int(self::SFX_MAX_BYTES, $sfxFallback, 1024),
        ];
    }

    public function saveUploadLimits(int $songMaxBytes, int $sfxMaxBytes): void
    {
        $this->db->beginTransaction();
        try {
            $this->setInt(self::SONG_MAX_BYTES, $songMaxBytes, 1024);
            $this->setInt(self::SFX_MAX_BYTES, $sfxMaxBytes, 1024);
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
}
