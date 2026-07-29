<?php

declare(strict_types=1);

namespace NightCore\Domain\Levels;

use NightCore\Domain\Accounts\AccountRepository;
use NightCore\Protocol\LevelHash;
use NightCore\Protocol\XorCipher;
use NightCore\Security\AccountAuthenticator;

final class LevelService
{
    public function __construct(
        private LevelRepository $levels,
        private LevelStorage $storage,
        private AccountRepository $accounts,
        private AccountAuthenticator $authenticator,
        private int $uploadCooldownSeconds
    ) {
    }

    /** @param array<string,string> $input */
    public function upload(int $accountID, string $gjp, string $gjp2, string $ip, array $input): string
    {
        if (!$this->authenticator->verify($accountID, $gjp, $gjp2, $ip)) {
            return '-1';
        }

        $account = $this->accounts->findById($accountID);
        if ($account === null) {
            return '-1';
        }

        $levelName = $this->field($input['levelName'] ?? '', 100);
        $levelString = $input['levelString'] ?? '';
        if ($levelName === '' || $levelString === '') {
            return '-1';
        }

        $userID = $this->accounts->ensureUser($accountID, (string) $account['userName']);
        if ($this->uploadCooldownSeconds > 0 && $this->levels->recentUploadExists($userID, $ip, time() - $this->uploadCooldownSeconds)) {
            return '-1';
        }

        $gameVersion = $this->int($input['gameVersion'] ?? '0', 0, 99);
        $now = time();
        $data = [
            'levelName' => $levelName,
            'gameVersion' => $gameVersion,
            'binaryVersion' => $this->int($input['binaryVersion'] ?? '0', 0, 999),
            'userName' => $this->field((string) $account['userName'], 20),
            'levelDesc' => $this->normalizeDescription($input['levelDesc'] ?? '', $gameVersion),
            'levelVersion' => $this->int($input['levelVersion'] ?? '1', 1, 1000000),
            'levelLength' => $this->int($input['levelLength'] ?? '0', 0, 10),
            'audioTrack' => $this->int($input['audioTrack'] ?? '0', 0, 1000),
            'auto' => $this->int($input['auto'] ?? '0', 0, 1),
            'password' => $this->copyPassword($input['password'] ?? ($gameVersion > 17 ? '0' : '1')),
            'original' => $this->int($input['original'] ?? '0'),
            'twoPlayer' => $this->int($input['twoPlayer'] ?? '0', 0, 1),
            'songID' => $this->int($input['songID'] ?? '0'),
            'objects' => $this->int($input['objects'] ?? '0'),
            'coins' => $this->int($input['coins'] ?? '0', 0, 10),
            'requestedStars' => $this->int($input['requestedStars'] ?? '0', 0, 10),
            'extraString' => $this->field($input['extraString'] ?? '29_29_29_40_29_29_29_29_29_29_29_29_29_29_29_29', 4096),
            'levelString' => $levelString,
            'levelInfo' => $this->field($input['levelInfo'] ?? '', 8192),
            'secret' => $this->field($input['secret'] ?? '', 64),
            'updateDate' => $now,
            'unlisted' => $this->int($input['unlisted1'] ?? ($input['unlisted'] ?? '0'), 0, 2),
            'unlisted2' => $this->int($input['unlisted2'] ?? ($input['unlisted1'] ?? ($input['unlisted'] ?? '0')), 0, 2),
            'hostname' => $this->field($ip, 45),
            'isLDM' => $this->int($input['ldm'] ?? '0', 0, 1),
            'wt' => $this->int($input['wt'] ?? '0'),
            'wt2' => $this->int($input['wt2'] ?? '0'),
            'settingsString' => $this->field($input['settingsString'] ?? '', 16384),
            'songIDs' => $this->numberListString($input['songIDs'] ?? ''),
            'sfxIDs' => $this->numberListString($input['sfxIDs'] ?? ''),
            'ts' => $this->int($input['ts'] ?? '0'),
        ];

        $requestedLevelID = $this->int($input['levelID'] ?? '0');
        $levelID = $this->levels->findExistingLevelId($userID, $requestedLevelID, $levelName);
        if ($levelID === null) {
            $levelID = $this->levels->insert($userID, $accountID, $data);
        } else {
            $this->levels->update($levelID, $userID, $data);
        }

        $this->storage->write($levelID, $levelString);
        return (string) $levelID;
    }

    /** @param array<string,string> $input */
    public function download(int $levelID, int $accountID, string $gjp, string $gjp2, string $ip, array $input): string
    {
        if ($levelID <= 0) {
            return '-1';
        }

        $level = $this->levels->findById($levelID);
        if ($level === null) {
            return '-1';
        }

        if ((int) $level['unlisted2'] !== 0) {
            if ($accountID <= 0 || (string) $accountID !== (string) $level['extID'] || !$this->authenticator->verify($accountID, $gjp, $gjp2, $ip)) {
                return '-1';
            }
        }

        if ($this->bool($input['inc'] ?? '0')) {
            $this->levels->incrementDownload($levelID, $ip);
        }

        $levelString = $this->storage->read($levelID);
        if ($levelString === null || $levelString === '') {
            $levelString = (string) $level['levelString'];
        }
        if ($levelString === '') {
            return '-1';
        }

        $gameVersion = $this->int($input['gameVersion'] ?? '1', 1, 99);
        $description = (string) $level['levelDesc'];
        if ($gameVersion < 20) {
            $decoded = base64_decode(strtr($description, '-_', '+/'), true);
            if ($decoded !== false) {
                $description = $this->field($decoded, 4096);
            }
        }

        if ($gameVersion > 18 && str_starts_with($levelString, 'kS1')) {
            $compressed = gzcompress($levelString);
            if ($compressed !== false) {
                $levelString = rtrim(strtr(base64_encode($compressed), '+/', '-_'), '=');
            }
        }

        $password = (string) $level['password'];
        $encodedPassword = $password;
        if ($gameVersion > 19 && $password !== '0') {
            $encodedPassword = base64_encode(XorCipher::apply($password, '26364'));
        }

        $response = implode(':', [
            '1', (string) (int) $level['levelID'],
            '2', $this->field((string) $level['levelName'], 100),
            '3', $description,
            '4', $levelString,
            '5', (string) (int) $level['levelVersion'],
            '6', (string) (int) $level['userID'],
            '8', '10',
            '9', (string) (int) $level['starDifficulty'],
            '10', (string) (int) $level['downloads'],
            '11', '1',
            '12', (string) (int) $level['audioTrack'],
            '13', (string) (int) $level['gameVersion'],
            '14', (string) (int) $level['likes'],
            '17', (string) (int) $level['starDemon'],
            '43', (string) (int) $level['starDemonDiff'],
            '25', (string) (int) $level['starAuto'],
            '18', (string) (int) $level['starStars'],
            '19', (string) (int) $level['starFeatured'],
            '42', (string) (int) $level['starEpic'],
            '45', (string) (int) $level['objects'],
            '15', (string) (int) $level['levelLength'],
            '30', (string) (int) $level['original'],
            '31', (string) (int) $level['twoPlayer'],
            '28', date('d-m-Y G-i', (int) $level['uploadDate']),
            '29', date('d-m-Y G-i', (int) $level['updateDate']),
            '35', (string) (int) $level['songID'],
            '36', $this->field((string) $level['extraString'], 4096),
            '37', (string) (int) $level['coins'],
            '38', (string) (int) $level['starCoins'],
            '39', (string) (int) $level['requestedStars'],
            '46', (string) (int) $level['wt'],
            '47', (string) (int) $level['wt2'],
            '48', $this->field((string) $level['settingsString'], 16384),
            '40', (string) (int) $level['isLDM'],
            '27', $encodedPassword,
            '52', $this->field((string) $level['songIDs'], 8192),
            '53', $this->field((string) $level['sfxIDs'], 8192),
            '57', (string) (int) $level['ts'],
        ]);

        if ($this->bool($input['extras'] ?? '0')) {
            $response .= ':26:' . $this->field((string) $level['levelInfo'], 8192);
        }

        $response .= '#' . LevelHash::solo($levelString) . '#';
        $hashSource = implode(',', [
            (int) $level['userID'],
            (int) $level['starStars'],
            (int) $level['starDemon'],
            (int) $level['levelID'],
            (int) $level['starCoins'],
            (int) $level['starFeatured'],
            $password,
            0,
        ]);
        return $response . LevelHash::solo2($hashSource);
    }

    /** @param array<string,string> $input */
    public function search(array $input, int $accountID, string $gjp, string $gjp2, string $ip): string
    {
        if (!empty($input['gauntlet'])) {
            return '-1';
        }

        $type = $this->int($input['type'] ?? '0', 0, 100);
        if (in_array($type, [12, 13, 21, 22, 23, 25, 27], true)) {
            return '-1';
        }

        $gameVersion = $this->int($input['gameVersion'] ?? '0', 0, 99);
        $binaryVersion = $this->int($input['binaryVersion'] ?? '0', 0, 999);
        if ($gameVersion === 20 && $binaryVersion > 27) {
            $gameVersion = 21;
        }

        $criteria = ['maxGameVersion' => $gameVersion === 0 ? 18 : $gameVersion];
        if ($this->bool($input['original'] ?? '0')) {
            $criteria['originalOnly'] = true;
        }
        if ($this->bool($input['coins'] ?? '0')) {
            $criteria['verifiedCoins'] = true;
        }
        if ($this->bool($input['twoPlayer'] ?? '0')) {
            $criteria['twoPlayer'] = true;
        }
        if ($this->bool($input['star'] ?? '0')) {
            $criteria['starred'] = true;
        }
        if ($this->bool($input['noStar'] ?? '0')) {
            $criteria['unstarred'] = true;
        }

        if (($input['song'] ?? '') !== '') {
            $song = $this->int($input['song']);
            if ($this->bool($input['customSong'] ?? '0')) {
                $criteria['songID'] = $song;
            } else {
                $criteria['audioTrack'] = max(0, $song - 1);
            }
        }

        $lengths = $this->numberList($input['len'] ?? '');
        if ($lengths !== []) {
            $criteria['lengths'] = $lengths;
        }
        $completed = $this->numberList($input['completedLevels'] ?? '');
        if ($completed !== []) {
            if ($this->bool($input['uncompleted'] ?? '0')) {
                $criteria['excludeCompleted'] = $completed;
            } elseif ($this->bool($input['onlyCompleted'] ?? '0')) {
                $criteria['completedOnly'] = $completed;
            }
        }

        $epics = [];
        if ($this->bool($input['epic'] ?? '0')) {
            $epics[] = 1;
        }
        if ($this->bool($input['mythic'] ?? '0')) {
            $epics[] = 2;
        }
        if ($this->bool($input['legendary'] ?? '0')) {
            $epics[] = 3;
        }
        if ($epics !== []) {
            $criteria['epicValues'] = $epics;
        } elseif ($this->bool($input['featured'] ?? '0')) {
            $criteria['featured'] = true;
        }

        $diff = trim($input['diff'] ?? '-');
        if ($diff === '-1') {
            $criteria['starDifficulty'] = 0;
        } elseif ($diff === '-3') {
            $criteria['starAuto'] = true;
        } elseif ($diff === '-2') {
            $criteria['starDemon'] = true;
            $demonMap = [1 => 3, 2 => 4, 3 => 0, 4 => 5, 5 => 6];
            $demonFilter = $this->int($input['demonFilter'] ?? '0', 0, 5);
            if (isset($demonMap[$demonFilter])) {
                $criteria['starDemonDiff'] = $demonMap[$demonFilter];
            }
        } elseif ($diff !== '' && $diff !== '-') {
            $diffs = $this->numberList($diff);
            if ($diffs !== []) {
                $criteria['difficulties'] = array_map(static fn (int $value): int => $value * 10, $diffs);
            }
        }

        $str = trim($input['str'] ?? '');
        $order = 'uploadDate';
        $descending = true;
        $limit = $type === 26 ? 100 : 10;

        switch ($type) {
            case 0:
            case 15:
                $order = 'likes';
                if ($str !== '') {
                    if (ctype_digit($str)) {
                        $criteria = ['levelID' => (int) $str, 'includeUnlisted' => true];
                    } else {
                        $criteria['name'] = $this->field($str, 100);
                    }
                }
                break;
            case 1:
                $order = 'downloads';
                break;
            case 2:
                $order = 'likes';
                break;
            case 3:
                $criteria['recentSince'] = time() - 604800;
                $order = 'likes';
                break;
            case 5:
                if (!ctype_digit($str)) {
                    return '-1';
                }
                $criteria['userID'] = (int) $str;
                break;
            case 6:
            case 17:
                $criteria['featured'] = true;
                $order = 'rateDate';
                break;
            case 7:
                $criteria['magic'] = true;
                break;
            case 10:
            case 19:
            case 26:
                $ids = $this->numberList($str);
                if ($ids === []) {
                    return '-1';
                }
                $criteria['ids'] = $ids;
                $order = 'levelID';
                break;
            case 11:
                $criteria['rated'] = true;
                $order = 'rateDate';
                break;
            case 16:
                $criteria['epicOnly'] = true;
                $order = 'rateDate';
                break;
        }

        $page = max(0, $this->int($input['page'] ?? '0'));
        $offset = $page * 10;
        $result = $this->levels->search($criteria, $offset, $limit, $order, $descending);
        if ($result['rows'] === []) {
            return '-1';
        }

        $exactPrivateSearch = isset($criteria['levelID']);
        $levelStrings = [];
        $userStrings = [];
        $hashRows = [];
        foreach ($result['rows'] as $level) {
            if ($exactPrivateSearch && (int) $level['unlisted2'] !== 0) {
                if ($accountID <= 0 || (string) $accountID !== (string) $level['extID'] || !$this->authenticator->verify($accountID, $gjp, $gjp2, $ip)) {
                    return '-1';
                }
            }

            $levelStrings[] = implode(':', [
                '1', (string) (int) $level['levelID'],
                '2', $this->field((string) $level['levelName'], 100),
                '5', (string) (int) $level['levelVersion'],
                '6', (string) (int) $level['userID'],
                '8', '10',
                '9', (string) (int) $level['starDifficulty'],
                '10', (string) (int) $level['downloads'],
                '12', (string) (int) $level['audioTrack'],
                '13', (string) (int) $level['gameVersion'],
                '14', (string) (int) $level['likes'],
                '17', (string) (int) $level['starDemon'],
                '43', (string) (int) $level['starDemonDiff'],
                '25', (string) (int) $level['starAuto'],
                '18', (string) (int) $level['starStars'],
                '19', (string) (int) $level['starFeatured'],
                '42', (string) (int) $level['starEpic'],
                '45', (string) (int) $level['objects'],
                '3', $this->field((string) $level['levelDesc'], 8192),
                '15', (string) (int) $level['levelLength'],
                '30', (string) (int) $level['original'],
                '31', (string) (int) $level['twoPlayer'],
                '37', (string) (int) $level['coins'],
                '38', (string) (int) $level['starCoins'],
                '39', (string) (int) $level['requestedStars'],
                '46', '1',
                '47', '2',
                '40', (string) (int) $level['isLDM'],
                '35', (string) (int) $level['songID'],
            ]);

            $extID = ctype_digit((string) $level['ownerExtID']) ? (string) $level['ownerExtID'] : '0';
            $userStrings[(int) $level['userID']] = (int) $level['userID'] . ':' . $this->field((string) ($level['ownerName'] ?: $level['userName']), 20) . ':' . $extID;
            $hashRows[] = [
                'levelID' => (int) $level['levelID'],
                'stars' => (int) $level['starStars'],
                'coins' => (int) $level['starCoins'],
            ];
        }

        $response = implode('|', $levelStrings) . '#' . implode('|', array_values($userStrings));
        if ($gameVersion > 18) {
            $response .= '#';
        }
        return $response . '#' . $result['total'] . ':' . $offset . ':' . $limit . '#' . LevelHash::multi($hashRows);
    }

    /** @return list<int> */
    private function numberList(string $value): array
    {
        if ($value === '') {
            return [];
        }
        $result = [];
        foreach (preg_split('/[:,]/', $value) ?: [] as $part) {
            $part = trim($part);
            if ($part !== '' && ctype_digit($part)) {
                $result[] = (int) $part;
            }
        }
        return array_values(array_unique($result));
    }

    private function numberListString(string $value): string
    {
        return implode(',', $this->numberList($value));
    }

    private function normalizeDescription(string $value, int $gameVersion): string
    {
        if ($gameVersion < 20) {
            return rtrim(strtr(base64_encode($this->field($value, 4096)), '+/', '-_'), '=');
        }
        return substr(preg_replace('/[^A-Za-z0-9_\-=]/', '', $value) ?? '', 0, 8192);
    }

    private function copyPassword(string $value): string
    {
        $value = trim($value);
        return preg_match('/^\d{1,10}$/', $value) ? $value : '0';
    }

    private function int(string $value, int $min = 0, int $max = 2147483647): int
    {
        $value = trim($value);
        if (!preg_match('/^-?\d+$/', $value)) {
            return $min;
        }
        return max($min, min($max, (int) $value));
    }

    private function bool(string $value): bool
    {
        return $value === '1' || strtolower($value) === 'true';
    }

    private function field(string $value, int $maxLength): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F]/', '', $value) ?? '';
        $value = str_replace([':', '|', '#'], '', $value);
        return substr($value, 0, $maxLength);
    }
}
