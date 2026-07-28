<?php

declare(strict_types=1);

namespace NightCore\Core;

final class Request
{
    public static function post(string $key, string $default = ''): string
    {
        $value = $_POST[$key] ?? $default;
        return is_scalar($value) ? (string) $value : $default;
    }

    public static function postTrimmed(string $key, string $default = ''): string
    {
        return trim(self::post($key, $default));
    }
}
