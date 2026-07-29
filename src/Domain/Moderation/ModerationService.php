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
        private array $adminAccountIDs
    ) {
    }

    public function requestAccess(int $accountID, string $gjp, string $gjp2, string $ip): string
    {
        if (!$this->authenticator->verify($accountID, $gjp, $gjp2, $ip)) {
            return '-1';
        }
        $role = $this->role($accountID);
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
        $role = $this->role($accountID);
        if ($role === null || (int) $role['roleLevel'] <= 0) {
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
        $role = $this->role($accountID);
        if ($role === null || (int) $role['canRate'] !== 1) {
            return '-1';
        }
        $feature = (int) $role['canFeature'] === 1 ? max(0, $feature) : 0;
        $epic = (int) $role['canEpic'] === 1 ? max(0, min(3, $epic)) : 0;
        return $this->moderation->rate($levelID, $accountID, max(0, min(10, $stars)), $feature, $epic) ? '1' : '-1';
    }

    public function rateDemon(int $accountID, string $gjp, string $gjp2, string $ip, int $levelID, int $difficulty): string
    {
        if ($levelID <= 0 || !$this->authenticator->verify($accountID, $gjp, $gjp2, $ip)) {
            return '-1';
        }
        $role = $this->role($accountID);
        if ($role === null || (int) $role['canRate'] !== 1) {
            return '-1';
        }
        $allowed = [3, 4, 5, 6];
        if (!in_array($difficulty, $allowed, true)) {
            return '-1';
        }
        return $this->moderation->rateDemon($levelID, $accountID, $difficulty) ? '1' : '-1';
    }

    private function role(int $accountID): ?array
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
