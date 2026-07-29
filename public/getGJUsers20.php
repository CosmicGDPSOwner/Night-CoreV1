<?php

declare(strict_types=1);

use NightCore\Core\Request;
use NightCore\Core\Response;

try {
    /** @var NightCore\Core\Application $app */
    $app = require dirname(__DIR__) . '/bootstrap.php';
    Response::gd($app->profiles()->searchUsers(Request::post('str'), (int) Request::post('page')));
} catch (Throwable $e) {
    error_log('Night Core user search failed: ' . $e->getMessage());
    Response::gd('-1');
}
