<?php

declare(strict_types=1);

namespace NightCore\Domain\Accounts;

use NightCore\Security\PasswordService;
use PDOException;

final class AccountService
{
    public function __construct(
        private AccountRepository $accounts,
        private AuthRateLimiter $rateLimiter,
        private PasswordService $passwords,
        private bool $preactivateAccounts,
        private bool $migrateLegacyUdidLevels
    ) {
    }

    public function register(string $userName, string $password, string $email): int
    {
        $userName = trim($userName);
        if ($userName === '' || $password === '') {
            return -1;
        }

        if (strlen($userName) > 20) {
            return -4;
        }

        if ($this->accounts->findByUsername($userName) !== null) {
            return -2;
        }

        try {
            $this->accounts->create(
                $userName,
                $this->passwords->hashPassword($password),
                trim($email),
                $this->preactivateAccounts ? 1 : 0,
                $this->passwords->hashGjp2FromPassword($password)
            );
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                return -2;
            }
            throw $e;
        }

        return 1;
    }

    public function login(
        string $userName,
        string $password,
        string $gjp2,
        string $udid,
        string $ip
    ): string {
        $account = $this->accounts->findByUsername(trim($userName));
        if ($account === null) {
            return '-1';
        }

        $accountID = (int) $account['accountID'];
        if ($this->rateLimiter->blocked($ip)) {
            return '-12';
        }

        $valid = false;
        $usedPassword = false;

        if ($password !== '') {
            $usedPassword = true;
            $valid = $this->passwords->verifyPassword($password, (string) $account['password']);
        } elseif ($gjp2 !== '') {
            $valid = $this->passwords->verifyGjp2($gjp2, (string) $account['gjp2']);
        }

        if (!$valid) {
            $this->rateLimiter->record($ip, $accountID);
            return '-1';
        }

        if ((int) $account['isActive'] !== 1) {
            return '-1';
        }

        if ($usedPassword) {
            if ($this->passwords->passwordNeedsRehash((string) $account['password'])) {
                $this->accounts->updatePasswordHash($accountID, $this->passwords->hashPassword($password));
            }
            if ((string) $account['gjp2'] === '') {
                $this->accounts->updateGjp2Hash($accountID, $this->passwords->hashGjp2FromPassword($password));
            }
        }

        $this->rateLimiter->clear($ip);
        $userID = $this->accounts->ensureUser($accountID, (string) $account['userName']);

        if ($this->migrateLegacyUdidLevels) {
            $this->accounts->migrateLegacyUdidLevels($udid, $accountID, $userID);
        }

        return $accountID . ',' . $userID;
    }
}
