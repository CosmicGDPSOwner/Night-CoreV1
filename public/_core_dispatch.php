<?php

declare(strict_types=1);

use NightCore\Core\ClientIp;
use NightCore\Core\Config;
use NightCore\Core\Request;
use NightCore\Core\Response;

try {
    /** @var NightCore\Core\Application $app */
    $app = require dirname(__DIR__) . '/bootstrap.php';
    $endpoint = basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? $_SERVER['SCRIPT_NAME'] ?? ''));
    $ip = ClientIp::detect(Config::getBool('TRUST_PROXY_HEADERS', false));
    $accountID = (int) Request::post('accountID');
    $gjp = Request::post('gjp');
    $gjp2 = Request::post('gjp2');
    $intAny = static function (array $keys, int $default = 0): int {
        foreach ($keys as $key) {
            $value = Request::post((string) $key);
            if ($value !== '' && preg_match('/^-?\d+$/', $value)) {
                return (int) $value;
            }
        }
        return $default;
    };

    if ($endpoint === 'getGJLevelLists.php' && (int) Request::post('type') === 0) {
        $exactList = trim(Request::post('str'));
        if ($exactList !== '' && ctype_digit($exactList) && (int) $exactList > 0) {
            $app->trackListDownload((int) $exactList, $ip);
        }
    }

    $result = match ($endpoint) {
        'getGJSongInfo.php' => $app->content()->song((int) Request::post('songID')),

        'uploadGJComment21.php', 'uploadGJComment20.php' => $app->content()->uploadLevelComment(
            $accountID, $gjp, $gjp2, $ip,
            (int) Request::post('levelID'), Request::post('comment'),
            (int) Request::post('percent'), (int) Request::post('gameVersion')
        ),
        'getGJComments21.php', 'getGJComments20.php' => $app->content()->levelComments(
            (int) Request::post('levelID'), (int) Request::post('userID'),
            (int) Request::post('page'), (int) (Request::post('count', '10') ?: '10'),
            (int) Request::post('mode'), (int) Request::post('gameVersion'), (int) Request::post('binaryVersion')
        ),
        'uploadGJAccComment20.php' => $app->content()->uploadAccountComment(
            $accountID, $gjp, $gjp2, $ip, Request::post('comment'), (int) Request::post('gameVersion')
        ),
        'getGJAccountComments20.php' => $app->content()->accountComments(
            (int) Request::post('accountID'), (int) Request::post('page'), 10,
            (int) Request::post('gameVersion'), (int) Request::post('binaryVersion')
        ),
        'deleteGJComment20.php', 'deleteGJAccComment20.php' => $app->content()->deleteComment(
            $accountID, $gjp, $gjp2, $ip, (int) Request::post('commentID')
        ),
        'likeGJItem211.php', 'likeGJItem21.php', 'likeGJItem20.php' => $app->content()->like(
            $accountID, $gjp, $gjp2, $ip,
            $intAny(['type'], 1),
            $intAny(['itemID', 'levelID']), Request::post('like', '1') === '1' ? 1 : -1
        ),
        'reportGJLevel.php' => $app->content()->reportLevel(
            $accountID, $gjp, $gjp2, $ip, (int) Request::post('levelID'), Request::post('reason')
        ),

        'uploadFriendRequest20.php' => $app->social()->sendFriendRequest(
            $accountID, $gjp, $gjp2, $ip, $intAny(['toAccountID', 'targetAccountID']), Request::post('comment')
        ),
        'getGJFriendRequests20.php' => $app->social()->friendRequests(
            $accountID, $gjp, $gjp2, $ip, (int) Request::post('page'), Request::post('getSent') === '1'
        ),
        'readGJFriendRequest20.php' => $app->social()->readFriendRequest(
            $accountID, $gjp, $gjp2, $ip, $intAny(['requestID', 'friendRequestID'])
        ),
        'acceptGJFriendRequest20.php' => $app->social()->acceptFriend(
            $accountID, $gjp, $gjp2, $ip, $intAny(['targetAccountID', 'toAccountID'])
        ),
        'deleteGJFriendRequests20.php' => $app->social()->deleteFriendRequest(
            $accountID, $gjp, $gjp2, $ip, $intAny(['targetAccountID', 'toAccountID'])
        ),
        'removeGJFriend20.php' => $app->social()->removeFriend(
            $accountID, $gjp, $gjp2, $ip, $intAny(['targetAccountID', 'toAccountID'])
        ),
        'blockGJUser20.php' => $app->social()->block(
            $accountID, $gjp, $gjp2, $ip, $intAny(['targetAccountID', 'toAccountID'])
        ),
        'unblockGJUser20.php' => $app->social()->unblock(
            $accountID, $gjp, $gjp2, $ip, $intAny(['targetAccountID', 'toAccountID'])
        ),
        'getGJUserList20.php' => $app->social()->userList(
            $accountID, $gjp, $gjp2, $ip, (int) Request::post('type')
        ),
        'uploadGJMessage20.php' => $app->social()->sendMessage(
            $accountID, $gjp, $gjp2, $ip, $intAny(['toAccountID', 'targetAccountID']),
            Request::post('subject'), Request::post('body')
        ),
        'getGJMessages20.php' => $app->social()->messages(
            $accountID, $gjp, $gjp2, $ip, (int) Request::post('page'), Request::post('getSent') === '1'
        ),
        'downloadGJMessage20.php' => $app->social()->downloadMessage(
            $accountID, $gjp, $gjp2, $ip, (int) Request::post('messageID'), Request::post('isSender') === '1'
        ),
        'deleteGJMessages20.php' => $app->social()->deleteMessages(
            $accountID, $gjp, $gjp2, $ip, Request::post('messages', Request::post('messageID'))
        ),

        'backupGJAccountNew.php' => $app->progress()->backup(
            $accountID, $gjp, $gjp2, $ip, Request::post('saveData'), Request::post('saveData2', Request::post('saveExtra'))
        ),
        'syncGJAccountNew.php' => $app->progress()->sync($accountID, $gjp, $gjp2, $ip),
        'getGJScores20.php' => $app->progress()->globalScores(
            $accountID, $gjp, $gjp2, $ip, (int) Request::post('type')
        ),
        'getGJLevelScores211.php', 'getGJLevelScores.php' => $app->progress()->levelScores(
            $accountID, $gjp, $gjp2, $ip, (int) Request::post('levelID')
        ),
        'getGJDailyLevel.php' => $app->progress()->daily(
            $intAny(['type', 'weekly'], 0)
        ),
        'getGJGauntlets21.php', 'getGJGauntlets.php' => $app->progress()->gauntlets(),
        'getGJMapPacks21.php', 'getGJMapPacks20.php' => $app->progress()->mapPacks((int) Request::post('page')),
        'uploadGJLevelList.php' => $app->progress()->uploadList(
            $accountID,
            $gjp,
            $gjp2,
            $ip,
            (int) Request::post('listID'),
            Request::post('listName'),
            Request::post('listDesc'),
            Request::post('listLevels', Request::post('levelIDs')),
            (int) Request::post('difficulty'),
            (int) (Request::post('listVersion', '1') ?: '1'),
            (int) Request::post('original'),
            (int) Request::post('unlisted')
        ),
        'deleteGJLevelList.php' => $app->progress()->deleteList(
            $accountID, $gjp, $gjp2, $ip, (int) Request::post('listID')
        ),
        'getGJLevelLists.php' => $app->progress()->lists(
            $accountID,
            $gjp,
            $gjp2,
            $ip,
            Request::post('str'),
            (int) Request::post('page'),
            (int) Request::post('type'),
            Request::post('followed')
        ),

        'requestUserAccess.php' => $app->moderation()->requestAccess($accountID, $gjp, $gjp2, $ip),
        'suggestGJStars20.php' => $app->moderation()->suggestStars(
            $accountID, $gjp, $gjp2, $ip, (int) Request::post('levelID'), (int) Request::post('stars'), (int) Request::post('feature')
        ),
        'rateGJStars211.php', 'rateGJStars20.php' => $app->moderation()->rateStars(
            $accountID, $gjp, $gjp2, $ip, (int) Request::post('levelID'), (int) Request::post('stars'),
            (int) Request::post('feature'), (int) Request::post('epic')
        ),
        'rateGJDemon21.php' => $app->moderation()->rateDemon(
            $accountID, $gjp, $gjp2, $ip, (int) Request::post('levelID'), $intAny(['rating', 'difficulty'])
        ),
        default => '-1',
    };

    Response::gd($result);
} catch (Throwable $e) {
    error_log('Night Core endpoint failed: ' . $e->getMessage());
    Response::gd('-1');
}
