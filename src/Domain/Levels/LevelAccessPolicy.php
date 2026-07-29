<?php

declare(strict_types=1);

namespace NightCore\Domain\Levels;

use NightCore\Domain\Social\SocialRepository;
use NightCore\Security\AccountAuthenticator;

final class LevelAccessPolicy
{
    public function __construct(
        private SocialRepository $social,
        private AccountAuthenticator $authenticator
    ) {
    }

    public function canAccessPrivate(
        int $viewerAccountID,
        int $ownerAccountID,
        string $gjp,
        string $gjp2,
        string $ip
    ): bool {
        if ($viewerAccountID <= 0 || $ownerAccountID <= 0) {
            return false;
        }
        if (!$this->authenticator->verify($viewerAccountID, $gjp, $gjp2, $ip)) {
            return false;
        }
        if ($viewerAccountID === $ownerAccountID) {
            return true;
        }
        if ($this->social->isBlockedEither($viewerAccountID, $ownerAccountID)) {
            return false;
        }
        return $this->social->areFriends($viewerAccountID, $ownerAccountID);
    }
}
