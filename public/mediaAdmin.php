<?php

declare(strict_types=1);

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/mediaAdmin.php');
$path = parse_url($requestUri, PHP_URL_PATH);
$query = parse_url($requestUri, PHP_URL_QUERY);
$path = is_string($path) && $path !== '' ? $path : '/mediaAdmin.php';
$target = preg_replace('~/mediaAdmin\.php$~', '/dashboard.php', $path) ?: '/dashboard.php';
if (is_string($query) && $query !== '') {
    $target .= '?' . $query;
}

header('Location: ' . $target, true, in_array($method, ['GET', 'HEAD'], true) ? 301 : 307);
exit;
