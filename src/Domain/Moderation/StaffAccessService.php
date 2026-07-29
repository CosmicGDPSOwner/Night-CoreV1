<?php

declare(strict_types=1);

namespace NightCore\Domain\Moderation;

final class StaffAccessService
{
    /** @param array<int,int> $ownerAccountIDs */
    public function __construct(private StaffAccessRepository $repository, private array $ownerAccountIDs = [])
    {
    }

    public function isOwner(int $accountID): bool
    {
        return $accountID > 0 && in_array($accountID, $this->ownerAccountIDs, true);
    }

    public function has(int $accountID, string $permission): bool
    {
        return $this->isOwner($accountID) || $this->repository->hasPermission($accountID, $permission);
    }

    /** @return array<string,mixed>|null */
    public function identity(int $accountID): ?array
    {
        if ($this->isOwner($accountID)) {
            return [
                'accountID' => $accountID,
                'roleID' => 0,
                'roleName' => 'Owner',
                'priority' => PHP_INT_MAX,
                'modBadgeLevel' => 2,
                'badgeText' => 'OWNER',
                'badgeColor' => '#f59e0b',
                'commentColor' => '#fbbf24',
                'usernameColor' => '#fde68a',
                'owner' => true,
            ];
        }
        $role = $this->repository->roleForAccount($accountID);
        if ($role !== null) {
            $role['owner'] = false;
        }
        return $role;
    }

    /** @return array<int,string> */
    public function permissions(int $accountID): array
    {
        if ($this->isOwner($accountID)) {
            return ['*'];
        }
        return $this->repository->permissionsForAccount($accountID);
    }

    public function nativeBadgeLevel(int $accountID): int
    {
        $identity = $this->identity($accountID);
        return $identity === null ? 0 : max(0, min(2, (int) ($identity['modBadgeLevel'] ?? 0)));
    }

    public function nativeCommentColor(int $accountID): string
    {
        $identity = $this->identity($accountID);
        if ($identity === null || $this->nativeBadgeLevel($accountID) <= 0) {
            return '';
        }
        $hex = (string) ($identity['commentColor'] ?? '');
        if (preg_match('/^#([0-9a-fA-F]{2})([0-9a-fA-F]{2})([0-9a-fA-F]{2})$/', $hex, $match) !== 1) {
            return '255,255,255';
        }
        return hexdec($match[1]) . ',' . hexdec($match[2]) . ',' . hexdec($match[3]);
    }

    public function repository(): StaffAccessRepository
    {
        return $this->repository;
    }
}
