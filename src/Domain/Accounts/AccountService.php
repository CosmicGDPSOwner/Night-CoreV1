<?php

declare(strict_types=1);
namespace NightCore\Domain\Accounts;

use NightCore\Core\Config;
use NightCore\Security\PasswordService;
use PDOException;

final class AccountService
{
    public function __construct(private AccountRepository $accounts, private AuthRateLimiter $rateLimiter, private PasswordService $passwords, private bool $preactivateAccounts, private bool $migrateLegacyUdidLevels) {}

    public function register(string $userName, string $password, string $email, string $ip = ''): int
    {
        $userName = trim($userName);
        $hashKey = Config::get('REGISTRATION_IP_HASH_KEY', '') ?? '';
        $maxPerIp = max(0, Config::getInt('REGISTRATION_MAX_PER_IP', 2));
        $maxPerSubnet = max(0, Config::getInt('REGISTRATION_MAX_PER_SUBNET', 10));
        $windowSeconds = max(0, Config::getInt('REGISTRATION_WINDOW_SECONDS', 86400));
        if ($this->accounts->registrationBlocked($ip, $maxPerIp, $maxPerSubnet, $windowSeconds, $hashKey)) {
            $this->accounts->recordRegistrationAttempt($ip, false, 'rate_limited', $hashKey);
            return -1;
        }
        if ($userName === '' || $password === '') {
            $this->accounts->recordRegistrationAttempt($ip, false, 'invalid_input', $hashKey);
            return -1;
        }
        if (strlen($userName) > 20) {
            $this->accounts->recordRegistrationAttempt($ip, false, 'username_length', $hashKey);
            return -4;
        }
        if ($this->accounts->findByUsername($userName) !== null) {
            $this->accounts->recordRegistrationAttempt($ip, false, 'duplicate_username', $hashKey);
            return -2;
        }
        try {
            $this->accounts->create($userName, $this->passwords->hashPassword($password), trim($email), $this->preactivateAccounts ? 1 : 0, $this->passwords->hashGjp2FromPassword($password));
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                $this->accounts->recordRegistrationAttempt($ip, false, 'duplicate_account', $hashKey);
                return -2;
            }
            throw $e;
        }
        $this->accounts->recordRegistrationAttempt($ip, true, 'registered', $hashKey);
        return 1;
    }

    public function login(string $userName, string $password, string $gjp2, string $udid, string $ip): string
    {
        $account = $this->accounts->findByUsername(trim($userName));
        if ($account === null) return '-1';
        $accountID = (int) $account['accountID'];
        if ($this->rateLimiter->blocked($ip)) return '-12';
        $valid = false; $usedPassword = false;
        if ($password !== '') { $usedPassword = true; $valid = $this->passwords->verifyPassword($password, (string) $account['password']); }
        elseif ($gjp2 !== '') { $valid = $this->passwords->verifyGjp2($gjp2, (string) $account['gjp2']); }
        if (!$valid) { $this->rateLimiter->record($ip, $accountID); return '-1'; }
        if ((int) $account['isActive'] !== 1 || $this->accounts->isAccountBanned($accountID)) return '-1';
        if ($usedPassword) {
            if ($this->passwords->passwordNeedsRehash((string) $account['password'])) $this->accounts->updatePasswordHash($accountID, $this->passwords->hashPassword($password));
            if ((string) $account['gjp2'] === '') $this->accounts->updateGjp2Hash($accountID, $this->passwords->hashGjp2FromPassword($password));
        }
        $this->rateLimiter->clear($ip);
        $this->accounts->touchActivity($accountID);
        $userID = $this->accounts->ensureUser($accountID, (string) $account['userName']);
        if ($this->migrateLegacyUdidLevels) $this->accounts->migrateLegacyUdidLevels($udid, $accountID, $userID);
        return $accountID . ',' . $userID;
    }
}
