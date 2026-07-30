<?php

declare(strict_types=1);

namespace NightCore\Web\Security;

use NightCore\Core\AccountPolicy;
use NightCore\Core\Config;
use RuntimeException;

final class PanelSecurity
{
    private string $nonce;
    private string $fingerprint;
    private int $accountID;
    /** @var array<string,mixed>|null */
    private ?array $account = null;

    private function __construct(
        private string $sessionName,
        private string $prefix,
        private AccountPolicy $policy,
        private AccountStateProvider $accounts,
        private bool $privatePage
    ) {
        self::assertIdentifier($sessionName, 'session name');
        self::assertIdentifier($prefix, 'session key prefix');

        $this->nonce = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
        $this->fingerprint = self::browserFingerprint();
        $this->startSession();
        $this->accountID = (int) ($_SESSION[$this->key('account_id')] ?? 0);
    }

    public static function boot(
        string $sessionName,
        string $prefix,
        AccountPolicy $policy,
        AccountStateProvider $accounts,
        bool $privatePage = false
    ): self {
        return new self($sessionName, $prefix, $policy, $accounts, $privatePage);
    }

    public function sendHeaders(): void
    {
        SecurityHeaders::send($this->nonce, $this->privatePage);
    }

    public function nonce(): string
    {
        return $this->nonce;
    }

    public function sessionDescription(): string
    {
        return $this->policy->sessionDescription();
    }

    public function accountId(): int
    {
        return $this->accountID;
    }

    /** @return array<string,mixed>|null */
    public function account(): ?array
    {
        return $this->account;
    }

    public function validate(?callable $authorization = null, ?int $now = null): bool
    {
        if ($this->accountID <= 0) {
            $this->account = null;
            return false;
        }

        $now ??= time();
        $issuedAt = (int) ($_SESSION[$this->key('issued_at')] ?? 0);
        $lastSeenAt = (int) ($_SESSION[$this->key('last_seen')] ?? 0);
        $storedFingerprint = (string) ($_SESSION[$this->key('fingerprint')] ?? '');
        $account = $this->accounts->find($this->accountID);

        $allowed = $account !== null
            && $this->accounts->isAllowed($this->accountID, $account, $now)
            && !$this->policy->sessionExpired($issuedAt, $lastSeenAt, $now)
            && $storedFingerprint !== ''
            && hash_equals($storedFingerprint, $this->fingerprint);

        if ($allowed && $authorization !== null) {
            $allowed = (bool) $authorization($this->accountID, $account, $now);
        }

        if (!$allowed || $account === null) {
            $this->signOut();
            return false;
        }

        $_SESSION[$this->key('last_seen')] = $now;
        $this->account = $account;
        return true;
    }

    /** @param array<string,mixed> $account */
    public function signIn(array $account, ?int $now = null): void
    {
        $accountID = (int) ($account['accountID'] ?? 0);
        if ($accountID <= 0) {
            throw new RuntimeException('Cannot create a panel session without a valid account ID.');
        }

        $now ??= time();
        session_regenerate_id(true);
        $_SESSION[$this->key('account_id')] = $accountID;
        $_SESSION[$this->key('issued_at')] = $now;
        $_SESSION[$this->key('last_seen')] = $now;
        $_SESSION[$this->key('fingerprint')] = $this->fingerprint;
        unset($_SESSION[$this->key('csrf')]);

        $this->accountID = $accountID;
        $this->account = $account;
    }

    public function signOut(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $this->accountID = 0;
        $this->account = null;
    }

    public function csrfToken(): string
    {
        $key = $this->key('csrf');
        if (!isset($_SESSION[$key]) || !is_string($_SESSION[$key]) || strlen($_SESSION[$key]) < 32) {
            $_SESSION[$key] = bin2hex(random_bytes(24));
        }
        return $_SESSION[$key];
    }

    public function requireCsrf(mixed $provided): void
    {
        $provided = is_string($provided) ? $provided : '';
        $expected = $this->csrfToken();
        if ($provided === '' || !hash_equals($expected, $provided)) {
            throw new RuntimeException('Invalid request token. Refresh the page and try again.');
        }
    }

    private function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            if (session_name() !== $this->sessionName) {
                throw new RuntimeException('A different PHP session is already active.');
            }
            return;
        }

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_samesite', 'Strict');

        session_name($this->sessionName);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => self::isHttps(),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
    }

    private function key(string $name): string
    {
        return $this->prefix . '_' . $name;
    }

    private static function browserFingerprint(): string
    {
        return hash('sha256', substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 512));
    }

    private static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        if (!Config::getBool('TRUST_PROXY_HEADERS', false)) {
            return false;
        }
        $proto = strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0] ?? ''));
        return $proto === 'https';
    }

    private static function assertIdentifier(string $value, string $label): void
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/D', $value) !== 1) {
            throw new RuntimeException('Invalid panel ' . $label . '.');
        }
    }
}
