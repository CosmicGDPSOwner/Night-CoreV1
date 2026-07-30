<?php

declare(strict_types=1);

use NightCore\Core\ClientIp;
use NightCore\Core\Config;
use NightCore\Core\Request;
use NightCore\Core\Response;
use NightCore\Domain\Progress\EventRewardClaimService;

try {
    foreach (['userName', 'stars', 'demons', 'icon', 'color1', 'color2'] as $required) {
        if (!array_key_exists($required, $_POST)) {
            Response::gd('-1');
        }
    }

    /** @var NightCore\Core\Application $app */
    $app = require dirname(__DIR__) . '/bootstrap.php';

    $keys = [
        'gameVersion', 'secret', 'stars', 'demons', 'coins', 'icon', 'color1', 'color2', 'color3', 'iconType',
        'userCoins', 'special', 'accIcon', 'accShip', 'accBall', 'accBird', 'accDart', 'accRobot', 'accGlow',
        'accSpider', 'accExplosion', 'accSwing', 'accJetpack', 'diamonds', 'moons', 'sinfoe', 'dinfoe',
    ];
    $input = [];
    foreach ($keys as $key) {
        $input[$key] = Request::post($key);
    }

    $accountID = (int) Request::post('accountID');
    $result = $app->profiles()->updateScore(
        $accountID,
        Request::post('gjp'),
        Request::post('gjp2'),
        ClientIp::detect(Config::getBool('TRUST_PROXY_HEADERS', false)),
        $input
    );

    if ($result !== '-1') {
        (new EventRewardClaimService($app->db(), $app->tables(), $app->schema()))->observe(
            $accountID,
            max(0, (int) ($input['sinfoe'] !== '' ? $input['sinfoe'] : '0')),
            max(0, (int) ($input['dinfoe'] !== '' ? $input['dinfoe'] : '0'))
        );
    }

    Response::gd($result);
} catch (Throwable $e) {
    error_log('Night Core update user score failed: ' . $e->getMessage());
    Response::gd('-1');
}
