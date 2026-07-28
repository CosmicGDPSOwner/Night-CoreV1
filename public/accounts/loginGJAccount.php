<?php

declare(strict_types=1);

use NightCore\Core\ClientIp;
use NightCore\Core\Config;
use NightCore\Core\Request;
use NightCore\Core\Response;

try {
    /** @var NightCore\Core\Application $app */
    $app = require dirname(__DIR__, 2) . '/bootstrap.php';

    $result = $app->accounts()->login(
        Request::postTrimmed('userName'),
        Request::post('password'),
        Request::post('gjp2'),
        Request::post('udid'),
        ClientIp::detect(Config::getBool('TRUST_PROXY_HEADERS', false))
    );

    Response::gd($result);
} catch (Throwable $e) {
    error_log('Night Core login failed: ' . $e->getMessage());
    Response::gd('-1');
}
