<?php

declare(strict_types=1);

use NightCore\Core\ClientIp;
use NightCore\Core\Config;
use NightCore\Core\Request;
use NightCore\Core\Response;

try {
    /** @var NightCore\Core\Application $app */
    $app = require dirname(__DIR__) . '/bootstrap.php';

    $levelID = (int) Request::post('levelID');
    if ($levelID <= 0) {
        Response::gd('-1');
    }

    Response::gd($app->levels()->download(
        $levelID,
        (int) Request::post('accountID'),
        Request::post('gjp'),
        Request::post('gjp2'),
        ClientIp::detect(Config::getBool('TRUST_PROXY_HEADERS', false)),
        [
            'gameVersion' => Request::post('gameVersion', '1'),
            'binaryVersion' => Request::post('binaryVersion', '0'),
            'extras' => Request::post('extras', '0'),
            'inc' => Request::post('inc', '0'),
        ]
    ));
} catch (Throwable $e) {
    error_log('Night Core download level failed: ' . $e->getMessage());
    Response::gd('-1');
}
