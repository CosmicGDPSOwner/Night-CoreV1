<?php

declare(strict_types=1);

use NightCore\Core\Config;

require dirname(__DIR__) . '/autoload.php';

$temp = tempnam(sys_get_temp_dir(), 'nightcore-env-');
if ($temp === false) {
    throw new RuntimeException('Unable to create temporary env file.');
}

try {
    if (function_exists('putenv')) {
        putenv('SERVER_NAME=_');
        putenv('NIGHTCORE_SERVER_NAME');
    }
    unset($_ENV['NIGHTCORE_SERVER_NAME']);

    file_put_contents($temp, "NIGHTCORE_SERVER_NAME=Night Core Nginx Test\n");
    Config::loadEnv($temp);

    $actual = Config::get('SERVER_NAME');
    if ($actual !== 'Night Core Nginx Test') {
        throw new RuntimeException('NIGHTCORE_SERVER_NAME did not override CGI SERVER_NAME; actual=' . var_export($actual, true));
    }

    echo "server-name config: OK\n";
} finally {
    @unlink($temp);
}
