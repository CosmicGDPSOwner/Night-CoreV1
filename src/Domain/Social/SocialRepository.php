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
        $query = $this->db->prepare('SELECT 1 FROM ' . $this->tables->get('core_blocks') . ' WHERE (ownerAccountID = :a1 AND blockedAccountID = :b1) OR (ownerAccountID = :b2 AND blockedAccountID = :a2) LIMIT 1');
        $query->execute([':a1' => $a, ':b1' => $b, ':b2' => $b, ':a2' => $a]);
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
            $newForLow = $low === $otherAccountID ? 1 : 0;
            $newForHigh = $high === $otherAccountID ? 1 : 0;
            $add = $this->db->prepare('INSERT INTO ' . $this->tables->get('core_friendships') . ' (accountLow, accountHigh, newForLow, newForHigh, createdAt) VALUES (:low, :high, :newForLow, :newForHigh, :createdAt) ON DUPLICATE KEY UPDATE newForLow = GREATEST(newForLow, VALUES(newForLow)), newForHigh = GREATEST(newForHigh, VALUES(newForHigh))');
            $add->execute([':low' => $low, ':high' => $high, ':newForLow' => $newForLow, ':newForHigh' => $newForHigh, ':createdAt' => time()]);
            $delete = $this->db->prepare('DELETE FROM ' . $this->tables->get('core_friend_requests') . ' WHERE (fromAccountID = :me1 AND toAccountID = :other1) OR (fromAccountID = :other2 AND toAccountID = :me2)');
            $delete->execute([':me1' => $accountID, ':other1' => $otherAccountID, ':other2' => $otherAccountID, ':me2' => $accountID]);
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
        $query = $this->db->prepare('DELETE FROM ' . $this->tables->get('core_friend_requests') . ' WHERE (fromAccountID = :me1 AND toAccountID = :other1) OR (fromAccountID = :other2 AND toAccountID = :me2)');
        $query->execute([':me1' => $accountID, ':other1' => $otherAccountID, ':other2' => $otherAccountID, ':me2' => $accountID]);
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
            $deleteReq = $this->db->prepare('DELETE FROM ' . $this->tables->get('core_friend_requests') . ' WHERE (fromAccountID = :me1 AND toAccountID = :other1) OR (fromAccountID = :other2 AND toAccountID = :me2)');
            $deleteReq->execute([':me1' => $accountID, ':other1' => $otherAccountID, ':other2' => $otherAccountID, ':me2' => $accountID]);
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
        $states = [];
        if ($type === 0) {
            $query = $this->db->prepare('SELECT CASE WHEN f.accountLow = :meAccount THEN f.accountHigh ELSE f.accountLow END AS accountID, CASE WHEN f.accountLow = :meNew THEN f.newForLow ELSE f.newForHigh END AS isNew FROM ' . $this->tables->get('core_friendships') . ' f WHERE f.accountLow = :meLow OR f.accountHigh = :meHigh');
            $query->execute([':meAccount' => $accountID, ':meNew' => $accountID, ':meLow' => $accountID, ':meHigh' => $accountID]);
            $relationshipRows = $query->fetchAll();
            foreach ($relationshipRows as $row) {
                $states[(int) $row['accountID']] = (int) $row['isNew'];
            }
        } elseif ($type === 1) {
            $query = $this->db->prepare('SELECT blockedAccountID AS accountID, 0 AS isNew FROM ' . $this->tables->get('core_blocks') . ' WHERE ownerAccountID = :me');
            $query->execute([':me' => $accountID]);
            $relationshipRows = $query->fetchAll();
            foreach ($relationshipRows as $row) {
                $states[(int) $row['accountID']] = 0;
            }
        } else {
            return [];
        }

        $ids = array_keys($states);
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $users = $this->db->prepare('SELECT userName, userID, icon, color1, color2, iconType, special, extID FROM ' . $this->tables->get('users') . ' WHERE CAST(extID AS UNSIGNED) IN (' . $placeholders . ') ORDER BY userName ASC');
        $users->execute($ids);
        $rows = $users->fetchAll();
        foreach ($rows as &$row) {
            $row['isNew'] = $states[(int) $row['extID']] ?? 0;
        }
        unset($row);

        if ($type === 0) {
            $clearLow = $this->db->prepare('UPDATE ' . $this->tables->get('core_friendships') . ' SET newForLow = 0 WHERE accountLow = :me');
            $clearLow->execute([':me' => $accountID]);
            $clearHigh = $this->db->prepare('UPDATE ' . $this->tables->get('core_friendships') . ' SET newForHigh = 0 WHERE accountHigh = :me');
            $clearHigh->execute([':me' => $accountID]);
        }
        return $rows;
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
        $query = $this->db->prepare('SELECT m.messageID, m.fromAccountID, m.toAccountID, m.subject, m.body, m.isRead, m.createdAt, u.userName, u.userID, u.extID FROM ' . $this->tables->get('core_messages') . ' m LEFT JOIN ' . $this->tables->get('users') . ' u ON u.extID = CAST(CASE WHEN m.fromAccountID = :meCase THEN m.toAccountID ELSE m.fromAccountID END AS CHAR) WHERE m.messageID = :messageID AND (m.fromAccountID = :meFrom OR m.toAccountID = :meTo) LIMIT 1');
        $query->execute([':meCase' => $accountID, ':messageID' => $messageID, ':meFrom' => $accountID, ':meTo' => $accountID]);
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
