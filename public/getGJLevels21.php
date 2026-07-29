<?php

declare(strict_types=1);

use NightCore\Core\ClientIp;
use NightCore\Core\Config;
use NightCore\Core\Request;
use NightCore\Core\Response;

try {
    /** @var NightCore\Core\Application $app */
    $app = require dirname(__DIR__) . '/bootstrap.php';

    $keys = [
        'gameVersion', 'binaryVersion', 'type', 'str', 'page', 'diff', 'demonFilter', 'len', 'original', 'coins',
        'uncompleted', 'onlyCompleted', 'completedLevels', 'song', 'customSong', 'twoPlayer', 'star', 'noStar',
        'featured', 'epic', 'mythic', 'legendary', 'gauntlet', 'followed',
    ];
    $input = [];
    foreach ($keys as $key) {
        $input[$key] = Request::post($key);
    }

    Response::gd($app->levels()->search(
        $input,
        (int) Request::post('accountID'),
        Request::post('gjp'),
        Request::post('gjp2'),
        ClientIp::detect(Config::getBool('TRUST_PROXY_HEADERS', false))
    ));
} catch (Throwable $e) {
    error_log('Night Core get levels failed: ' . $e->getMessage());
    Response::gd('-1');
}
