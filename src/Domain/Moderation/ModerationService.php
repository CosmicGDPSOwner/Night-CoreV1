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
        if (!$this->authenticator->verify($accountID, $gjp, $gjp2, $ip)) return '-1';
        if ($this->staffAccess !== null && ($this->staffAccess->has($accountID, 'levels.suggest') || $this->staffAccess->has($accountID, 'levels.rate') || $this->staffAccess->has($accountID, 'levels.demon'))) {
            return (string) max(1, $this->staffAccess->nativeBadgeLevel($accountID));
        }
        $role = $this->legacyRole($accountID);
        return $role !== null && (int) $role['roleLevel'] > 0 ? (string) min(2, (int) $role['roleLevel']) : '-1';
    }

    public function suggestStars(int $accountID, string $gjp, string $gjp2, string $ip, int $levelID, int $stars, int $feature): string
    {
        if ($levelID <= 0 || !$this->authenticator->verify($accountID, $gjp, $gjp2, $ip)) return '-1';

        // Some Geometry Dash clients use suggestGJStars20.php even for accounts
        // that are allowed to rate. Preserve ordinary suggestions for suggest-only
        // staff, but promote this request to a real rate when the account has
        // levels.rate (or legacy canRate). This keeps the stock moderator panel
        // functional without requiring a modified client.
        if ($this->has($accountID, 'levels.rate', 'canRate')) {
            $stars = max(0, min(10, $stars));
            [$difficulty, $auto, $demon] = $this->difficultyFromStars($stars);
            $ratedFeature = $this->has($accountID, 'levels.feature', 'canFeature') && $feature > 0 ? 1 : 0;
            return $this->moderation->rate(
                $levelID,
                $accountID,
                $stars,
                $ratedFeature,
                0,
                $difficulty,
                $auto,
                $demon
            ) ? '1' : '-1';
        }

        if (!$this->has($accountID, 'levels.suggest', 'roleLevel')) return '-1';
        $this->moderation->suggest($levelID, $accountID, max(0, min(10, $stars)), $feature > 0 ? 1 : 0);
        return '1';
    }

    public function rateStars(int $accountID, string $gjp, string $gjp2, string $ip, int $levelID, int $stars, int $feature, int $epic): string
    {
        if ($levelID <= 0 || !$this->authenticator->verify($accountID, $gjp, $gjp2, $ip)) return '-1';
        if (!$this->has($accountID, 'levels.rate', 'canRate')) return '-1';
        $canFeature = $this->has($accountID, 'levels.feature', 'canFeature');
        $canEpic = $this->has($accountID, 'levels.epic', 'canEpic');
        $stars = max(0, min(10, $stars));
        [$difficulty, $auto, $demon] = $this->difficultyFromStars($stars);
        return $this->moderation->rate($levelID, $accountID, $stars, $canFeature ? max(0, $feature) : 0, $canEpic ? max(0, min(3, $epic)) : 0, $difficulty, $auto, $demon) ? '1' : '-1';
    }

    public function rateTier(int $accountID, string $gjp, string $gjp2, string $ip, int $levelID, int $stars, int $feature, int $epic): string
    {
        if ($levelID <= 0 || $stars < 0 || $stars > 10 || !$this->authenticator->verify($accountID, $gjp, $gjp2, $ip)) return '-1';
        if (!$this->has($accountID, 'levels.rate', 'canRate')) return '-1';
        if ($feature > 0 && !$this->has($accountID, 'levels.feature', 'canFeature')) return '-1';
        if ($epic > 0 && !$this->has($accountID, 'levels.epic', 'canEpic')) return '-1';
        [$difficulty, $auto, $demon] = $this->difficultyFromStars($stars);
        return $this->moderation->rate($levelID, $accountID, $stars, $feature, $epic, $difficulty, $auto, $demon) ? '1' : '-1';
    }

    public function rateDemon(int $accountID, string $gjp, string $gjp2, string $ip, int $levelID, int $rating): string
    {
        if ($levelID <= 0 || !$this->authenticator->verify($accountID, $gjp, $gjp2, $ip)) return '-1';
        if (!$this->has($accountID, 'levels.demon', 'canRate') && !$this->has($accountID, 'levels.rate', 'canRate')) return '-1';
        $map = [1 => 3, 2 => 4, 3 => 0, 4 => 5, 5 => 6];
        if (!isset($map[$rating])) return '-1';
        return $this->moderation->rateDemon($levelID, $accountID, $map[$rating]) ? (string) $levelID : '-1';
    }

    public function setAccountBan(int $accountID, string $gjp, string $gjp2, string $ip, string $userName, bool $banned): string
    {
        if (!$this->authenticator->verify($accountID, $gjp, $gjp2, $ip) || !$this->has($accountID, 'users.ban', 'canBan')) return '-1';
        $target = $this->moderation->accountIdByUsername($userName);
        if ($target === null || $target === $accountID || in_array($target, $this->adminAccountIDs, true)) return '-1';
        return $this->moderation->setAccountBan($target, $accountID, $banned) ? '1' : '-1';
    }

    public function setLeaderboardBan(int $accountID, string $gjp, string $gjp2, string $ip, string $userName, bool $banned): string
    {
        if (!$this->authenticator->verify($accountID, $gjp, $gjp2, $ip) || !$this->has($accountID, 'users.leaderboard_ban', 'canBan')) return '-1';
        $target = $this->moderation->accountIdByUsername($userName);
        if ($target === null || $target === $accountID || in_array($target, $this->adminAccountIDs, true)) return '-1';
        return $this->moderation->setLeaderboardBan($target, $accountID, $banned) ? '1' : '-1';
    }

    private function has(int $accountID, string $permission, string $legacyField): bool
    {
        if ($this->staffAccess?->has($accountID, $permission) ?? false) return true;
        $role = $this->legacyRole($accountID);
        if ($role === null) return false;
        if ($legacyField === 'roleLevel') return (int) ($role['roleLevel'] ?? 0) > 0;
        return (int) ($role[$legacyField] ?? 0) === 1;
    }

    /** @return array{0:int,1:int,2:int} */
    private function difficultyFromStars(int $stars): array
    {
        return match ($stars) {
            1 => [50, 1, 0], 2 => [10, 0, 0], 3 => [20, 0, 0], 4, 5 => [30, 0, 0], 6, 7 => [40, 0, 0], 8, 9 => [50, 0, 0], 10 => [50, 0, 1], default => [0, 0, 0],
        };
    }

    private function legacyRole(int $accountID): ?array
    {
        if (in_array($accountID, $this->adminAccountIDs, true)) {
            return ['accountID'=>$accountID,'roleLevel'=>2,'roleName'=>'Administrator','canRate'=>1,'canFeature'=>1,'canEpic'=>1,'canModerateComments'=>1,'canBan'=>1];
        }
        return $this->moderation->role($accountID);
    }
}
