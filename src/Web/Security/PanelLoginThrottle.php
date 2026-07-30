<?php

declare(strict_types=1);

namespace NightCore\Web\Security;

use NightCore\Core\Config;
use PDO;
use RuntimeException;

final class PanelLoginThrottle
{
    private string $identifier;

    public function __construct(
        private PDO $db,
        private string $table,
        string $scope,
        string $clientAddress
    ) {
        if (preg_match('/^[A-Za-z0-9_]+$/D', $table) !== 1) {
            throw new RuntimeException('Invalid login-attempt table name.');
        }
        if (preg_match('/^[a-z][a-z0-9_-]{0,31}$/D', $scope) !== 1) {
            throw new RuntimeException('Invalid panel login scope.');
        }

        $key = trim(Config::get('PANEL_SECURITY_HASH_KEY', '') ?? '');
        if ($key === '') {
            $key = trim(Config::get('REGISTRATION_IP_HASH_KEY', '') ?? '');
        }
        $payload = $scope . "\0" . trim($clientAddress);
        $this->identifier = $key === ''
            ? hash('sha256', $payload)
            : hash_hmac('sha256', $payload, $key);
    }

    public function blocked(int $maximumFailures, int $windowSeconds, ?int $now = null): bool
    {
        if ($maximumFailures <= 0 || $windowSeconds <= 0) {
            return false;
        }
        $now ??= time();
        $query = $this->db->prepare(
            'SELECT COUNT(*) FROM ' . $this->table
            . ' WHERE ipHash = :ipHash AND success = 0 AND attemptedAt >= :since'
        );
        $query->execute([
            ':ipHash' => $this->identifier,
            ':since' => $now - $windowSeconds,
        ]);
        return (int) $query->fetchColumn() >= $maximumFailures;
    }

    public function record(int $accountID, bool $success, ?int $now = null): void
    {
        $query = $this->db->prepare(
            'INSERT INTO ' . $this->table
            . ' (ipHash, accountID, success, attemptedAt)'
            . ' VALUES (:ipHash, :accountID, :success, :attemptedAt)'
        );
        $query->execute([
            ':ipHash' => $this->identifier,
            ':accountID' => max(0, $accountID),
            ':success' => $success ? 1 : 0,
            ':attemptedAt' => $now ?? time(),
        ]);
    }

    public function identifier(): string
    {
        return $this->identifier;
    }
}
