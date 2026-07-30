<?php

declare(strict_types=1);

use NightCore\Core\ClientIp;
use NightCore\Core\Config;
use NightCore\Core\Request;
use NightCore\Core\Response;

try {
    /** @var NightCore\Core\Application $app */
    $app = require dirname(__DIR__, 2) . '/bootstrap.php';

    $result = $app->accounts()->register(
        Request::postTrimmed('userName'),
        Request::post('password'),
        Request::postTrimmed('email'),
        ClientIp::detect(Config::getBool('TRUST_PROXY_HEADERS', false))
    );

    Response::gd((string) $result);
} catch (Throwable $e) {
    error_log('Night Core register failed: ' . $e->getMessage());
    Response::gd('-1');
}
