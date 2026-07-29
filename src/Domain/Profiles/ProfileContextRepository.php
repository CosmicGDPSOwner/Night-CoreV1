<?php

declare(strict_types=1);

namespace NightCore\Domain\Profiles;

use NightCore\Core\TableNames;
use PDO;

final class ProfileContextRepository
{
    public function __construct(private PDO $db, private TableNames $tables)
    {
    }

    public function isBlockedEither(int $viewerAccountID, int $targetAccountID): bool
    {
        if ($viewerAccountID <= 0 || $targetAccountID <= 0 || $viewerAccountID === $targetAccountID) {
            return false;
        }
        $query = $this->db->prepare(
            'SELECT 1 FROM ' . $this->tables->get('core_blocks') .
            ' WHERE (ownerAccountID = :viewer1 AND blockedAccountID = :target1) OR (ownerAccountID = :target2 AND blockedAccountID = :viewer2) LIMIT 1'
        );
        $query->execute([
            ':viewer1' => $viewerAccountID,
            ':target1' => $targetAccountID,
            ':target2' => $targetAccountID,
            ':viewer2' => $viewerAccountID,
        ]);
        return $query->fetchColumn() !== false;
    }

    /** @return array{state:int,request:?array<string,mixed>} */
    public function relationship(int $viewerAccountID, int $targetAccountID): array
    {
        if ($viewerAccountID <= 0 || $targetAccountID <= 0 || $viewerAccountID === $targetAccountID) {
            return ['state' => 0, 'request' => null];
        }

        [$low, $high] = $viewerAccountID < $targetAccountID
            ? [$viewerAccountID, $targetAccountID]
            : [$targetAccountID, $viewerAccountID];
        $friend = $this->db->prepare('SELECT 1 FROM ' . $this->tables->get('core_friendships') . ' WHERE accountLow = :low AND accountHigh = :high LIMIT 1');
        $friend->execute([':low' => $low, ':high' => $high]);
        if ($friend->fetchColumn() !== false) {
            return ['state' => 1, 'request' => null];
        }

        $incoming = $this->db->prepare(
            'SELECT requestID, message, createdAt FROM ' . $this->tables->get('core_friend_requests') .
            ' WHERE fromAccountID = :target AND toAccountID = :viewer LIMIT 1'
        );
        $incoming->execute([':target' => $targetAccountID, ':viewer' => $viewerAccountID]);
        $request = $incoming->fetch();
        if ($request !== false) {
            return ['state' => 3, 'request' => $request];
        }

        $outgoing = $this->db->prepare(
            'SELECT 1 FROM ' . $this->tables->get('core_friend_requests') .
            ' WHERE fromAccountID = :viewer AND toAccountID = :target LIMIT 1'
        );
        $outgoing->execute([':viewer' => $viewerAccountID, ':target' => $targetAccountID]);
        if ($outgoing->fetchColumn() !== false) {
            return ['state' => 4, 'request' => null];
        }

        return ['state' => 0, 'request' => null];
    }

    /** @return array{messages:int,requests:int,friends:int} */
    public function notificationCounts(int $accountID): array
    {
        $messages = $this->db->prepare('SELECT COUNT(*) FROM ' . $this->tables->get('core_messages') . ' WHERE toAccountID = :accountID AND isRead = 0');
        $messages->execute([':accountID' => $accountID]);

        $requests = $this->db->prepare('SELECT COUNT(*) FROM ' . $this->tables->get('core_friend_requests') . ' WHERE toAccountID = :accountID');
        $requests->execute([':accountID' => $accountID]);

        $friends = $this->db->prepare(
            'SELECT COUNT(*) FROM ' . $this->tables->get('core_friendships') .
            ' WHERE (accountLow = :accountLow AND newForLow = 1) OR (accountHigh = :accountHigh AND newForHigh = 1)'
        );
        $friends->execute([':accountLow' => $accountID, ':accountHigh' => $accountID]);

        return [
            'messages' => (int) $messages->fetchColumn(),
            'requests' => (int) $requests->fetchColumn(),
            'friends' => (int) $friends->fetchColumn(),
        ];
    }

    public function moderatorBadge(int $accountID): int
    {
        $query = $this->db->prepare('SELECT roleLevel FROM ' . $this->tables->get('core_moderator_roles') . ' WHERE accountID = :accountID LIMIT 1');
        $query->execute([':accountID' => $accountID]);
        $value = $query->fetchColumn();
        return $value === false ? 0 : max(0, min(2, (int) $value));
    }
}
