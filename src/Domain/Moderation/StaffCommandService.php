<?php

declare(strict_types=1);

namespace NightCore\Domain\Moderation;

final class StaffCommandService
{
    private const SYNTAX_ERROR = 'temp_0_Error: incomplete command spelling';

    public function __construct(
        private ModerationService $moderation,
        private ?RotationEventService $rotations = null
    ) {
    }

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
            return match (strtolower($matches[1])) {
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
                'easy' => 1, 'medium' => 2, 'hard' => 3, 'insane' => 4, 'extreme' => 5,
            };
            return $this->moderation->rateDemon($accountID, $gjp, $gjp2, $ip, $levelID, $rating) === '-1' ? '-1' : '1';
        }

        if (preg_match('/^!(ban|unban|leaderboardban|leaderboardunban)\s+([^\s]{1,64})$/i', $command, $matches) === 1) {
            return match (strtolower($matches[1])) {
                'ban' => $this->moderation->setAccountBan($accountID, $gjp, $gjp2, $ip, $matches[2], true),
                'unban' => $this->moderation->setAccountBan($accountID, $gjp, $gjp2, $ip, $matches[2], false),
                'leaderboardban' => $this->moderation->setLeaderboardBan($accountID, $gjp, $gjp2, $ip, $matches[2], true),
                'leaderboardunban' => $this->moderation->setLeaderboardBan($accountID, $gjp, $gjp2, $ip, $matches[2], false),
                default => self::SYNTAX_ERROR,
            };
        }

        if ($this->rotations !== null && preg_match('/^!(daily|weekly)$/i', $command, $matches) === 1) {
            return $this->rotations->scheduleRotation($accountID, $gjp, $gjp2, $ip, $levelID, strtolower($matches[1]));
        }

        if ($this->rotations !== null && preg_match('/^!(event|eventchange|eventset)(?:\s+(.+))?$/i', $command, $matches) === 1) {
            $action = strtolower($matches[1]);
            $options = $this->parseOptions($matches[2] ?? '');
            if ($options === null) {
                return self::SYNTAX_ERROR;
            }

            $start = array_key_exists('start', $options) ? $this->parseStart($options['start']) : ($action === 'event' || $action === 'eventset' ? time() : null);
            $duration = array_key_exists('duration', $options) ? $this->parseDuration($options['duration']) : null;
            $end = $duration === null ? null : (($start ?? time()) + $duration);
            $rewards = array_key_exists('reward', $options) ? $this->parseRewards($options['reward']) : null;

            if (($action === 'event' || $action === 'eventset') && ($start === null || $end === null || $rewards === null)) {
                return self::SYNTAX_ERROR;
            }
            if ($action === 'eventchange' && $start === null && $end === null && $rewards === null) {
                return self::SYNTAX_ERROR;
            }

            if ($action === 'event') {
                return $this->rotations->createEvent($accountID, $gjp, $gjp2, $ip, $levelID, $start, $end, $rewards);
            }
            return $this->rotations->changeEvent($accountID, $gjp, $gjp2, $ip, $levelID, $start, $end, $rewards, $action === 'eventset');
        }

        return str_starts_with($command, '!') ? self::SYNTAX_ERROR : null;
    }

    /** @return array<string,string>|null */
    private function parseOptions(string $input): ?array
    {
        $input = trim($input);
        if ($input === '') {
            return [];
        }
        $result = [];
        foreach (preg_split('/\s+/', $input) ?: [] as $part) {
            if (!str_contains($part, '=')) {
                return null;
            }
            [$key, $value] = explode('=', $part, 2);
            $key = strtolower(trim($key));
            $value = trim($value);
            if (!in_array($key, ['start', 'duration', 'reward'], true) || $value === '' || isset($result[$key])) {
                return null;
            }
            $result[$key] = $value;
        }
        return $result;
    }

    private function parseStart(string $value): ?int
    {
        if (strtolower($value) === 'now') {
            return time();
        }
        $timestamp = strtotime($value);
        return $timestamp === false ? null : $timestamp;
    }

    private function parseDuration(string $value): ?int
    {
        if (preg_match('/^(\d{1,4})(m|h|d|w)$/i', $value, $matches) !== 1) {
            return null;
        }
        $factor = match (strtolower($matches[2])) {
            'm' => 60, 'h' => 3600, 'd' => 86400, 'w' => 604800,
        };
        return (int) $matches[1] * $factor;
    }

    /** @return array<string,int>|null */
    private function parseRewards(string $value): ?array
    {
        $result = [];
        foreach (explode(',', strtolower($value)) as $reward) {
            if (preg_match('/^(diamonds|orbs|stars|moons|keys):(\d{1,7})$/', trim($reward), $matches) !== 1) {
                return null;
            }
            $result[$matches[1]] = (int) $matches[2];
        }
        return $result === [] ? null : $result;
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
