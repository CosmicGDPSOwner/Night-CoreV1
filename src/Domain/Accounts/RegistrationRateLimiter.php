<?php

declare(strict_types=1);

namespace NightCore\Domain\Accounts;

use NightCore\Core\SchemaInspector;
use NightCore\Core\TableNames;
use PDO;

final class RegistrationRateLimiter
{
    public function __construct(
        private PDO $db,
        private TableNames $tables,
        private SchemaInspector $schema,
        private int $maxPerIp,
        private int $maxPerSubnet,
        private int $windowSeconds,
        private string $hashKey
    ) {
    }

    public function blocked(string $ip): bool
    {
        if (!$this->available() || $this->windowSeconds <= 0) {
            return false;
        }

        $cutoff = time() - $this->windowSeconds;
        $table = $this->tables->get('core_registration_attempts');
        if ($this->maxPerIp > 0) {
            $query = $this->db->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE ipHash = :hash AND attemptedAt > :cutoff');
            $query->execute([':hash' => $this->hash($ip), ':cutoff' => $cutoff]);
            if ((int) $query->fetchColumn() >= $this->maxPerIp) {
                return true;
            }
        }

        if ($this->maxPerSubnet > 0) {
            $query = $this->db->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE subnetHash = :hash AND attemptedAt > :cutoff');
            $query->execute([':hash' => $this->hash($this->subnet($ip)), ':cutoff' => $cutoff]);
            if ((int) $query->fetchColumn() >= $this->maxPerSubnet) {
                return true;
            }
        }

        return false;
    }

    public function record(string $ip, bool $success, string $reason): void
    {
        if (!$this->available()) {
            return;
        }

        $query = $this->db->prepare(
            'INSERT INTO ' . $this->tables->get('core_registration_attempts') .
            ' (ipHash, subnetHash, success, reason, attemptedAt) VALUES (:ipHash, :subnetHash, :success, :reason, :attemptedAt)'
        );
        $query->execute([
            ':ipHash' => $this->hash($ip),
            ':subnetHash' => $this->hash($this->subnet($ip)),
            ':success' => $success ? 1 : 0,
            ':reason' => substr($reason, 0, 32),
            ':attemptedAt' => time(),
        ]);
    }

    private function available(): bool
    {
        return $this->schema->tableExists('core_registration_attempts');
    }

    private function hash(string $value): string
    {
        return hash_hmac('sha256', $value, $this->hashKey !== '' ? $this->hashKey : 'nightcore-registration');
    }

    private function subnet(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);
            return $parts[0] . '.' . $parts[1] . '.' . $parts[2] . '.0/24';
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $packed = inet_pton($ip);
            if ($packed !== false) {
                return bin2hex(substr($packed, 0, 8)) . '::/64';
            }
        }
        return 'unknown';
    }
}
