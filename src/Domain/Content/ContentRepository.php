<?php

declare(strict_types=1);

namespace NightCore\Domain\Content;

use NightCore\Core\TableNames;
use PDO;
use Throwable;

final class ContentRepository
{
    public function __construct(private PDO $db, private TableNames $tables)
    {
    }

    public function findSong(int $songID): ?array
    {
        $query = $this->db->prepare('SELECT songID, name, authorID, authorName, size, download, isDisabled FROM ' . $this->tables->get('core_songs') . ' WHERE songID = :songID LIMIT 1');
        $query->execute([':songID' => $songID]);
        $row = $query->fetch();
        return $row === false ? null : $row;
    }

    /** @param array<string,mixed> $song */
    public function upsertSong(array $song): void
    {
        $query = $this->db->prepare(
            'INSERT INTO ' . $this->tables->get('core_songs') . ' (songID, name, authorID, authorName, size, download, isDisabled, createdAt) '
            . 'VALUES (:songID, :name, :authorID, :authorName, :size, :download, :isDisabled, :createdAt) '
            . 'ON DUPLICATE KEY UPDATE name = VALUES(name), authorID = VALUES(authorID), authorName = VALUES(authorName), '
            . 'size = VALUES(size), download = VALUES(download), isDisabled = VALUES(isDisabled), createdAt = VALUES(createdAt)'
        );
        $query->execute([
            ':songID' => (int) $song['songID'],
            ':name' => (string) $song['name'],
            ':authorID' => (int) $song['authorID'],
            ':authorName' => (string) $song['authorName'],
            ':size' => (string) $song['size'],
            ':download' => (string) $song['download'],
            ':isDisabled' => (int) ($song['isDisabled'] ?? 0),
            ':createdAt' => (int) ($song['createdAt'] ?? time()),
        ]);
    }

    public function reserveLocalSong(string $originalName, int $uploadedAt): int
    {
        $query = $this->db->prepare(
            'INSERT INTO ' . $this->tables->get('core_local_songs') . ' (originalName, sha256, bytes, uploadedAt) '
            . 'VALUES (:originalName, :sha256, 0, :uploadedAt)'
        );
        $query->execute([
            ':originalName' => $originalName,
            ':sha256' => str_repeat('0', 64),
            ':uploadedAt' => $uploadedAt,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function finalizeLocalSong(int $songID, string $sha256, int $bytes): void
    {
        $query = $this->db->prepare(
            'UPDATE ' . $this->tables->get('core_local_songs') . ' SET sha256 = :sha256, bytes = :bytes WHERE songID = :songID'
        );
        $query->execute([':sha256' => $sha256, ':bytes' => $bytes, ':songID' => $songID]);
        if ($query->rowCount() === 0) {
            throw new \RuntimeException('Reserved local song row disappeared before finalization.');
        }
    }

    public function findLocalSong(int $songID): ?array
    {
        $query = $this->db->prepare(
            'SELECT l.songID, l.originalName, l.sha256, l.bytes, l.uploadedAt, s.name, s.authorName, s.size, s.download, s.isDisabled '
            . 'FROM ' . $this->tables->get('core_local_songs') . ' l '
            . 'LEFT JOIN ' . $this->tables->get('core_songs') . ' s ON s.songID = l.songID '
            . 'WHERE l.songID = :songID LIMIT 1'
        );
        $query->execute([':songID' => $songID]);
        $row = $query->fetch();
        return $row === false ? null : $row;
    }

    /** @return list<array<string,mixed>> */
    public function listLocalSongs(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $query = $this->db->query(
            'SELECT l.songID, l.originalName, l.sha256, l.bytes, l.uploadedAt, s.name, s.authorName, s.size, s.download, s.isDisabled '
            . 'FROM ' . $this->tables->get('core_local_songs') . ' l '
            . 'LEFT JOIN ' . $this->tables->get('core_songs') . ' s ON s.songID = l.songID '
            . 'ORDER BY l.songID DESC LIMIT ' . $limit
        );
        return $query->fetchAll();
    }

    public function deleteLocalSongRows(int $songID): void
    {
        $this->db->beginTransaction();
        try {
            $song = $this->db->prepare('DELETE FROM ' . $this->tables->get('core_songs') . ' WHERE songID = :songID');
            $song->execute([':songID' => $songID]);
            $local = $this->db->prepare('DELETE FROM ' . $this->tables->get('core_local_songs') . ' WHERE songID = :songID');
            $local->execute([':songID' => $songID]);
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function canAttemptSongFetch(int $songID, int $now): bool
    {
        $query = $this->db->prepare('SELECT retryAfter FROM ' . $this->tables->get('core_song_fetch_failures') . ' WHERE songID = :songID LIMIT 1');
        $query->execute([':songID' => $songID]);
        $retryAfter = $query->fetchColumn();
        return $retryAfter === false || (int) $retryAfter <= $now;
    }

    public function recordSongFetchFailure(int $songID, int $retryAfter, int $now): void
    {
        $query = $this->db->prepare(
            'INSERT INTO ' . $this->tables->get('core_song_fetch_failures') . ' (songID, retryAfter, attempts, updatedAt) '
            . 'VALUES (:songID, :retryAfter, 1, :updatedAt) '
            . 'ON DUPLICATE KEY UPDATE retryAfter = VALUES(retryAfter), attempts = attempts + 1, updatedAt = VALUES(updatedAt)'
        );
        $query->execute([':songID' => $songID, ':retryAfter' => $retryAfter, ':updatedAt' => $now]);
    }

    public function clearSongFetchFailure(int $songID): void
    {
        $query = $this->db->prepare('DELETE FROM ' . $this->tables->get('core_song_fetch_failures') . ' WHERE songID = :songID');
        $query->execute([':songID' => $songID]);
    }

    public function addComment(int $accountID, int $userID, string $userName, int $targetType, int $targetID, string $comment, int $percent): int
    {
        $query = $this->db->prepare('INSERT INTO ' . $this->tables->get('core_comments') . ' (accountID, userID, userName, targetType, targetID, comment, percent, createdAt) VALUES (:accountID, :userID, :userName, :targetType, :targetID, :comment, :percent, :createdAt)');
        $query->execute([
            ':accountID' => $accountID,
            ':userID' => $userID,
            ':userName' => $userName,
            ':targetType' => $targetType,
            ':targetID' => $targetID,
            ':comment' => $comment,
            ':percent' => $percent,
            ':createdAt' => time(),
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function deleteComment(int $commentID, int $accountID, bool $moderator = false): bool
    {
        $sql = 'DELETE FROM ' . $this->tables->get('core_comments') . ' WHERE commentID = :commentID';
        if (!$moderator) {
            $sql .= ' AND accountID = :accountID';
        }
        $query = $this->db->prepare($sql);
        $params = [':commentID' => $commentID];
        if (!$moderator) {
            $params[':accountID'] = $accountID;
        }
        $query->execute($params);
        return $query->rowCount() > 0;
    }

    /** @return array{rows:array<int,array<string,mixed>>,total:int} */
    public function commentsForTarget(int $targetType, int $targetID, int $page, int $count, bool $byLikes = false): array
    {
        $offset = $page * $count;
        $order = $byLikes ? 'c.likes DESC, c.commentID DESC' : 'c.commentID DESC';
        $countQuery = $this->db->prepare('SELECT COUNT(*) FROM ' . $this->tables->get('core_comments') . ' WHERE targetType = :targetType AND targetID = :targetID');
        $countQuery->execute([':targetType' => $targetType, ':targetID' => $targetID]);
        $total = (int) $countQuery->fetchColumn();

        $sql = 'SELECT c.commentID, c.accountID, c.userID, c.userName, c.targetID, c.comment, c.percent, c.likes, c.isSpam, c.createdAt, '
            . 'u.icon, u.color1, u.color2, u.iconType, u.special, u.extID '
            . 'FROM ' . $this->tables->get('core_comments') . ' c '
            . 'LEFT JOIN ' . $this->tables->get('users') . ' u ON u.userID = c.userID '
            . 'WHERE c.targetType = :targetType AND c.targetID = :targetID ORDER BY ' . $order . ' LIMIT ' . $count . ' OFFSET ' . $offset;
        $query = $this->db->prepare($sql);
        $query->execute([':targetType' => $targetType, ':targetID' => $targetID]);
        return ['rows' => $query->fetchAll(), 'total' => $total];
    }

    /** @return array{rows:array<int,array<string,mixed>>,total:int} */
    public function commentsByUser(int $userID, int $page, int $count): array
    {
        $offset = $page * $count;
        $countQuery = $this->db->prepare('SELECT COUNT(*) FROM ' . $this->tables->get('core_comments') . ' WHERE targetType = 0 AND userID = :userID');
        $countQuery->execute([':userID' => $userID]);
        $total = (int) $countQuery->fetchColumn();
        $query = $this->db->prepare('SELECT c.commentID, c.accountID, c.userID, c.userName, c.targetID, c.comment, c.percent, c.likes, c.isSpam, c.createdAt, u.icon, u.color1, u.color2, u.iconType, u.special, u.extID FROM ' . $this->tables->get('core_comments') . ' c LEFT JOIN ' . $this->tables->get('users') . ' u ON u.userID = c.userID WHERE c.targetType = 0 AND c.userID = :userID ORDER BY c.commentID DESC LIMIT ' . $count . ' OFFSET ' . $offset);
        $query->execute([':userID' => $userID]);
        return ['rows' => $query->fetchAll(), 'total' => $total];
    }

    public function applyLike(int $accountID, int $itemType, int $itemID, int $value): bool
    {
        if (!in_array($itemType, [1, 2, 3, 4], true) || !in_array($value, [-1, 1], true)) {
            return false;
        }

        $this->db->beginTransaction();
        try {
            $find = $this->db->prepare('SELECT value FROM ' . $this->tables->get('core_item_likes') . ' WHERE accountID = :accountID AND itemType = :itemType AND itemID = :itemID FOR UPDATE');
            $find->execute([':accountID' => $accountID, ':itemType' => $itemType, ':itemID' => $itemID]);
            $old = $find->fetchColumn();
            if ($old !== false && (int) $old === $value) {
                $this->db->rollBack();
                return false;
            }

            if ($old === false) {
                $write = $this->db->prepare('INSERT INTO ' . $this->tables->get('core_item_likes') . ' (accountID, itemType, itemID, value, createdAt) VALUES (:accountID, :itemType, :itemID, :value, :createdAt)');
                $delta = $value;
            } else {
                $write = $this->db->prepare('UPDATE ' . $this->tables->get('core_item_likes') . ' SET value = :value, createdAt = :createdAt WHERE accountID = :accountID AND itemType = :itemType AND itemID = :itemID');
                $delta = $value - (int) $old;
            }
            $write->execute([':accountID' => $accountID, ':itemType' => $itemType, ':itemID' => $itemID, ':value' => $value, ':createdAt' => time()]);

            if ($itemType === 1) {
                $table = $this->tables->get('levels');
                $idColumn = 'levelID';
                $extra = '';
                $params = [':delta' => $delta, ':itemID' => $itemID];
            } elseif ($itemType === 4) {
                $table = $this->tables->get('core_level_lists');
                $idColumn = 'listID';
                $extra = '';
                $params = [':delta' => $delta, ':itemID' => $itemID];
            } else {
                $table = $this->tables->get('core_comments');
                $idColumn = 'commentID';
                $extra = ' AND targetType = :targetType';
                $params = [':delta' => $delta, ':itemID' => $itemID, ':targetType' => $itemType === 2 ? 0 : 1];
            }
            $update = $this->db->prepare('UPDATE ' . $table . ' SET likes = GREATEST(-2147483648, likes + :delta) WHERE ' . $idColumn . ' = :itemID' . $extra);
            $update->execute($params);
            if ($update->rowCount() === 0) {
                $this->db->rollBack();
                return false;
            }
            $this->db->commit();
            return true;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function report(int $accountID, int $itemType, int $itemID, string $reason): void
    {
        $query = $this->db->prepare('INSERT INTO ' . $this->tables->get('core_reports') . ' (accountID, itemType, itemID, reason, createdAt) VALUES (:accountID, :itemType, :itemID, :reason, :createdAt)');
        $query->execute([':accountID' => $accountID, ':itemType' => $itemType, ':itemID' => $itemID, ':reason' => $reason, ':createdAt' => time()]);
    }
}
