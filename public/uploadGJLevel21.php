<?php

declare(strict_types=1);

use NightCore\Core\ClientIp;
use NightCore\Core\Config;
use NightCore\Core\Request;
use NightCore\Core\Response;

try {
    foreach (['gameVersion', 'levelName', 'levelString'] as $required) {
        if (!array_key_exists($required, $_POST)) {
            Response::gd('-1');
        }
    }

    $levelString = Request::post('levelString');
    if ($levelString === '' || strlen($levelString) > max(1, Config::getInt('LEVEL_MAX_BYTES', 8388608))) {
        Response::gd('-1');
    }

    /** @var NightCore\Core\Application $app */
    $app = require dirname(__DIR__) . '/bootstrap.php';

    $keys = [
        'gameVersion', 'binaryVersion', 'levelID', 'levelName', 'levelDesc', 'levelVersion', 'levelLength', 'audioTrack',
        'secret', 'auto', 'password', 'original', 'twoPlayer', 'songID', 'objects', 'coins', 'requestedStars', 'extraString',
        'levelString', 'levelInfo', 'unlisted', 'unlisted1', 'unlisted2', 'ldm', 'wt', 'wt2', 'settingsString', 'songIDs',
        'sfxIDs', 'ts',
    ];
    $input = [];
    foreach ($keys as $key) {
        $input[$key] = $key === 'levelString' ? $levelString : Request::post($key);
    }

    Response::gd($app->levels()->upload(
        (int) Request::post('accountID'),
        Request::post('gjp'),
        Request::post('gjp2'),
        ClientIp::detect(Config::getBool('TRUST_PROXY_HEADERS', false)),
        $input
    ));
} catch (Throwable $e) {
    error_log('Night Core upload level failed: ' . $e->getMessage());
    Response::gd('-1');
}
