<?php

declare(strict_types=1);

use NightCore\Core\Application;
use NightCore\Core\Config;

// Register the lightweight NightCore autoloader for every bootstrap invocation.
// This is safe for ordinary one-request PHP SAPIs and also keeps the built-in
// development server reliable across sequential requests.
require __DIR__ . '/autoload.php';

Config::loadEnv(__DIR__ . '/.env');

return Application::boot();
