<?php

declare(strict_types=1);

use NightCore\Core\Config;
use NightCore\Core\Response;

try {
    /** @var NightCore\Core\Application $app */
    $app = require dirname(__DIR__) . '/bootstrap.php';

    Response::json([
        'core' => 'Night Core V1',
        'server' => $app->serverName(),
        'profile' => $app->profile(),
        'basePath' => Config::get('BASE_PATH', '/'),
    ]);
} catch (Throwable $e) {
    error_log('Night Core info failed: ' . $e->getMessage());
    Response::json(['error' => 'unavailable'], 503);
}
