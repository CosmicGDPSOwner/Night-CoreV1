<?php

declare(strict_types=1);

use NightCore\Core\Application;

$root = dirname(__DIR__);
/** @var Application $app */
$app = require $root . '/bootstrap.php';

$songID = isset($_GET['songID']) && is_scalar($_GET['songID']) ? (int) $_GET['songID'] : 0;
$record = $app->customSongs()->downloadRecord($songID);
if ($record === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "not_found\n";
    exit;
}

$path = (string) $record['path'];
$size = filesize($path);
if ($size === false || $size <= 0) {
    http_response_code(404);
    exit;
}

$etagHash = (string) ($record['sha256'] ?? '');
if ($etagHash === '') {
    $computed = hash_file('sha256', $path);
    $etagHash = is_string($computed) ? $computed : (string) $size;
}
$etag = '"' . $etagHash . '"';
header('Content-Type: audio/mpeg');
header('Content-Disposition: inline; filename="' . $songID . '.mp3"');
header('Accept-Ranges: bytes');
header('Cache-Control: public, max-age=86400');
header('ETag: ' . $etag);
header('X-Content-Type-Options: nosniff');

if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim((string) $_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
    http_response_code(304);
    exit;
}

$start = 0;
$end = $size - 1;
$range = isset($_SERVER['HTTP_RANGE']) ? trim((string) $_SERVER['HTTP_RANGE']) : '';
if ($range !== '') {
    if (!preg_match('/^bytes=(\d*)-(\d*)$/', $range, $match) || ($match[1] === '' && $match[2] === '')) {
        http_response_code(416);
        header('Content-Range: bytes */' . $size);
        exit;
    }

    if ($match[1] === '') {
        $suffix = (int) $match[2];
        if ($suffix <= 0) {
            http_response_code(416);
            header('Content-Range: bytes */' . $size);
            exit;
        }
        $start = max(0, $size - $suffix);
    } else {
        $start = (int) $match[1];
        if ($match[2] !== '') {
            $end = min($end, (int) $match[2]);
        }
    }

    if ($start < 0 || $start >= $size || $end < $start) {
        http_response_code(416);
        header('Content-Range: bytes */' . $size);
        exit;
    }

    http_response_code(206);
    header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
}

$length = $end - $start + 1;
header('Content-Length: ' . $length);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
    exit;
}

$handle = fopen($path, 'rb');
if (!is_resource($handle)) {
    http_response_code(500);
    exit;
}

if ($start > 0) {
    fseek($handle, $start);
}
$remaining = $length;
while ($remaining > 0 && !feof($handle)) {
    $chunk = fread($handle, min(65536, $remaining));
    if ($chunk === false || $chunk === '') {
        break;
    }
    echo $chunk;
    $remaining -= strlen($chunk);
}
fclose($handle);
