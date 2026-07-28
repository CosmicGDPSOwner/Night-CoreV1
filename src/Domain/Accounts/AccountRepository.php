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

    public function findByUsername(string $userName): ?array
    {
        $query = $this->db->prepare(
            'SELECT accountID, userName, password, email, isActive, gjp2 FROM ' . $this->tables->get('accounts') .
            ' WHERE LOWER(userName) = LOWER(:userName) LIMIT 1'
        );
        $query->execute([':userName' => $userName]);
        $row = $query->fetch();
        return $row === false ? null : $row;
    }

    public function findById(int $accountID): ?array
    {
        $query = $this->db->prepare(
            'SELECT accountID, userName, password, email, isActive, gjp2 FROM ' . $this->tables->get('accounts') .
            ' WHERE accountID = :accountID LIMIT 1'
        );
        $query->execute([':accountID' => $accountID]);
        $row = $query->fetch();
        return $row === false ? null : $row;
    }

    public function create(string $userName, string $passwordHash, string $email, int $isActive, string $gjp2Hash): int
    {
        $query = $this->db->prepare(
            'INSERT INTO ' . $this->tables->get('accounts') .
            ' (userName, password, email, registerDate, isActive, gjp2) VALUES (:userName, :password, :email, :registerDate, :isActive, :gjp2)'
        );
        $query->execute([
            ':userName' => $userName,
            ':password' => $passwordHash,
            ':email' => $email,
            ':registerDate' => time(),
            ':isActive' => $isActive,
            ':gjp2' => $gjp2Hash,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function updatePasswordHash(int $accountID, string $hash): void
    {
        $query = $this->db->prepare('UPDATE ' . $this->tables->get('accounts') . ' SET password = :hash WHERE accountID = :accountID');
        $query->execute([':hash' => $hash, ':accountID' => $accountID]);
    }

    public function updateGjp2Hash(int $accountID, string $hash): void
    {
        $query = $this->db->prepare('UPDATE ' . $this->tables->get('accounts') . ' SET gjp2 = :hash WHERE accountID = :accountID');
        $query->execute([':hash' => $hash, ':accountID' => $accountID]);
    }

    public function ensureUser(int $accountID, string $userName): int
    {
        $query = $this->db->prepare(
            'SELECT userID FROM ' . $this->tables->get('users') . ' WHERE extID = :accountID ORDER BY isRegistered DESC, userID ASC LIMIT 1'
        );
        $query->execute([':accountID' => (string) $accountID]);
        $userID = $query->fetchColumn();
        if ($userID !== false) {
            return (int) $userID;
        }

        $query = $this->db->prepare(
            'INSERT INTO ' . $this->tables->get('users') . ' (isRegistered, extID, userName) VALUES (1, :accountID, :userName)'
        );
        $query->execute([':accountID' => (string) $accountID, ':userName' => $userName]);
        return (int) $this->db->lastInsertId();
    }

    public function migrateLegacyUdidLevels(string $udid, int $accountID, int $userID): void
    {
        if ($udid === '' || is_numeric($udid) || !$this->schema->tableExists('levels') || !$this->schema->tableExists('users')) {
            return;
        }

        $query = $this->db->prepare(
            'SELECT userID FROM ' . $this->tables->get('users') . ' WHERE extID = :udid ORDER BY userID ASC LIMIT 1'
        );
        $query->execute([':udid' => $udid]);
        $oldUserID = $query->fetchColumn();
        if ($oldUserID === false || (int) $oldUserID === $userID) {
            return;
        }

        $query = $this->db->prepare(
            'UPDATE ' . $this->tables->get('levels') . ' SET userID = :userID, extID = :accountID WHERE userID = :oldUserID'
        );
        $query->execute([
            ':userID' => $userID,
            ':accountID' => $accountID,
            ':oldUserID' => (int) $oldUserID,
        ]);
    }
}
