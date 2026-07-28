<?php

declare(strict_types=1);

use NightCore\Core\TableNames;
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

$tables = new TableNames('demo_');
if ($tables->raw('accounts') !== 'demo_accounts') {
    $failures[] = 'table prefix';
}

if ($failures !== []) {
    fwrite(STDERR, 'FAILED: ' . implode(', ', $failures) . PHP_EOL);
    exit(1);
}

echo "Night Core self-test: OK\n";
