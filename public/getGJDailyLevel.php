<?php

declare(strict_types=1);

use NightCore\Core\Request;
use NightCore\Core\Response;

try {
    /** @var NightCore\Core\Application $app */
    $app = require dirname(__DIR__) . '/bootstrap.php';

    $typeRaw = Request::post('type', Request::post('weekly', '0'));
    $slotType = preg_match('/^-?\d+$/', $typeRaw) ? (int) $typeRaw : 0;
    if (!in_array($slotType, [0, 1, 2], true)) {
        Response::gd('-1');
        return;
    }

    $now = time();
    $table = $app->tables()->get('core_daily_levels');
    $query = $app->db()->prepare(
        'SELECT slotID, levelID, startsAt, endsAt FROM ' . $table .
        ' WHERE slotType = :slotType AND startsAt <= :nowStart AND (endsAt = 0 OR endsAt > :nowEnd)'
        . ' ORDER BY startsAt DESC, slotID DESC LIMIT 1'
    );
    $query->execute([
        ':slotType' => $slotType,
        ':nowStart' => $now,
        ':nowEnd' => $now,
    ]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        Response::gd('-1');
        return;
    }

    $slotID = (int) $row['slotID'];
    $endsAt = (int) $row['endsAt'];
    if ($slotType === 0) {
        Response::gd($slotID . '|' . max(0, $endsAt - $now));
        return;
    }
    if ($slotType === 1) {
        Response::gd(($slotID + 100001) . '|' . max(0, $endsAt - $now));
        return;
    }

    $eventQuery = $app->db()->prepare(
        'SELECT eventID, rewardJson FROM ' . $app->tables()->get('core_events')
        . " WHERE eventID = :eventID AND status IN ('scheduled','active') LIMIT 1"
    );
    $eventQuery->execute([':eventID' => $slotID]);
    $event = $eventQuery->fetch(PDO::FETCH_ASSOC);
    if ($event === false) {
        Response::gd('-1');
        return;
    }

    $chk = Request::post('chk');
    $chkNumber = '0';
    if (strlen($chk) > 5) {
        $encodedChk = substr($chk, 5);
        $padding = (4 - strlen($encodedChk) % 4) % 4;
        $decodedChk = base64_decode(strtr($encodedChk . str_repeat('=', $padding), '-_', '+/'), true);
        if (is_string($decodedChk)) {
            $plainChk = '';
            $key = '59182';
            for ($i = 0, $length = strlen($decodedChk); $i < $length; $i++) {
                $plainChk .= chr(ord($decodedChk[$i]) ^ ord($key[$i % strlen($key)]));
            }
            if ($plainChk !== '' && ctype_digit($plainChk)) {
                $chkNumber = $plainChk;
            }
        }
    }

    $decodedRewards = json_decode((string) $event['rewardJson'], true);
    $rewardPairs = [];
    $rewardMap = [
        'orbs' => 7,
        'diamonds' => 8,
        'keys' => 6,
        'goldkeys' => 15,
    ];
    if (is_array($decodedRewards)) {
        foreach ($rewardMap as $name => $itemID) {
            $amount = isset($decodedRewards[$name]) && is_numeric($decodedRewards[$name])
                ? (int) $decodedRewards[$name]
                : 0;
            if ($amount > 0) {
                $rewardPairs[] = (string) $itemID;
                $rewardPairs[] = (string) min(1000000, $amount);
            }
        }
    }
    if ($rewardPairs === []) {
        $rewardPairs = ['8', '1'];
    }

    $randomPrefix = static function (): string {
        return substr(strtr(base64_encode(random_bytes(6)), '+/=', 'AZ0'), 0, 5);
    };
    $plain = $randomPrefix() . ':' . $chkNumber . ':' . (int) $event['eventID'] . ':3:' . implode(',', $rewardPairs);
    $xor = '';
    $key = '59182';
    for ($i = 0, $length = strlen($plain); $i < $length; $i++) {
        $xor .= chr(ord($plain[$i]) ^ ord($key[$i % strlen($key)]));
    }
    $encodedBody = strtr(base64_encode($xor), '+/', '-_');
    $encoded = $randomPrefix() . $encodedBody;
    $hash = sha1($encodedBody . 'pC26fpYaQCtg');

    Response::gd(($slotID + 200001) . '|10|' . $encoded . '|' . $hash);
} catch (Throwable $e) {
    error_log('Night Core daily endpoint failed: ' . $e->getMessage());
    Response::gd('-1');
}
