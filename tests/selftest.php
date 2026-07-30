<?php

declare(strict_types=1);

use NightCore\Core\AccountPolicy;
use NightCore\Core\TableNames;
use NightCore\Domain\Content\NewgroundsSongParser;
use NightCore\Protocol\LevelHash;
use NightCore\Protocol\XorCipher;
use NightCore\Security\PasswordService;

require_once dirname(__DIR__) . '/autoload.php';

$failures = [];
$passwords = new PasswordService();

if ($passwords->gjp2FromPassword('test') !== '439f7de9b0c427d5e366eea6d6905db2cf3caf7e') {
    $failures[] = 'GJP2 derivation';
}

$passwordHash = $passwords->hashPassword('secret');
if (!$passwords->verifyPassword('secret', $passwordHash) || $passwords->verifyPassword('wrong', $passwordHash)) {
    $failures[] = 'password hashing';
}

$gjp2 = $passwords->gjp2FromPassword('secret');
$gjp2Hash = $passwords->hashGjp2($gjp2);
if (!$passwords->verifyGjp2($gjp2, $gjp2Hash)) {
    $failures[] = 'GJP2 hashing';
}

$plain = 'secret';
$encoded = base64_encode(XorCipher::apply($plain, '37526'));
$gdEncoded = str_replace(['/', '+'], ['_', '-'], $encoded);
if (XorCipher::decodeGjp($gdEncoded) !== $plain) {
    $failures[] = 'legacy GJP codec';
}

if (LevelHash::solo('abc') !== '61353676b92a74ba73096ad981f4fb923addffc6') {
    $failures[] = 'level solo hash';
}

if (LevelHash::multi([
    ['levelID' => 123, 'stars' => 5, 'coins' => 1],
    ['levelID' => 45, 'stars' => 10, 'coins' => 0],
]) !== 'cff48a23e85d676ca0252c40687c25b1069b7dd2') {
    $failures[] = 'level multi hash';
}

if (LevelHash::solo2('1,5,0,123,1,0,0,0') !== '0b35d193384c953d1ff985cfe319913261e4d2ee') {
    $failures[] = 'level solo2 hash';
}

$tables = new TableNames('demo_');
if ($tables->raw('accounts') !== 'demo_accounts') {
    $failures[] = 'table prefix';
}

$policyRoot = sys_get_temp_dir() . '/nightcore-account-policy-' . bin2hex(random_bytes(6));
$legacyConfig = $policyRoot . '/config';
try {
    if (!mkdir($legacyConfig, 0700, true) && !is_dir($legacyConfig)) {
        throw new RuntimeException('cannot create account policy test directory');
    }

    $defaults = AccountPolicy::load($policyRoot);
    if (!$defaults->accountDeletionEnabled()) {
        $failures[] = 'account deletion policy defaults enabled';
    }
    if ($defaults->sessionIdleTimeoutSeconds() !== 1800
        || $defaults->sessionAbsoluteTimeoutSeconds() !== 28800) {
        $failures[] = 'session policy defaults';
    }

    file_put_contents(
        $policyRoot . '/config2.php',
        "<?php\nreturn [\n"
        . "'account_deletion_enabled' => false,\n"
        . "'session_idle_timeout_seconds' => 0,\n"
        . "'session_absolute_timeout_seconds' => 0,\n"
        . "];\n"
    );
    $configured = AccountPolicy::load($policyRoot);
    if ($configured->accountDeletionEnabled()) {
        $failures[] = 'config2 can disable account deletion';
    }
    if ($configured->sessionIdleTimeoutSeconds() !== 0
        || $configured->sessionAbsoluteTimeoutSeconds() !== 0) {
        $failures[] = 'config2 accepts eternal session values';
    }
    $now = time();
    if ($configured->sessionExpired($now - 100000, $now - 100000, $now)) {
        $failures[] = 'zero timeouts keep valid session alive';
    }

    @unlink($policyRoot . '/config2.php');
    file_put_contents(
        $legacyConfig . '/account.php',
        "<?php\nreturn ['account_deletion_enabled' => false];\n"
    );
    if (AccountPolicy::load($policyRoot)->accountDeletionEnabled()) {
        $failures[] = 'legacy account policy fallback';
    }
} catch (Throwable $error) {
    $failures[] = 'account policy: ' . $error->getMessage();
} finally {
    @unlink($policyRoot . '/config2.php');
    @unlink($legacyConfig . '/account.php');
    @rmdir($legacyConfig);
    @rmdir($policyRoot);
}

$songParser = new NewgroundsSongParser();
$boomlings = $songParser->parseBoomlings(
    '1~|~576668~|~2~|~Stereo Madness Remix~|~3~|~1234~|~4~|~Test Artist~|~5~|~7.25~|~6~|~~|~10~|~https%3A%2F%2Faudio.ngfiles.com%2F576000%2F576668_test.mp3~|~7~|~~|~8~|~1',
    576668
);
if ($boomlings === null || $boomlings['songID'] !== 576668 || $boomlings['authorName'] !== 'Test Artist' || $boomlings['download'] !== 'https://audio.ngfiles.com/576000/576668_test.mp3') {
    $failures[] = 'Boomlings song parsing';
}

$newgroundsHtml = <<<'HTML'
<html><head><title>Test &amp; Song - Newgrounds.com</title></head><body>
<script>window.test={"url":"https:\/\/audio.ngfiles.com\/123000\/123456_Test-Song.mp3?f123","artist":"ExampleArtist"};</script>
<div>File Info Song 6.9 MB 3 min 2 sec</div>
</body></html>
HTML;
$newgrounds = $songParser->parseNewgroundsPage($newgroundsHtml, 123456);
if ($newgrounds === null || $newgrounds['name'] !== 'Test & Song' || $newgrounds['authorName'] !== 'ExampleArtist' || $newgrounds['size'] !== '6.90') {
    $failures[] = 'Newgrounds page parsing';
}

if ($failures !== []) {
    fwrite(STDERR, 'FAILED: ' . implode(', ', $failures) . PHP_EOL);
    exit(1);
}

echo "Night Core self-test: OK\n";
