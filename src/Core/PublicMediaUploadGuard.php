<?php

declare(strict_types=1);

namespace NightCore\Core;

use PDO;
use RuntimeException;
use Throwable;

final class PublicMediaUploadGuard
{
    public function __construct(
        private PDO $db,
        private TableNames $tables,
        private MediaPolicy $policy
    ) {
    }

    public function reserve(string $clientIp, string $storagePath, int $incomingBytes, ?int $now = null): void
    {
        if (!$this->policy->publicUploadsEnabled()) {
            throw new RuntimeException('Public media uploads are disabled.');
        }

        $now ??= time();
        $incomingBytes = max(0, $incomingBytes);
        $this->assertDiskReserve($storagePath, $incomingBytes);

        $ip = trim($clientIp);
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            $ip = 'unknown';
        }
        $connectionScope = 'ip:' . hash('sha256', $ip);
        $globalScope = 'global';
        $table = $this->tables->get('core_media_upload_rate_limits');

        $this->db->beginTransaction();
        try {
            $insert = $this->db->prepare(
                'INSERT IGNORE INTO ' . $table
                . ' (scopeKey, windowStartedAt, uploadCount, lastUploadAt)'
                . ' VALUES (:scopeKey, :windowStartedAt, 0, 0)'
            );
            foreach ([$globalScope, $connectionScope] as $scope) {
                $insert->execute([
                    ':scopeKey' => $scope,
                    ':windowStartedAt' => $now,
                ]);
            }

            $scopes = [$globalScope, $connectionScope];
            sort($scopes, SORT_STRING);
            $select = $this->db->prepare(
                'SELECT scopeKey, windowStartedAt, uploadCount, lastUploadAt'
                . ' FROM ' . $table . ' WHERE scopeKey = :scopeKey FOR UPDATE'
            );
            $state = [];
            foreach ($scopes as $scope) {
                $select->execute([':scopeKey' => $scope]);
                $row = $select->fetch();
                if (!is_array($row)) {
                    throw new RuntimeException('Unable to initialize media upload protection.');
                }
                $state[$scope] = $row;
            }

            $windowSeconds = 3600;
            $connectionRow = $this->normalizedWindow($state[$connectionScope], $now, $windowSeconds);
            $globalRow = $this->normalizedWindow($state[$globalScope], $now, $windowSeconds);

            $cooldown = $this->policy->uploadCooldownSeconds();
            if ($cooldown > 0 && (int) $connectionRow['lastUploadAt'] > 0) {
                $remaining = $cooldown - ($now - (int) $connectionRow['lastUploadAt']);
                if ($remaining > 0) {
                    throw new RuntimeException('Upload temporarily unavailable. Try again later.');
                }
            }

            $connectionLimit = $this->policy->uploadsPerHourPerIp();
            if ($connectionLimit > 0 && (int) $connectionRow['uploadCount'] >= $connectionLimit) {
                throw new RuntimeException('Upload temporarily unavailable. Try again later.');
            }

            $globalLimit = $this->policy->globalUploadsPerHour();
            if ($globalLimit > 0 && (int) $globalRow['uploadCount'] >= $globalLimit) {
                throw new RuntimeException('Upload temporarily unavailable. Try again later.');
            }

            $update = $this->db->prepare(
                'UPDATE ' . $table
                . ' SET windowStartedAt = :windowStartedAt, uploadCount = :uploadCount, lastUploadAt = :lastUploadAt'
                . ' WHERE scopeKey = :scopeKey'
            );
            foreach ([
                $connectionScope => $connectionRow,
                $globalScope => $globalRow,
            ] as $scope => $row) {
                $update->execute([
                    ':windowStartedAt' => (int) $row['windowStartedAt'],
                    ':uploadCount' => (int) $row['uploadCount'] + 1,
                    ':lastUploadAt' => $now,
                    ':scopeKey' => $scope,
                ]);
            }

            $this->db->commit();
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function normalizedWindow(array $row, int $now, int $windowSeconds): array
    {
        $started = (int) ($row['windowStartedAt'] ?? 0);
        if ($started <= 0 || $now - $started >= $windowSeconds) {
            $row['windowStartedAt'] = $now;
            $row['uploadCount'] = 0;
        }
        return $row;
    }

    private function assertDiskReserve(string $storagePath, int $incomingBytes): void
    {
        $path = is_dir($storagePath) ? $storagePath : dirname($storagePath);
        $free = @disk_free_space($path);
        if ($free === false) {
            throw new RuntimeException('Unable to verify free disk space for media uploads.');
        }

        if ($free - $incomingBytes < $this->policy->minimumFreeBytes()) {
            throw new RuntimeException('Media uploads are temporarily unavailable.');
        }
    }
}
