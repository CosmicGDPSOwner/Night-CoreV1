<?php

declare(strict_types=1);

namespace NightCore\Core;

use RuntimeException;

final class AccountPolicy
{
    private function __construct(private bool $accountDeletionEnabled)
    {
    }

    public static function load(string $root): self
    {
        $accountDeletionEnabled = true;
        $path = rtrim($root, '/\\') . '/config/account.php';

        if (is_file($path)) {
            $settings = require $path;
            if (!is_array($settings)) {
                throw new RuntimeException('config/account.php must return an array.');
            }

            if (array_key_exists('account_deletion_enabled', $settings)) {
                $accountDeletionEnabled = self::boolValue(
                    $settings['account_deletion_enabled'],
                    'account_deletion_enabled'
                );
            }
        }

        return new self($accountDeletionEnabled);
    }

    public function accountDeletionEnabled(): bool
    {
        return $this->accountDeletionEnabled;
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

        throw new RuntimeException('Invalid boolean value for account setting ' . $key . '.');
    }
}
