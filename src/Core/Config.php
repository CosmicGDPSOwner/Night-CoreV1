<?php

declare(strict_types=1);

namespace NightCore\Core;

final class Config
{
    public static function loadEnv(string $path): void
    {
        if (!is_file($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            if ($key === '' || getenv($key) !== false) {
                continue;
            }

            if (strlen($value) >= 2) {
                $first = $value[0];
                $last = $value[strlen($value) - 1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $value = substr($value, 1, -1);
                }
            }

            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $value = getenv($key);
        return $value === false ? $default : $value;
    }

    public static function getBool(string $key, bool $default = false): bool
    {
        $value = self::get($key);
        if ($value === null) {
            return $default;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        return $parsed ?? $default;
    }

    public static function getInt(string $key, int $default): int
    {
        $value = self::get($key);
        return $value !== null && is_numeric($value) ? (int) $value : $default;
    }

    /** @return array{dsn:?string,host:string,port:int,name:string,user:string,password:string,charset:string} */
    public static function database(): array
    {
        return [
            'dsn' => self::get('DB_DSN'),
            'host' => self::get('DB_HOST', '127.0.0.1') ?? '127.0.0.1',
            'port' => self::getInt('DB_PORT', 3306),
            'name' => self::get('DB_NAME', 'gdps') ?? 'gdps',
            'user' => self::get('DB_USER', 'gdps') ?? 'gdps',
            'password' => self::get('DB_PASS', '') ?? '',
            'charset' => self::get('DB_CHARSET', 'utf8mb4') ?? 'utf8mb4',
        ];
    }
}
