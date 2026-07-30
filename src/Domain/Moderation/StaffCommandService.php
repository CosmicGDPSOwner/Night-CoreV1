<?php

declare(strict_types=1);

namespace NightCore\Domain\Moderation;

final class StaffCommandService
{
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

        if (preg_match('/^!rate\s+(\d{1,2})$/i', $command, $matches) === 1) {
            $stars = (int) $matches[1];
            if ($stars < 1 || $stars > 10) {
                return '-1';
            }

            // Base rate only: no Featured and no Epic/Legendary/Mythic tier.
            return $this->moderation->rateStars(
                $accountID,
                $gjp,
                $gjp2,
                $ip,
                $levelID,
                $stars,
                0,
                0
            );
        }

        return null;
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
