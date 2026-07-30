<?php

declare(strict_types=1);

namespace NightCore\Domain\Moderation;

use NightCore\Core\TableNames;
use PDO;
use Throwable;

final class ModerationRepository
{
    public function __construct(private PDO $db, private TableNames $tables)
    {
    }

    public function role(int $accountID): ?array
    {
        $query = $this->db->prepare('SELECT accountID, roleLevel, roleName, canRate, canFeature, canEpic, canModerateComments, canBan FROM ' . $this->tables->get('core_moderator_roles') . ' WHERE accountID = :accountID LIMIT 1');
        $query->execute([':accountID' => $accountID]);
        $row = $query->fetch();
        return $row === false ? null : $row;
    }

    public function accountIdByUsername(string $userName): ?int
    {
        $query = $this->db->prepare('SELECT accountID FROM ' . $this->tables->get('accounts') . ' WHERE LOWER(userName) = LOWER(:userName) LIMIT 1');
        $query->execute([':userName' => $userName]);
        $value = $query->fetchColumn();
        return $value === false ? null : (int) $value;
    }

    public function setAccountBan(int $targetAccountID, int $actorAccountID, bool $banned): bool
    {
        if ($targetAccountID <= 0) {
            return false;
        }
        $query = $this->db->prepare(
            'INSERT INTO ' . $this->tables->get('core_user_moderation') .
            ' (accountID, accountBanned, bannedBy, bannedAt, updatedAt) VALUES (:accountID, :banned, :actor, :bannedAt, :updatedAt) '
            . 'ON DUPLICATE KEY UPDATE accountBanned = VALUES(accountBanned), bannedBy = VALUES(bannedBy), bannedAt = VALUES(bannedAt), updatedAt = VALUES(updatedAt)'
        );
        $now = time();
        $query->execute([
            ':accountID' => $targetAccountID,
            ':banned' => $banned ? 1 : 0,
            ':actor' => $actorAccountID,
            ':bannedAt' => $banned ? $now : 0,
            ':updatedAt' => $now,
        ]);
        return true;
    }

    public function setLeaderboardBan(int $targetAccountID, int $actorAccountID, bool $banned): bool
    {
        if ($targetAccountID <= 0) {
            return false;
        }
        $this->db->beginTransaction();
        try {
            $state = $this->db->prepare(
                'INSERT INTO ' . $this->tables->get('core_user_moderation') .
                ' (accountID, leaderboardBanned, leaderboardBannedBy, leaderboardBannedAt, updatedAt) VALUES (:accountID, :banned, :actor, :bannedAt, :updatedAt) '
                . 'ON DUPLICATE KEY UPDATE leaderboardBanned = VALUES(leaderboardBanned), leaderboardBannedBy = VALUES(leaderboardBannedBy), leaderboardBannedAt = VALUES(leaderboardBannedAt), updatedAt = VALUES(updatedAt)'
            );
            $now = time();
            $state->execute([
                ':accountID' => $targetAccountID,
                ':banned' => $banned ? 1 : 0,
                ':actor' => $actorAccountID,
                ':bannedAt' => $banned ? $now : 0,
                ':updatedAt' => $now,
            ]);

            // Keep the legacy users.isBanned flag synchronized so existing global
            // leaderboard/profile compatibility paths also hide leaderboard-banned users.
            $users = $this->db->prepare('UPDATE ' . $this->tables->get('users') . ' SET isBanned = :banned WHERE CAST(extID AS UNSIGNED) = :accountID');
            $users->execute([':banned' => $banned ? 1 : 0, ':accountID' => $targetAccountID]);
            $this->db->commit();
            return true;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function suggest(int $levelID, int $accountID, int $stars, int $feature): void
    {
        $query = $this->db->prepare('INSERT INTO ' . $this->tables->get('core_star_suggestions') . ' (levelID, accountID, stars, feature, createdAt) VALUES (:levelID, :accountID, :stars, :feature, :createdAt) ON DUPLICATE KEY UPDATE stars = VALUES(stars), feature = VALUES(feature), createdAt = VALUES(createdAt)');
        $query->execute([':levelID' => $levelID, ':accountID' => $accountID, ':stars' => $stars, ':feature' => $feature, ':createdAt' => time()]);
    }

    public function rate(int $levelID, int $accountID, int $stars, int $feature, int $epic, int $difficulty, int $auto, int $demon): bool
    {
        $this->db->beginTransaction();
        try {
            $find = $this->db->prepare('SELECT userID FROM ' . $this->tables->get('levels') . ' WHERE levelID = :levelID LIMIT 1 FOR UPDATE');
            $find->execute([':levelID' => $levelID]);
            $userID = $find->fetchColumn();
            if ($userID === false) {
                $this->db->rollBack();
                return false;
            }
            $update = $this->db->prepare('UPDATE ' . $this->tables->get('levels') . ' SET starStars = :stars, starDifficulty = :difficulty, starAuto = :auto, starDemon = :demonValue, starDemonDiff = CASE WHEN :demonCheck = 1 THEN starDemonDiff ELSE 0 END, starFeatured = :feature, starEpic = :epic, rateDate = :rateDate WHERE levelID = :levelID');
            $update->execute([
                ':stars' => $stars,
                ':difficulty' => $difficulty,
                ':auto' => $auto,
                ':demonValue' => $demon,
                ':demonCheck' => $demon,
                ':feature' => $feature,
                ':epic' => $epic,
                ':rateDate' => $stars > 0 ? time() : 0,
                ':levelID' => $levelID,
            ]);
            $log = $this->db->prepare('INSERT INTO ' . $this->tables->get('core_rate_log') . ' (levelID, accountID, stars, feature, epic, demon, demonDifficulty, createdAt) VALUES (:levelID, :accountID, :stars, :feature, :epic, :demon, 0, :createdAt)');
            $log->execute([':levelID' => $levelID, ':accountID' => $accountID, ':stars' => $stars, ':feature' => $feature, ':epic' => $epic, ':demon' => $demon, ':createdAt' => time()]);
            $this->recalculateCreatorPoints((int) $userID);
            $this->db->commit();
            return true;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function rateDemon(int $levelID, int $accountID, int $difficulty): bool
    {
        $this->db->beginTransaction();
        try {
            $find = $this->db->prepare('SELECT levelID FROM ' . $this->tables->get('levels') . ' WHERE levelID = :levelID LIMIT 1 FOR UPDATE');
            $find->execute([':levelID' => $levelID]);
            if ($find->fetchColumn() === false) {
                $this->db->rollBack();
                return false;
            }
            $update = $this->db->prepare('UPDATE ' . $this->tables->get('levels') . ' SET starDemonDiff = :difficulty WHERE levelID = :levelID');
            $update->execute([':difficulty' => $difficulty, ':levelID' => $levelID]);
            $log = $this->db->prepare('INSERT INTO ' . $this->tables->get('core_rate_log') . ' (levelID, accountID, stars, feature, epic, demon, demonDifficulty, createdAt) SELECT levelID, :accountID, starStars, starFeatured, starEpic, starDemon, :difficulty, :createdAt FROM ' . $this->tables->get('levels') . ' WHERE levelID = :levelID');
            $log->execute([':accountID' => $accountID, ':difficulty' => $difficulty, ':createdAt' => time(), ':levelID' => $levelID]);
            $this->db->commit();
            return true;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    private function recalculateCreatorPoints(int $userID): void
    {
        $query = $this->db->prepare('SELECT COALESCE(SUM(CASE WHEN starStars > 0 THEN 1 + CASE WHEN starFeatured > 0 THEN 1 ELSE 0 END + LEAST(3, GREATEST(0, starEpic)) ELSE 0 END), 0) FROM ' . $this->tables->get('levels') . ' WHERE userID = :userID');
        $query->execute([':userID' => $userID]);
        $points = (int) $query->fetchColumn();
        $update = $this->db->prepare('UPDATE ' . $this->tables->get('users') . ' SET creatorPoints = :points WHERE userID = :userID');
        $update->execute([':points' => $points, ':userID' => $userID]);
    }
}
