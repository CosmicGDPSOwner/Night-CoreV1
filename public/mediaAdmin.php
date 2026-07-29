<?php

declare(strict_types=1);

use NightCore\Core\Application;
use NightCore\Core\Config;

$root = dirname(__DIR__);
/** @var Application $app */
$app = require $root . '/bootstrap.php';

$expectedToken = trim(Config::get('MEDIA_ADMIN_TOKEN', '') ?? '');
if ($expectedToken === '') {
    $expectedToken = trim(Config::get('CUSTOM_SONG_ADMIN_TOKEN', '') ?? '');
}
if ($expectedToken === '') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "not_found\n";
    exit;
}

$isHttps = !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
session_name('nightcore_media_admin');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$tokenFingerprint = hash('sha256', $expectedToken);
$authenticated = isset($_SESSION['media_admin']) && hash_equals($tokenFingerprint, (string) $_SESSION['media_admin']);
$message = '';
$error = '';

$csrf = static function (): string {
    if (!isset($_SESSION['media_csrf']) || !is_string($_SESSION['media_csrf']) || strlen($_SESSION['media_csrf']) < 32) {
        $_SESSION['media_csrf'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['media_csrf'];
};

$requireCsrf = static function () use ($csrf): void {
    $provided = isset($_POST['csrf']) && is_string($_POST['csrf']) ? $_POST['csrf'] : '';
    if ($provided === '' || !hash_equals($csrf(), $provided)) {
        throw new RuntimeException('Invalid dashboard request token. Refresh the page and try again.');
    }
};

$uploadError = static function (array $file, string $label): void {
    $code = isset($file['error']) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
    if ($code === UPLOAD_ERR_OK) {
        return;
    }
    $messages = [
        UPLOAD_ERR_INI_SIZE => $label . ' exceeds PHP upload_max_filesize.',
        UPLOAD_ERR_FORM_SIZE => $label . ' exceeds the form upload limit.',
        UPLOAD_ERR_PARTIAL => $label . ' upload was interrupted.',
        UPLOAD_ERR_NO_FILE => 'Choose a ' . $label . ' file.',
        UPLOAD_ERR_NO_TMP_DIR => 'The server has no upload temporary directory.',
        UPLOAD_ERR_CANT_WRITE => 'The server could not write the uploaded file.',
        UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the upload.',
    ];
    throw new RuntimeException($messages[$code] ?? 'Unknown file upload error.');
};

$publicBaseUrl = static function (string $configKey, string $fallbackKey = ''): string {
    $baseUrl = trim(Config::get($configKey, '') ?? '');
    if ($baseUrl === '' && $fallbackKey !== '') {
        $baseUrl = trim(Config::get($fallbackKey, '') ?? '');
    }
    if ($baseUrl !== '') {
        return rtrim($baseUrl, '/');
    }

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
        throw new RuntimeException('Set the public media base URL before uploading files.');
    }
    $basePath = trim(Config::get('BASE_PATH', '/') ?? '/');
    $basePath = $basePath === '/' ? '' : '/' . trim($basePath, '/');
    return $scheme . '://' . $host . $basePath;
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : '';
    try {
        if ($action === 'login') {
            $provided = isset($_POST['token']) && is_string($_POST['token']) ? $_POST['token'] : '';
            if (!hash_equals($expectedToken, $provided)) {
                throw new RuntimeException('Invalid admin token.');
            }
            session_regenerate_id(true);
            $_SESSION['media_admin'] = $tokenFingerprint;
            unset($_SESSION['media_csrf']);
            $authenticated = true;
            $message = 'Dashboard unlocked.';
        } elseif (!$authenticated) {
            throw new RuntimeException('Authenticate before changing media settings.');
        } else {
            $requireCsrf();
            if ($action === 'logout') {
                $_SESSION = [];
                if (ini_get('session.use_cookies')) {
                    $params = session_get_cookie_params();
                    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool) $params['secure'], (bool) $params['httponly']);
                }
                session_destroy();
                $authenticated = false;
                $message = 'Logged out.';
            } elseif ($action === 'save_limits') {
                $songMiB = isset($_POST['songLimitMiB']) && is_scalar($_POST['songLimitMiB']) ? (int) $_POST['songLimitMiB'] : 0;
                $sfxMiB = isset($_POST['sfxLimitMiB']) && is_scalar($_POST['sfxLimitMiB']) ? (int) $_POST['sfxLimitMiB'] : 0;
                if ($songMiB < 1 || $songMiB > 1024 || $sfxMiB < 1 || $sfxMiB > 1024) {
                    throw new RuntimeException('Upload limits must be between 1 and 1024 MiB.');
                }
                $app->mediaSettings()->saveUploadLimits($songMiB * 1048576, $sfxMiB * 1048576);
                $message = 'Upload limits saved: songs ' . $songMiB . ' MiB, SFX ' . $sfxMiB . ' MiB.';
            } elseif ($action === 'upload_song') {
                if (!isset($_FILES['song']) || !is_array($_FILES['song'])) {
                    throw new RuntimeException('Choose an MP3 file.');
                }
                $upload = $_FILES['song'];
                $uploadError($upload, 'MP3');
                $tmpName = isset($upload['tmp_name']) && is_string($upload['tmp_name']) ? $upload['tmp_name'] : '';
                $originalName = isset($upload['name']) && is_string($upload['name']) ? $upload['name'] : 'song.mp3';
                if ($tmpName === '' || !is_uploaded_file($tmpName)) {
                    throw new RuntimeException('The uploaded MP3 was not received as a valid HTTP upload.');
                }
                $name = isset($_POST['name']) && is_string($_POST['name']) ? $_POST['name'] : '';
                $author = isset($_POST['author']) && is_string($_POST['author']) ? $_POST['author'] : '';
                $result = $app->customSongs()->import(
                    $tmpName,
                    $originalName,
                    $name,
                    $author,
                    $publicBaseUrl('CUSTOM_SONG_PUBLIC_BASE_URL')
                );
                $message = 'Song uploaded. ID: ' . $result['songID'] . ' — ' . $result['name'];
            } elseif ($action === 'upload_sfx') {
                if (!isset($_FILES['sfx']) || !is_array($_FILES['sfx'])) {
                    throw new RuntimeException('Choose an OGG file.');
                }
                $upload = $_FILES['sfx'];
                $uploadError($upload, 'OGG');
                $tmpName = isset($upload['tmp_name']) && is_string($upload['tmp_name']) ? $upload['tmp_name'] : '';
                $originalName = isset($upload['name']) && is_string($upload['name']) ? $upload['name'] : 'sfx.ogg';
                if ($tmpName === '' || !is_uploaded_file($tmpName)) {
                    throw new RuntimeException('The uploaded OGG was not received as a valid HTTP upload.');
                }
                $name = isset($_POST['name']) && is_string($_POST['name']) ? $_POST['name'] : '';
                $result = $app->customSfx()->import(
                    $tmpName,
                    $originalName,
                    $name,
                    $publicBaseUrl('CUSTOM_SFX_PUBLIC_BASE_URL', 'CUSTOM_SONG_PUBLIC_BASE_URL')
                );
                $message = 'SFX uploaded. ID: ' . $result['sfxID'] . ' — ' . $result['name'];
            } elseif ($action === 'delete_song') {
                $songID = isset($_POST['songID']) && is_scalar($_POST['songID']) ? (int) $_POST['songID'] : 0;
                $message = $app->customSongs()->delete($songID) ? 'Song ' . $songID . ' deleted.' : 'Song not found.';
            } elseif ($action === 'delete_sfx') {
                $sfxID = isset($_POST['sfxID']) && is_scalar($_POST['sfxID']) ? (int) $_POST['sfxID'] : 0;
                $message = $app->customSfx()->delete($sfxID) ? 'SFX ' . $sfxID . ' deleted.' : 'SFX not found.';
            }
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$songFallback = max(1024, Config::getInt('CUSTOM_SONG_MAX_BYTES', 26214400));
$sfxFallback = max(1024, Config::getInt('CUSTOM_SFX_MAX_BYTES', 10485760));
$limits = $app->mediaSettings()->uploadLimits($songFallback, $sfxFallback);
$songLimitMiB = max(1, (int) round($limits['songMaxBytes'] / 1048576));
$sfxLimitMiB = max(1, (int) round($limits['sfxMaxBytes'] / 1048576));
$songs = $authenticated ? $app->customSongs()->list(100) : [];
$sfxRows = $authenticated ? $app->customSfx()->list(100) : [];
$csrfValue = $authenticated ? $csrf() : '';

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Night Core media dashboard</title>
<style>
:root{color-scheme:dark}*{box-sizing:border-box}body{margin:0;background:#090b10;color:#eef2ff;font:15px/1.45 system-ui,-apple-system,Segoe UI,sans-serif}main{max-width:1180px;margin:0 auto;padding:28px 18px 60px}header{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:22px}.brand h1{font-size:27px;margin:0}.brand p{margin:4px 0 0;color:#98a2b3}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.card{background:#11151d;border:1px solid #252b38;border-radius:16px;padding:20px;box-shadow:0 10px 30px #0004}.wide{grid-column:1/-1}h2{margin:0 0 14px;font-size:18px}label{display:block;margin:12px 0 5px;color:#cbd5e1;font-weight:650}input{width:100%;padding:10px 12px;border-radius:9px;border:1px solid #384152;background:#090c12;color:#fff}button{border:0;border-radius:9px;padding:10px 14px;background:#6d5dfc;color:#fff;font-weight:750;cursor:pointer;margin-top:14px}.danger{background:#52242a}.ghost{background:#242a35}.row{display:flex;gap:12px;align-items:end}.row>div{flex:1}.notice{padding:12px 14px;border-radius:11px;margin-bottom:16px}.ok{background:#143620;border:1px solid #245a35}.err{background:#421d22;border:1px solid #71303a}.muted,small{color:#98a2b3}.metric{font-size:27px;font-weight:800}.pill{display:inline-block;padding:3px 8px;border-radius:999px;background:#222938;color:#cbd5e1;font-size:12px}table{width:100%;border-collapse:collapse}th,td{text-align:left;padding:10px 8px;border-bottom:1px solid #252b38;vertical-align:middle}th{color:#98a2b3;font-size:12px;text-transform:uppercase;letter-spacing:.04em}code{font-size:12px;word-break:break-all}td form{margin:0}td button{margin:0;padding:7px 10px}.login{max-width:480px;margin:80px auto}.top-actions form{margin:0}@media(max-width:780px){.grid{grid-template-columns:1fr}.row{display:block}.wide{grid-column:auto}header{align-items:flex-start;flex-direction:column}.table-wrap{overflow-x:auto}}
</style>
</head>
<body><main>
<?php if (!$authenticated): ?>
<section class="card login">
<div class="brand"><h1>Night Core media</h1><p>Owner dashboard</p></div>
<?php if ($message !== ''): ?><div class="notice ok"><?= $escape($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="notice err"><?= $escape($error) ?></div><?php endif; ?>
<form method="post">
<input type="hidden" name="action" value="login">
<label>Admin token</label><input type="password" name="token" autocomplete="current-password" required autofocus>
<button type="submit">Open dashboard</button>
</form>
</section>
<?php else: ?>
<header><div class="brand"><h1>Night Core media dashboard</h1><p>Songs, SFX and upload policy in one place.</p></div><div class="top-actions"><form method="post"><input type="hidden" name="action" value="logout"><input type="hidden" name="csrf" value="<?= $escape($csrfValue) ?>"><button class="ghost" type="submit">Log out</button></form></div></header>
<?php if ($message !== ''): ?><div class="notice ok"><?= $escape($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="notice err"><?= $escape($error) ?></div><?php endif; ?>
<div class="grid">
<section class="card">
<h2>Upload limits</h2>
<form method="post">
<input type="hidden" name="action" value="save_limits"><input type="hidden" name="csrf" value="<?= $escape($csrfValue) ?>">
<div class="row"><div><label>Song max size, MiB</label><input type="number" min="1" max="1024" name="songLimitMiB" value="<?= $songLimitMiB ?>" required></div><div><label>SFX max size, MiB</label><input type="number" min="1" max="1024" name="sfxLimitMiB" value="<?= $sfxLimitMiB ?>" required></div></div>
<button type="submit">Save limits</button>
</form>
<p class="muted">These values are applied immediately by Night Core. PHP currently reports <code>upload_max_filesize=<?= $escape((string) ini_get('upload_max_filesize')) ?></code> and <code>post_max_size=<?= $escape((string) ini_get('post_max_size')) ?></code>; web-server limits can still be lower.</p>
</section>
<section class="card">
<h2>Storage</h2>
<div class="row"><div><span class="pill">Songs</span><div class="metric"><?= count($songs) ?></div><small>ID <?= $app->customSongs()->minSongID() ?>–<?= $app->customSongs()->maxSongID() ?></small></div><div><span class="pill">SFX</span><div class="metric"><?= count($sfxRows) ?></div><small>ID <?= $app->customSfx()->minSfxID() ?>–<?= $app->customSfx()->maxSfxID() ?></small></div></div>
</section>
<section class="card">
<h2>Upload song</h2>
<form method="post" enctype="multipart/form-data">
<input type="hidden" name="action" value="upload_song"><input type="hidden" name="csrf" value="<?= $escape($csrfValue) ?>">
<label>Song title</label><input type="text" name="name" maxlength="255" required>
<label>Author / artist</label><input type="text" name="author" maxlength="255" required>
<label>MP3 file</label><input type="file" name="song" accept="audio/mpeg,.mp3" required>
<small>Night Core limit: <?= $songLimitMiB ?> MiB.</small><br><button type="submit">Upload song</button>
</form>
</section>
<section class="card">
<h2>Upload SFX</h2>
<form method="post" enctype="multipart/form-data">
<input type="hidden" name="action" value="upload_sfx"><input type="hidden" name="csrf" value="<?= $escape($csrfValue) ?>">
<label>SFX name</label><input type="text" name="name" maxlength="255" required>
<label>OGG file</label><input type="file" name="sfx" accept="audio/ogg,.ogg" required>
<small>Night Core limit: <?= $sfxLimitMiB ?> MiB. OGG only.</small><br><button type="submit">Upload SFX</button>
</form>
</section>
<section class="card wide"><h2>Local songs</h2><div class="table-wrap"><table><thead><tr><th>ID</th><th>Song</th><th>Size</th><th>Download</th><th></th></tr></thead><tbody>
<?php foreach ($songs as $song): ?><tr><td><strong><?= (int) $song['songID'] ?></strong></td><td><?= $escape((string) ($song['name'] ?? '(reserved)')) ?><br><small><?= $escape((string) ($song['authorName'] ?? '')) ?></small></td><td><?= $escape((string) ($song['size'] ?? '0')) ?> MB</td><td><code><?= $escape((string) ($song['download'] ?? '')) ?></code></td><td><?php if (!empty($song['name'])): ?><form method="post"><input type="hidden" name="action" value="delete_song"><input type="hidden" name="csrf" value="<?= $escape($csrfValue) ?>"><input type="hidden" name="songID" value="<?= (int) $song['songID'] ?>"><button class="danger" type="submit">Delete</button></form><?php endif; ?></td></tr><?php endforeach; ?>
<?php if ($songs === []): ?><tr><td colspan="5" class="muted">No local songs yet.</td></tr><?php endif; ?>
</tbody></table></div></section>
<section class="card wide"><h2>Local SFX</h2><div class="table-wrap"><table><thead><tr><th>ID</th><th>SFX</th><th>Size</th><th>Download</th><th></th></tr></thead><tbody>
<?php foreach ($sfxRows as $sfx): ?><tr><td><strong><?= (int) $sfx['sfxID'] ?></strong></td><td><?= $escape((string) $sfx['name']) ?></td><td><?= number_format(((int) $sfx['bytes']) / 1048576, 2, '.', '') ?> MB</td><td><code><?= $escape((string) $sfx['download']) ?></code></td><td><form method="post"><input type="hidden" name="action" value="delete_sfx"><input type="hidden" name="csrf" value="<?= $escape($csrfValue) ?>"><input type="hidden" name="sfxID" value="<?= (int) $sfx['sfxID'] ?>"><button class="danger" type="submit">Delete</button></form></td></tr><?php endforeach; ?>
<?php if ($sfxRows === []): ?><tr><td colspan="5" class="muted">No local SFX yet.</td></tr><?php endif; ?>
</tbody></table></div></section>
</div>
<?php endif; ?>
</main></body></html>
