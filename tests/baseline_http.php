<?php

declare(strict_types=1);

use NightCore\Core\Application;

$root = dirname(__DIR__);
/** @var Application $app */
$app = require $root . '/bootstrap.php';
$db = $app->db();
$tables = $app->tables();
$baseUrl = 'http://127.0.0.1:8099';
$log = sys_get_temp_dir() . '/nightcore-baseline-http.log';

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function expectSame(string $expected, string $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' expected=' . $expected . ' actual=' . $actual);
    }
}

function post(string $baseUrl, string $path, array $data): string
{
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\nConnection: close\r\n",
            'content' => http_build_query($data, '', '&', PHP_QUERY_RFC3986),
            'ignore_errors' => true,
            'timeout' => 8,
        ],
    ]);
    $result = @file_get_contents($baseUrl . $path, false, $context);
    if ($result === false) {
        throw new RuntimeException('HTTP request failed: ' . $path);
    }
    return trim($result);
}

function xorGjp(string $password): string
{
    $key = '37526';
    $out = '';
    for ($i = 0, $length = strlen($password); $i < $length; $i++) {
        $out .= chr(ord($password[$i]) ^ ord($key[$i % strlen($key)]));
    }
    return base64_encode($out);
}

function accountAuth(array $account): array
{
    return [
        'accountID' => $account['accountID'],
        'gjp' => xorGjp($account['password']),
        'gjp2' => sha1($account['password'] . 'mI29fmAnxgTs'),
    ];
}

function createAccount(string $baseUrl, string $name, string $password): array
{
    $register = post($baseUrl, '/accounts/registerGJAccount.php', [
        'userName' => $name,
        'password' => $password,
        'email' => strtolower($name) . '@example.invalid',
        'secret' => 'Wmfd2893gb7',
    ]);
    expectSame('1', $register, 'registration failed for ' . $name);

    $login = post($baseUrl, '/accounts/loginGJAccount.php', [
        'userName' => $name,
        'password' => $password,
        'secret' => 'Wmfd2893gb7',
    ]);
    expect((bool) preg_match('/^(\d+),(\d+)$/', $login, $match), 'unexpected login response: ' . $login);
    return [
        'accountID' => (int) $match[1],
        'userID' => (int) $match[2],
        'userName' => $name,
        'password' => $password,
    ];
}

function uploadLevel(string $baseUrl, array $account, string $name, int $songID = 0, int $unlisted = 0): int
{
    $response = post($baseUrl, '/uploadGJLevel21.php', accountAuth($account) + [
        'userName' => $account['userName'],
        'levelName' => $name,
        'levelString' => 'kS1,1,2;1,1,2,2;1,2,2,3;',
        'gameVersion' => 22,
        'binaryVersion' => 42,
        'levelVersion' => 1,
        'levelLength' => 1,
        'audioTrack' => 0,
        'songID' => $songID,
        'objects' => 3,
        'coins' => 3,
        'requestedStars' => 5,
        'password' => 1,
        'unlisted1' => $unlisted,
        'unlisted2' => $unlisted,
        'secret' => 'Wmfd2893gb7',
    ]);
    expect(ctype_digit($response) && (int) $response > 0, 'level upload failed: ' . $response);
    return (int) $response;
}

function scalar(PDO $db, string $sql, array $params = []): mixed
{
    $query = $db->prepare($sql);
    $query->execute($params);
    return $query->fetchColumn();
}

$descriptor = [
    0 => ['pipe', 'r'],
    1 => ['file', $log, 'a'],
    2 => ['file', $log, 'a'],
];
$server = proc_open([PHP_BINARY, '-S', '127.0.0.1:8099', '-t', $root . '/public'], $descriptor, $pipes, $root);
if (!is_resource($server)) {
    throw new RuntimeException('Unable to start PHP integration server.');
}

try {
    $ready = false;
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $socket = @fsockopen('127.0.0.1', 8099, $errno, $error, 0.2);
        if (is_resource($socket)) {
            fclose($socket);
            $ready = true;
            break;
        }
        usleep(100000);
    }
    expect($ready, 'PHP integration server did not start.');

    $suffix = substr(bin2hex(random_bytes(4)), 0, 6);
    $alice = createAccount($baseUrl, 'Alice' . $suffix, 'AlicePass!42');
    $bob = createAccount($baseUrl, 'Bob' . $suffix, 'BobPass!42');

    $aliceAuth = accountAuth($alice);
    $bobAuth = accountAuth($bob);

    $aliceScore = post($baseUrl, '/updateGJUserScore22.php', $aliceAuth + [
        'userName' => 'spoofed-name-must-be-ignored',
        'gameVersion' => 22,
        'stars' => 100,
        'diamonds' => 200,
        'demons' => 3,
        'coins' => 10,
        'userCoins' => 5,
        'icon' => 1,
        'color1' => 2,
        'color2' => 3,
    ]);
    expect(ctype_digit($aliceScore), 'Alice profile update failed.');
    $bobScore = post($baseUrl, '/updateGJUserScore22.php', $bobAuth + [
        'userName' => $bob['userName'],
        'gameVersion' => 22,
        'stars' => 50,
        'diamonds' => 100,
        'demons' => 1,
        'coins' => 4,
        'userCoins' => 2,
        'icon' => 2,
        'color1' => 4,
        'color2' => 5,
    ]);
    expect(ctype_digit($bobScore), 'Bob profile update failed.');

    $songTable = $tables->get('core_songs');
    $song = $db->prepare('INSERT INTO ' . $songTable . ' (songID, name, authorID, authorName, size, download, isDisabled, createdAt) VALUES (123, :name, 77, :author, 1.23, :download, 0, :createdAt) ON DUPLICATE KEY UPDATE name = VALUES(name), authorName = VALUES(authorName), download = VALUES(download), isDisabled = 0');
    $song->execute([
        ':name' => 'Baseline Song',
        ':author' => 'Night Core',
        ':download' => 'https://example.invalid/baseline.mp3',
        ':createdAt' => time(),
    ]);
    $songInfo = post($baseUrl, '/getGJSongInfo.php', ['songID' => 123]);
    expect(str_contains($songInfo, 'Baseline Song'), 'song endpoint did not return seeded song.');

    $levelIDs = [];
    for ($i = 1; $i <= 5; $i++) {
        $levelIDs[] = uploadLevel($baseUrl, $alice, 'Baseline-' . $suffix . '-' . $i, $i === 1 ? 123 : 0);
    }
    $mainLevel = $levelIDs[0];
    $privateLevel = uploadLevel($baseUrl, $alice, 'Private-' . $suffix, 0, 1);
    $lifecycleLevel = uploadLevel($baseUrl, $alice, 'Lifecycle-' . $suffix);

    $search = post($baseUrl, '/getGJLevels21.php', [
        'gameVersion' => 22,
        'binaryVersion' => 42,
        'type' => 0,
        'str' => $mainLevel,
        'page' => 0,
    ]);
    expect(str_contains($search, 'Baseline Song'), 'custom song metadata missing from level search.');

    $downloadsBefore = (int) scalar($db, 'SELECT downloads FROM ' . $tables->get('levels') . ' WHERE levelID = ?', [$mainLevel]);
    for ($i = 0; $i < 3; $i++) {
        $download = post($baseUrl, '/downloadGJLevel22.php', [
            'levelID' => $mainLevel,
            'inc' => 1,
            'gameVersion' => 22,
            'binaryVersion' => 42,
        ]);
        expect($download !== '-1' && str_contains($download, '#' ), 'level download failed.');
    }
    $downloadsAfter = (int) scalar($db, 'SELECT downloads FROM ' . $tables->get('levels') . ' WHERE levelID = ?', [$mainLevel]);
    expect($downloadsAfter === $downloadsBefore + 2, 'level download counter must allow only two legacy events per IP hash.');
    expect((int) scalar($db, 'SELECT COUNT(*) FROM ' . $tables->get('core_level_downloads') . ' WHERE levelID = ?', [$mainLevel]) === 2, 'level download hash events mismatch.');

    $privateDenied = post($baseUrl, '/getGJLevels21.php', $bobAuth + [
        'gameVersion' => 22,
        'binaryVersion' => 42,
        'type' => 0,
        'str' => $privateLevel,
    ]);
    expectSame('-1', $privateDenied, 'private level leaked before friendship.');

    expectSame('1', post($baseUrl, '/uploadFriendRequest20.php', $bobAuth + [
        'toAccountID' => $alice['accountID'],
        'comment' => 'baseline-request',
    ]), 'friend request failed.');
    $aliceViewsBob = post($baseUrl, '/getGJUserInfo20.php', $aliceAuth + [
        'targetAccountID' => $bob['accountID'],
    ]);
    expect(str_contains($aliceViewsBob, ':31:3'), 'incoming friend-request profile state missing.');
    expectSame('1', post($baseUrl, '/acceptGJFriendRequest20.php', $aliceAuth + [
        'targetAccountID' => $bob['accountID'],
    ]), 'friend accept failed.');

    $bobSelf = post($baseUrl, '/getGJUserInfo20.php', $bobAuth + [
        'targetAccountID' => $bob['accountID'],
    ]);
    expect(str_contains($bobSelf, ':40:1'), 'new-friend notification missing.');
    $friends = post($baseUrl, '/getGJUserList20.php', $bobAuth + ['type' => 0]);
    expect(str_contains($friends, $alice['userName']), 'friend list missing Alice.');

    $privateAllowed = post($baseUrl, '/getGJLevels21.php', $bobAuth + [
        'gameVersion' => 22,
        'binaryVersion' => 42,
        'type' => 0,
        'str' => $privateLevel,
    ]);
    expect($privateAllowed !== '-1', 'friend could not access private level.');

    expectSame('1', post($baseUrl, '/uploadGJMessage20.php', $bobAuth + [
        'toAccountID' => $alice['accountID'],
        'subject' => base64_encode('Baseline subject'),
        'body' => base64_encode('Baseline body'),
    ]), 'message upload failed.');
    $aliceSelf = post($baseUrl, '/getGJUserInfo20.php', $aliceAuth + ['targetAccountID' => $alice['accountID']]);
    expect(str_contains($aliceSelf, ':38:1'), 'unread-message notification missing.');
    $messages = post($baseUrl, '/getGJMessages20.php', $aliceAuth + ['page' => 0, 'getSent' => 0]);
    expect($messages !== '-2' && preg_match('/:1:(\d+):4:/', $messages, $messageMatch) === 1, 'message inbox response invalid.');
    $messageID = (int) $messageMatch[1];
    $message = post($baseUrl, '/downloadGJMessage20.php', $aliceAuth + ['messageID' => $messageID, 'isSender' => 0]);
    expect(str_contains($message, ':5:'), 'message body download failed.');

    expectSame('1', post($baseUrl, '/backupGJAccountNew.php', $aliceAuth + [
        'saveData' => 'BASELINE-SAVE',
        'saveData2' => 'BASELINE-EXTRA',
    ]), 'account backup failed.');
    expectSame('BASELINE-SAVE;21;BASELINE-EXTRA', post($baseUrl, '/syncGJAccountNew.php', $aliceAuth), 'account sync mismatch.');

    $commentPayload = base64_encode('baseline progress comment');
    expectSame('1', post($baseUrl, '/uploadGJComment21.php', $bobAuth + [
        'levelID' => $mainLevel,
        'comment' => $commentPayload,
        'percent' => 73,
        'gameVersion' => 22,
    ]), 'level comment upload failed.');
    $comments = post($baseUrl, '/getGJComments21.php', [
        'levelID' => $mainLevel,
        'page' => 0,
        'count' => 10,
        'mode' => 0,
        'gameVersion' => 22,
        'binaryVersion' => 42,
    ]);
    expect(preg_match('/~6~(\d+)~10~73/', $comments, $commentMatch) === 1, 'comment response or percent invalid.');
    $commentID = (int) $commentMatch[1];
    $levelScores = post($baseUrl, '/getGJLevelScores211.php', $bobAuth + ['levelID' => $mainLevel]);
    expect(str_contains($levelScores, ':3:73'), 'comment percent did not reach level leaderboard.');
    expectSame('1', post($baseUrl, '/deleteGJComment20.php', $aliceAuth + ['commentID' => $commentID]), 'level creator could not delete comment.');

    expectSame('1', post($baseUrl, '/likeGJItem211.php', $bobAuth + [
        'type' => 1,
        'itemID' => $mainLevel,
        'like' => 1,
    ]), 'level like failed.');
    expectSame('1', post($baseUrl, '/reportGJLevel.php', $bobAuth + [
        'levelID' => $mainLevel,
        'reason' => 'baseline-report',
    ]), 'level report failed.');
    expect((int) scalar($db, 'SELECT COUNT(*) FROM ' . $tables->get('core_reports') . ' WHERE itemType = 1 AND itemID = ?', [$mainLevel]) === 1, 'level report was not persisted.');

    $listID = post($baseUrl, '/uploadGJLevelList.php', $aliceAuth + [
        'listName' => 'Baseline List ' . $suffix,
        'listDesc' => base64_encode('baseline list'),
        'listLevels' => implode(',', $levelIDs),
        'difficulty' => 3,
        'listVersion' => 1,
        'original' => 0,
        'unlisted' => 0,
    ]);
    expect(ctype_digit($listID) && (int) $listID > 0, 'list upload failed: ' . $listID);
    $listID = (int) $listID;
    $listOne = post($baseUrl, '/getGJLevelLists.php', ['type' => 0, 'str' => $listID, 'page' => 0]);
    $listTwo = post($baseUrl, '/getGJLevelLists.php', ['type' => 0, 'str' => $listID, 'page' => 0]);
    expect($listOne !== '-1' && $listTwo !== '-1', 'list lookup failed.');
    expect((int) scalar($db, 'SELECT downloads FROM ' . $tables->get('core_level_lists') . ' WHERE listID = ?', [$listID]) === 1, 'list download dedupe failed.');
    $ipHash = (string) scalar($db, 'SELECT ipHash FROM ' . $tables->get('core_list_downloads') . ' WHERE listID = ? LIMIT 1', [$listID]);
    expect(strlen($ipHash) === 64, 'list download did not store a SHA-256 IP identifier.');
    expectSame('1', post($baseUrl, '/likeGJItem211.php', $bobAuth + ['type' => 4, 'itemID' => $listID, 'like' => 1]), 'list like failed.');
    expect((int) scalar($db, 'SELECT likes FROM ' . $tables->get('core_level_lists') . ' WHERE listID = ?', [$listID]) === 1, 'list like counter mismatch.');
    $friendLists = post($baseUrl, '/getGJLevelLists.php', $bobAuth + ['type' => 13, 'str' => '', 'page' => 0]);
    expect(str_contains($friendLists, 'Baseline List ' . $suffix), 'friends list-search mode did not resolve Alice.');

    $now = time();
    $rotationTable = $tables->get('core_daily_levels');
    $rotation = $db->prepare('INSERT INTO ' . $rotationTable . ' (slotType, slotID, levelID, startsAt, endsAt) VALUES (:slotType, 1, :levelID, :startsAt, :endsAt) ON DUPLICATE KEY UPDATE levelID = VALUES(levelID), startsAt = VALUES(startsAt), endsAt = VALUES(endsAt)');
    foreach ([0, 1, 2] as $slotType) {
        $rotation->execute([':slotType' => $slotType, ':levelID' => $mainLevel, ':startsAt' => $now - 60, ':endsAt' => $now + 3600]);
    }
    expect(str_starts_with(post($baseUrl, '/getGJDailyLevel.php', ['type' => 0]), '1|'), 'daily slot response invalid.');
    expect(str_starts_with(post($baseUrl, '/getGJDailyLevel.php', ['type' => 1]), '100002|'), 'weekly slot response invalid.');
    expect(str_starts_with(post($baseUrl, '/getGJDailyLevel.php', ['type' => 2]), '200002|'), 'event slot response invalid.');
    foreach ([21, 22, 23] as $type) {
        $rotationSearch = post($baseUrl, '/getGJLevels21.php', ['gameVersion' => 22, 'binaryVersion' => 42, 'type' => $type, 'page' => 0]);
        expect(str_contains($rotationSearch, '1:' . $mainLevel . ':'), 'rotation level-search mode failed for type ' . $type);
    }

    $gauntlet = $db->prepare('INSERT INTO ' . $tables->get('core_gauntlets') . ' (gauntletID, levelIDs, updatedAt) VALUES (1, :levelIDs, :updatedAt) ON DUPLICATE KEY UPDATE levelIDs = VALUES(levelIDs), updatedAt = VALUES(updatedAt)');
    $gauntlet->execute([':levelIDs' => implode(',', $levelIDs), ':updatedAt' => $now]);
    $gauntlets = post($baseUrl, '/getGJGauntlets21.php', []);
    expect(str_contains($gauntlets, '1:1:3:' . implode(',', $levelIDs)) && str_contains($gauntlets, '#'), 'gauntlet response/hash invalid.');
    $gauntletSearch = post($baseUrl, '/getGJLevels21.php', ['gameVersion' => 22, 'binaryVersion' => 42, 'gauntlet' => 1, 'page' => 0]);
    expect(str_contains($gauntletSearch, '44:1:'), 'gauntlet level marker missing.');

    $pack = $db->prepare('INSERT INTO ' . $tables->get('core_map_packs') . ' (packID, name, levelIDs, stars, coins, difficulty, color1, color2, updatedAt) VALUES (1, :name, :levelIDs, 6, 2, 3, :color1, :color2, :updatedAt) ON DUPLICATE KEY UPDATE name = VALUES(name), levelIDs = VALUES(levelIDs), stars = VALUES(stars), coins = VALUES(coins), updatedAt = VALUES(updatedAt)');
    $pack->execute([
        ':name' => 'Baseline Pack',
        ':levelIDs' => implode(',', array_slice($levelIDs, 0, 3)),
        ':color1' => '255,255,255',
        ':color2' => '0,0,0',
        ':updatedAt' => $now,
    ]);
    $packs = post($baseUrl, '/getGJMapPacks21.php', ['page' => 0]);
    expect(substr_count($packs, '#') === 2 && str_contains($packs, 'Baseline Pack'), 'map-pack response/hash framing invalid.');

    $role = $db->prepare('INSERT INTO ' . $tables->get('core_moderator_roles') . ' (accountID, roleLevel, roleName, canRate, canFeature, canEpic, canModerateComments, canBan, updatedAt) VALUES (:accountID, 2, :roleName, 1, 1, 1, 1, 1, :updatedAt) ON DUPLICATE KEY UPDATE roleLevel = 2, canRate = 1, canFeature = 1, canEpic = 1, canModerateComments = 1, canBan = 1, updatedAt = VALUES(updatedAt)');
    $role->execute([':accountID' => $alice['accountID'], ':roleName' => 'Baseline Moderator', ':updatedAt' => $now]);
    expectSame('2', post($baseUrl, '/requestUserAccess.php', $aliceAuth), 'moderator access level invalid.');
    expectSame('1', post($baseUrl, '/suggestGJStars20.php', $aliceAuth + ['levelID' => $levelIDs[1], 'stars' => 5, 'feature' => 1]), 'star suggestion failed.');
    $suggested = post($baseUrl, '/getGJLevels21.php', ['gameVersion' => 22, 'binaryVersion' => 42, 'type' => 27, 'page' => 0]);
    expect(str_contains($suggested, '1:' . $levelIDs[1] . ':'), 'suggested-level search mode failed.');

    expectSame('1', post($baseUrl, '/rateGJStars211.php', $aliceAuth + [
        'levelID' => $mainLevel,
        'stars' => 10,
        'feature' => 1,
        'epic' => 3,
    ]), 'star rating failed.');
    expectSame((string) $mainLevel, post($baseUrl, '/rateGJDemon21.php', $aliceAuth + [
        'levelID' => $mainLevel,
        'rating' => 5,
    ]), 'demon rating failed.');
    expect((int) scalar($db, 'SELECT starStars FROM ' . $tables->get('levels') . ' WHERE levelID = ?', [$mainLevel]) === 10, 'rated stars not persisted.');
    expect((int) scalar($db, 'SELECT starDemonDiff FROM ' . $tables->get('levels') . ' WHERE levelID = ?', [$mainLevel]) === 6, 'demon difficulty mapping mismatch.');
    expect((int) scalar($db, 'SELECT creatorPoints FROM ' . $tables->get('users') . ' WHERE userID = ?', [$alice['userID']]) === 5, '2.2 creator-point tier calculation mismatch.');
    expectSame('-1', post($baseUrl, '/deleteGJLevelUser20.php', $aliceAuth + ['levelID' => $mainLevel]), 'rated level must not be user-deletable.');

    expectSame('1', post($baseUrl, '/uploadGJAccComment20.php', $bobAuth + [
        'comment' => base64_encode('moderation target'),
        'gameVersion' => 22,
    ]), 'account comment upload failed.');
    $accountComments = post($baseUrl, '/getGJAccountComments20.php', [
        'accountID' => $bob['accountID'],
        'page' => 0,
        'gameVersion' => 22,
        'binaryVersion' => 42,
    ]);
    expect(preg_match('/~6~(\d+)/', $accountComments, $accountCommentMatch) === 1, 'account comment response invalid.');
    expectSame('1', post($baseUrl, '/deleteGJAccComment20.php', $aliceAuth + ['commentID' => (int) $accountCommentMatch[1]]), 'comment moderator permission failed.');

    $encodedDescription = rtrim(strtr(base64_encode('updated lifecycle description'), '+/', '-_'), '=');
    expectSame('1', post($baseUrl, '/updateGJDesc20.php', $aliceAuth + [
        'levelID' => $lifecycleLevel,
        'levelDesc' => $encodedDescription,
    ]), 'owned level description update failed.');
    expectSame('1', post($baseUrl, '/updateGJDesc20.php', $aliceAuth + [
        'levelID' => $lifecycleLevel,
        'levelDesc' => $encodedDescription,
    ]), 'unchanged owned description should still succeed.');
    expectSame('1', post($baseUrl, '/deleteGJLevelUser20.php', $aliceAuth + ['levelID' => $lifecycleLevel]), 'unrated owned level deletion failed.');
    expect((int) scalar($db, 'SELECT COUNT(*) FROM ' . $tables->get('levels') . ' WHERE levelID = ?', [$lifecycleLevel]) === 0, 'deleted level still exists in database.');
    $storagePath = trim((string) getenv('LEVEL_STORAGE_PATH'));
    if ($storagePath !== '') {
        expect(!is_file(rtrim($storagePath, '/\\') . DIRECTORY_SEPARATOR . $lifecycleLevel), 'deleted level payload still exists.');
    }

    $global = post($baseUrl, '/getGJScores20.php', ['type' => 0]);
    expect(str_contains($global, $alice['userName']) && str_contains($global, $bob['userName']), 'global leaderboard missing baseline users.');

    expectSame('1', post($baseUrl, '/blockGJUser20.php', $aliceAuth + ['targetAccountID' => $bob['accountID']]), 'block failed.');
    $blockedPrivate = post($baseUrl, '/getGJLevels21.php', $bobAuth + [
        'gameVersion' => 22,
        'binaryVersion' => 42,
        'type' => 0,
        'str' => $privateLevel,
    ]);
    expectSame('-1', $blockedPrivate, 'block did not revoke private-level access.');
    expectSame('1', post($baseUrl, '/unblockGJUser20.php', $aliceAuth + ['targetAccountID' => $bob['accountID']]), 'unblock failed.');

    echo "baseline integration ok\n";
} finally {
    proc_terminate($server);
    foreach ($pipes as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
    proc_close($server);
}
