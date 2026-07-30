<?php

declare(strict_types=1);

namespace NightCore\Core;

use RuntimeException;
use Throwable;

final class AccountPolicy
{
    private const DEFAULT_IDLE_TIMEOUT_SECONDS = 1800;
    private const DEFAULT_ABSOLUTE_TIMEOUT_SECONDS = 28800;
    private const MAX_TIMEOUT_SECONDS = 315360000;

    private function __construct(
        private bool $accountDeletionEnabled,
        private int $sessionIdleTimeoutSeconds,
        private int $sessionAbsoluteTimeoutSeconds
    ) {
    }

    public static function load(string $root): self
    {
        $root = rtrim($root, '/\\');
        $primaryPath = $root . '/config2.php';
        $legacyPath = $root . '/config/account.php';
        $path = is_file($primaryPath)
            ? $primaryPath
            : (is_file($legacyPath) ? $legacyPath : null);

        if ($path === null) {
            return self::defaults();
        }

        try {
            $settings = require $path;
            if (!is_array($settings)) {
                throw new RuntimeException(basename($path) . ' must return an array.');
            }

            return new self(
                array_key_exists('account_deletion_enabled', $settings)
                    ? self::boolValue($settings['account_deletion_enabled'], 'account_deletion_enabled')
                    : true,
                array_key_exists('session_idle_timeout_seconds', $settings)
                    ? self::timeoutValue($settings['session_idle_timeout_seconds'], 'session_idle_timeout_seconds')
                    : self::DEFAULT_IDLE_TIMEOUT_SECONDS,
                array_key_exists('session_absolute_timeout_seconds', $settings)
                    ? self::timeoutValue($settings['session_absolute_timeout_seconds'], 'session_absolute_timeout_seconds')
                    : self::DEFAULT_ABSOLUTE_TIMEOUT_SECONDS
            );
        } catch (Throwable $error) {
            error_log(
                'Night Core private config ignored (' . $path . '): ' . $error->getMessage()
            );
            return self::defaults();
        }
    }

    public function accountDeletionEnabled(): bool
    {
        return $this->accountDeletionEnabled;
    }

    public function sessionIdleTimeoutSeconds(): int
    {
        return $this->sessionIdleTimeoutSeconds;
    }

    public function sessionAbsoluteTimeoutSeconds(): int
    {
        return $this->sessionAbsoluteTimeoutSeconds;
    }

    public function sessionExpired(int $issuedAt, int $lastSeenAt, int $now): bool
    {
        if ($issuedAt <= 0 || $lastSeenAt <= 0) {
            return true;
        }
        if ($this->sessionIdleTimeoutSeconds > 0
            && $now - $lastSeenAt > $this->sessionIdleTimeoutSeconds) {
            return true;
        }
        return $this->sessionAbsoluteTimeoutSeconds > 0
            && $now - $issuedAt > $this->sessionAbsoluteTimeoutSeconds;
    }

    public function sessionDescription(): string
    {
        $idle = $this->sessionIdleTimeoutSeconds;
        $absolute = $this->sessionAbsoluteTimeoutSeconds;

        if ($idle === 0 && $absolute === 0) {
            return 'Session does not expire automatically.';
        }
        if ($idle === 0) {
            return 'No inactivity timeout; session expires after '
                . self::humanDuration($absolute) . ' total.';
        }
        if ($absolute === 0) {
            return 'Session expires after ' . self::humanDuration($idle)
                . ' of inactivity; no absolute timeout.';
        }
        return 'Session expires after ' . self::humanDuration($idle)
            . ' of inactivity or ' . self::humanDuration($absolute) . ' total.';
    }

    private static function defaults(): self
    {
        return new self(
            true,
            self::DEFAULT_IDLE_TIMEOUT_SECONDS,
            self::DEFAULT_ABSOLUTE_TIMEOUT_SECONDS
        );
    }

    private static function boolValue(mixed $value, string $key): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) && ($value === 0 || $value === 1)) {
            return $value === 1;
        }
        if (is_string($value)) {
            $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        throw new RuntimeException('Invalid boolean value for private setting ' . $key . '.');
    }

    private static function timeoutValue(mixed $value, string $key): int
    {
        if (is_int($value)) {
            $seconds = $value;
        } elseif (is_string($value) && preg_match('/^\d+$/D', trim($value)) === 1) {
            $seconds = (int) trim($value);
        } else {
            throw new RuntimeException('Invalid integer value for private setting ' . $key . '.');
        }

        if ($seconds < 0 || $seconds > self::MAX_TIMEOUT_SECONDS) {
            throw new RuntimeException('Private setting ' . $key . ' is outside the allowed range.');
        }
        return $seconds;
    }

    private static function humanDuration(int $seconds): string
    {
        foreach ([86400 => 'day', 3600 => 'hour', 60 => 'minute'] as $unitSeconds => $unit) {
            if ($seconds >= $unitSeconds && $seconds % $unitSeconds === 0) {
                $value = intdiv($seconds, $unitSeconds);
                return $value . ' ' . $unit . ($value === 1 ? '' : 's');
            }
        }
        return $seconds . ' second' . ($seconds === 1 ? '' : 's');
    }
}
