<?php

declare(strict_types=1);

namespace NightCore\Domain\Accounts;

use NightCore\Core\SchemaInspector;
use NightCore\Core\TableNames;
use PDO;

final class AccountRepository
{
    public function __construct(
        private PDO $db,
        private TableNames $tables,
        private SchemaInspector $schema
    ) {
    }

    /** @return array<string,mixed>|null */
    public function findByUsername(string $userName): ?array
    {
        $query = $this->db->prepare(
            'SELECT accountID, userName, password, email, isActive, gjp2'
            . ' FROM ' . $this->tables->get('accounts')
            . ' WHERE LOWER(userName) = LOWER(:userName) LIMIT 1'
        );
        $query->execute([':userName' => $userName]);
        $row = $query->fetch();
        return $row === false ? null : $row;
    }

    /** @return array<string,mixed>|null */
    public function findById(int $accountID): ?array
    {
        $query = $this->db->prepare(
            'SELECT accountID, userName, password, email, isActive, gjp2'
            . ' FROM ' . $this->tables->get('accounts')
            . ' WHERE accountID = :accountID LIMIT 1'
        );
        $query->execute([':accountID' => $accountID]);
        $row = $query->fetch();
        return $row === false ? null : $row;
    }

    public function isAccountBanned(int $accountID): bool
    {
        if ($accountID <= 0 || !$this->schema->tableExists('core_user_moderation')) {
            return false;
        }
        $query = $this->db->prepare(
            'SELECT accountBanned FROM ' . $this->tables->get('core_user_moderation')
            . ' WHERE accountID = :accountID LIMIT 1'
        );
        $query->execute([':accountID' => $accountID]);
        return (int) ($query->fetchColumn() ?: 0) === 1;
    }

    public function isDeletionDue(int $accountID, ?int $now = null): bool
    {
        if ($accountID <= 0 || !$this->schema->tableExists('core_account_lifecycle')) {
            return false;
        }
        $query = $this->db->prepare(
            'SELECT deletionScheduledAt FROM ' . $this->tables->get('core_account_lifecycle')
            . ' WHERE accountID = :accountID AND softDeletedAt = 0 LIMIT 1'
        );
        $query->execute([':accountID' => $accountID]);
        $scheduledAt = (int) ($query->fetchColumn() ?: 0);
        return $scheduledAt > 0 && $scheduledAt <= ($now ?? time());
    }

    public function create(
        string $userName,
        string $passwordHash,
        string $email,
        int $isActive,
        string $gjp2Hash
    ): int {
        $now = time();
        $query = $this->db->prepare(
            'INSERT INTO ' . $this->tables->get('accounts')
            . ' (userName, password, email, registerDate, isActive, gjp2)'
            . ' VALUES (:userName, :password, :email, :registerDate, :isActive, :gjp2)'
        );
        $query->execute([
            ':userName' => $userName,
            ':password' => $passwordHash,
            ':email' => $email,
            ':registerDate' => $now,
            ':isActive' => $isActive,
            ':gjp2' => $gjp2Hash,
        ]);
        $accountID = (int) $this->db->lastInsertId();
        $this->touchActivity($accountID, $now);
        return $accountID;
    }

    public function registrationBlocked(
        string $ip,
        int $maxPerIp,
        int $maxPerSubnet,
        int $windowSeconds,
        string $hashKey
    ): bool {
        if (!$this->schema->tableExists('core_registration_attempts') || $windowSeconds <= 0) {
            return false;
        }

        $cutoff = time() - $windowSeconds;
        $table = $this->tables->get('core_registration_attempts');
        if ($maxPerIp > 0) {
            $query = $this->db->prepare(
                'SELECT COUNT(*) FROM ' . $table
                . ' WHERE ipHash = :ipHash AND attemptedAt >= :cutoff'
            );
            $query->execute([
                ':ipHash' => $this->registrationHash($ip, $hashKey),
                ':cutoff' => $cutoff,
            ]);
            if ((int) $query->fetchColumn() >= $maxPerIp) {
                return true;
            }
        }

        if ($maxPerSubnet > 0) {
            $query = $this->db->prepare(
                'SELECT COUNT(*) FROM ' . $table
                . ' WHERE subnetHash = :subnetHash AND attemptedAt >= :cutoff'
            );
            $query->execute([
                ':subnetHash' => $this->registrationHash($this->registrationSubnet($ip), $hashKey),
                ':cutoff' => $cutoff,
            ]);
            if ((int) $query->fetchColumn() >= $maxPerSubnet) {
                return true;
            }
        }

        return false;
    }

    public function recordRegistrationAttempt(
        string $ip,
        bool $success,
        string $reason,
        string $hashKey
    ): void {
        if (!$this->schema->tableExists('core_registration_attempts')) {
            return;
        }

        $now = time();
        $query = $this->db->prepare(
            'INSERT INTO ' . $this->tables->get('core_registration_attempts')
            . ' (ipHash, subnetHash, success, reason, attemptedAt)'
            . ' VALUES (:ipHash, :subnetHash, :success, :reason, :attemptedAt)'
        );
        $query->execute([
            ':ipHash' => $this->registrationHash($ip, $hashKey),
            ':subnetHash' => $this->registrationHash($this->registrationSubnet($ip), $hashKey),
            ':success' => $success ? 1 : 0,
            ':reason' => substr($reason, 0, 32),
            ':attemptedAt' => $now,
        ]);

        $cleanup = $this->db->prepare(
            'DELETE FROM ' . $this->tables->get('core_registration_attempts')
            . ' WHERE attemptedAt < :before'
        );
        $cleanup->execute([':before' => $now - 2592000]);
    }

    public function touchActivity(int $accountID, ?int $at = null): void
    {
        if ($accountID <= 0 || !$this->schema->tableExists('core_account_lifecycle')) {
            return;
        }
        $at ??= time();
        $query = $this->db->prepare(
            'INSERT INTO ' . $this->tables->get('core_account_lifecycle')
            . ' (accountID, lastActiveAt, retentionDays, deletionScheduledAt, softDeletedAt, updatedAt)'
            . ' VALUES (:accountID, :lastActiveAt, 14, 0, 0, :updatedAt)'
            . ' ON DUPLICATE KEY UPDATE lastActiveAt = VALUES(lastActiveAt), updatedAt = VALUES(updatedAt)'
        );
        $query->execute([
            ':accountID' => $accountID,
            ':lastActiveAt' => $at,
            ':updatedAt' => $at,
        ]);
    }

    public function updatePasswordHash(int $accountID, string $hash): void
    {
        $query = $this->db->prepare(
            'UPDATE ' . $this->tables->get('accounts')
            . ' SET password = :hash WHERE accountID = :accountID'
        );
        $query->execute([':hash' => $hash, ':accountID' => $accountID]);
    }

    public function updateGjp2Hash(int $accountID, string $hash): void
    {
        $query = $this->db->prepare(
            'UPDATE ' . $this->tables->get('accounts')
            . ' SET gjp2 = :hash WHERE accountID = :accountID'
        );
        $query->execute([':hash' => $hash, ':accountID' => $accountID]);
    }

    public function ensureUser(int $accountID, string $userName): int
    {
        $query = $this->db->prepare(
            'SELECT userID FROM ' . $this->tables->get('users')
            . ' WHERE extID = :accountID ORDER BY isRegistered DESC, userID ASC LIMIT 1'
        );
        $query->execute([':accountID' => (string) $accountID]);
        $id = $query->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }

        $query = $this->db->prepare(
            'INSERT INTO ' . $this->tables->get('users')
            . ' (isRegistered, extID, userName) VALUES (1, :accountID, :userName)'
        );
        $query->execute([
            ':accountID' => (string) $accountID,
            ':userName' => $userName,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function migrateLegacyUdidLevels(string $udid, int $accountID, int $userID): void
    {
        if ($udid === ''
            || is_numeric($udid)
            || !$this->schema->tableExists('levels')
            || !$this->schema->tableExists('users')) {
            return;
        }
        $query = $this->db->prepare(
            'SELECT userID FROM ' . $this->tables->get('users')
            . ' WHERE extID = :udid ORDER BY userID ASC LIMIT 1'
        );
        $query->execute([':udid' => $udid]);
        $old = $query->fetchColumn();
        if ($old === false || (int) $old === $userID) {
            return;
        }

        $query = $this->db->prepare(
            'UPDATE ' . $this->tables->get('levels')
            . ' SET userID = :userID, extID = :accountID WHERE userID = :oldUserID'
        );
        $query->execute([
            ':userID' => $userID,
            ':accountID' => $accountID,
            ':oldUserID' => (int) $old,
        ]);
    }

    private function registrationHash(string $value, string $key): string
    {
        return hash_hmac('sha256', $value, $key);
    }

    private function registrationSubnet(string $ip): string
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
