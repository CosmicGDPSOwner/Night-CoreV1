<?php

declare(strict_types=1);

namespace NightCore\Domain\Social;

use NightCore\Core\TableNames;
use PDO;
use Throwable;

final class SocialRepository
{
    public function __construct(private PDO $db, private TableNames $tables)
    {
    }

    public function privacy(int $accountID): ?array
    {
        $query = $this->db->prepare('SELECT accountID, mS, frS FROM ' . $this->tables->get('accounts') . ' WHERE accountID = :accountID LIMIT 1');
        $query->execute([':accountID' => $accountID]);
        $row = $query->fetch();
        return $row === false ? null : $row;
    }

    public function areFriends(int $a, int $b): bool
    {
        [$low, $high] = $this->pair($a, $b);
        $query = $this->db->prepare('SELECT 1 FROM ' . $this->tables->get('core_friendships') . ' WHERE accountLow = :low AND accountHigh = :high LIMIT 1');
        $query->execute([':low' => $low, ':high' => $high]);
        return $query->fetchColumn() !== false;
    }

    public function isBlockedEither(int $a, int $b): bool
    {
        $query = $this->db->prepare('SELECT 1 FROM ' . $this->tables->get('core_blocks') . ' WHERE (ownerAccountID = :a AND blockedAccountID = :b) OR (ownerAccountID = :b AND blockedAccountID = :a) LIMIT 1');
        $query->execute([':a' => $a, ':b' => $b]);
        return $query->fetchColumn() !== false;
    }

    public function createRequest(int $from, int $to, string $message): bool
    {
        if ($from === $to || $this->areFriends($from, $to) || $this->isBlockedEither($from, $to)) {
            return false;
        }
        $query = $this->db->prepare('INSERT INTO ' . $this->tables->get('core_friend_requests') . ' (fromAccountID, toAccountID, message, isRead, createdAt) VALUES (:from, :to, :message, 0, :createdAt) ON DUPLICATE KEY UPDATE message = VALUES(message), isRead = 0, createdAt = VALUES(createdAt)');
        $query->execute([':from' => $from, ':to' => $to, ':message' => $message, ':createdAt' => time()]);
        return true;
    }

    /** @return array{rows:array<int,array<string,mixed>>,total:int} */
    public function requests(int $accountID, bool $sent, int $page): array
    {
        $offset = max(0, $page) * 10;
        $where = $sent ? 'r.fromAccountID = :accountID' : 'r.toAccountID = :accountID';
        $other = $sent ? 'r.toAccountID' : 'r.fromAccountID';
        $count = $this->db->prepare('SELECT COUNT(*) FROM ' . $this->tables->get('core_friend_requests') . ' r WHERE ' . $where);
        $count->execute([':accountID' => $accountID]);
        $total = (int) $count->fetchColumn();
        $query = $this->db->prepare('SELECT r.requestID, r.fromAccountID, r.toAccountID, r.message, r.isRead, r.createdAt, u.userName, u.userID, u.icon, u.color1, u.color2, u.iconType, u.special, u.extID FROM ' . $this->tables->get('core_friend_requests') . ' r LEFT JOIN ' . $this->tables->get('users') . ' u ON u.extID = CAST(' . $other . ' AS CHAR) WHERE ' . $where . ' ORDER BY r.createdAt DESC LIMIT 10 OFFSET ' . $offset);
        $query->execute([':accountID' => $accountID]);
        return ['rows' => $query->fetchAll(), 'total' => $total];
    }

    public function markRequestRead(int $accountID, int $requestID): bool
    {
        $query = $this->db->prepare('UPDATE ' . $this->tables->get('core_friend_requests') . ' SET isRead = 1 WHERE requestID = :requestID AND toAccountID = :accountID');
        $query->execute([':requestID' => $requestID, ':accountID' => $accountID]);
        return $query->rowCount() > 0;
    }

    public function acceptRequest(int $accountID, int $otherAccountID): bool
    {
        if ($accountID <= 0 || $otherAccountID <= 0 || $accountID === $otherAccountID) {
            return false;
        }
        $this->db->beginTransaction();
        try {
            $find = $this->db->prepare('SELECT requestID FROM ' . $this->tables->get('core_friend_requests') . ' WHERE fromAccountID = :other AND toAccountID = :me LIMIT 1 FOR UPDATE');
            $find->execute([':other' => $otherAccountID, ':me' => $accountID]);
            if ($find->fetchColumn() === false) {
                $this->db->rollBack();
                return false;
            }
            [$low, $high] = $this->pair($accountID, $otherAccountID);
            $add = $this->db->prepare('INSERT INTO ' . $this->tables->get('core_friendships') . ' (accountLow, accountHigh, createdAt) VALUES (:low, :high, :createdAt) ON DUPLICATE KEY UPDATE createdAt = createdAt');
            $add->execute([':low' => $low, ':high' => $high, ':createdAt' => time()]);
            $delete = $this->db->prepare('DELETE FROM ' . $this->tables->get('core_friend_requests') . ' WHERE (fromAccountID = :me AND toAccountID = :other) OR (fromAccountID = :other AND toAccountID = :me)');
            $delete->execute([':me' => $accountID, ':other' => $otherAccountID]);
            $this->db->commit();
            return true;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function deleteRequest(int $accountID, int $otherAccountID): bool
    {
        $query = $this->db->prepare('DELETE FROM ' . $this->tables->get('core_friend_requests') . ' WHERE (fromAccountID = :me AND toAccountID = :other) OR (fromAccountID = :other AND toAccountID = :me)');
        $query->execute([':me' => $accountID, ':other' => $otherAccountID]);
        return $query->rowCount() > 0;
    }

    public function removeFriend(int $accountID, int $otherAccountID): bool
    {
        [$low, $high] = $this->pair($accountID, $otherAccountID);
        $query = $this->db->prepare('DELETE FROM ' . $this->tables->get('core_friendships') . ' WHERE accountLow = :low AND accountHigh = :high');
        $query->execute([':low' => $low, ':high' => $high]);
        return $query->rowCount() > 0;
    }

    public function block(int $accountID, int $otherAccountID): bool
    {
        if ($accountID === $otherAccountID || $otherAccountID <= 0) {
            return false;
        }
        $this->db->beginTransaction();
        try {
            [$low, $high] = $this->pair($accountID, $otherAccountID);
            $deleteFriend = $this->db->prepare('DELETE FROM ' . $this->tables->get('core_friendships') . ' WHERE accountLow = :low AND accountHigh = :high');
            $deleteFriend->execute([':low' => $low, ':high' => $high]);
            $deleteReq = $this->db->prepare('DELETE FROM ' . $this->tables->get('core_friend_requests') . ' WHERE (fromAccountID = :me AND toAccountID = :other) OR (fromAccountID = :other AND toAccountID = :me)');
            $deleteReq->execute([':me' => $accountID, ':other' => $otherAccountID]);
            $insert = $this->db->prepare('INSERT INTO ' . $this->tables->get('core_blocks') . ' (ownerAccountID, blockedAccountID, createdAt) VALUES (:me, :other, :createdAt) ON DUPLICATE KEY UPDATE createdAt = VALUES(createdAt)');
            $insert->execute([':me' => $accountID, ':other' => $otherAccountID, ':createdAt' => time()]);
            $this->db->commit();
            return true;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function unblock(int $accountID, int $otherAccountID): bool
    {
        $query = $this->db->prepare('DELETE FROM ' . $this->tables->get('core_blocks') . ' WHERE ownerAccountID = :me AND blockedAccountID = :other');
        $query->execute([':me' => $accountID, ':other' => $otherAccountID]);
        return $query->rowCount() > 0;
    }

    /** @return array<int,array<string,mixed>> */
    public function userList(int $accountID, int $type): array
    {
        if ($type === 0) {
            $query = $this->db->prepare('SELECT CASE WHEN f.accountLow = :me THEN f.accountHigh ELSE f.accountLow END AS accountID FROM ' . $this->tables->get('core_friendships') . ' f WHERE f.accountLow = :me OR f.accountHigh = :me');
            $query->execute([':me' => $accountID]);
        } elseif ($type === 1) {
            $query = $this->db->prepare('SELECT blockedAccountID AS accountID FROM ' . $this->tables->get('core_blocks') . ' WHERE ownerAccountID = :me');
            $query->execute([':me' => $accountID]);
        } else {
            return [];
        }
        $ids = array_map('intval', array_column($query->fetchAll(), 'accountID'));
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $users = $this->db->prepare('SELECT userName, userID, icon, color1, color2, iconType, special, extID FROM ' . $this->tables->get('users') . ' WHERE CAST(extID AS UNSIGNED) IN (' . $placeholders . ') ORDER BY userName ASC');
        $users->execute($ids);
        return $users->fetchAll();
    }

    public function sendMessage(int $from, int $to, string $subject, string $body): bool
    {
        if ($from === $to || $this->isBlockedEither($from, $to)) {
            return false;
        }
        $query = $this->db->prepare('INSERT INTO ' . $this->tables->get('core_messages') . ' (fromAccountID, toAccountID, subject, body, isRead, createdAt) VALUES (:from, :to, :subject, :body, 0, :createdAt)');
        $query->execute([':from' => $from, ':to' => $to, ':subject' => $subject, ':body' => $body, ':createdAt' => time()]);
        return true;
    }

    /** @return array{rows:array<int,array<string,mixed>>,total:int} */
    public function messages(int $accountID, bool $sent, int $page): array
    {
        $offset = max(0, $page) * 10;
        $where = $sent ? 'm.fromAccountID = :me' : 'm.toAccountID = :me';
        $other = $sent ? 'm.toAccountID' : 'm.fromAccountID';
        $count = $this->db->prepare('SELECT COUNT(*) FROM ' . $this->tables->get('core_messages') . ' m WHERE ' . $where);
        $count->execute([':me' => $accountID]);
        $total = (int) $count->fetchColumn();
        $query = $this->db->prepare('SELECT m.messageID, m.fromAccountID, m.toAccountID, m.subject, m.isRead, m.createdAt, u.userName, u.userID, u.extID FROM ' . $this->tables->get('core_messages') . ' m LEFT JOIN ' . $this->tables->get('users') . ' u ON u.extID = CAST(' . $other . ' AS CHAR) WHERE ' . $where . ' ORDER BY m.messageID DESC LIMIT 10 OFFSET ' . $offset);
        $query->execute([':me' => $accountID]);
        return ['rows' => $query->fetchAll(), 'total' => $total];
    }

    public function message(int $accountID, int $messageID): ?array
    {
        $query = $this->db->prepare('SELECT m.messageID, m.fromAccountID, m.toAccountID, m.subject, m.body, m.isRead, m.createdAt, u.userName, u.userID, u.extID FROM ' . $this->tables->get('core_messages') . ' m LEFT JOIN ' . $this->tables->get('users') . ' u ON u.extID = CAST(CASE WHEN m.fromAccountID = :me THEN m.toAccountID ELSE m.fromAccountID END AS CHAR) WHERE m.messageID = :messageID AND (m.fromAccountID = :me OR m.toAccountID = :me) LIMIT 1');
        $query->execute([':me' => $accountID, ':messageID' => $messageID]);
        $row = $query->fetch();
        if ($row === false) {
            return null;
        }
        if ((int) $row['toAccountID'] === $accountID && (int) $row['isRead'] === 0) {
            $mark = $this->db->prepare('UPDATE ' . $this->tables->get('core_messages') . ' SET isRead = 1 WHERE messageID = :messageID');
            $mark->execute([':messageID' => $messageID]);
            $row['isRead'] = 1;
        }
        return $row;
    }

    /** @param array<int,int> $messageIDs */
    public function deleteMessages(int $accountID, array $messageIDs): int
    {
        $messageIDs = array_values(array_unique(array_filter($messageIDs, static fn(int $id): bool => $id > 0)));
        if ($messageIDs === []) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($messageIDs), '?'));
        $query = $this->db->prepare('DELETE FROM ' . $this->tables->get('core_messages') . ' WHERE messageID IN (' . $placeholders . ') AND (fromAccountID = ? OR toAccountID = ?)');
        $query->execute([...$messageIDs, $accountID, $accountID]);
        return $query->rowCount();
    }

    /** @return array{0:int,1:int} */
    private function pair(int $a, int $b): array
    {
        return $a < $b ? [$a, $b] : [$b, $a];
    }
}
