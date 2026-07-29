<?php

declare(strict_types=1);

namespace NightCore\Domain\Content;

use NightCore\Domain\Accounts\AccountRepository;
use NightCore\Domain\Progress\ProgressRepository;
use NightCore\Security\AccountAuthenticator;

final class ContentService
{
    public function __construct(
        private ContentRepository $content,
        private AccountRepository $accounts,
        private AccountAuthenticator $authenticator,
        private ProgressRepository $progress,
        private CommentAccessPolicy $commentAccess,
        private NewgroundsSongProvider $songProvider
    ) {
    }

    public function song(int $songID): string
    {
        if ($songID <= 0) {
            return '-1';
        }
        $song = $this->content->findSong($songID);
        if ($song === null) {
            $song = $this->songProvider->findOrFetch($songID);
        }
        if ($song === null) {
            return '-1';
        }
        if ((int) $song['isDisabled'] === 1) {
            return '-2';
        }
        $download = (string) $song['download'];
        if (str_contains($download, ':')) {
            $download = rawurlencode($download);
        }
        return implode('~|~', [
            '1', (string) (int) $song['songID'],
            '2', $this->field((string) $song['name'], 255),
            '3', (string) (int) $song['authorID'],
            '4', $this->field((string) $song['authorName'], 255),
            '5', (string) $song['size'],
            '6', '',
            '10', $download,
            '7', '',
            '8', '0',
        ]);
    }

    public function uploadLevelComment(int $accountID, string $gjp, string $gjp2, string $ip, int $levelID, string $comment, int $percent, int $gameVersion): string
    {
        if ($levelID <= 0 || !$this->authenticator->verify($accountID, $gjp, $gjp2, $ip)) {
            return '-1';
        }
        $account = $this->accounts->findById($accountID);
        if ($account === null) {
            return '-1';
        }
        $comment = $this->field($comment, 2048);
        if ($comment === '') {
            return '-1';
        }
        if ($gameVersion < 20) {
            $comment = base64_encode($comment);
        }
        $userID = $this->accounts->ensureUser($accountID, (string) $account['userName']);
        $percent = max(0, min(100, $percent));
        $this->content->addComment($accountID, $userID, (string) $account['userName'], 0, $levelID, $comment, $percent);
        if ($percent > 0) {
            $this->progress->upsertLevelScore($accountID, $userID, $levelID, $percent, 0, 0, 0);
        }
        return '1';
    }

    public function uploadAccountComment(int $accountID, string $gjp, string $gjp2, string $ip, string $comment, int $gameVersion): string
    {
        if (!$this->authenticator->verify($accountID, $gjp, $gjp2, $ip)) {
            return '-1';
        }
        $account = $this->accounts->findById($accountID);
        if ($account === null) {
            return '-1';
        }
        $comment = $this->field($comment, 2048);
        if ($comment === '') {
            return '-1';
        }
        if ($gameVersion < 20) {
            $comment = base64_encode($comment);
        }
        $userID = $this->accounts->ensureUser($accountID, (string) $account['userName']);
        $this->content->addComment($accountID, $userID, (string) $account['userName'], 1, $accountID, $comment, 0);
        return '1';
    }

    public function deleteComment(
        int $accountID,
        string $gjp,
        string $gjp2,
        string $ip,
        int $commentID,
        int $targetType
    ): string {
        if ($commentID <= 0 || !$this->authenticator->verify($accountID, $gjp, $gjp2, $ip)) {
            return '-1';
        }
        if (!$this->commentAccess->canDelete($accountID, $commentID, $targetType)) {
            return '-1';
        }
        return $this->content->deleteComment($commentID, $accountID, true) ? '1' : '-1';
    }

    public function levelComments(int $levelID, int $userID, int $page, int $count, int $mode, int $gameVersion, int $binaryVersion): string
    {
        $page = max(0, $page);
        $count = max(1, min(50, $count));
        $showLevelID = false;
        if ($levelID > 0) {
            $result = $this->content->commentsForTarget(0, $levelID, $page, $count, $mode !== 0);
        } elseif ($userID > 0) {
            $result = $this->content->commentsByUser($userID, $page, $count);
            $showLevelID = true;
        } else {
            return '-1';
        }
        return $this->formatComments($result, $page, $count, $gameVersion, $binaryVersion, $showLevelID);
    }

    public function accountComments(int $profileAccountID, int $page, int $count, int $gameVersion, int $binaryVersion): string
    {
        if ($profileAccountID <= 0) {
            return '-1';
        }
        $page = max(0, $page);
        $count = max(1, min(50, $count));
        $result = $this->content->commentsForTarget(1, $profileAccountID, $page, $count, false);
        return $this->formatAccountComments($result, $page, $count);
    }

    public function like(int $accountID, string $gjp, string $gjp2, string $ip, int $itemType, int $itemID, int $value): string
    {
        if ($itemID <= 0 || !$this->authenticator->verify($accountID, $gjp, $gjp2, $ip)) {
            return '-1';
        }
        return $this->content->applyLike($accountID, $itemType, $itemID, $value >= 1 ? 1 : -1) ? '1' : '-1';
    }

    public function reportLevel(int $accountID, string $gjp, string $gjp2, string $ip, int $levelID, string $reason): string
    {
        if ($levelID <= 0) {
            return '-1';
        }
        if ($accountID > 0 && !$this->authenticator->verify($accountID, $gjp, $gjp2, $ip)) {
            return '-1';
        }
        $this->content->report(max(0, $accountID), 1, $levelID, $this->field($reason, 255));
        return '1';
    }

    /** @param array{rows:array<int,array<string,mixed>>,total:int} $result */
    private function formatComments(array $result, int $page, int $count, int $gameVersion, int $binaryVersion, bool $showLevelID): string
    {
        if ($result['total'] === 0) {
            return '-2';
        }
        $comments = [];
        $users = [];
        foreach ($result['rows'] as $row) {
            $comment = (string) $row['comment'];
            if ($gameVersion < 20) {
                $decoded = base64_decode($comment, true);
                if ($decoded !== false) {
                    $comment = $decoded;
                }
            }
            $parts = [];
            if ($showLevelID) {
                $parts[] = '1~' . (int) $row['targetID'];
            }
            $parts[] = '2~' . $comment;
            $parts[] = '3~' . (int) $row['userID'];
            $parts[] = '4~' . (int) $row['likes'];
            $parts[] = '5~0';
            $parts[] = '7~' . (int) $row['isSpam'];
            $parts[] = '9~' . date('d/m/Y G.i', (int) $row['createdAt']);
            $parts[] = '6~' . (int) $row['commentID'];
            $parts[] = '10~' . (int) $row['percent'];
            $extID = is_numeric((string) ($row['extID'] ?? '')) ? (int) $row['extID'] : (int) $row['accountID'];
            if ($binaryVersion > 31) {
                $parts[] = '11~0:1~' . $this->field((string) $row['userName'], 20)
                    . '~7~1~9~' . (int) ($row['icon'] ?? 0)
                    . '~10~' . (int) ($row['color1'] ?? 0)
                    . '~11~' . (int) ($row['color2'] ?? 0)
                    . '~14~' . (int) ($row['iconType'] ?? 0)
                    . '~15~' . (int) ($row['special'] ?? 0)
                    . '~16~' . $extID;
            } else {
                $users[(int) $row['userID']] = (int) $row['userID'] . ':' . $this->field((string) $row['userName'], 20) . ':' . $extID;
            }
            $comments[] = implode('~', $parts);
        }
        $offset = $page * $count;
        $body = implode('|', $comments);
        if ($binaryVersion < 32) {
            $body .= '#' . implode('|', $users);
        }
        return $body . '#' . $result['total'] . ':' . $offset . ':' . count($result['rows']);
    }

    /** @param array{rows:array<int,array<string,mixed>>,total:int} $result */
    private function formatAccountComments(array $result, int $page, int $count): string
    {
        if ($result['total'] === 0) {
            return '#0:0:0';
        }

        $comments = [];
        foreach ($result['rows'] as $row) {
            $comments[] = implode('~', [
                '2', (string) $row['comment'],
                '3', (string) (int) $row['userID'],
                '4', (string) (int) $row['likes'],
                '5', '0',
                '7', (string) (int) $row['isSpam'],
                '9', date('d/m/Y G:i', (int) $row['createdAt']),
                '6', (string) (int) $row['commentID'],
            ]);
        }

        return implode('|', $comments) . '#' . $result['total'] . ':' . ($page * $count) . ':' . $count;
    }

    private function field(string $value, int $max): string
    {
        $value = str_replace("\0", '', trim($value));
        return strlen($value) > $max ? substr($value, 0, $max) : $value;
    }
}
