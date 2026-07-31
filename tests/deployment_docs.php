<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];

$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$files = [
    'README.md',
    'README.ru.md',
    'docs/DEPLOYMENT.md',
    'docs/ru/DEPLOYMENT.md',
    'docs/SHARED_HOSTING.md',
    'docs/ru/SHARED_HOSTING.md',
    'docs/UPDATING.md',
    'docs/ru/UPDATING.md',
    'docs/BACKUP_AND_RECOVERY.md',
    'docs/ru/BACKUP_AND_RECOVERY.md',
    'docs/DEPLOYMENT_CHECKLIST.md',
    'docs/ru/DEPLOYMENT_CHECKLIST.md',
    'deploy/README.md',
    'deploy/nginx/nightcore.conf.example',
    'deploy/cron/nightcore.cron.example',
    '.env.shared.example',
    'config/media.php.example',
];

$contents = [];
foreach ($files as $relative) {
    $path = $root . '/' . $relative;
    $assert(is_file($path), $relative . ' exists');
    $content = is_file($path) ? file_get_contents($path) : false;
    $assert(is_string($content), $relative . ' is readable');
    if (!is_string($content)) {
        continue;
    }
    $contents[$relative] = $content;
    $assert(!str_contains($content, "\u{2013}"), $relative . ' does not contain an en dash');
    $assert(!str_contains($content, "\u{2014}"), $relative . ' does not contain an em dash');
}

$deployment = $contents['docs/ru/DEPLOYMENT.md'] ?? '';
$assert(str_contains($deployment, 'root /var/www/nightcore/public;'), 'Russian deployment guide requires public document root');
$assert(str_contains($deployment, 'php bin/nightcore install'), 'Russian deployment guide documents installer');
$assert(str_contains($deployment, 'TRUST_PROXY_HEADERS=0'), 'Russian deployment guide documents safe proxy default');
$assert(str_contains($deployment, 'docs/ru/BACKUP_AND_RECOVERY.md'), 'Russian deployment guide links recovery documentation');

$nginx = $contents['deploy/nginx/nightcore.conf.example'] ?? '';
$assert(str_contains($nginx, 'root /var/www/nightcore/public;'), 'Nginx example uses public document root');
$assert(str_contains($nginx, 'try_files $uri =404;'), 'Nginx example rejects missing PHP scripts');
$assert(str_contains($nginx, 'fastcgi_pass CHANGE_ME_PHP_FPM_SOCKET;'), 'Nginx example requires explicit PHP-FPM socket');

$media = $contents['config/media.php.example'] ?? '';
$assert(str_contains($media, 'does not enable anonymous uploads'), 'media config states that uploads require authentication');
$assert(!str_contains($media, 'accepts uploads without an admin token'), 'media config removes outdated anonymous-upload wording');

$shared = $contents['.env.shared.example'] ?? '';
$assert(str_contains($shared, 'REGISTRATION_IP_HASH_KEY='), 'shared-host template includes registration HMAC key');
$assert(str_contains($shared, 'PANEL_SECURITY_HASH_KEY='), 'shared-host template includes panel HMAC key');

if ($failures !== []) {
    fwrite(STDERR, "Deployment documentation tests failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Deployment documentation tests passed.\n";
