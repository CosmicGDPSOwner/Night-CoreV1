<?php

declare(strict_types=1);

namespace NightCore\Domain\Social;

use NightCore\Domain\Accounts\AccountRepository;
use NightCore\Security\AccountAuthenticator;

final class SocialService
{
    public function __construct(
        private SocialRepository $social,
        private AccountRepository $accounts,
        private AccountAuthenticator $authenticator
    ) {
    }

    public function sendFriendRequest(int $accountID, string $gjp, string $gjp2, string $ip, int $targetAccountID, string $message): string
    {
        if (!$this->auth($accountID, $gjp, $gjp2, $ip) || $targetAccountID <= 0 || $this->accounts->findById($targetAccountID) === null) {
            return '-1';
        }
        $privacy = $this->social->privacy($targetAccountID);
        if ($privacy === null || (int) $privacy['frS'] === 2) {
            return '-1';
        }
        if ((int) $privacy['frS'] === 1 && !$this->social->areFriends($accountID, $targetAccountID)) {
            return '-1';
        }
        return $this->social->createRequest($accountID, $targetAccountID, $this->field($message, 255)) ? '1' : '-1';
    }

    public function friendRequests(int $accountID, string $gjp, string $gjp2, string $ip, int $page, bool $sent): string
    {
        if (!$this->auth($accountID, $gjp, $gjp2, $ip)) {
            return '-1';
        }
        $result = $this->social->requests($accountID, $sent, max(0, $page));
        if ($result['total'] === 0) {
            return '-2';
        }
        $rows = [];
        foreach ($result['rows'] as $row) {
            $rows[] = '1:' . $this->field((string) ($row['userName'] ?? ''), 20)
                . ':2:' . (int) ($row['userID'] ?? 0)
                . ':9:' . (int) ($row['icon'] ?? 0)
                . ':10:' . (int) ($row['color1'] ?? 0)
                . ':11:' . (int) ($row['color2'] ?? 0)
                . ':14:' . (int) ($row['iconType'] ?? 0)
                . ':15:' . (int) ($row['special'] ?? 0)
                . ':16:' . (int) ($row['extID'] ?? ($sent ? $row['toAccountID'] : $row['fromAccountID']))
                . ':32:' . (int) $row['requestID']
                . ':35:' . $this->field((string) $row['message'], 255)
                . ':41:' . ((int) $row['isRead'] === 0 ? 1 : 0)
                . ':37:' . date('d/m/Y G.i', (int) $row['createdAt']);
        }
        return implode('|', $rows) . '#' . $result['total'] . ':' . max(0, $page) * 10 . ':10';
    }

    public function readFriendRequest(int $accountID, string $gjp, string $gjp2, string $ip, int $requestID): string
    {
        if (!$this->auth($accountID, $gjp, $gjp2, $ip)) {
            return '-1';
        }
        return $this->social->markRequestRead($accountID, $requestID) ? '1' : '-1';
    }

    public function acceptFriend(int $accountID, string $gjp, string $gjp2, string $ip, int $targetAccountID): string
    {
        if (!$this->auth($accountID, $gjp, $gjp2, $ip)) {
            return '-1';
        }
        return $this->social->acceptRequest($accountID, $targetAccountID) ? '1' : '-1';
    }

    public function deleteFriendRequest(int $accountID, string $gjp, string $gjp2, string $ip, int $targetAccountID): string
    {
        if (!$this->auth($accountID, $gjp, $gjp2, $ip)) {
            return '-1';
        }
        return $this->social->deleteRequest($accountID, $targetAccountID) ? '1' : '-1';
    }

    public function removeFriend(int $accountID, string $gjp, string $gjp2, string $ip, int $targetAccountID): string
    {
        if (!$this->auth($accountID, $gjp, $gjp2, $ip)) {
            return '-1';
        }
        return $this->social->removeFriend($accountID, $targetAccountID) ? '1' : '-1';
    }

    public function block(int $accountID, string $gjp, string $gjp2, string $ip, int $targetAccountID): string
    {
        if (!$this->auth($accountID, $gjp, $gjp2, $ip)) {
            return '-1';
        }
        return $this->social->block($accountID, $targetAccountID) ? '1' : '-1';
    }

    public function unblock(int $accountID, string $gjp, string $gjp2, string $ip, int $targetAccountID): string
    {
        if (!$this->auth($accountID, $gjp, $gjp2, $ip)) {
            return '-1';
        }
        return $this->social->unblock($accountID, $targetAccountID) ? '1' : '-1';
    }

    public function userList(int $accountID, string $gjp, string $gjp2, string $ip, int $type): string
    {
        if (!$this->auth($accountID, $gjp, $gjp2, $ip) || !in_array($type, [0, 1], true)) {
            return '-1';
        }
        $users = $this->social->userList($accountID, $type);
        if ($users === []) {
            return '-2';
        }
        $rows = [];
        foreach ($users as $user) {
            $rows[] = '1:' . $this->field((string) $user['userName'], 20)
                . ':2:' . (int) $user['userID']
                . ':9:' . (int) $user['icon']
                . ':10:' . (int) $user['color1']
                . ':11:' . (int) $user['color2']
                . ':14:' . (int) $user['iconType']
                . ':15:' . (int) $user['special']
                . ':16:' . (int) $user['extID']
                . ':18:0:41:' . (int) ($user['isNew'] ?? 0);
        }
        return implode('|', $rows);
    }

    public function sendMessage(int $accountID, string $gjp, string $gjp2, string $ip, int $targetAccountID, string $subject, string $body): string
    {
        if (!$this->auth($accountID, $gjp, $gjp2, $ip) || $targetAccountID <= 0 || $this->accounts->findById($targetAccountID) === null) {
            return '-1';
        }
        $privacy = $this->social->privacy($targetAccountID);
        if ($privacy === null || (int) $privacy['mS'] === 2) {
            return '-1';
        }
        if ((int) $privacy['mS'] === 1 && !$this->social->areFriends($accountID, $targetAccountID)) {
            return '-1';
        }
        return $this->social->sendMessage($accountID, $targetAccountID, $this->field($subject, 255), $this->field($body, 8192)) ? '1' : '-1';
    }

    public function messages(int $accountID, string $gjp, string $gjp2, string $ip, int $page, bool $sent): string
    {
        if (!$this->auth($accountID, $gjp, $gjp2, $ip)) {
            return '-1';
        }
        $result = $this->social->messages($accountID, $sent, max(0, $page));
        if ($result['total'] === 0) {
            return '-2';
        }
        $rows = [];
        foreach ($result['rows'] as $row) {
            $otherAccount = $sent ? (int) $row['toAccountID'] : (int) $row['fromAccountID'];
            $rows[] = '6:' . $this->field((string) ($row['userName'] ?? ''), 20)
                . ':3:' . (int) ($row['userID'] ?? 0)
                . ':2:' . (int) ($row['extID'] ?? $otherAccount)
                . ':1:' . (int) $row['messageID']
                . ':4:' . $this->field((string) $row['subject'], 255)
                . ':8:' . ((int) $row['isRead'] === 0 ? 1 : 0)
                . ':9:' . ($sent ? 1 : 0)
                . ':7:' . date('d/m/Y G.i', (int) $row['createdAt']);
        }
        return implode('|', $rows) . '#' . $result['total'] . ':' . max(0, $page) * 10 . ':10';
    }

    public function downloadMessage(int $accountID, string $gjp, string $gjp2, string $ip, int $messageID, bool $sender): string
    {
        if (!$this->auth($accountID, $gjp, $gjp2, $ip)) {
            return '-1';
        }
        $row = $this->social->message($accountID, $messageID);
        if ($row === null) {
            return '-1';
        }
        if ($sender && (int) $row['fromAccountID'] !== $accountID) {
            return '-1';
        }
        if (!$sender && (int) $row['toAccountID'] !== $accountID) {
            return '-1';
        }
        $otherAccount = $sender ? (int) $row['toAccountID'] : (int) $row['fromAccountID'];
        return '6:' . $this->field((string) ($row['userName'] ?? ''), 20)
            . ':3:' . (int) ($row['userID'] ?? 0)
            . ':2:' . (int) ($row['extID'] ?? $otherAccount)
            . ':1:' . (int) $row['messageID']
            . ':4:' . $this->field((string) $row['subject'], 255)
            . ':8:' . ((int) $row['isRead'] === 0 ? 1 : 0)
            . ':9:' . ($sender ? 1 : 0)
            . ':5:' . $this->field((string) $row['body'], 8192)
            . ':7:' . date('d/m/Y G.i', (int) $row['createdAt']);
    }

    public function deleteMessages(int $accountID, string $gjp, string $gjp2, string $ip, string $ids): string
    {
        if (!$this->auth($accountID, $gjp, $gjp2, $ip)) {
            return '-1';
        }
        $messageIDs = [];
        foreach (explode(',', $ids) as $id) {
            if (ctype_digit(trim($id))) {
                $messageIDs[] = (int) trim($id);
            }
        }
        return $this->social->deleteMessages($accountID, $messageIDs) > 0 ? '1' : '-1';
    }

    private function auth(int $accountID, string $gjp, string $gjp2, string $ip): bool
    {
        return $accountID > 0 && $this->authenticator->verify($accountID, $gjp, $gjp2, $ip);
    }

    private function field(string $value, int $max): string
    {
        $value = str_replace(["\0", '|', ':', '#'], '', trim($value));
        return strlen($value) > $max ? substr($value, 0, $max) : $value;
    }
}
