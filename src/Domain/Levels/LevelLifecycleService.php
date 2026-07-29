<?php

declare(strict_types=1);

namespace NightCore\Domain\Levels;

use NightCore\Security\AccountAuthenticator;
use RuntimeException;

final class LevelLifecycleService
{
    public function __construct(
        private LevelLifecycleRepository $levels,
        private LevelStorage $storage,
        private AccountAuthenticator $authenticator
    ) {
    }

    public function delete(int $accountID, string $gjp, string $gjp2, string $ip, int $levelID): string
    {
        if ($levelID <= 0 || !$this->authenticator->verify($accountID, $gjp, $gjp2, $ip)) {
            return '-1';
        }
        if (!$this->levels->deleteOwnedUnrated($levelID, $accountID)) {
            return '-1';
        }

        try {
            $this->storage->delete($levelID);
        } catch (RuntimeException $e) {
            error_log('Night Core could not remove deleted level payload ' . $levelID . ': ' . $e->getMessage());
        }
        return '1';
    }

    public function updateDescription(
        int $accountID,
        string $gjp,
        string $gjp2,
        string $ip,
        int $levelID,
        string $description
    ): string {
        if ($levelID <= 0 || !$this->authenticator->verify($accountID, $gjp, $gjp2, $ip)) {
            return '-1';
        }

        $description = preg_replace('/[^A-Za-z0-9+\/_\-=]/', '', trim($description)) ?? '';
        $description = substr($description, 0, 8192);
        return $this->levels->updateDescription($levelID, $accountID, $description) ? '1' : '-1';
    }
}
