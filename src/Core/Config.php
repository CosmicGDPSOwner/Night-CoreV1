<?php

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

    /** @return array{host:string,port:int,name:string,user:string,password:string} */
    public static function database(): array
    {
        return [
            'host' => self::get('DB_HOST', '127.0.0.1') ?? '127.0.0.1',
            'port' => (int) (self::get('DB_PORT', '3306') ?? '3306'),
            'name' => self::get('DB_NAME', 'nightgdps') ?? 'nightgdps',
            'user' => self::get('DB_USER', 'nightcore') ?? 'nightcore',
            'password' => self::get('DB_PASS', '') ?? '',
        ];
    }
}
