<?php

declare(strict_types=1);

use NightCore\Core\ClientIp;
use NightCore\Core\Config;
use NightCore\Core\Request;
use NightCore\Core\Response;
use NightCore\Protocol\LevelHash;
use PDO;

try {
    /** @var NightCore\Core\Application $app */
    $app = require dirname(__DIR__) . '/bootstrap.php';

    $requestedLevelID = (int) Request::post('levelID');
    $levelID = $requestedLevelID;
    $timelyID = 0;
    $isTimely = $requestedLevelID < 0;

    if ($isTimely) {
        $slotType = match ($requestedLevelID) {
            -1 => 0,
            -2 => 1,
            -3 => 2,
            default => -1,
        };
        if ($slotType < 0) {
            Response::gd('-1');
            return;
        }

        $now = time();
        $slotQuery = $app->db()->prepare(
            'SELECT slotID, levelID FROM ' . $app->tables()->get('core_daily_levels')
            . ' WHERE slotType = :slotType'
            . ' AND startsAt <= :nowStart'
            . ' AND (endsAt = 0 OR endsAt > :nowEnd)'
            . ' ORDER BY startsAt DESC, slotID DESC LIMIT 1'
        );
        $slotQuery->execute([
            ':slotType' => $slotType,
            ':nowStart' => $now,
            ':nowEnd' => $now,
        ]);
        $slot = $slotQuery->fetch(PDO::FETCH_ASSOC);
        if ($slot === false) {
            Response::gd('-1');
            return;
        }

        $levelID = (int) $slot['levelID'];
        $slotID = (int) $slot['slotID'];
        $timelyID = match ($slotType) {
            1 => $slotID + 100001,
            2 => $slotID + 200000,
            default => $slotID,
        };
    }

    if ($levelID <= 0) {
        Response::gd('-1');
        return;
    }

    $response = $app->levels()->download(
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
    );

    if (!$isTimely || $response === '-1') {
        Response::gd($response);
        return;
    }

    $parts = explode('#', $response);
    if (count($parts) < 3 || $parts[0] === '') {
        Response::gd('-1');
        return;
    }

    $metadataQuery = $app->db()->prepare(
        'SELECT levels.levelID, levels.userID, levels.userName AS levelUserName,'
        . ' levels.starStars, levels.starDemon, levels.starCoins, levels.starFeatured, levels.password,'
        . ' users.userName AS ownerName, users.extID AS ownerExtID'
        . ' FROM ' . $app->tables()->get('levels') . ' levels'
        . ' LEFT JOIN ' . $app->tables()->get('users') . ' users ON levels.userID = users.userID'
        . ' WHERE levels.levelID = :levelID LIMIT 1'
    );
    $metadataQuery->execute([':levelID' => $levelID]);
    $level = $metadataQuery->fetch(PDO::FETCH_ASSOC);
    if ($level === false) {
        Response::gd('-1');
        return;
    }

    $parts[0] .= ':41:' . $timelyID;
    $parts[2] = LevelHash::solo2(implode(',', [
        (int) $level['userID'],
        (int) $level['starStars'],
        (int) $level['starDemon'],
        (int) $level['levelID'],
        (int) $level['starCoins'],
        (int) $level['starFeatured'],
        (string) $level['password'],
        $timelyID,
    ]));

    $ownerName = (string) (($level['ownerName'] ?? '') !== '' ? $level['ownerName'] : $level['levelUserName']);
    $ownerName = preg_replace('/[:#|~]/', '', $ownerName) ?? '';
    $ownerExtID = ctype_digit((string) ($level['ownerExtID'] ?? ''))
        ? (string) $level['ownerExtID']
        : '0';

    $parts = array_slice($parts, 0, 3);
    $parts[] = (int) $level['userID'] . ':' . $ownerName . ':' . $ownerExtID;
    Response::gd(implode('#', $parts));
} catch (Throwable $e) {
    error_log('Night Core download level failed: ' . $e->getMessage());
    Response::gd('-1');
}
