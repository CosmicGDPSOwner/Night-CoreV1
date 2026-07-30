<?php

declare(strict_types=1);

namespace NightCore\Domain\Accounts;

use NightCore\Core\SchemaInspector;
use NightCore\Core\TableNames;
use PDO;

final class AccountRepository
{
    public function __construct(private PDO $db, private TableNames $tables, private SchemaInspector $schema) {}

    public function findByUsername(string $userName): ?array
    {
        $query = $this->db->prepare('SELECT accountID, userName, password, email, isActive, gjp2 FROM ' . $this->tables->get('accounts') . ' WHERE LOWER(userName) = LOWER(:userName) LIMIT 1');
        $query->execute([':userName' => $userName]);
        $row = $query->fetch();
        return $row === false ? null : $row;
    }

    public function findById(int $accountID): ?array
    {
        $query = $this->db->prepare('SELECT accountID, userName, password, email, isActive, gjp2 FROM ' . $this->tables->get('accounts') . ' WHERE accountID = :accountID LIMIT 1');
        $query->execute([':accountID' => $accountID]);
        $row = $query->fetch();
        return $row === false ? null : $row;
    }

    public function isAccountBanned(int $accountID): bool
    {
        if ($accountID <= 0 || !$this->schema->tableExists('core_user_moderation')) return false;
        $query = $this->db->prepare('SELECT accountBanned FROM ' . $this->tables->get('core_user_moderation') . ' WHERE accountID = :accountID LIMIT 1');
        $query->execute([':accountID' => $accountID]);
        return (int) ($query->fetchColumn() ?: 0) === 1;
    }

    public function create(string $userName, string $passwordHash, string $email, int $isActive, string $gjp2Hash): int
    {
        $query = $this->db->prepare('INSERT INTO ' . $this->tables->get('accounts') . ' (userName, password, email, registerDate, isActive, gjp2) VALUES (:userName, :password, :email, :registerDate, :isActive, :gjp2)');
        $query->execute([':userName'=>$userName,':password'=>$passwordHash,':email'=>$email,':registerDate'=>time(),':isActive'=>$isActive,':gjp2'=>$gjp2Hash]);
        return (int) $this->db->lastInsertId();
    }

    public function updatePasswordHash(int $accountID, string $hash): void { $q=$this->db->prepare('UPDATE '.$this->tables->get('accounts').' SET password = :hash WHERE accountID = :accountID'); $q->execute([':hash'=>$hash,':accountID'=>$accountID]); }
    public function updateGjp2Hash(int $accountID, string $hash): void { $q=$this->db->prepare('UPDATE '.$this->tables->get('accounts').' SET gjp2 = :hash WHERE accountID = :accountID'); $q->execute([':hash'=>$hash,':accountID'=>$accountID]); }

    public function ensureUser(int $accountID, string $userName): int
    {
        $q=$this->db->prepare('SELECT userID FROM '.$this->tables->get('users').' WHERE extID = :accountID ORDER BY isRegistered DESC, userID ASC LIMIT 1'); $q->execute([':accountID'=>(string)$accountID]); $id=$q->fetchColumn();
        if ($id!==false) return (int)$id;
        $q=$this->db->prepare('INSERT INTO '.$this->tables->get('users').' (isRegistered, extID, userName) VALUES (1, :accountID, :userName)'); $q->execute([':accountID'=>(string)$accountID,':userName'=>$userName]); return (int)$this->db->lastInsertId();
    }

    public function migrateLegacyUdidLevels(string $udid, int $accountID, int $userID): void
    {
        if ($udid==='' || is_numeric($udid) || !$this->schema->tableExists('levels') || !$this->schema->tableExists('users')) return;
        $q=$this->db->prepare('SELECT userID FROM '.$this->tables->get('users').' WHERE extID = :udid ORDER BY userID ASC LIMIT 1'); $q->execute([':udid'=>$udid]); $old=$q->fetchColumn();
        if ($old===false || (int)$old===$userID) return;
        $q=$this->db->prepare('UPDATE '.$this->tables->get('levels').' SET userID = :userID, extID = :accountID WHERE userID = :oldUserID'); $q->execute([':userID'=>$userID,':accountID'=>$accountID,':oldUserID'=>(int)$old]);
    }
}
