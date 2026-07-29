<?php

declare(strict_types=1);

use NightCore\Core\Application;
use NightCore\Core\Config;
use NightCore\Core\DeploymentInspector;
use NightCore\Core\MigrationRunner;

$root = dirname(__DIR__);
require_once $root . '/autoload.php';
Config::loadEnv($root . '/.env');

$expectedToken = trim(Config::get('WEB_INSTALL_TOKEN', '') ?? '');
if ($expectedToken === '') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "not_found\n";
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html><head><meta charset="utf-8"><title>Night Core installer</title></head><body>';
    echo '<h1>Night Core V1 shared-host installer</h1>';
    echo '<p>This endpoint is enabled only while WEB_INSTALL_TOKEN is configured.</p>';
    echo '<form method="post"><label>Install token <input type="password" name="token" required></label> ';
    echo '<button type="submit">Install / update</button></form>';
    echo '</body></html>';
    exit;
}

$providedToken = isset($_POST['token']) && is_string($_POST['token']) ? $_POST['token'] : '';
if (!hash_equals($expectedToken, $providedToken)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "forbidden\n";
    exit;
}

header('Content-Type: text/plain; charset=utf-8');

try {
    /** @var Application $app */
    $app = Application::boot();

    DeploymentInspector::ensureLevelStorage($root);
    echo 'Level storage: OK' . PHP_EOL;

    $mediaAdminEnabled = trim(Config::get('MEDIA_ADMIN_TOKEN', '') ?? '') !== ''
        || trim(Config::get('CUSTOM_SONG_ADMIN_TOKEN', '') ?? '') !== '';
    if ($mediaAdminEnabled) {
        DeploymentInspector::ensureCustomSongStorage($root);
        echo 'Custom song storage: OK' . PHP_EOL;
        DeploymentInspector::ensureCustomSfxStorage($root);
        echo 'Custom SFX storage: OK' . PHP_EOL;
    }

    $runner = new MigrationRunner($app->db(), $app->tables());
    $applied = $runner->migrate($root . '/migrations');
    if ($applied === []) {
        echo 'Migrations: already current' . PHP_EOL;
    } else {
        foreach ($applied as $migration) {
            echo 'Applied: ' . $migration . PHP_EOL;
        }
    }

    $checks = DeploymentInspector::inspect($app, $root);
    foreach ($checks as $check) {
        $status = $check['ok'] ? 'OK' : ($check['critical'] ? 'FAIL' : 'WARN');
        echo sprintf('[%s] %s', $status, $check['label']) . PHP_EOL;
    }

    if (!DeploymentInspector::allCriticalOk($checks)) {
        http_response_code(503);
        echo 'Installation checks: FAILED' . PHP_EOL;
        exit;
    }

    echo 'Installation checks: OK' . PHP_EOL;
    echo 'IMPORTANT: set WEB_INSTALL_TOKEN= in .env now to disable this installer.' . PHP_EOL;
} catch (Throwable $e) {
    error_log('Night Core shared-host install failed: ' . $e->getMessage());
    http_response_code(500);
    echo 'installation_failed' . PHP_EOL;
}
