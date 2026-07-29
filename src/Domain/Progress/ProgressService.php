<?php

declare(strict_types=1);

namespace NightCore\Domain\Progress;

use NightCore\Domain\Accounts\AccountRepository;
use NightCore\Security\AccountAuthenticator;

final class ProgressService
{
    public function __construct(
        private ProgressRepository $progress,
        private AccountRepository $accounts,
        private AccountAuthenticator $authenticator,
        private int $maxSaveBytes
    ) {
    }

    public function backup(int $accountID, string $gjp, string $gjp2, string $ip, string $saveData, string $saveExtra): string
    {
        if (!$this->auth($accountID, $gjp, $gjp2, $ip)) {
            return '-1';
        }
        $bytes = strlen($saveData) + strlen($saveExtra);
        if ($bytes <= 0 || $bytes > $this->maxSaveBytes) {
            return '-1';
        }
        $this->progress->saveAccount($accountID, $saveData, $saveExtra);
        return '1';
    }

    public function sync(int $accountID, string $gjp, string $gjp2, string $ip): string
    {
        if (!$this->auth($accountID, $gjp, $gjp2, $ip)) {
            return '-1';
        }
        $save = $this->progress->accountSave($accountID);
        if ($save === null) {
            return '-1';
        }
        return (string) $save['saveData'] . ';21;' . (string) $save['saveExtra'];
    }

    public function submitLevelScore(int $accountID, string $gjp, string $gjp2, string $ip, int $levelID, int $percent, int $coins, int $attempts, int $scoreTime): string
    {
        if ($levelID <= 0 || !$this->auth($accountID, $gjp, $gjp2, $ip)) {
            return '-1';
        }
        $account = $this->accounts->findById($accountID);
        if ($account === null) {
            return '-1';
        }
        $userID = $this->accounts->ensureUser($accountID, (string) $account['userName']);
        $this->progress->upsertLevelScore(
            $accountID,
            $userID,
            $levelID,
            max(0, min(100, $percent)),
            max(0, min(3, $coins)),
            max(0, $attempts),
            max(0, $scoreTime)
        );
        return '1';
    }

    public function globalScores(int $accountID, string $gjp, string $gjp2, string $ip, int $type): string
    {
        if ($accountID > 0 && !$this->auth($accountID, $gjp, $gjp2, $ip)) {
            return '-1';
        }
        $rows = $type === 1 && $accountID > 0 ? $this->progress->relativeScores($accountID) : $this->progress->globalScores();
        if ($rows === []) {
            return '-1';
        }
        $out = [];
        foreach ($rows as $index => $row) {
            $out[] = '1:' . $this->field((string) $row['userName'], 20)
                . ':2:' . (int) $row['userID']
                . ':3:' . (int) $row['stars']
                . ':4:' . (int) $row['demons']
                . ':8:' . (int) $row['creatorPoints']
                . ':9:' . (int) $row['icon']
                . ':10:' . (int) $row['color1']
                . ':11:' . (int) $row['color2']
                . ':13:' . (int) $row['coins']
                . ':14:' . (int) $row['iconType']
                . ':15:' . (int) $row['special']
                . ':16:' . (int) $row['extID']
                . ':17:' . (int) $row['userCoins']
                . ':46:' . (int) $row['diamonds']
                . ':6:' . ($index + 1);
        }
        return implode('|', $out);
    }

    public function levelScores(int $accountID, string $gjp, string $gjp2, string $ip, int $levelID): string
    {
        if ($levelID <= 0 || ($accountID > 0 && !$this->auth($accountID, $gjp, $gjp2, $ip))) {
            return '-1';
        }
        $rows = $this->progress->levelScores($levelID);
        if ($rows === []) {
            return '-1';
        }
        $out = [];
        foreach ($rows as $index => $row) {
            $out[] = '1:' . $this->field((string) ($row['userName'] ?? ''), 20)
                . ':2:' . (int) $row['userID']
                . ':3:' . (int) $row['percent']
                . ':4:' . (int) $row['coins']
                . ':5:' . (int) $row['attempts']
                . ':6:' . ($index + 1)
                . ':9:' . (int) ($row['icon'] ?? 0)
                . ':10:' . (int) ($row['color1'] ?? 0)
                . ':11:' . (int) ($row['color2'] ?? 0)
                . ':14:' . (int) ($row['iconType'] ?? 0)
                . ':15:' . (int) ($row['special'] ?? 0)
                . ':16:' . (int) ($row['extID'] ?? $row['accountID'])
                . ':42:' . (int) $row['scoreTime'];
        }
        return implode('|', $out);
    }

    public function daily(int $slotType): string
    {
        if (!in_array($slotType, [0, 1, 2], true)) {
            return '-1';
        }
        $now = time();
        $row = $this->progress->currentRotation($slotType, $now);
        if ($row === null) {
            return '-1';
        }
        $id = (int) $row['slotID'];
        if ($slotType === 1) {
            $id += 100001;
        } elseif ($slotType === 2) {
            $id += 200001;
        }
        $endsAt = (int) $row['endsAt'];
        if ($endsAt <= $now) {
            $endsAt = $slotType === 1 ? strtotime('next monday', $now) : strtotime('tomorrow 00:00:00', $now);
        }
        return $id . '|' . max(0, $endsAt - $now);
    }

    public function gauntlets(): string
    {
        $rows = $this->progress->gauntlets();
        if ($rows === []) {
            return '-1';
        }
        $out = [];
        foreach ($rows as $row) {
            $ids = $this->idList((string) $row['levelIDs'], 5);
            if (count($ids) !== 5) {
                continue;
            }
            $out[] = '1:' . (int) $row['gauntletID'] . ':3:' . $ids[0] . ':4:' . $ids[1] . ':5:' . $ids[2] . ':6:' . $ids[3] . ':7:' . $ids[4];
        }
        return $out === [] ? '-1' : implode('|', $out);
    }

    public function mapPacks(int $page): string
    {
        $page = max(0, $page);
        $result = $this->progress->mapPacks($page);
        if ($result['total'] === 0) {
            return '-1';
        }
        $rows = [];
        foreach ($result['rows'] as $row) {
            $rows[] = '1:' . (int) $row['packID']
                . ':2:' . $this->field((string) $row['name'], 100)
                . ':3:' . $this->idListString((string) $row['levelIDs'], 100)
                . ':4:' . (int) $row['stars']
                . ':5:' . (int) $row['coins']
                . ':6:' . (int) $row['difficulty']
                . ':7:' . $this->field((string) $row['color1'], 16)
                . ':8:' . $this->field((string) $row['color2'], 16);
        }
        return implode('|', $rows) . '#' . $result['total'] . ':' . $page * 10 . ':10';
    }

    public function uploadList(int $accountID, string $gjp, string $gjp2, string $ip, int $listID, string $name, string $description, string $levelIDs, int $reward, int $unlisted): string
    {
        if (!$this->auth($accountID, $gjp, $gjp2, $ip)) {
            return '-1';
        }
        $account = $this->accounts->findById($accountID);
        if ($account === null) {
            return '-1';
        }
        $name = $this->field($name, 100);
        $levels = $this->idListString($levelIDs, 100);
        if ($name === '' || $levels === '') {
            return '-1';
        }
        $userID = $this->accounts->ensureUser($accountID, (string) $account['userName']);
        $saved = $this->progress->saveList($accountID, $userID, max(0, $listID), $name, $this->field($description, 4096), $levels, max(0, $reward), $unlisted === 0 ? 0 : 1);
        return $saved > 0 ? (string) $saved : '-1';
    }

    public function deleteList(int $accountID, string $gjp, string $gjp2, string $ip, int $listID): string
    {
        if (!$this->auth($accountID, $gjp, $gjp2, $ip) || $listID <= 0) {
            return '-1';
        }
        return $this->progress->deleteList($accountID, $listID) ? '1' : '-1';
    }

    public function lists(int $accountID, string $gjp, string $gjp2, string $ip, string $search, int $page, int $type): string
    {
        if ($accountID > 0 && !$this->auth($accountID, $gjp, $gjp2, $ip)) {
            return '-1';
        }
        $page = max(0, $page);
        $result = $this->progress->lists($this->field($search, 100), $page, $type, max(0, $accountID));
        if ($result['total'] === 0) {
            return '-1';
        }
        $rows = [];
        foreach ($result['rows'] as $row) {
            $rows[] = '1:' . (int) $row['listID']
                . ':2:' . $this->field((string) $row['listName'], 100)
                . ':3:' . $this->field((string) $row['listDesc'], 4096)
                . ':5:' . (int) $row['userID']
                . ':7:' . (int) $row['downloads']
                . ':10:' . (int) $row['likes']
                . ':14:' . (int) $row['reward']
                . ':19:' . $this->idListString((string) $row['levelIDs'], 100)
                . ':28:' . date('d-m-Y G-i', (int) $row['createdAt'])
                . ':29:' . date('d-m-Y G-i', (int) $row['updatedAt']);
        }
        return implode('|', $rows) . '#' . $result['total'] . ':' . $page * 10 . ':10';
    }

    public function rotationLevelId(int $slotType, int $slotID): ?int
    {
        return $this->progress->rotationLevelId($slotType, $slotID);
    }

    /** @return array<int,int> */
    public function gauntletLevelIds(int $gauntletID): array
    {
        return $this->progress->gauntletLevelIds($gauntletID);
    }

    private function auth(int $accountID, string $gjp, string $gjp2, string $ip): bool
    {
        return $accountID > 0 && $this->authenticator->verify($accountID, $gjp, $gjp2, $ip);
    }

    private function field(string $value, int $max): string
    {
        $value = str_replace(["\0", '|', '#'], '', trim($value));
        return strlen($value) > $max ? substr($value, 0, $max) : $value;
    }

    /** @return array<int,int> */
    private function idList(string $value, int $max): array
    {
        $ids = [];
        foreach (preg_split('/[,;\s]+/', $value) ?: [] as $part) {
            if ($part !== '' && ctype_digit($part)) {
                $id = (int) $part;
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
        }
        return array_slice(array_values(array_unique($ids)), 0, $max);
    }

    private function idListString(string $value, int $max): string
    {
        return implode(',', $this->idList($value, $max));
    }
}
