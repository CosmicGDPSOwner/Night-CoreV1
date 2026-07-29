<?php

declare(strict_types=1);

use NightCore\Core\Application;
use NightCore\Core\MigrationRunner;
use NightCore\Domain\Content\MediaSettingsRepository;

$root = dirname(__DIR__);
require_once $root . '/autoload.php';

$failures = [];
$assert = static function (bool $condition, string $label) use (&$failures): void {
    if (!$condition) {
        $failures[] = $label;
    }
};

$app = Application::boot();
(new MigrationRunner($app->db(), $app->tables()))->migrate($root . '/migrations');
$app->customSongs()->storage()->ensure();
$app->customSfx()->storage()->ensure();

$settingsTable = $app->tables()->get('core_media_settings');
$sfxTable = $app->tables()->get('core_local_sfx');
$temp = tempnam(sys_get_temp_dir(), 'nightcore-sfx-');
if ($temp === false) {
    throw new RuntimeException('Unable to create SFX test file.');
}
$payload = "OggS\x00\x02NIGHTCORE-SFX-TEST";
file_put_contents($temp, $payload);
$result = null;
$server = null;
$pipes = [];
$log = sys_get_temp_dir() . '/nightcore-custom-sfx-http.log';

try {
    $app->mediaSettings()->saveUploadLimits(10 * 1048576, 3 * 1048576);
    $assert($app->customSongs()->storage()->maxBytes() === 10 * 1048576, 'dashboard song limit override');
    $assert($app->customSfx()->storage()->maxBytes() === 3 * 1048576, 'dashboard SFX limit override');

    $result = $app->customSfx()->import(
        $temp,
        'nightcore-test.ogg',
        'Night Core SFX Test',
        'http://127.0.0.1:8101'
    );
    $sfxID = (int) $result['sfxID'];
    $assert($sfxID >= 2000000 && $sfxID <= 8999999, 'local SFX ID range');
    $assert((int) $result['bytes'] === strlen($payload), 'stored SFX byte count');
    $assert(is_file($app->customSfx()->storage()->path($sfxID)), 'stored OGG file');
    $assert(str_contains((string) $result['download'], 'downloadCustomSfx.php?sfxID=' . $sfxID), 'SFX download URL');

    $descriptor = [
        0 => ['pipe', 'r'],
        1 => ['file', $log, 'a'],
        2 => ['file', $log, 'a'],
    ];
    $server = proc_open([PHP_BINARY, '-S', '127.0.0.1:8101', '-t', $root . '/public'], $descriptor, $pipes, $root);
    if (!is_resource($server)) {
        throw new RuntimeException('Unable to start custom SFX HTTP test server.');
    }

    $ready = false;
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $socket = @fsockopen('127.0.0.1', 8101, $errno, $error, 0.2);
        if (is_resource($socket)) {
            fclose($socket);
            $ready = true;
            break;
        }
        usleep(100000);
    }
    $assert($ready, 'custom SFX HTTP server readiness');

    if ($ready) {
        $full = @file_get_contents('http://127.0.0.1:8101/downloadCustomSfx.php?sfxID=' . $sfxID);
        $assert($full === $payload, 'full OGG HTTP download');

        $rangeContext = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "Range: bytes=0-3\r\nConnection: close\r\n",
                'ignore_errors' => true,
                'timeout' => 5,
            ],
        ]);
        $partial = @file_get_contents('http://127.0.0.1:8101/downloadCustomSfx.php?sfxID=' . $sfxID, false, $rangeContext);
        $assert($partial === 'OggS', 'ranged OGG HTTP download');
    }

    $assert($app->customSfx()->delete($sfxID), 'custom SFX delete');
    $assert(!is_file($app->customSfx()->storage()->path($sfxID)), 'custom SFX file delete');
} catch (Throwable $e) {
    $failures[] = 'exception: ' . $e->getMessage();
} finally {
    @unlink($temp);
    if (is_resource($server)) {
        proc_terminate($server);
    }
    foreach ($pipes as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
    if (is_resource($server)) {
        proc_close($server);
    }
    if (is_array($result) && isset($result['sfxID'])) {
        try {
            $cleanup = $app->db()->prepare('DELETE FROM ' . $sfxTable . ' WHERE sfxID = :sfxID');
            $cleanup->execute([':sfxID' => (int) $result['sfxID']]);
        } catch (Throwable) {
        }
    }
    try {
        $cleanupSettings = $app->db()->prepare(
            'DELETE FROM ' . $settingsTable . ' WHERE settingKey IN (:songKey, :sfxKey)'
        );
        $cleanupSettings->execute([
            ':songKey' => MediaSettingsRepository::SONG_MAX_BYTES,
            ':sfxKey' => MediaSettingsRepository::SFX_MAX_BYTES,
        ]);
    } catch (Throwable) {
    }
}

if ($failures !== []) {
    fwrite(STDERR, 'MEDIA DASHBOARD TEST FAILED: ' . implode(', ', $failures) . PHP_EOL);
    if (is_file($log)) {
        fwrite(STDERR, file_get_contents($log) ?: '');
    }
    exit(1);
}

echo "Night Core media dashboard test: OK\n";
