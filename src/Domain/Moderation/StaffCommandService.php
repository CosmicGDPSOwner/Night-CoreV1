<?php

declare(strict_types=1);

namespace NightCore\Domain\Moderation;

final class StaffCommandService
{
    private const SYNTAX_ERROR = 'temp_0_Error: incomplete command spelling';

    public function __construct(private ModerationService $moderation)
    {
    }

    /**
     * Execute a supported staff command posted as a level comment.
     *
     * Returns null when the comment is not a recognized staff command.
     * Returns the Geometry Dash endpoint result when the command was handled.
     */
    public function executeLevelComment(
        int $accountID,
        string $gjp,
        string $gjp2,
        string $ip,
        int $levelID,
        string $comment
    ): ?string {
        $command = $this->normalizeCommand($comment);
        if ($command === null) {
            return null;
        }

        if (preg_match('/^!(rate|feature|epic|legendary|mythic)\s+(\d{1,2})$/i', $command, $matches) === 1) {
            $stars = (int) $matches[2];
            if ($stars < 1 || $stars > 10) {
                return self::SYNTAX_ERROR;
            }
            $tier = strtolower($matches[1]);
            return match ($tier) {
                'rate' => $this->moderation->rateTier($accountID, $gjp, $gjp2, $ip, $levelID, $stars, 0, 0),
                'feature' => $this->moderation->rateTier($accountID, $gjp, $gjp2, $ip, $levelID, $stars, 1, 0),
                'epic' => $this->moderation->rateTier($accountID, $gjp, $gjp2, $ip, $levelID, $stars, 1, 1),
                'legendary' => $this->moderation->rateTier($accountID, $gjp, $gjp2, $ip, $levelID, $stars, 1, 2),
                'mythic' => $this->moderation->rateTier($accountID, $gjp, $gjp2, $ip, $levelID, $stars, 1, 3),
                default => self::SYNTAX_ERROR,
            };
        }

        if (preg_match('/^!unrate$/i', $command) === 1) {
            return $this->moderation->rateTier($accountID, $gjp, $gjp2, $ip, $levelID, 0, 0, 0);
        }

        if (preg_match('/^!demon\s+(easy|medium|hard|insane|extreme)$/i', $command, $matches) === 1) {
            $rating = match (strtolower($matches[1])) {
                'easy' => 1,
                'medium' => 2,
                'hard' => 3,
                'insane' => 4,
                'extreme' => 5,
            };
            return $this->moderation->rateDemon($accountID, $gjp, $gjp2, $ip, $levelID, $rating) === '-1' ? '-1' : '1';
        }

        if (preg_match('/^!(ban|unban|leaderboardban|leaderboardunban)\s+([^\s]{1,64})$/i', $command, $matches) === 1) {
            $action = strtolower($matches[1]);
            $userName = $matches[2];
            return match ($action) {
                'ban' => $this->moderation->setAccountBan($accountID, $gjp, $gjp2, $ip, $userName, true),
                'unban' => $this->moderation->setAccountBan($accountID, $gjp, $gjp2, $ip, $userName, false),
                'leaderboardban' => $this->moderation->setLeaderboardBan($accountID, $gjp, $gjp2, $ip, $userName, true),
                'leaderboardunban' => $this->moderation->setLeaderboardBan($accountID, $gjp, $gjp2, $ip, $userName, false),
                default => self::SYNTAX_ERROR,
            };
        }

        // Never publish something that was clearly intended as a staff command.
        // The temp_0_ prefix is understood by stock Geometry Dash 2.1+ and makes
        // the response appear as an in-game dialog instead of a silent failure.
        return str_starts_with($command, '!') ? self::SYNTAX_ERROR : null;
    }

    private function normalizeCommand(string $comment): ?string
    {
        $comment = trim(str_replace("\0", '', $comment));
        if ($comment === '') {
            return null;
        }

        if (str_starts_with($comment, '!')) {
            return preg_replace('/\s+/', ' ', $comment) ?: $comment;
        }

        $decoded = base64_decode(strtr($comment, '-_', '+/'), true);
        if ($decoded === false) {
            return null;
        }

        $decoded = trim(str_replace("\0", '', $decoded));
        if (!str_starts_with($decoded, '!')) {
            return null;
        }

        return preg_replace('/\s+/', ' ', $decoded) ?: $decoded;
    }
}
