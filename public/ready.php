<?php

declare(strict_types=1);

use NightCore\Core\DeploymentInspector;
use NightCore\Core\Response;

try {
    /** @var NightCore\Core\Application $app */
    $app = require dirname(__DIR__) . '/bootstrap.php';
    $checks = DeploymentInspector::inspect($app, dirname(__DIR__));

    if (!DeploymentInspector::allCriticalOk($checks)) {
        $failed = [];
        foreach ($checks as $check) {
            if ($check['critical'] && !$check['ok']) {
                $failed[] = $check['name'];
            }
        }
        error_log('Night Core readiness failed: ' . implode(', ', $failed));
        Response::text('not_ready', 503);
        return;
    }

    Response::text('ready');
} catch (Throwable $e) {
    error_log('Night Core readiness failed: ' . $e->getMessage());
    Response::text('not_ready', 503);
}
