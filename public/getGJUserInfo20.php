<?php

declare(strict_types=1);

use NightCore\Core\ClientIp;
use NightCore\Core\Config;
use NightCore\Core\Request;
use NightCore\Core\Response;

try {
    /** @var NightCore\Core\Application $app */
    $app = require dirname(__DIR__) . '/bootstrap.php';

    $result = $app->profiles()->getUserInfo(
        (int) Request::post('targetAccountID'),
        (int) Request::post('accountID'),
        Request::post('gjp'),
        Request::post('gjp2'),
        ClientIp::detect(Config::getBool('TRUST_PROXY_HEADERS', false))
    );

    Response::gd($result);
} catch (Throwable $e) {
    error_log('Night Core get user info failed: ' . $e->getMessage());
    Response::gd('-1');
}
