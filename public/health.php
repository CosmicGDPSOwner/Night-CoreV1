<?php

declare(strict_types=1);

use NightCore\Core\Response;

try {
    /** @var NightCore\Core\Application $app */
    $app = require dirname(__DIR__) . '/bootstrap.php';
    $app->db()->query('SELECT 1');
    Response::text('ok');
} catch (Throwable $e) {
    error_log('Night Core health check failed: ' . $e->getMessage());
    Response::text('db_unavailable', 503);
}
