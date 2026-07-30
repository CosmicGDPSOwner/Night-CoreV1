<?php

declare(strict_types=1);

namespace NightCore\Security;

use NightCore\Domain\Accounts\AccountRepository;
use NightCore\Domain\Accounts\AuthRateLimiter;
use NightCore\Protocol\XorCipher;

final class AccountAuthenticator
{
    public function __construct(
        private AccountRepository $accounts,
        private AuthRateLimiter $rateLimiter,
        private PasswordService $passwords
    ) {
    }

    public function verify(int $accountID, string $gjp, string $gjp2, string $ip): bool
    {
        if ($accountID <= 0 || $this->rateLimiter->blocked($ip)) {
            return false;
        }

        $account = $this->accounts->findById($accountID);
        if ($account === null || (int) $account['isActive'] !== 1 || $this->accounts->isAccountBanned($accountID)) {
            return false;
        }

        $valid = false;
        if ($gjp2 !== '') {
            $valid = $this->passwords->verifyGjp2($gjp2, (string) $account['gjp2']);
        } elseif ($gjp !== '') {
            $decoded = XorCipher::decodeGjp($gjp);
            $valid = $decoded !== null && $this->passwords->verifyPassword($decoded, (string) $account['password']);
        }

        if (!$valid) {
            $this->rateLimiter->record($ip, $accountID);
            return false;
        }

        $this->rateLimiter->clear($ip);
        return true;
    }
}
