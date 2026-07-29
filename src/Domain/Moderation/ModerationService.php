<?php

declare(strict_types=1);

namespace NightCore\Domain\Moderation;

use NightCore\Security\AccountAuthenticator;

final class ModerationService
{
    /** @param array<int,int> $adminAccountIDs */
    public function __construct(
        private ModerationRepository $moderation,
        private AccountAuthenticator $authenticator,
        private array $adminAccountIDs,
        private ?StaffAccessService $staffAccess = null
    ) {
    }

    public function requestAccess(int $accountID, string $gjp, string $gjp2, string $ip): string
    {
        if (!$this->authenticator->verify($accountID, $gjp, $gjp2, $ip)) {
            return '-1';
        }
        if ($this->staffAccess !== null) {
            $hasAccess = $this->staffAccess->has($accountID, 'levels.suggest')
                || $this->staffAccess->has($accountID, 'levels.rate')
                || $this->staffAccess->has($accountID, 'levels.demon');
            if ($hasAccess) {
                return (string) max(1, $this->staffAccess->nativeBadgeLevel($accountID));
            }
        }
        $role = $this->legacyRole($accountID);
        if ($role === null || (int) $role['roleLevel'] <= 0) {
            return '-1';
        }
        return (string) min(2, (int) $role['roleLevel']);
    }

    public function suggestStars(int $accountID, string $gjp, string $gjp2, string $ip, int $levelID, int $stars, int $feature): string
    {
        if ($levelID <= 0 || !$this->authenticator->verify($accountID, $gjp, $gjp2, $ip)) {
            return '-1';
        }
        $allowed = $this->staffAccess?->has($accountID, 'levels.suggest') ?? false;
        if (!$allowed) {
            $role = $this->legacyRole($accountID);
            $allowed = $role !== null && (int) $role['roleLevel'] > 0;
        }
        if (!$allowed) {
            return '-1';
        }
        $this->moderation->suggest($levelID, $accountID, max(0, min(10, $stars)), $feature > 0 ? 1 : 0);
        return '1';
    }

    public function rateStars(int $accountID, string $gjp, string $gjp2, string $ip, int $levelID, int $stars, int $feature, int $epic): string
    {
        if ($levelID <= 0 || !$this->authenticator->verify($accountID, $gjp, $gjp2, $ip)) {
            return '-1';
        }

        $canRate = $this->staffAccess?->has($accountID, 'levels.rate') ?? false;
        $canFeature = $this->staffAccess?->has($accountID, 'levels.feature') ?? false;
        $canEpic = $this->staffAccess?->has($accountID, 'levels.epic') ?? false;
        if (!$canRate) {
            $role = $this->legacyRole($accountID);
            if ($role === null || (int) $role['canRate'] !== 1) {
                return '-1';
            }
            $canFeature = (int) $role['canFeature'] === 1;
            $canEpic = (int) $role['canEpic'] === 1;
        }

        $stars = max(0, min(10, $stars));
        [$difficulty, $auto, $demon] = $this->difficultyFromStars($stars);
        $feature = $canFeature ? max(0, $feature) : 0;
        $epic = $canEpic ? max(0, min(3, $epic)) : 0;
        return $this->moderation->rate($levelID, $accountID, $stars, $feature, $epic, $difficulty, $auto, $demon) ? '1' : '-1';
    }

    public function rateDemon(int $accountID, string $gjp, string $gjp2, string $ip, int $levelID, int $rating): string
    {
        if ($levelID <= 0 || !$this->authenticator->verify($accountID, $gjp, $gjp2, $ip)) {
            return '-1';
        }
        $allowed = ($this->staffAccess?->has($accountID, 'levels.demon') ?? false)
            || ($this->staffAccess?->has($accountID, 'levels.rate') ?? false);
        if (!$allowed) {
            $role = $this->legacyRole($accountID);
            $allowed = $role !== null && (int) $role['canRate'] === 1;
        }
        if (!$allowed) {
            return '-1';
        }
        $map = [1 => 3, 2 => 4, 3 => 0, 4 => 5, 5 => 6];
        if (!isset($map[$rating])) {
            return '-1';
        }
        return $this->moderation->rateDemon($levelID, $accountID, $map[$rating]) ? (string) $levelID : '-1';
    }

    /** @return array{0:int,1:int,2:int} */
    private function difficultyFromStars(int $stars): array
    {
        return match ($stars) {
            1 => [50, 1, 0],
            2 => [10, 0, 0],
            3 => [20, 0, 0],
            4, 5 => [30, 0, 0],
            6, 7 => [40, 0, 0],
            8, 9 => [50, 0, 0],
            10 => [50, 0, 1],
            default => [0, 0, 0],
        };
    }

    private function legacyRole(int $accountID): ?array
    {
        if (in_array($accountID, $this->adminAccountIDs, true)) {
            return [
                'accountID' => $accountID,
                'roleLevel' => 2,
                'roleName' => 'Administrator',
                'canRate' => 1,
                'canFeature' => 1,
                'canEpic' => 1,
                'canModerateComments' => 1,
                'canBan' => 1,
            ];
        }
        return $this->moderation->role($accountID);
    }
}
