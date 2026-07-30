<?php

declare(strict_types=1);

use NightCore\Core\AccountPolicy;
use NightCore\Web\Security\AccountStateProvider;
use NightCore\Web\Security\PanelSecurity;
use NightCore\Web\Security\SecurityHeaders;

require_once dirname(__DIR__) . '/autoload.php';

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$root = sys_get_temp_dir() . '/nightcore-web-security-' . bin2hex(random_bytes(6));
$sessionPath = $root . '/sessions';
mkdir($sessionPath, 0700, true);
ini_set('session.save_path', $sessionPath);
$_SERVER['HTTP_USER_AGENT'] = 'NightCore-Web-Security-Test/1.0';
$_SERVER['HTTPS'] = 'on';

file_put_contents(
    $root . '/config2.php',
    "<?php\nreturn [\n"
    . "    'account_deletion_enabled' => true,\n"
    . "    'session_idle_timeout_seconds' => 0,\n"
    . "    'session_absolute_timeout_seconds' => 0,\n"
    . "];\n"
);

$provider = new class implements AccountStateProvider {
    /** @var array<int,array<string,mixed>> */
    public array $accounts = [
        42 => ['accountID' => 42, 'userName' => 'SecurityTest', 'isActive' => 1],
    ];
    public bool $allowed = true;

    public function find(int $accountID): ?array
    {
        return $this->accounts[$accountID] ?? null;
    }

    public function isAllowed(int $accountID, array $account, int $now): bool
    {
        return $this->allowed && (int) ($account['isActive'] ?? 0) === 1;
    }
};

try {
    $policy = AccountPolicy::load($root);
    $security = PanelSecurity::boot(
        'nightcore_security_test',
        'security_test',
        $policy,
        $provider,
        true
    );

    $token = $security->csrfToken();
    $assert(strlen($token) >= 32, 'CSRF token has sufficient length');
    $assert(hash_equals($token, $security->csrfToken()), 'CSRF token stays stable in one session');
    $security->requireCsrf($token);

    $csrfRejected = false;
    try {
        $security->requireCsrf('wrong-token');
    } catch (RuntimeException) {
        $csrfRejected = true;
    }
    $assert($csrfRejected, 'invalid CSRF token is rejected');

    $security->signIn($provider->accounts[42], 100);
    $_SESSION['security_test_issued_at'] = 1;
    $_SESSION['security_test_last_seen'] = 1;
    $assert($security->validate(null, 500000), 'zero timeouts keep an otherwise valid session alive');
    $assert($security->accountId() === 42, 'validated session exposes account ID');
    $assert((string) ($security->account()['userName'] ?? '') === 'SecurityTest', 'validated session exposes account');

    $permissionAllowed = $security->validate(
        static fn(int $accountID, array $account, int $now): bool => $accountID === 42
            && (string) ($account['userName'] ?? '') === 'SecurityTest'
            && $now === 500000,
        500000
    );
    $assert($permissionAllowed, 'authorization callback can retain an allowed panel session');

    $permissionRejected = !$security->validate(
        static fn(int $accountID, array $account, int $now): bool => false,
        500000
    );
    $assert($permissionRejected, 'authorization callback invalidates removed panel access');

    $security->signIn($provider->accounts[42], 100);
    $_SESSION['security_test_issued_at'] = 1;
    $_SESSION['security_test_last_seen'] = 1;
    $_SESSION['security_test_fingerprint'] = str_repeat('0', 64);
    $assert(!$security->validate(null, 500001), 'browser fingerprint mismatch invalidates session');
    $assert($security->accountId() === 0, 'invalid session is cleared');

    $nonce = $security->nonce();
    $csp = SecurityHeaders::contentSecurityPolicy($nonce);
    $assert(str_contains($csp, "script-src 'self' 'nonce-" . $nonce . "'"), 'CSP authorizes only nonce script blocks');
    $assert(str_contains($csp, "script-src-attr 'none'"), 'CSP blocks inline event handlers');
    $assert(!str_contains($csp, "script-src 'self' 'unsafe-inline'"), 'CSP does not allow arbitrary inline scripts');

    foreach (['dashboard.php', 'staffAdmin.php', 'eventAdmin.php'] as $panelFile) {
        $source = file_get_contents(dirname(__DIR__) . '/public/' . $panelFile);
        $assert(is_string($source) && str_contains($source, 'PanelSecurity::boot('), $panelFile . ' uses shared PanelSecurity');
        $assert(is_string($source) && str_contains($source, '<style nonce='), $panelFile . ' applies nonce to style block');
        $assert(is_string($source) && !str_contains($source, 'onsubmit='), $panelFile . ' has no inline submit handler');
        $assert(is_string($source) && !str_contains($source, 'onclick='), $panelFile . ' has no inline click handler');
    }
} catch (Throwable $error) {
    $failures[] = 'exception: ' . $error->getMessage();
} finally {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    @unlink($root . '/config2.php');
    foreach (glob($sessionPath . '/*') ?: [] as $path) {
        @unlink($path);
    }
    @rmdir($sessionPath);
    @rmdir($root);
}

if ($failures !== []) {
    fwrite(STDERR, "Web security tests failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Web security tests passed.\n";
