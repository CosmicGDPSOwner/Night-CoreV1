<?php

declare(strict_types=1);

use NightCore\Core\Application;
use NightCore\Core\MigrationRunner;
use NightCore\Security\PasswordService;

$root = dirname(__DIR__);
require_once $root . '/autoload.php';

$failures = [];

$assert = static function (bool $condition, string $label) use (&$failures): void {
    if (!$condition) {
        $failures[] = $label;
    }
};

try {
    $app = Application::boot();
    $migrations = new MigrationRunner($app->db(), $app->tables());
    $migrations->migrate($root . '/migrations');

    foreach (['accounts', 'users', 'levels', 'core_auth_attempts', 'core_level_downloads'] as $table) {
        $assert($app->schema()->tableExists($table), 'missing table ' . $table);
    }

    $assert($app->accounts()->register('IntegrationUser', 'secret', 'integration@example.test') === 1, 'account registration');
    $login = $app->accounts()->login('IntegrationUser', 'secret', '', 'integration-udid', '127.0.0.1');
    $assert((bool) preg_match('/^\d+,\d+$/', $login), 'account login');

    [$accountID, $userID] = array_map('intval', explode(',', $login));
    $passwords = new PasswordService();
    $gjp2 = $passwords->gjp2FromPassword('secret');

    $profileUpdate = $app->profiles()->updateScore($accountID, '', $gjp2, '127.0.0.1', [
        'gameVersion' => '22',
        'secret' => 'integration',
        'stars' => '123',
        'demons' => '4',
        'coins' => '50',
        'icon' => '1',
        'color1' => '2',
        'color2' => '3',
        'color3' => '4',
        'iconType' => '0',
        'userCoins' => '10',
        'special' => '0',
        'accIcon' => '1',
        'accShip' => '1',
        'accBall' => '1',
        'accBird' => '1',
        'accDart' => '1',
        'accRobot' => '1',
        'accGlow' => '1',
        'accSpider' => '1',
        'accExplosion' => '1',
        'accSwing' => '1',
        'accJetpack' => '1',
        'diamonds' => '25',
        'moons' => '6',
    ]);
    $assert($profileUpdate === (string) $userID, 'profile update');

    $levelID = $app->levels()->upload($accountID, '', $gjp2, '127.0.0.1', [
        'gameVersion' => '22',
        'binaryVersion' => '40',
        'levelID' => '0',
        'levelName' => 'Integration Level',
        'levelDesc' => rtrim(strtr(base64_encode('Night Core integration'), '+/', '-_'), '='),
        'levelVersion' => '1',
        'levelLength' => '3',
        'audioTrack' => '0',
        'secret' => 'integration',
        'password' => '1234',
        'objects' => '1000',
        'coins' => '3',
        'requestedStars' => '5',
        'levelString' => 'kS1,1,1,2,2,3,3;',
        'unlisted' => '0',
        'ldm' => '0',
    ]);
    $assert(ctype_digit($levelID) && (int) $levelID > 0, 'level upload');

    $search = $app->levels()->search([
        'gameVersion' => '22',
        'binaryVersion' => '40',
        'type' => '0',
        'str' => $levelID,
        'page' => '0',
    ], $accountID, '', $gjp2, '127.0.0.1');
    $assert($search !== '-1' && str_contains($search, '1:' . $levelID . ':2:Integration Level'), 'level search');

    $download = $app->levels()->download((int) $levelID, $accountID, '', $gjp2, '127.0.0.1', [
        'gameVersion' => '22',
        'binaryVersion' => '40',
        'extras' => '1',
        'inc' => '1',
    ]);
    $assert($download !== '-1' && str_starts_with($download, '1:' . $levelID . ':2:Integration Level:'), 'level download');
    $assert(substr_count($download, '#') === 2, 'level download hashes');

    $stored = getenv('LEVEL_STORAGE_PATH');
    if ($stored !== false && $stored !== '') {
        $assert(is_file(rtrim($stored, '/\\') . DIRECTORY_SEPARATOR . $levelID), 'level file storage');
    }
} catch (Throwable $e) {
    $failures[] = 'exception: ' . $e->getMessage();
}

if ($failures !== []) {
    fwrite(STDERR, 'INTEGRATION FAILED: ' . implode(', ', $failures) . PHP_EOL);
    exit(1);
}

echo "Night Core integration test: OK\n";
