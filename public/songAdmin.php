<?php

declare(strict_types=1);

use NightCore\Core\Application;
use NightCore\Core\Config;

$root = dirname(__DIR__);
/** @var Application $app */
$app = require $root . '/bootstrap.php';

$expectedToken = trim(Config::get('CUSTOM_SONG_ADMIN_TOKEN', '') ?? '');
if ($expectedToken === '') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "not_found\n";
    exit;
}

$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$message = '';
$error = '';
$providedToken = '';
$authenticated = false;

if ($method === 'POST') {
    $providedToken = isset($_POST['token']) && is_string($_POST['token']) ? $_POST['token'] : '';
    if (!hash_equals($expectedToken, $providedToken)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo "forbidden\n";
        exit;
    }
    $authenticated = true;

    try {
        $action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : 'upload';
        if ($action === 'delete') {
            $songID = isset($_POST['songID']) && is_scalar($_POST['songID']) ? (int) $_POST['songID'] : 0;
            $message = $app->customSongs()->delete($songID)
                ? 'Custom song ' . $songID . ' deleted.'
                : 'Custom song not found.';
        } else {
            if (!isset($_FILES['song']) || !is_array($_FILES['song'])) {
                throw new RuntimeException('Choose an MP3 file.');
            }
            $upload = $_FILES['song'];
            $uploadError = isset($upload['error']) ? (int) $upload['error'] : UPLOAD_ERR_NO_FILE;
            if ($uploadError !== UPLOAD_ERR_OK) {
                $uploadErrors = [
                    UPLOAD_ERR_INI_SIZE => 'The MP3 exceeds the PHP upload_max_filesize limit.',
                    UPLOAD_ERR_FORM_SIZE => 'The MP3 exceeds the form upload limit.',
                    UPLOAD_ERR_PARTIAL => 'The MP3 upload was interrupted.',
                    UPLOAD_ERR_NO_FILE => 'Choose an MP3 file.',
                    UPLOAD_ERR_NO_TMP_DIR => 'The server has no upload temporary directory.',
                    UPLOAD_ERR_CANT_WRITE => 'The server could not write the uploaded MP3.',
                    UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the upload.',
                ];
                throw new RuntimeException($uploadErrors[$uploadError] ?? 'Unknown file upload error.');
            }

            $tmpName = isset($upload['tmp_name']) && is_string($upload['tmp_name']) ? $upload['tmp_name'] : '';
            $originalName = isset($upload['name']) && is_string($upload['name']) ? $upload['name'] : 'song.mp3';
            if ($tmpName === '' || !is_uploaded_file($tmpName)) {
                throw new RuntimeException('The uploaded MP3 was not received as a valid HTTP upload.');
            }

            $name = isset($_POST['name']) && is_string($_POST['name']) ? $_POST['name'] : '';
            $author = isset($_POST['author']) && is_string($_POST['author']) ? $_POST['author'] : '';
            $baseUrl = trim(Config::get('CUSTOM_SONG_PUBLIC_BASE_URL', '') ?? '');
            if ($baseUrl === '') {
                $scheme = 'http';
                if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
                    $scheme = 'https';
                } elseif (Config::getBool('TRUST_PROXY_HEADERS', false)) {
                    $forwarded = strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0] ?? ''));
                    if ($forwarded === 'https') {
                        $scheme = 'https';
                    }
                }
                $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
                if ($host === '' || preg_match('/^[A-Za-z0-9.\-:\[\]]+$/', $host) !== 1) {
                    throw new RuntimeException('Set CUSTOM_SONG_PUBLIC_BASE_URL before uploading songs.');
                }
                $basePath = trim(Config::get('BASE_PATH', '/') ?? '/');
                $basePath = $basePath === '/' ? '' : '/' . trim($basePath, '/');
                $baseUrl = $scheme . '://' . $host . $basePath;
            }

            $result = $app->customSongs()->import($tmpName, $originalName, $name, $author, $baseUrl);
            $message = 'Uploaded. Song ID: ' . $result['songID'] . ' — ' . $result['name'] . ' by ' . $result['authorName'];
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

header('Content-Type: text/html; charset=utf-8');
$maxMb = number_format($app->customSongs()->storage()->maxBytes() / 1048576, 1, '.', '');
$songs = $authenticated ? $app->customSongs()->list(100) : [];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Night Core custom songs</title>
<style>
body{font-family:system-ui,-apple-system,sans-serif;max-width:900px;margin:40px auto;padding:0 18px;line-height:1.45;background:#111;color:#eee}
main{background:#1b1b1b;border:1px solid #333;border-radius:14px;padding:24px}
label{display:block;margin:14px 0 5px}input{box-sizing:border-box;width:100%;max-width:560px;padding:10px;border:1px solid #555;border-radius:8px;background:#0d0d0d;color:#fff}
button{margin-top:16px;padding:10px 16px;border:0;border-radius:8px;cursor:pointer;font-weight:700}.ok{padding:10px;background:#153b20;border-radius:8px}.err{padding:10px;background:#4b1818;border-radius:8px}
table{width:100%;border-collapse:collapse;margin-top:24px}th,td{text-align:left;border-bottom:1px solid #333;padding:9px 6px;vertical-align:top}code{word-break:break-all}.danger{margin:0;padding:6px 10px}
small{color:#aaa}
</style>
</head>
<body><main>
<h1>Night Core custom songs</h1>
<p>Upload an MP3 hosted directly by this Night Core installation. The generated Song ID can be entered as a custom song ID in Geometry Dash.</p>
<?php if ($message !== ''): ?><p class="ok"><?= $escape($message) ?></p><?php endif; ?>
<?php if ($error !== ''): ?><p class="err"><?= $escape($error) ?></p><?php endif; ?>
<form method="post" enctype="multipart/form-data">
<input type="hidden" name="action" value="upload">
<label>Admin token</label><input type="password" name="token" required value="">
<label>Song title</label><input type="text" name="name" maxlength="255" required>
<label>Author / artist</label><input type="text" name="author" maxlength="255" required>
<label>MP3 file</label><input type="file" name="song" accept="audio/mpeg,.mp3" required>
<small>Night Core limit: <?= $escape($maxMb) ?> MiB. PHP/Nginx may impose a lower request limit.</small><br>
<button type="submit">Upload song</button>
</form>
<?php if ($authenticated): ?>
<h2>Local song library</h2>
<table><thead><tr><th>ID</th><th>Song</th><th>Size</th><th>Download</th><th></th></tr></thead><tbody>
<?php foreach ($songs as $song): ?>
<tr>
<td><strong><?= (int) $song['songID'] ?></strong></td>
<td><?= $escape((string) ($song['name'] ?? '(reserved)')) ?><br><small><?= $escape((string) ($song['authorName'] ?? '')) ?></small></td>
<td><?= $escape((string) ($song['size'] ?? '0')) ?> MB</td>
<td><?php if (!empty($song['download'])): ?><code><?= $escape((string) $song['download']) ?></code><?php endif; ?></td>
<td><?php if (!empty($song['name'])): ?><form method="post"><input type="hidden" name="action" value="delete"><input type="hidden" name="token" value="<?= $escape($providedToken) ?>"><input type="hidden" name="songID" value="<?= (int) $song['songID'] ?>"><button class="danger" type="submit">Delete</button></form><?php endif; ?></td>
</tr>
<?php endforeach; ?>
</tbody></table>
<?php endif; ?>
</main></body></html>
