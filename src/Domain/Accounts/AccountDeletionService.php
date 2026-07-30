<?php

declare(strict_types=1);

namespace NightCore\Domain\Accounts;

use NightCore\Core\Config;
use NightCore\Core\SchemaInspector;
use NightCore\Core\TableNames;
use NightCore\Security\PasswordService;
use PDO;
use RuntimeException;
use Throwable;

final class AccountDeletionService
{
    /** @var array<int,int> */
    private const RETENTION_OPTIONS = [7, 14, 30, 60, 90];

    public function __construct(
        private PDO $db,
        private TableNames $tables,
        private SchemaInspector $schema,
        private AccountRepository $accounts,
        private PasswordService $passwords
    ) {
    }

    /** @return array<int,int> */
    public function retentionOptions(): array
    {
        return self::RETENTION_OPTIONS;
    }

    /** @return array<string,int> */
    public function status(int $accountID): array
    {
        $this->requireTables();
        $this->ensureLifecycle($accountID);

        $query = $this->db->prepare(
            'SELECT lastActiveAt, retentionDays, deletionScheduledAt, softDeletedAt, updatedAt'
            . ' FROM ' . $this->tables->get('core_account_lifecycle')
            . ' WHERE accountID = :accountID LIMIT 1'
        );
        $query->execute([':accountID' => $accountID]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new RuntimeException('Account lifecycle state is unavailable.');
        }

        return [
            'lastActiveAt' => (int) $row['lastActiveAt'],
            'retentionDays' => (int) $row['retentionDays'],
            'deletionScheduledAt' => (int) $row['deletionScheduledAt'],
            'softDeletedAt' => (int) $row['softDeletedAt'],
            'updatedAt' => (int) $row['updatedAt'],
        ];
    }

    /** @return array<string,int> */
    public function schedule(
        int $accountID,
        string $currentPassword,
        string $confirmedUsername,
        int $retentionDays
    ): array {
        $this->requireTables();
        if (!in_array($retentionDays, self::RETENTION_OPTIONS, true)) {
            throw new RuntimeException('Choose one of the available deletion periods.');
        }
        if ($this->isBootstrapAdmin($accountID)) {
            throw new RuntimeException('Bootstrap administrator accounts cannot be deleted from the public dashboard.');
        }

        $account = $this->accounts->findById($accountID);
        if ($account === null || (int) ($account['isActive'] ?? 0) !== 1) {
            throw new RuntimeException('This account cannot be scheduled for deletion.');
        }
        if ($currentPassword === ''
            || !$this->passwords->verifyPassword($currentPassword, (string) $account['password'])) {
            throw new RuntimeException('Current password is incorrect.');
        }
        if (!hash_equals((string) $account['userName'], trim($confirmedUsername))) {
            throw new RuntimeException('Type the account username exactly to confirm deletion.');
        }

        $now = time();
        $scheduledAt = $now + ($retentionDays * 86400);
        $this->db->beginTransaction();
        try {
            $query = $this->db->prepare(
                'INSERT INTO ' . $this->tables->get('core_account_lifecycle')
                . ' (accountID, lastActiveAt, retentionDays, deletionScheduledAt, softDeletedAt, updatedAt)'
                . ' VALUES (:accountID, :lastActiveAt, :retentionDays, :deletionScheduledAt, 0, :updatedAt)'
                . ' ON DUPLICATE KEY UPDATE retentionDays = VALUES(retentionDays),'
                . ' deletionScheduledAt = VALUES(deletionScheduledAt), softDeletedAt = 0, updatedAt = VALUES(updatedAt)'
            );
            $query->execute([
                ':accountID' => $accountID,
                ':lastActiveAt' => $now,
                ':retentionDays' => $retentionDays,
                ':deletionScheduledAt' => $scheduledAt,
                ':updatedAt' => $now,
            ]);
            $this->audit($accountID, 'scheduled', $retentionDays, $scheduledAt, $now);
            $this->db->commit();
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }

        return $this->status($accountID);
    }

    public function cancel(int $accountID): void
    {
        $this->requireTables();
        $now = time();
        $this->db->beginTransaction();
        try {
            $query = $this->db->prepare(
                'UPDATE ' . $this->tables->get('core_account_lifecycle')
                . ' SET deletionScheduledAt = 0, updatedAt = :updatedAt'
                . ' WHERE accountID = :accountID AND softDeletedAt = 0'
            );
            $query->execute([
                ':updatedAt' => $now,
                ':accountID' => $accountID,
            ]);
            $this->audit($accountID, 'cancelled', 0, 0, $now);
            $this->db->commit();
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    public function touchActivity(int $accountID): void
    {
        if ($accountID <= 0 || !$this->schema->tableExists('core_account_lifecycle')) {
            return;
        }
        $now = time();
        $query = $this->db->prepare(
            'INSERT INTO ' . $this->tables->get('core_account_lifecycle')
            . ' (accountID, lastActiveAt, retentionDays, deletionScheduledAt, softDeletedAt, updatedAt)'
            . ' VALUES (:accountID, :lastActiveAt, 14, 0, 0, :updatedAt)'
            . ' ON DUPLICATE KEY UPDATE lastActiveAt = VALUES(lastActiveAt), updatedAt = VALUES(updatedAt)'
        );
        $query->execute([
            ':accountID' => $accountID,
            ':lastActiveAt' => $now,
            ':updatedAt' => $now,
        ]);
    }

    public function purgeDue(int $limit = 100): int
    {
        $this->requireTables();
        $limit = max(1, min(1000, $limit));
        $query = $this->db->prepare(
            'SELECT accountID FROM ' . $this->tables->get('core_account_lifecycle')
            . ' WHERE deletionScheduledAt > 0 AND deletionScheduledAt <= :now AND softDeletedAt = 0'
            . ' ORDER BY deletionScheduledAt ASC LIMIT ' . $limit
        );
        $query->execute([':now' => time()]);

        $deleted = 0;
        foreach ($query->fetchAll(PDO::FETCH_COLUMN) as $accountID) {
            if ($this->anonymizeDueAccount((int) $accountID)) {
                $deleted++;
            }
        }
        return $deleted;
    }

    private function anonymizeDueAccount(int $accountID): bool
    {
        $now = time();
        $this->db->beginTransaction();
        try {
            $stateQuery = $this->db->prepare(
                'SELECT deletionScheduledAt, softDeletedAt FROM ' . $this->tables->get('core_account_lifecycle')
                . ' WHERE accountID = :accountID LIMIT 1 FOR UPDATE'
            );
            $stateQuery->execute([':accountID' => $accountID]);
            $state = $stateQuery->fetch(PDO::FETCH_ASSOC);
            if ($state === false
                || (int) $state['softDeletedAt'] > 0
                || (int) $state['deletionScheduledAt'] <= 0
                || (int) $state['deletionScheduledAt'] > $now) {
                $this->db->rollBack();
                return false;
            }

            $accountQuery = $this->db->prepare(
                'SELECT accountID FROM ' . $this->tables->get('accounts')
                . ' WHERE accountID = :accountID LIMIT 1 FOR UPDATE'
            );
            $accountQuery->execute([':accountID' => $accountID]);
            if ($accountQuery->fetchColumn() === false) {
                $this->markDeleted($accountID, $now);
                $this->db->commit();
                return true;
            }

            $anonymousName = substr('deleted_' . $accountID . '_' . bin2hex(random_bytes(4)), 0, 20);
            $passwordHash = $this->passwords->hashPassword(bin2hex(random_bytes(32)));
            $gjp2Hash = $this->passwords->hashGjp2(bin2hex(random_bytes(20)));
            $update = $this->db->prepare(
                'UPDATE ' . $this->tables->get('accounts')
                . ' SET userName = :userName, password = :password, email = :email, isActive = 0, gjp2 = :gjp2'
                . ' WHERE accountID = :accountID'
            );
            $update->execute([
                ':userName' => $anonymousName,
                ':password' => $passwordHash,
                ':email' => '',
                ':gjp2' => $gjp2Hash,
                ':accountID' => $accountID,
            ]);

            if ($this->schema->tableExists('users')) {
                $users = $this->db->prepare(
                    'UPDATE ' . $this->tables->get('users')
                    . ' SET userName = :userName WHERE extID = :accountID AND isRegistered = 1'
                );
                $users->execute([
                    ':userName' => 'Deleted User',
                    ':accountID' => (string) $accountID,
                ]);
            }
            if ($this->schema->tableExists('core_media_login_attempts')) {
                $loginAttempts = $this->db->prepare(
                    'DELETE FROM ' . $this->tables->get('core_media_login_attempts') . ' WHERE accountID = :accountID'
                );
                $loginAttempts->execute([':accountID' => $accountID]);
            }
            if ($this->schema->tableExists('core_media_upload_audit')) {
                $uploadAudit = $this->db->prepare(
                    'UPDATE ' . $this->tables->get('core_media_upload_audit')
                    . " SET originalName = '' WHERE accountID = :accountID"
                );
                $uploadAudit->execute([':accountID' => $accountID]);
            }

            $this->markDeleted($accountID, $now);
            $this->audit($accountID, 'anonymized', 0, 0, $now);
            $this->db->commit();
            return true;
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    private function markDeleted(int $accountID, int $now): void
    {
        $query = $this->db->prepare(
            'UPDATE ' . $this->tables->get('core_account_lifecycle')
            . ' SET deletionScheduledAt = 0, softDeletedAt = :softDeletedAt, updatedAt = :updatedAt'
            . ' WHERE accountID = :accountID'
        );
        $query->execute([
            ':softDeletedAt' => $now,
            ':updatedAt' => $now,
            ':accountID' => $accountID,
        ]);
    }

    private function ensureLifecycle(int $accountID): void
    {
        if ($accountID <= 0) {
            throw new RuntimeException('Invalid account.');
        }
        $now = time();
        $query = $this->db->prepare(
            'INSERT IGNORE INTO ' . $this->tables->get('core_account_lifecycle')
            . ' (accountID, lastActiveAt, retentionDays, deletionScheduledAt, softDeletedAt, updatedAt)'
            . ' VALUES (:accountID, :lastActiveAt, 14, 0, 0, :updatedAt)'
        );
        $query->execute([
            ':accountID' => $accountID,
            ':lastActiveAt' => $now,
            ':updatedAt' => $now,
        ]);
    }

    private function audit(
        int $accountID,
        string $action,
        int $retentionDays,
        int $scheduledAt,
        int $createdAt
    ): void {
        $query = $this->db->prepare(
            'INSERT INTO ' . $this->tables->get('core_account_deletion_audit')
            . ' (accountID, action, retentionDays, scheduledAt, createdAt)'
            . ' VALUES (:accountID, :action, :retentionDays, :scheduledAt, :createdAt)'
        );
        $query->execute([
            ':accountID' => $accountID,
            ':action' => $action,
            ':retentionDays' => max(0, $retentionDays),
            ':scheduledAt' => max(0, $scheduledAt),
            ':createdAt' => $createdAt,
        ]);
    }

    private function requireTables(): void
    {
        if (!$this->schema->tableExists('core_account_lifecycle')
            || !$this->schema->tableExists('core_account_deletion_audit')) {
            throw new RuntimeException('Apply migration 0021 before using account deletion.');
        }
    }

    private function isBootstrapAdmin(int $accountID): bool
    {
        $raw = trim(Config::get('CORE_ADMIN_ACCOUNT_IDS', '') ?? '');
        if ($raw === '') {
            return false;
        }
        foreach (explode(',', $raw) as $part) {
            $part = trim($part);
            if ($part !== '' && ctype_digit($part) && (int) $part === $accountID) {
                return true;
            }
        }
        return false;
    }
}
