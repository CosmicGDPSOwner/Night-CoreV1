<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'NightCore\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

use NightCore\Core\Config;
use NightCore\Core\Database;

Config::loadEnv(__DIR__ . '/.env');

return [
    'db' => Database::connect(Config::database()),
    'basePath' => Config::get('BASE_PATH', '/gd/utypdf'),
];
