<?php

declare(strict_types=1);

namespace NightCore\Domain\Accounts;

use NightCore\Core\SchemaInspector;
use NightCore\Core\TableNames;
use NightCore\Security\PasswordService;
use PDO;
use RuntimeException;
use Throwable;

final class SensitiveActionConfirmationService
{
    public function __construct(
        private PDO $db,
        private TableNames $tables,
        private SchemaInspector $schema,
        private AccountRepository $accounts,
        private PasswordService $passwords
    ) {
    }

    /** @return array{requirePassword:bool,updatedAt:int} */
    public function status(int $accountID): array
    {
        $this->requireTables();
        if ($accountID <= 0) {
            throw new RuntimeException('Invalid account.');
        }

        $now = time();
        $insert = $this->db->prepare(
            'INSERT IGNORE INTO ' . $this->tables->get('core_account_security_preferences')
            . ' (accountID, requireSensitivePassword, updatedAt) VALUES (:accountID, 1, :updatedAt)'
        );
        $insert->execute([':accountID' => $accountID, ':updatedAt' => $now]);

        $query = $this->db->prepare(
            'SELECT requireSensitivePassword, updatedAt FROM '
            . $this->tables->get('core_account_security_preferences')
            . ' WHERE accountID = :accountID LIMIT 1'
        );
        $query->execute([':accountID' => $accountID]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new RuntimeException('Security confirmation settings are unavailable.');
        }

        return [
            'requirePassword' => (int) $row['requireSensitivePassword'] === 1,
            'updatedAt' => (int) $row['updatedAt'],
        ];
    }

    public function requiresPassword(int $accountID): bool
    {
        if ($accountID <= 0 || !$this->tablesAvailable()) {
            return true;
        }

        try {
            return $this->status($accountID)['requirePassword'];
        } catch (Throwable) {
            // Fail closed: missing or unreadable settings must never disable re-authentication.
            return true;
        }
    }

    /** @return array{requirePassword:bool,updatedAt:int} */
    public function save(int $accountID, string $currentPassword, bool $requirePassword): array
    {
        $this->requireTables();
        $account = $this->accounts->findById($accountID);
        if ($account === null || (int) ($account['isActive'] ?? 0) !== 1) {
            throw new RuntimeException('This account cannot change security settings.');
        }
        if ($currentPassword === ''
            || !$this->passwords->verifyPassword($currentPassword, (string) $account['password'])) {
            throw new RuntimeException('Current password is incorrect.');
        }

        $before = $this->status($accountID)['requirePassword'];
        $after = $requirePassword;
        $now = time();

        $this->db->beginTransaction();
        try {
            $save = $this->db->prepare(
                'INSERT INTO ' . $this->tables->get('core_account_security_preferences')
                . ' (accountID, requireSensitivePassword, updatedAt)'
                . ' VALUES (:accountID, :required, :updatedAt)'
                . ' ON DUPLICATE KEY UPDATE requireSensitivePassword = VALUES(requireSensitivePassword),'
                . ' updatedAt = VALUES(updatedAt)'
            );
            $save->execute([
                ':accountID' => $accountID,
                ':required' => $after ? 1 : 0,
                ':updatedAt' => $now,
            ]);

            if ($before !== $after) {
                $audit = $this->db->prepare(
                    'INSERT INTO ' . $this->tables->get('core_account_security_audit')
                    . ' (accountID, requiredBefore, requiredAfter, createdAt)'
                    . ' VALUES (:accountID, :requiredBefore, :requiredAfter, :createdAt)'
                );
                $audit->execute([
                    ':accountID' => $accountID,
                    ':requiredBefore' => $before ? 1 : 0,
                    ':requiredAfter' => $after ? 1 : 0,
                    ':createdAt' => $now,
                ]);
            }

            $this->db->commit();
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }

        return $this->status($accountID);
    }

    public function verifyForAction(int $accountID, string $currentPassword): void
    {
        if (!$this->requiresPassword($accountID)) {
            return;
        }

        $account = $this->accounts->findById($accountID);
        if ($currentPassword === ''
            || $account === null
            || !$this->passwords->verifyPassword($currentPassword, (string) $account['password'])) {
            throw new RuntimeException('Current password confirmation failed.');
        }
    }

    private function requireTables(): void
    {
        if (!$this->tablesAvailable()) {
            throw new RuntimeException('Apply migration 0022 before changing security confirmation settings.');
        }
    }

    private function tablesAvailable(): bool
    {
        return $this->schema->tableExists('core_account_security_preferences')
            && $this->schema->tableExists('core_account_security_audit');
    }
}
