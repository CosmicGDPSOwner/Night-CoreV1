<?php

declare(strict_types=1);

use NightCore\Core\ClientIp;
use NightCore\Core\Config;
use NightCore\Core\Request;
use NightCore\Core\Response;

try {
    /** @var NightCore\Core\Application $app */
    $app = require dirname(__DIR__) . '/bootstrap.php';

    $keys = ['mS', 'frS', 'cS', 'yt', 'twitter', 'twitch', 'discord', 'instagram', 'tiktok', 'custom'];
    $input = [];
    foreach ($keys as $key) {
        $input[$key] = Request::post($key);
    }

    $result = $app->profiles()->updateAccountSettings(
        (int) Request::post('accountID'),
        Request::post('gjp'),
        Request::post('gjp2'),
        ClientIp::detect(Config::getBool('TRUST_PROXY_HEADERS', false)),
        $input
    );

    Response::gd($result);
} catch (Throwable $e) {
    error_log('Night Core update account settings failed: ' . $e->getMessage());
    Response::gd('-1');
}
