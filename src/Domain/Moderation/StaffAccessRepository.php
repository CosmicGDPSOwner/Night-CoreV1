<?php

declare(strict_types=1);

namespace NightCore\Domain\Moderation;

use NightCore\Core\TableNames;
use PDO;

final class StaffAccessRepository
{
    public function __construct(private PDO $db, private TableNames $tables)
    {
    }

    /** @return array<string,mixed>|null */
    public function roleForAccount(int $accountID): ?array
    {
        $sql = 'SELECT a.accountID, r.roleID, r.name AS roleName, r.priority, r.badgeText, r.badgeColor, r.commentColor, r.usernameColor '
            . 'FROM ' . $this->tables->get('core_staff_assignments') . ' a '
            . 'INNER JOIN ' . $this->tables->get('core_staff_roles') . ' r ON r.roleID = a.roleID '
            . 'WHERE a.accountID = :accountID LIMIT 1';
        $query = $this->db->prepare($sql);
        $query->execute([':accountID' => $accountID]);
        $row = $query->fetch();
        return $row === false ? null : $row;
    }

    /** @return array<int,string> */
    public function permissionsForAccount(int $accountID): array
    {
        $sql = 'SELECT rp.permissionKey FROM ' . $this->tables->get('core_staff_assignments') . ' a '
            . 'INNER JOIN ' . $this->tables->get('core_staff_role_permissions') . ' rp ON rp.roleID = a.roleID '
            . 'WHERE a.accountID = :accountID ORDER BY rp.permissionKey';
        $query = $this->db->prepare($sql);
        $query->execute([':accountID' => $accountID]);
        return array_values(array_map('strval', $query->fetchAll(PDO::FETCH_COLUMN)));
    }

    public function hasPermission(int $accountID, string $permission): bool
    {
        $sql = 'SELECT 1 FROM ' . $this->tables->get('core_staff_assignments') . ' a '
            . 'INNER JOIN ' . $this->tables->get('core_staff_role_permissions') . ' rp ON rp.roleID = a.roleID '
            . 'WHERE a.accountID = :accountID AND rp.permissionKey = :permission LIMIT 1';
        $query = $this->db->prepare($sql);
        $query->execute([':accountID' => $accountID, ':permission' => $permission]);
        return $query->fetchColumn() !== false;
    }

    /** @return array<int,array<string,mixed>> */
    public function roles(): array
    {
        $query = $this->db->query('SELECT roleID, name, priority, badgeText, badgeColor, commentColor, usernameColor FROM ' . $this->tables->get('core_staff_roles') . ' ORDER BY priority DESC, roleID ASC');
        return $query->fetchAll() ?: [];
    }

    /** @return array<int,string> */
    public function permissionKeys(): array
    {
        $query = $this->db->query('SELECT permissionKey FROM ' . $this->tables->get('core_staff_permissions') . ' ORDER BY permissionKey');
        return array_values(array_map('strval', $query->fetchAll(PDO::FETCH_COLUMN)));
    }

    /** @return array<int,string> */
    public function permissionsForRole(int $roleID): array
    {
        $query = $this->db->prepare('SELECT permissionKey FROM ' . $this->tables->get('core_staff_role_permissions') . ' WHERE roleID = :roleID ORDER BY permissionKey');
        $query->execute([':roleID' => $roleID]);
        return array_values(array_map('strval', $query->fetchAll(PDO::FETCH_COLUMN)));
    }

    public function saveRole(?int $roleID, string $name, int $priority, string $badgeText, string $badgeColor, string $commentColor, string $usernameColor, array $permissions): int
    {
        $this->db->beginTransaction();
        try {
            $now = time();
            if ($roleID === null) {
                $insert = $this->db->prepare('INSERT INTO ' . $this->tables->get('core_staff_roles') . ' (name, priority, badgeText, badgeColor, commentColor, usernameColor, createdAt, updatedAt) VALUES (:name, :priority, :badgeText, :badgeColor, :commentColor, :usernameColor, :createdAt, :updatedAt)');
                $insert->execute(compact('name', 'priority', 'badgeText', 'badgeColor', 'commentColor', 'usernameColor') + [':createdAt' => $now, ':updatedAt' => $now]);
                $roleID = (int) $this->db->lastInsertId();
            } else {
                $update = $this->db->prepare('UPDATE ' . $this->tables->get('core_staff_roles') . ' SET name=:name, priority=:priority, badgeText=:badgeText, badgeColor=:badgeColor, commentColor=:commentColor, usernameColor=:usernameColor, updatedAt=:updatedAt WHERE roleID=:roleID');
                $update->execute([':name'=>$name, ':priority'=>$priority, ':badgeText'=>$badgeText, ':badgeColor'=>$badgeColor, ':commentColor'=>$commentColor, ':usernameColor'=>$usernameColor, ':updatedAt'=>$now, ':roleID'=>$roleID]);
            }
            $this->db->prepare('DELETE FROM ' . $this->tables->get('core_staff_role_permissions') . ' WHERE roleID = :roleID')->execute([':roleID' => $roleID]);
            $insertPermission = $this->db->prepare('INSERT INTO ' . $this->tables->get('core_staff_role_permissions') . ' (roleID, permissionKey) VALUES (:roleID, :permissionKey)');
            foreach (array_values(array_unique($permissions)) as $permission) {
                $insertPermission->execute([':roleID' => $roleID, ':permissionKey' => $permission]);
            }
            $this->db->commit();
            return $roleID;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function assignRole(int $accountID, int $roleID, int $assignedBy): void
    {
        $query = $this->db->prepare('INSERT INTO ' . $this->tables->get('core_staff_assignments') . ' (accountID, roleID, assignedBy, assignedAt) VALUES (:accountID, :roleID, :assignedBy, :assignedAt) ON DUPLICATE KEY UPDATE roleID=VALUES(roleID), assignedBy=VALUES(assignedBy), assignedAt=VALUES(assignedAt)');
        $query->execute([':accountID'=>$accountID, ':roleID'=>$roleID, ':assignedBy'=>$assignedBy, ':assignedAt'=>time()]);
    }

    public function removeAssignment(int $accountID): void
    {
        $query = $this->db->prepare('DELETE FROM ' . $this->tables->get('core_staff_assignments') . ' WHERE accountID = :accountID');
        $query->execute([':accountID' => $accountID]);
    }
}
