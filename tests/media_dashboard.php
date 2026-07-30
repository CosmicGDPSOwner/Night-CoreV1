<?php

declare(strict_types=1);

use NightCore\Core\Application;
use NightCore\Core\MigrationRunner;
use NightCore\Core\PublicMediaUploadGuard;

$root = dirname(__DIR__);
require_once $root . '/autoload.php';

$failures = [];
$assert = static function (bool $condition, string $label) use (&$failures): void {
    if (!$condition) {
        $failures[] = $label;
    }
};

$policyPath = $root . '/config/media.php';
$policyBackup = is_file($policyPath) ? file_get_contents($policyPath) : null;
file_put_contents($policyPath, <<<'PHP'
<?php
return [
    'public_uploads' => true,
    'song_max_mib' => 10,
    'sfx_max_mib' => 3,
    'upload_cooldown_seconds' => 5,
    'uploads_per_hour_per_ip' => 2,
    'global_uploads_per_hour' => 3,
    'minimum_free_space_mib' => 1,
];
PHP
);

$app = Application::boot();
(new MigrationRunner($app->db(), $app->tables()))->migrate($root . '/migrations');
$app->customSongs()->storage()->ensure();
$app->customSfx()->storage()->ensure();

$sfxTable = $app->tables()->get('core_local_sfx');
$rateTable = $app->tables()->get('core_media_upload_rate_limits');
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
    $policy = $app->mediaPolicy();
    $assert($policy->publicUploadsEnabled(), 'public media uploads enabled by local PHP policy');
    $assert($app->customSongs()->storage()->maxBytes() === 10 * 1048576, 'private PHP song limit override');
    $assert($app->customSfx()->storage()->maxBytes() === 3 * 1048576, 'private PHP SFX limit override');
    $assert($policy->uploadCooldownSeconds() === 5, 'public upload cooldown policy');
    $assert($policy->uploadsPerHourPerIp() === 2, 'per-IP hourly upload policy');
    $assert($policy->globalUploadsPerHour() === 3, 'global hourly upload policy');

    $app->db()->exec('DELETE FROM ' . $rateTable);
    $guard = new PublicMediaUploadGuard($app->db(), $app->tables(), $policy);
    $storage = $app->customSfx()->storage()->directory();
    $guard->reserve('192.0.2.10', $storage, strlen($payload), 1000);

    $cooldownBlocked = false;
    try {
        $guard->reserve('192.0.2.10', $storage, strlen($payload), 1001);
    } catch (RuntimeException $e) {
        $cooldownBlocked = str_contains($e->getMessage(), 'wait');
    }
    $assert($cooldownBlocked, 'public upload cooldown blocks rapid repeat');

    $guard->reserve('192.0.2.10', $storage, strlen($payload), 1006);
    $hourlyBlocked = false;
    try {
        $guard->reserve('192.0.2.10', $storage, strlen($payload), 1012);
    } catch (RuntimeException $e) {
        $hourlyBlocked = str_contains($e->getMessage(), 'Hourly upload limit');
    }
    $assert($hourlyBlocked, 'per-IP hourly limit blocks excess upload');

    $guard->reserve('192.0.2.11', $storage, strlen($payload), 1012);
    $globalBlocked = false;
    try {
        $guard->reserve('192.0.2.12', $storage, strlen($payload), 1012);
    } catch (RuntimeException $e) {
        $globalBlocked = str_contains($e->getMessage(), 'temporarily busy');
    }
    $assert($globalBlocked, 'global hourly limit blocks excess upload');

    $app->db()->exec('DELETE FROM ' . $rateTable);

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
        $dashboard = @file_get_contents('http://127.0.0.1:8101/mediaAdmin.php');
        $assert(is_string($dashboard) && str_contains($dashboard, 'Public library.'), 'public media library loads without login');
        $assert(is_string($dashboard) && str_contains($dashboard, 'Account login required'), 'dashboard requires a GDPS account before upload');
        $assert(is_string($dashboard) && !str_contains($dashboard, 'name="action" value="upload_song"'), 'song upload form hidden before login');
        $assert(is_string($dashboard) && !str_contains($dashboard, 'name="action" value="upload_sfx"'), 'SFX upload form hidden before login');
        $assert(is_string($dashboard) && !str_contains($dashboard, 'Admin token'), 'dashboard has no admin token prompt');
        $assert(is_string($dashboard) && !str_contains($dashboard, 'save_limits'), 'dashboard has no public limit mutation');
        $assert(is_string($dashboard) && !str_contains($dashboard, 'delete_song'), 'dashboard has no public song deletion');
        $assert(is_string($dashboard) && !str_contains($dashboard, 'delete_sfx'), 'dashboard has no public SFX deletion');
        $assert(is_string($dashboard) && str_contains($dashboard, 'Anti-spam'), 'dashboard exposes read-only anti-spam policy');

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

    $assert($app->customSfx()->delete($sfxID), 'custom SFX delete through internal service');
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
        $app->db()->exec('DELETE FROM ' . $rateTable);
    } catch (Throwable) {
    }
    if ($policyBackup === null) {
        @unlink($policyPath);
    } else {
        file_put_contents($policyPath, $policyBackup);
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
