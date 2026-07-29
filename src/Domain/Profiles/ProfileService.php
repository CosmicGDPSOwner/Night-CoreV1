<?php

declare(strict_types=1);

namespace NightCore\Domain\Profiles;

use NightCore\Domain\Accounts\AccountRepository;
use NightCore\Security\AccountAuthenticator;

final class ProfileService
{
    public function __construct(
        private ProfileRepository $profiles,
        private AccountRepository $accounts,
        private AccountAuthenticator $authenticator
    ) {
    }

    public function getUserInfo(
        int $targetAccountID,
        int $viewerAccountID,
        string $gjp,
        string $gjp2,
        string $ip
    ): string {
        if ($targetAccountID <= 0) {
            return '-1';
        }

        if ($viewerAccountID > 0 && !$this->authenticator->verify($viewerAccountID, $gjp, $gjp2, $ip)) {
            return '-1';
        }

        $user = $this->profiles->findUserByAccountId($targetAccountID);
        if ($user === null) {
            return '-1';
        }

        $settings = $this->profiles->findAccountSettings($targetAccountID);
        $rank = (int) $user['isBanned'] === 0 ? $this->profiles->rankForStars((int) $user['stars']) : 0;
        $creatorPoints = (int) round((float) $user['creatorPoints'], 0, PHP_ROUND_HALF_DOWN);
        $extID = ctype_digit((string) $user['extID']) ? (string) $user['extID'] : '0';

        $result = implode(':', [
            '1', $this->field((string) $user['userName']),
            '2', (string) (int) $user['userID'],
            '13', (string) (int) $user['coins'],
            '17', (string) (int) $user['userCoins'],
            '10', (string) (int) $user['color1'],
            '11', (string) (int) $user['color2'],
            '51', (string) (int) $user['color3'],
            '3', (string) (int) $user['stars'],
            '46', (string) (int) $user['diamonds'],
            '52', (string) (int) $user['moons'],
            '4', (string) (int) $user['demons'],
            '8', (string) $creatorPoints,
            '18', (string) (int) $settings['mS'],
            '19', (string) (int) $settings['frS'],
            '50', (string) (int) $settings['cS'],
            '20', $this->field((string) $settings['youtubeurl']),
            '21', (string) (int) $user['accIcon'],
            '22', (string) (int) $user['accShip'],
            '23', (string) (int) $user['accBall'],
            '24', (string) (int) $user['accBird'],
            '25', (string) (int) $user['accDart'],
            '26', (string) (int) $user['accRobot'],
            '28', (string) (int) $user['accGlow'],
            '43', (string) (int) $user['accSpider'],
            '48', (string) (int) $user['accExplosion'],
            '53', (string) (int) $user['accSwing'],
            '54', (string) (int) $user['accJetpack'],
            '30', (string) $rank,
            '16', $extID,
            '31', '0',
            '44', $this->field((string) $settings['twitter']),
            '45', $this->field((string) $settings['twitch']),
            '49', '0',
            '55', $this->field((string) $user['dinfo']),
            '56', $this->field((string) $user['sinfo']),
            '57', $this->field((string) $user['pinfo']),
            '58', $this->field((string) $settings['discord']),
            '59', $this->field((string) $settings['instagram']),
            '60', $this->field((string) $settings['tiktok']),
            '61', $this->field((string) $settings['custom']),
        ]);

        if ($viewerAccountID === $targetAccountID) {
            $result .= ':38:0:39:0:40:0';
        }

        return $result . ':29:1';
    }

    public function searchUsers(string $term, int $page): string
    {
        $term = trim($term);
        if ($term === '') {
            return '-1';
        }

        $page = max(0, $page);
        $offset = $page * 10;
        $users = $this->profiles->search($term, $offset, 10);
        if ($users === []) {
            return '-1';
        }

        $rows = [];
        foreach ($users as $user) {
            $extID = ctype_digit((string) $user['extID']) ? (string) $user['extID'] : '0';
            $rows[] = implode(':', [
                '1', $this->field((string) $user['userName']),
                '2', (string) (int) $user['userID'],
                '13', (string) (int) $user['coins'],
                '17', (string) (int) $user['userCoins'],
                '9', (string) (int) $user['icon'],
                '10', (string) (int) $user['color1'],
                '11', (string) (int) $user['color2'],
                '51', (string) (int) $user['color3'],
                '14', (string) (int) $user['iconType'],
                '15', (string) (int) $user['special'],
                '16', $extID,
                '3', (string) (int) $user['stars'],
                '8', (string) (int) round((float) $user['creatorPoints'], 0, PHP_ROUND_HALF_DOWN),
                '4', (string) (int) $user['demons'],
                '46', (string) (int) $user['diamonds'],
                '52', (string) (int) $user['moons'],
            ]);
        }

        return implode('|', $rows) . '#' . $this->profiles->countSearch($term) . ':' . $offset . ':10';
    }

    /** @param array<string, string> $input */
    public function updateScore(
        int $accountID,
        string $gjp,
        string $gjp2,
        string $ip,
        array $input
    ): string {
        if (!$this->authenticator->verify($accountID, $gjp, $gjp2, $ip)) {
            return '-1';
        }

        $account = $this->accounts->findById($accountID);
        if ($account === null) {
            return '-1';
        }

        $userID = $this->accounts->ensureUser($accountID, (string) $account['userName']);
        $this->profiles->updateStats($userID, [
            'gameVersion' => $this->int($input['gameVersion'] ?? '0', 0, 99),
            'userName' => $this->field((string) $account['userName'], 20),
            'coins' => $this->int($input['coins'] ?? '0'),
            'secret' => $this->field($input['secret'] ?? '', 64),
            'stars' => $this->int($input['stars'] ?? '0'),
            'demons' => $this->int($input['demons'] ?? '0'),
            'icon' => $this->int($input['icon'] ?? '0', 0, 100000),
            'color1' => $this->int($input['color1'] ?? '0', 0, 10000),
            'color2' => $this->int($input['color2'] ?? '0', 0, 10000),
            'color3' => $this->int($input['color3'] ?? '0', 0, 10000),
            'iconType' => $this->int($input['iconType'] ?? '0', 0, 20),
            'userCoins' => $this->int($input['userCoins'] ?? '0'),
            'special' => $this->int($input['special'] ?? '0', 0, 10),
            'accIcon' => $this->int($input['accIcon'] ?? '0', 0, 100000),
            'accShip' => $this->int($input['accShip'] ?? '0', 0, 100000),
            'accBall' => $this->int($input['accBall'] ?? '0', 0, 100000),
            'accBird' => $this->int($input['accBird'] ?? '0', 0, 100000),
            'accDart' => $this->int($input['accDart'] ?? '0', 0, 100000),
            'accRobot' => $this->int($input['accRobot'] ?? '0', 0, 100000),
            'accGlow' => $this->int($input['accGlow'] ?? '0', 0, 1),
            'accSpider' => $this->int($input['accSpider'] ?? '0', 0, 100000),
            'accExplosion' => $this->int($input['accExplosion'] ?? '0', 0, 100000),
            'accSwing' => $this->int($input['accSwing'] ?? '0', 0, 100000),
            'accJetpack' => $this->int($input['accJetpack'] ?? '0', 0, 100000),
            'diamonds' => $this->int($input['diamonds'] ?? '0'),
            'moons' => $this->int($input['moons'] ?? '0'),
            'ip' => $this->field($ip, 45),
            'lastPlayed' => time(),
        ]);

        return (string) $userID;
    }

    /** @param array<string, string> $input */
    public function updateAccountSettings(
        int $accountID,
        string $gjp,
        string $gjp2,
        string $ip,
        array $input
    ): string {
        if (!$this->authenticator->verify($accountID, $gjp, $gjp2, $ip)) {
            return '-1';
        }

        $this->profiles->updateAccountSettings($accountID, [
            'mS' => $this->int($input['mS'] ?? '0', 0, 2),
            'frS' => $this->int($input['frS'] ?? '0', 0, 2),
            'cS' => $this->int($input['cS'] ?? '0', 0, 2),
            'youtubeurl' => $this->field($input['yt'] ?? '', 255),
            'twitter' => $this->field($input['twitter'] ?? '', 255),
            'twitch' => $this->field($input['twitch'] ?? '', 255),
            'discord' => $this->field($input['discord'] ?? '', 255),
            'instagram' => $this->field($input['instagram'] ?? '', 255),
            'tiktok' => $this->field($input['tiktok'] ?? '', 255),
            'custom' => $this->field($input['custom'] ?? '', 255),
        ]);

        return '1';
    }

    private function int(string $value, int $min = 0, int $max = 2147483647): int
    {
        if (!preg_match('/^-?\d+$/', trim($value))) {
            return $min;
        }
        return max($min, min($max, (int) $value));
    }

    private function field(string $value, int $maxLength = 255): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F]/', '', $value) ?? '';
        $value = str_replace([':', '|', '#'], '', $value);
        return substr($value, 0, $maxLength);
    }
}
