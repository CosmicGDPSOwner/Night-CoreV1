<?php

declare(strict_types=1);

use NightCore\Domain\Content\ContentService;

require dirname(__DIR__) . '/src/Domain/Content/ContentService.php';

date_default_timezone_set('UTC');

$reflection = new ReflectionClass(ContentService::class);
$service = $reflection->newInstanceWithoutConstructor();
$formatter = $reflection->getMethod('formatAccountComments');
$formatter->setAccessible(true);

$timestamp = strtotime('2026-07-29 14:52:00 UTC');
if ($timestamp === false) {
    throw new RuntimeException('Unable to create deterministic timestamp.');
}

$result = [
    'rows' => [[
        'comment' => base64_encode('profile comment'),
        'userID' => 17,
        'likes' => 0,
        'isSpam' => 0,
        'createdAt' => $timestamp,
        'commentID' => 91,
        // These fields deliberately resemble a level-comment author payload.
        // Account-comment responses must not serialize any of them.
        'percent' => 73,
        'userName' => 'ShouldNotAppear',
        'icon' => 44,
        'color1' => 12,
        'color2' => 13,
        'iconType' => 2,
        'special' => 1,
        'extID' => 99,
        'accountID' => 99,
    ]],
    'total' => 1,
];

$actual = $formatter->invoke($service, $result, 0, 10);
$expected = '2~cHJvZmlsZSBjb21tZW50~3~17~4~0~5~0~7~0~9~29/07/2026 14:52~6~91#1:0:10';
if ($actual !== $expected) {
    throw new RuntimeException("Account-comment wire format mismatch.\nExpected: {$expected}\nActual:   {$actual}");
}

$empty = $formatter->invoke($service, ['rows' => [], 'total' => 0], 3, 10);
if ($empty !== '#0:0:0') {
    throw new RuntimeException('Empty account-comment response must match the GD/Cvolton contract.');
}

echo "Account comment wire-format checks passed.\n";
