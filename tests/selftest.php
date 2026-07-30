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
$policyConfig = $policyRoot . '/config';
try {
    if (!mkdir($policyConfig, 0700, true) && !is_dir($policyConfig)) {
        throw new RuntimeException('cannot create account policy test directory');
    }
    if (!AccountPolicy::load($policyRoot)->accountDeletionEnabled()) {
        $failures[] = 'account deletion policy defaults enabled';
    }
    file_put_contents(
        $policyConfig . '/account.php',
        "<?php\nreturn ['account_deletion_enabled' => false];\n"
    );
    if (AccountPolicy::load($policyRoot)->accountDeletionEnabled()) {
        $failures[] = 'account deletion policy can be disabled';
    }
} catch (Throwable $error) {
    $failures[] = 'account deletion policy: ' . $error->getMessage();
} finally {
    @unlink($policyConfig . '/account.php');
    @rmdir($policyConfig);
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
