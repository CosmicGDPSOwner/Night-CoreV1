<?php

declare(strict_types=1);

use NightCore\Core\Config;

require_once dirname(__DIR__) . '/autoload.php';

$path = tempnam(sys_get_temp_dir(), 'nightcore-env-');
if ($path === false) {
    fwrite(STDERR, "Unable to create temporary env file.\n");
    exit(1);
}

file_put_contents($path, "NIGHTCORE_ENV_FALLBACK_TEST=works\nNIGHTCORE_ENV_FALLBACK_INT=42\n");

try {
    Config::loadEnv($path);

    if (Config::get('NIGHTCORE_ENV_FALLBACK_TEST') !== 'works') {
        throw new RuntimeException('String env fallback failed.');
    }
    if (Config::getInt('NIGHTCORE_ENV_FALLBACK_INT', 0) !== 42) {
        throw new RuntimeException('Integer env fallback failed.');
    }

    echo "Config env fallback: OK\n";
} finally {
    @unlink($path);
}
