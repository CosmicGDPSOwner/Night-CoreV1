<?php

declare(strict_types=1);

namespace NightCore\Domain\Moderation;

use NightCore\Core\TableNames;
use PDO;
use RuntimeException;
use Throwable;

final class StaffAccessRepository
{
    public function __construct(private PDO $db, private TableNames $tables)
    {
    }

    /** @return array<string,mixed>|null */
    public function roleForAccount(int $accountID): ?array
    {
        $sql = 'SELECT a.accountID, r.roleID, r.name AS roleName, r.priority, r.modBadgeLevel, r.badgeText, r.badgeColor, r.commentColor, r.usernameColor '
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
        $query = $this->db->query('SELECT roleID, name, priority, modBadgeLevel, badgeText, badgeColor, commentColor, usernameColor FROM ' . $this->tables->get('core_staff_roles') . ' ORDER BY priority DESC, roleID ASC');
        return $query->fetchAll() ?: [];
    }

    /** @return array<string,mixed>|null */
    public function role(int $roleID): ?array
    {
        $query = $this->db->prepare('SELECT roleID, name, priority, modBadgeLevel, badgeText, badgeColor, commentColor, usernameColor FROM ' . $this->tables->get('core_staff_roles') . ' WHERE roleID = :roleID LIMIT 1');
        $query->execute([':roleID' => $roleID]);
        $row = $query->fetch();
        return $row === false ? null : $row;
    }

    /** @return array<int,array{permissionKey:string,description:string}> */
    public function permissions(): array
    {
        $query = $this->db->query('SELECT permissionKey, description FROM ' . $this->tables->get('core_staff_permissions') . ' ORDER BY permissionKey');
        return $query->fetchAll() ?: [];
    }

    /** @return array<int,string> */
    public function permissionKeys(): array
    {
        return array_map(static fn (array $row): string => (string) $row['permissionKey'], $this->permissions());
    }

    /** @return array<int,string> */
    public function permissionsForRole(int $roleID): array
    {
        $query = $this->db->prepare('SELECT permissionKey FROM ' . $this->tables->get('core_staff_role_permissions') . ' WHERE roleID = :roleID ORDER BY permissionKey');
        $query->execute([':roleID' => $roleID]);
        return array_values(array_map('strval', $query->fetchAll(PDO::FETCH_COLUMN)));
    }

    /** @return array<int,array<string,mixed>> */
    public function assignments(): array
    {
        $sql = 'SELECT a.accountID, ac.userName, a.assignedBy, a.assignedAt, r.roleID, r.name AS roleName, r.priority, r.modBadgeLevel, r.badgeText, r.badgeColor, r.commentColor, r.usernameColor '
            . 'FROM ' . $this->tables->get('core_staff_assignments') . ' a '
            . 'INNER JOIN ' . $this->tables->get('core_staff_roles') . ' r ON r.roleID = a.roleID '
            . 'LEFT JOIN ' . $this->tables->get('accounts') . ' ac ON ac.accountID = a.accountID '
            . 'ORDER BY r.priority DESC, ac.userName ASC, a.accountID ASC';
        $query = $this->db->query($sql);
        return $query->fetchAll() ?: [];
    }

    public function saveRole(?int $roleID, string $name, int $priority, int $modBadgeLevel, string $badgeText, string $badgeColor, string $commentColor, string $usernameColor, array $permissions): int
    {
        $allowedPermissions = array_flip($this->permissionKeys());
        $permissions = array_values(array_unique(array_filter(array_map('strval', $permissions), static fn (string $key): bool => isset($allowedPermissions[$key]))));
        $modBadgeLevel = max(0, min(2, $modBadgeLevel));

        $this->db->beginTransaction();
        try {
            $now = time();
            $values = [
                ':name' => $name,
                ':priority' => $priority,
                ':modBadgeLevel' => $modBadgeLevel,
                ':badgeText' => $badgeText,
                ':badgeColor' => $badgeColor,
                ':commentColor' => $commentColor,
                ':usernameColor' => $usernameColor,
                ':updatedAt' => $now,
            ];
            if ($roleID === null) {
                $insert = $this->db->prepare('INSERT INTO ' . $this->tables->get('core_staff_roles') . ' (name, priority, modBadgeLevel, badgeText, badgeColor, commentColor, usernameColor, createdAt, updatedAt) VALUES (:name, :priority, :modBadgeLevel, :badgeText, :badgeColor, :commentColor, :usernameColor, :createdAt, :updatedAt)');
                $insert->execute($values + [':createdAt' => $now]);
                $roleID = (int) $this->db->lastInsertId();
            } else {
                if ($this->role($roleID) === null) {
                    throw new RuntimeException('Unknown staff role.');
                }
                $update = $this->db->prepare('UPDATE ' . $this->tables->get('core_staff_roles') . ' SET name=:name, priority=:priority, modBadgeLevel=:modBadgeLevel, badgeText=:badgeText, badgeColor=:badgeColor, commentColor=:commentColor, usernameColor=:usernameColor, updatedAt=:updatedAt WHERE roleID=:roleID');
                $update->execute($values + [':roleID' => $roleID]);
            }

            $this->db->prepare('DELETE FROM ' . $this->tables->get('core_staff_role_permissions') . ' WHERE roleID = :roleID')->execute([':roleID' => $roleID]);
            $insertPermission = $this->db->prepare('INSERT INTO ' . $this->tables->get('core_staff_role_permissions') . ' (roleID, permissionKey) VALUES (:roleID, :permissionKey)');
            foreach ($permissions as $permission) {
                $insertPermission->execute([':roleID' => $roleID, ':permissionKey' => $permission]);
            }
            $this->db->commit();
            return $roleID;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function deleteRole(int $roleID): bool
    {
        $this->db->beginTransaction();
        try {
            $this->db->prepare('DELETE FROM ' . $this->tables->get('core_staff_assignments') . ' WHERE roleID = :roleID')->execute([':roleID' => $roleID]);
            $this->db->prepare('DELETE FROM ' . $this->tables->get('core_staff_role_permissions') . ' WHERE roleID = :roleID')->execute([':roleID' => $roleID]);
            $delete = $this->db->prepare('DELETE FROM ' . $this->tables->get('core_staff_roles') . ' WHERE roleID = :roleID');
            $delete->execute([':roleID' => $roleID]);
            $deleted = $delete->rowCount() > 0;
            $this->db->commit();
            return $deleted;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function assignRole(int $accountID, int $roleID, int $assignedBy): void
    {
        if ($accountID <= 0 || $this->role($roleID) === null) {
            throw new RuntimeException('Invalid staff assignment.');
        }
        $account = $this->db->prepare('SELECT 1 FROM ' . $this->tables->get('accounts') . ' WHERE accountID = :accountID LIMIT 1');
        $account->execute([':accountID' => $accountID]);
        if ($account->fetchColumn() === false) {
            throw new RuntimeException('Account does not exist.');
        }
        $query = $this->db->prepare('INSERT INTO ' . $this->tables->get('core_staff_assignments') . ' (accountID, roleID, assignedBy, assignedAt) VALUES (:accountID, :roleID, :assignedBy, :assignedAt) ON DUPLICATE KEY UPDATE roleID=VALUES(roleID), assignedBy=VALUES(assignedBy), assignedAt=VALUES(assignedAt)');
        $query->execute([':accountID' => $accountID, ':roleID' => $roleID, ':assignedBy' => max(0, $assignedBy), ':assignedAt' => time()]);
    }

    public function removeAssignment(int $accountID): void
    {
        $query = $this->db->prepare('DELETE FROM ' . $this->tables->get('core_staff_assignments') . ' WHERE accountID = :accountID');
        $query->execute([':accountID' => $accountID]);
    }
}
