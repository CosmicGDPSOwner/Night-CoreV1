<?php

declare(strict_types=1);

use NightCore\Core\Application;
use NightCore\Core\MigrationRunner;

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

$temp = tempnam(sys_get_temp_dir(), 'nightcore-mp3-');
if ($temp === false) {
    throw new RuntimeException('Unable to create custom song test file.');
}
$payload = "ID3\x04\x00\x00\x00\x00\x00\x00NIGHTCORE-CUSTOM-SONG-TEST";
file_put_contents($temp, $payload);

$result = null;
$server = null;
$pipes = [];
$log = sys_get_temp_dir() . '/nightcore-custom-song-http.log';

try {
    $result = $app->customSongs()->import(
        $temp,
        'nightcore-test.mp3',
        'Night Core Local Test',
        'Night Core',
        'http://127.0.0.1:8100'
    );
    $songID = (int) $result['songID'];
    $assert($songID >= 90000000 && $songID <= 99999999, 'local song ID range');
    $assert((int) $result['bytes'] === strlen($payload), 'stored byte count');
    $assert(is_file($app->customSongs()->storage()->path($songID)), 'stored MP3 file');

    $wire = $app->content()->song($songID);
    $assert(str_contains($wire, 'Night Core Local Test'), 'song wire metadata');
    $assert(str_contains(rawurldecode($wire), 'downloadCustomSong.php?songID=' . $songID), 'song wire local download URL');

    $descriptor = [
        0 => ['pipe', 'r'],
        1 => ['file', $log, 'a'],
        2 => ['file', $log, 'a'],
    ];
    $server = proc_open([PHP_BINARY, '-S', '127.0.0.1:8100', '-t', $root . '/public'], $descriptor, $pipes, $root);
    if (!is_resource($server)) {
        throw new RuntimeException('Unable to start custom song HTTP test server.');
    }

    $ready = false;
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $socket = @fsockopen('127.0.0.1', 8100, $errno, $error, 0.2);
        if (is_resource($socket)) {
            fclose($socket);
            $ready = true;
            break;
        }
        usleep(100000);
    }
    $assert($ready, 'custom song HTTP server readiness');

    if ($ready) {
        $full = @file_get_contents('http://127.0.0.1:8100/downloadCustomSong.php?songID=' . $songID);
        $assert($full === $payload, 'full MP3 HTTP download');

        $rangeContext = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "Range: bytes=0-2\r\nConnection: close\r\n",
                'ignore_errors' => true,
                'timeout' => 5,
            ],
        ]);
        $partial = @file_get_contents('http://127.0.0.1:8100/downloadCustomSong.php?songID=' . $songID, false, $rangeContext);
        $assert($partial === 'ID3', 'ranged MP3 HTTP download');
    }

    $assert($app->customSongs()->delete($songID), 'custom song delete');
    $assert(!is_file($app->customSongs()->storage()->path($songID)), 'custom song file delete');
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
    if (is_array($result) && isset($result['songID'])) {
        try {
            $app->customSongs()->delete((int) $result['songID']);
        } catch (Throwable) {
        }
    }
}

if ($failures !== []) {
    fwrite(STDERR, 'CUSTOM SONG TEST FAILED: ' . implode(', ', $failures) . PHP_EOL);
    if (is_file($log)) {
        fwrite(STDERR, file_get_contents($log) ?: '');
    }
    exit(1);
}

echo "Night Core custom song test: OK\n";
