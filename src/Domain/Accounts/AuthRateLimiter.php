<?php

declare(strict_types=1);

namespace NightCore\Domain\Accounts;

use NightCore\Core\SchemaInspector;
use NightCore\Core\TableNames;
use PDO;

final class AuthRateLimiter
{
    public function __construct(
        private PDO $db,
        private TableNames $tables,
        private SchemaInspector $schema,
        private int $maxAttempts,
        private int $windowSeconds
    ) {
    }

    public function available(): bool
    {
        return $this->schema->tableExists('core_auth_attempts');
    }

    public function blocked(string $ip): bool
    {
        if (!$this->available() || $this->maxAttempts <= 0) {
            return false;
        }

        $query = $this->db->prepare(
            'SELECT COUNT(*) FROM ' . $this->tables->get('core_auth_attempts') . ' WHERE ip = :ip AND attemptedAt > :cutoff'
        );
        $query->execute([':ip' => $ip, ':cutoff' => time() - $this->windowSeconds]);
        return (int) $query->fetchColumn() >= $this->maxAttempts;
    }

    public function record(string $ip, ?int $accountID): void
    {
        if (!$this->available() || $this->maxAttempts <= 0) {
            return;
        }

        $query = $this->db->prepare(
            'INSERT INTO ' . $this->tables->get('core_auth_attempts') . ' (ip, accountID, attemptedAt) VALUES (:ip, :accountID, :attemptedAt)'
        );
        $query->execute([':ip' => $ip, ':accountID' => $accountID, ':attemptedAt' => time()]);
    }

    public function clear(string $ip): void
    {
        if (!$this->available()) {
            return;
        }

        $query = $this->db->prepare('DELETE FROM ' . $this->tables->get('core_auth_attempts') . ' WHERE ip = :ip');
        $query->execute([':ip' => $ip]);
    }
}
