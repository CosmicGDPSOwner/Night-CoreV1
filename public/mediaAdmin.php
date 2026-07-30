<?php

declare(strict_types=1);

use NightCore\Core\Application;
use NightCore\Core\Config;
use NightCore\Core\MediaAccountAccess;
use NightCore\Core\PublicMediaUploadGuard;

$root = dirname(__DIR__);
/** @var Application $app */
$app = require $root . '/bootstrap.php';
$policy = $app->mediaPolicy();
$publicUploads = $policy->publicUploadsEnabled();
$guard = new PublicMediaUploadGuard($app->db(), $app->tables(), $policy);
$access = new MediaAccountAccess(
    $app->db(),
    $app->tables(),
    $app->schema(),
    $app->accountRepository(),
    $app->passwordService()
);

$isHttps = !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
session_name('nightcore_media_account');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

const MEDIA_IDLE_TIMEOUT = 1800;
const MEDIA_ABSOLUTE_TIMEOUT = 28800;

$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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
        throw new RuntimeException('Invalid request token. Refresh the page and try again.');
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

$clientIp = static function (): string {
    if (Config::getBool('TRUST_PROXY_HEADERS', false)) {
        $forwarded = trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''))[0] ?? '');
        if ($forwarded !== '' && filter_var($forwarded, FILTER_VALIDATE_IP) !== false) {
            return $forwarded;
        }
    }
    $remote = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    return filter_var($remote, FILTER_VALIDATE_IP) !== false ? $remote : 'unknown';
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

$now = time();
$fingerprint = hash('sha256', substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 512));
$loggedAccountID = isset($_SESSION['media_account_id']) ? (int) $_SESSION['media_account_id'] : 0;
$loggedAccount = $loggedAccountID > 0 ? $app->accountRepository()->findById($loggedAccountID) : null;

if ($loggedAccountID > 0) {
    $issuedAt = (int) ($_SESSION['media_issued_at'] ?? 0);
    $lastSeen = (int) ($_SESSION['media_last_seen'] ?? 0);
    $sessionFingerprint = (string) ($_SESSION['media_fingerprint'] ?? '');
    $sessionInvalid = $issuedAt <= 0
        || $lastSeen <= 0
        || $now - $lastSeen > MEDIA_IDLE_TIMEOUT
        || $now - $issuedAt > MEDIA_ABSOLUTE_TIMEOUT
        || !hash_equals($fingerprint, $sessionFingerprint)
        || $loggedAccount === null
        || (int) ($loggedAccount['isActive'] ?? 0) !== 1
        || $app->accountRepository()->isAccountBanned($loggedAccountID);
    if ($sessionInvalid) {
        $_SESSION = [];
        session_regenerate_id(true);
        $loggedAccountID = 0;
        $loggedAccount = null;
        $error = 'Media upload session expired. Sign in again.';
    } else {
        $_SESSION['media_last_seen'] = $now;
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : '';
    try {
        $requireCsrf();

        if ($action === 'login') {
            $account = $access->login(
                (string) ($_POST['username'] ?? ''),
                (string) ($_POST['password'] ?? ''),
                $clientIp()
            );
            session_regenerate_id(true);
            $_SESSION['media_account_id'] = (int) $account['accountID'];
            $_SESSION['media_issued_at'] = time();
            $_SESSION['media_last_seen'] = time();
            $_SESSION['media_fingerprint'] = $fingerprint;
            unset($_SESSION['media_csrf']);
            $loggedAccountID = (int) $account['accountID'];
            $loggedAccount = $account;
            $message = 'Signed in as ' . (string) $account['userName'] . '.';
        } elseif ($action === 'logout') {
            $_SESSION = [];
            session_regenerate_id(true);
            $loggedAccountID = 0;
            $loggedAccount = null;
            $message = 'Signed out.';
        } else {
            if (!$publicUploads) {
                throw new RuntimeException('Media uploads are disabled.');
            }
            if ($loggedAccountID <= 0 || $loggedAccount === null) {
                throw new RuntimeException('Sign in with a GDPS account before uploading media.');
            }

            if ($action === 'upload_song') {
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
                $bytes = filesize($tmpName);
                if ($bytes === false || $bytes <= 0) {
                    throw new RuntimeException('The uploaded MP3 is empty.');
                }
                $songService = $app->customSongs();
                $guard->reserve($clientIp(), $songService->storage()->directory(), $bytes);
                $name = isset($_POST['name']) && is_string($_POST['name']) ? $_POST['name'] : '';
                $author = isset($_POST['author']) && is_string($_POST['author']) ? $_POST['author'] : '';
                $result = $songService->import(
                    $tmpName,
                    $originalName,
                    $name,
                    $author,
                    $publicBaseUrl('CUSTOM_SONG_PUBLIC_BASE_URL')
                );
                $access->recordUpload($loggedAccountID, 'song', $result, $originalName, $clientIp());
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
                $bytes = filesize($tmpName);
                if ($bytes === false || $bytes <= 0) {
                    throw new RuntimeException('The uploaded OGG is empty.');
                }
                $sfxService = $app->customSfx();
                $guard->reserve($clientIp(), $sfxService->storage()->directory(), $bytes);
                $name = isset($_POST['name']) && is_string($_POST['name']) ? $_POST['name'] : '';
                $result = $sfxService->import(
                    $tmpName,
                    $originalName,
                    $name,
                    $publicBaseUrl('CUSTOM_SFX_PUBLIC_BASE_URL', 'CUSTOM_SONG_PUBLIC_BASE_URL')
                );
                $access->recordUpload($loggedAccountID, 'sfx', $result, $originalName, $clientIp());
                $message = 'SFX uploaded. ID: ' . $result['sfxID'] . ' — ' . $result['name'];
            } else {
                throw new RuntimeException('Unknown media action.');
            }
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$songLimitMiB = max(1, (int) floor($app->customSongs()->storage()->maxBytes() / 1048576));
$sfxLimitMiB = max(1, (int) floor($app->customSfx()->storage()->maxBytes() / 1048576));
$minimumFreeMiB = max(1, (int) floor($policy->minimumFreeBytes() / 1048576));
$songs = $app->customSongs()->list(100);
$sfxRows = $app->customSfx()->list(100);
$csrfValue = $csrf();

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Night Core media library</title>
<style>
:root{color-scheme:dark}*{box-sizing:border-box}body{margin:0;background:#090b10;color:#eef2ff;font:15px/1.45 system-ui,-apple-system,Segoe UI,sans-serif}main{max-width:1180px;margin:0 auto;padding:28px 18px 60px}header{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:22px}.brand h1{font-size:27px;margin:0}.brand p{margin:4px 0 0;color:#98a2b3}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.card{background:#11151d;border:1px solid #252b38;border-radius:16px;padding:20px;box-shadow:0 10px 30px #0004}.wide{grid-column:1/-1}h2{margin:0 0 14px;font-size:18px}label{display:block;margin:12px 0 5px;color:#cbd5e1;font-weight:650}input{width:100%;padding:10px 12px;border-radius:9px;border:1px solid #384152;background:#090c12;color:#fff}button{border:0;border-radius:9px;padding:10px 14px;background:#6d5dfc;color:#fff;font-weight:750;cursor:pointer;margin-top:14px}.row{display:flex;gap:12px;align-items:end}.row>div{flex:1}.notice{padding:12px 14px;border-radius:11px;margin-bottom:16px}.ok{background:#143620;border:1px solid #245a35}.err{background:#421d22;border:1px solid #71303a}.muted,small{color:#98a2b3}.metric{font-size:27px;font-weight:800}.pill{display:inline-block;padding:3px 8px;border-radius:999px;background:#222938;color:#cbd5e1;font-size:12px}table{width:100%;border-collapse:collapse}th,td{text-align:left;padding:10px 8px;border-bottom:1px solid #252b38;vertical-align:middle}th{color:#98a2b3;font-size:12px;text-transform:uppercase;letter-spacing:.04em}code{font-size:12px;word-break:break-all}.header-form button{margin:0}@media(max-width:780px){.grid{grid-template-columns:1fr}.row{display:block}.wide{grid-column:auto}.table-wrap{overflow-x:auto}header{align-items:flex-start;flex-direction:column}}
</style>
</head>
<body><main>
<header><div class="brand"><h1>Night Core media library</h1><p>Public library. A GDPS account login is required to upload songs or SFX.</p></div><?php if ($loggedAccount !== null): ?><form class="header-form" method="post"><input type="hidden" name="action" value="logout"><input type="hidden" name="csrf" value="<?= $escape($csrfValue) ?>"><button type="submit">Sign out</button></form><?php endif; ?></header>
<?php if ($message !== ''): ?><div class="notice ok"><?= $escape($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="notice err"><?= $escape($error) ?></div><?php endif; ?>
<div class="grid">
<section class="card">
<h2>Upload limits</h2>
<div class="row"><div><span class="pill">Songs</span><div class="metric"><?= $songLimitMiB ?> MiB</div></div><div><span class="pill">SFX</span><div class="metric"><?= $sfxLimitMiB ?> MiB</div></div></div>
<p class="muted">Limits are read-only here and are controlled by the server owner.</p>
</section>
<section class="card">
<h2>Anti-spam</h2>
<div class="row"><div><span class="pill">Cooldown</span><div class="metric"><?= $policy->uploadCooldownSeconds() ?>s</div></div><div><span class="pill">Per IP / hour</span><div class="metric"><?= $policy->uploadsPerHourPerIp() ?></div></div></div>
<p class="muted">Global cap: <?= $policy->globalUploadsPerHour() ?> uploads/hour. Uploads pause automatically before free disk space drops below <?= $minimumFreeMiB ?> MiB.</p>
</section>
<section class="card wide">
<h2>Library</h2>
<div class="row"><div><span class="pill">Songs</span><div class="metric"><?= count($songs) ?></div></div><div><span class="pill">SFX</span><div class="metric"><?= count($sfxRows) ?></div></div></div>
</section>
<?php if (!$publicUploads): ?>
<section class="card wide"><div class="notice err">Media uploads are currently disabled by the server owner.</div></section>
<?php elseif ($loggedAccount === null): ?>
<section class="card wide" style="max-width:560px;margin-inline:auto;width:100%">
<h2>Account login required</h2>
<p class="muted">Sign in with your registered GDPS username and password. The password is verified and is never stored in this dashboard session.</p>
<form method="post">
<input type="hidden" name="action" value="login"><input type="hidden" name="csrf" value="<?= $escape($csrfValue) ?>">
<label>Username</label><input type="text" name="username" maxlength="64" autocomplete="username" required>
<label>Password</label><input type="password" name="password" autocomplete="current-password" required>
<button type="submit">Sign in</button>
</form>
</section>
<?php else: ?>
<section class="card wide"><span class="pill">Authenticated uploader</span><p>Signed in as <strong><?= $escape((string) $loggedAccount['userName']) ?></strong>.</p><p class="muted">Uploads remain subject to the IP cooldown, hourly quotas, file validation and storage reserve.</p></section>
<section class="card">
<h2>Upload song</h2>
<form method="post" enctype="multipart/form-data">
<input type="hidden" name="action" value="upload_song"><input type="hidden" name="csrf" value="<?= $escape($csrfValue) ?>">
<label>Song title</label><input type="text" name="name" maxlength="255" required>
<label>Author / artist</label><input type="text" name="author" maxlength="255" required>
<label>MP3 file</label><input type="file" name="song" accept="audio/mpeg,.mp3" required>
<small>Maximum file size: <?= $songLimitMiB ?> MiB.</small><br><button type="submit">Upload song</button>
</form>
</section>
<section class="card">
<h2>Upload SFX</h2>
<form method="post" enctype="multipart/form-data">
<input type="hidden" name="action" value="upload_sfx"><input type="hidden" name="csrf" value="<?= $escape($csrfValue) ?>">
<label>SFX name</label><input type="text" name="name" maxlength="255" required>
<label>OGG file</label><input type="file" name="sfx" accept="audio/ogg,.ogg" required>
<small>Maximum file size: <?= $sfxLimitMiB ?> MiB. OGG only.</small><br><button type="submit">Upload SFX</button>
</form>
</section>
<?php endif; ?>
<section class="card wide"><h2>Local songs</h2><div class="table-wrap"><table><thead><tr><th>ID</th><th>Song</th><th>Size</th><th>Download</th></tr></thead><tbody>
<?php foreach ($songs as $song): ?><tr><td><strong><?= (int) $song['songID'] ?></strong></td><td><?= $escape((string) ($song['name'] ?? '(reserved)')) ?><br><small><?= $escape((string) ($song['authorName'] ?? '')) ?></small></td><td><?= $escape((string) ($song['size'] ?? '0')) ?> MB</td><td><code><?= $escape((string) ($song['download'] ?? '')) ?></code></td></tr><?php endforeach; ?>
<?php if ($songs === []): ?><tr><td colspan="4" class="muted">No local songs yet.</td></tr><?php endif; ?>
</tbody></table></div></section>
<section class="card wide"><h2>Local SFX</h2><div class="table-wrap"><table><thead><tr><th>ID</th><th>SFX</th><th>Size</th><th>Download</th></tr></thead><tbody>
<?php foreach ($sfxRows as $sfx): ?><tr><td><strong><?= (int) $sfx['sfxID'] ?></strong></td><td><?= $escape((string) $sfx['name']) ?></td><td><?= number_format(((int) $sfx['bytes']) / 1048576, 2, '.', '') ?> MB</td><td><code><?= $escape((string) $sfx['download']) ?></code></td></tr><?php endforeach; ?>
<?php if ($sfxRows === []): ?><tr><td colspan="4" class="muted">No local SFX yet.</td></tr><?php endif; ?>
</tbody></table></div></section>
</div>
</main></body></html>
