<?php

declare(strict_types=1);

use NightCore\Core\Application;
use NightCore\Core\ClientIp;
use NightCore\Core\Config;
use NightCore\Core\MediaAccountAccess;
use NightCore\Core\PublicMediaUploadGuard;
use NightCore\Domain\Accounts\AccountDeletionService;

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
$deletion = new AccountDeletionService(
    $app->db(),
    $app->tables(),
    $app->schema(),
    $app->accountRepository(),
    $app->passwordService()
);

$isHttps = !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_name('nightcore_dashboard_account');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

const DASHBOARD_IDLE_TIMEOUT = 1800;
const DASHBOARD_ABSOLUTE_TIMEOUT = 28800;

$escape = static fn (string $value): string => htmlspecialchars(
    $value,
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);
$message = '';
$error = '';
$authPanelOpen = false;
$authTab = 'login';
$deletionStatus = null;

$csrf = static function (): string {
    if (!isset($_SESSION['dashboard_csrf'])
        || !is_string($_SESSION['dashboard_csrf'])
        || strlen($_SESSION['dashboard_csrf']) < 32) {
        $_SESSION['dashboard_csrf'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['dashboard_csrf'];
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
        UPLOAD_ERR_INI_SIZE => $label . ' exceeds the server upload limit.',
        UPLOAD_ERR_FORM_SIZE => $label . ' exceeds the form upload limit.',
        UPLOAD_ERR_PARTIAL => $label . ' upload was interrupted.',
        UPLOAD_ERR_NO_FILE => 'Choose a ' . $label . ' file.',
        UPLOAD_ERR_NO_TMP_DIR => 'The server cannot receive uploads right now.',
        UPLOAD_ERR_CANT_WRITE => 'The server could not write the uploaded file.',
        UPLOAD_ERR_EXTENSION => 'The upload was stopped by the server.',
    ];
    throw new RuntimeException($messages[$code] ?? 'Unknown file upload error.');
};

$clientIp = static fn (): string => ClientIp::detect(Config::getBool('TRUST_PROXY_HEADERS', false));

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
$loggedAccountID = isset($_SESSION['dashboard_account_id']) ? (int) $_SESSION['dashboard_account_id'] : 0;
$loggedAccount = $loggedAccountID > 0 ? $app->accountRepository()->findById($loggedAccountID) : null;

if ($loggedAccountID > 0) {
    $issuedAt = (int) ($_SESSION['dashboard_issued_at'] ?? 0);
    $lastSeen = (int) ($_SESSION['dashboard_last_seen'] ?? 0);
    $sessionFingerprint = (string) ($_SESSION['dashboard_fingerprint'] ?? '');
    $sessionInvalid = $issuedAt <= 0
        || $lastSeen <= 0
        || $now - $lastSeen > DASHBOARD_IDLE_TIMEOUT
        || $now - $issuedAt > DASHBOARD_ABSOLUTE_TIMEOUT
        || !hash_equals($fingerprint, $sessionFingerprint)
        || $loggedAccount === null
        || (int) ($loggedAccount['isActive'] ?? 0) !== 1
        || $app->accountRepository()->isAccountBanned($loggedAccountID)
        || $app->accountRepository()->isDeletionDue($loggedAccountID, $now);
    if ($sessionInvalid) {
        $_SESSION = [];
        session_regenerate_id(true);
        $loggedAccountID = 0;
        $loggedAccount = null;
        $error = 'Dashboard session expired or the account is unavailable. Sign in again.';
        $authPanelOpen = true;
    } else {
        $_SESSION['dashboard_last_seen'] = $now;
    }
}

$startAccountSession = static function (array $account) use (
    &$loggedAccountID,
    &$loggedAccount,
    $fingerprint,
    $deletion
): void {
    session_regenerate_id(true);
    $_SESSION['dashboard_account_id'] = (int) $account['accountID'];
    $_SESSION['dashboard_issued_at'] = time();
    $_SESSION['dashboard_last_seen'] = time();
    $_SESSION['dashboard_fingerprint'] = $fingerprint;
    unset($_SESSION['dashboard_csrf']);
    $loggedAccountID = (int) $account['accountID'];
    $loggedAccount = $account;
    $deletion->touchActivity($loggedAccountID);
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : '';
    $authTab = $action === 'register' ? 'register' : 'login';
    try {
        $requireCsrf();

        if ($action === 'login') {
            $account = $access->login(
                (string) ($_POST['username'] ?? ''),
                (string) ($_POST['password'] ?? ''),
                $clientIp()
            );
            $startAccountSession($account);
            $message = 'Signed in as ' . (string) $account['userName'] . '.';
        } elseif ($action === 'register') {
            if (!$app->schema()->tableExists('core_registration_attempts')) {
                throw new RuntimeException('Account registration is temporarily unavailable.');
            }

            $username = trim((string) ($_POST['username'] ?? ''));
            $email = trim((string) ($_POST['email'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');
            if ($password === '' || !hash_equals($password, $passwordConfirm)) {
                throw new RuntimeException('Passwords do not match.');
            }
            if ($email === '' || strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                throw new RuntimeException('Enter a valid email address.');
            }

            $registration = $app->accounts()->register($username, $password, $email, $clientIp());
            if ($registration !== 1) {
                $reason = match ($registration) {
                    -2 => 'This username is already registered.',
                    -4 => 'Username must contain no more than 20 characters.',
                    default => 'Registration was rejected. Check the fields or try again later.',
                };
                throw new RuntimeException($reason);
            }

            $account = $app->accountRepository()->findByUsername($username);
            if ($account !== null
                && (int) ($account['isActive'] ?? 0) === 1
                && !$app->accountRepository()->isAccountBanned((int) $account['accountID'])) {
                $startAccountSession($account);
                $message = 'Account created and signed in as ' . (string) $account['userName'] . '.';
                $authPanelOpen = true;
            } else {
                $message = 'Account created. It must be activated before you can sign in.';
                $authPanelOpen = true;
                $authTab = 'login';
            }
        } elseif ($action === 'schedule_deletion') {
            if ($loggedAccountID <= 0 || $loggedAccount === null) {
                throw new RuntimeException('Sign in before changing account deletion settings.');
            }
            $deletionStatus = $deletion->schedule(
                $loggedAccountID,
                (string) ($_POST['current_password'] ?? ''),
                (string) ($_POST['confirm_username'] ?? ''),
                (int) ($_POST['retention_days'] ?? 0)
            );
            $message = 'Account deletion scheduled for '
                . gmdate('Y-m-d H:i', (int) $deletionStatus['deletionScheduledAt']) . ' UTC.';
            $authPanelOpen = true;
        } elseif ($action === 'cancel_deletion') {
            if ($loggedAccountID <= 0 || $loggedAccount === null) {
                throw new RuntimeException('Sign in before changing account deletion settings.');
            }
            $deletion->cancel($loggedAccountID);
            $message = 'Scheduled account deletion cancelled.';
            $authPanelOpen = true;
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
                $result = $songService->import(
                    $tmpName,
                    $originalName,
                    isset($_POST['name']) && is_string($_POST['name']) ? $_POST['name'] : '',
                    isset($_POST['author']) && is_string($_POST['author']) ? $_POST['author'] : '',
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
                $originalName = isset($upload['name']) && is_string($upload['name'])
                    ? $upload['name']
                    : 'sfx.ogg';
                if ($tmpName === '' || !is_uploaded_file($tmpName)) {
                    throw new RuntimeException('The uploaded OGG was not received as a valid HTTP upload.');
                }
                $bytes = filesize($tmpName);
                if ($bytes === false || $bytes <= 0) {
                    throw new RuntimeException('The uploaded OGG is empty.');
                }
                $sfxService = $app->customSfx();
                $guard->reserve($clientIp(), $sfxService->storage()->directory(), $bytes);
                $result = $sfxService->import(
                    $tmpName,
                    $originalName,
                    isset($_POST['name']) && is_string($_POST['name']) ? $_POST['name'] : '',
                    $publicBaseUrl('CUSTOM_SFX_PUBLIC_BASE_URL', 'CUSTOM_SONG_PUBLIC_BASE_URL')
                );
                $access->recordUpload($loggedAccountID, 'sfx', $result, $originalName, $clientIp());
                $message = 'SFX uploaded. ID: ' . $result['sfxID'] . ' — ' . $result['name'];
            } else {
                throw new RuntimeException('Unknown dashboard action.');
            }
        }
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
        if (in_array($action, ['upload_song', 'upload_sfx'], true)
            && (str_contains(strtolower($error), 'wait')
                || str_contains(strtolower($error), 'hourly')
                || str_contains(strtolower($error), 'temporarily busy'))) {
            $error = 'Upload temporarily unavailable. Try again later.';
        }
        if (in_array($action, ['login', 'register', 'schedule_deletion', 'cancel_deletion'], true)) {
            $authPanelOpen = true;
        }
    }
}

if ($loggedAccountID > 0 && $loggedAccount !== null && $deletionStatus === null) {
    try {
        $deletionStatus = $deletion->status($loggedAccountID);
    } catch (Throwable) {
        $deletionStatus = null;
    }
}

$songLimitMiB = max(1, (int) floor($app->customSongs()->storage()->maxBytes() / 1048576));
$sfxLimitMiB = max(1, (int) floor($app->customSfx()->storage()->maxBytes() / 1048576));
$songs = $app->customSongs()->list(100);
$sfxRows = $app->customSfx()->list(100);
$csrfValue = $csrf();

header('Content-Type: text/html; charset=utf-8');
header("Content-Security-Policy: default-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'; object-src 'none'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'");
header('Referrer-Policy: same-origin');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Night Core dashboard</title>
<style>
:root{color-scheme:dark}*{box-sizing:border-box}body{margin:0;background:#090b10;color:#eef2ff;font:15px/1.45 system-ui,-apple-system,Segoe UI,sans-serif}main{max-width:1180px;margin:0 auto;padding:28px 18px 60px}header{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:22px}.brand{min-width:0}.brand h1{font-size:27px;margin:0}.brand p{margin:4px 0 0;color:#98a2b3}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.card{background:#11151d;border:1px solid #252b38;border-radius:16px;padding:20px;box-shadow:0 10px 30px #0004}.wide{grid-column:1/-1}h2,h3{margin:0 0 14px}h2{font-size:18px}h3{font-size:16px}label{display:block;margin:12px 0 5px;color:#cbd5e1;font-weight:650}input,select{width:100%;padding:10px 12px;border-radius:9px;border:1px solid #384152;background:#090c12;color:#fff}button{border:0;border-radius:9px;padding:10px 14px;background:#6d5dfc;color:#fff;font-weight:750;cursor:pointer;margin-top:14px}.row{display:flex;gap:12px;align-items:end}.row>div{flex:1}.notice{padding:12px 14px;border-radius:11px;margin-bottom:16px}.ok{background:#143620;border:1px solid #245a35}.err{background:#421d22;border:1px solid #71303a}.muted,small{color:#98a2b3}.metric{font-size:27px;font-weight:800}.pill{display:inline-block;padding:3px 8px;border-radius:999px;background:#222938;color:#cbd5e1;font-size:12px}table{width:100%;border-collapse:collapse}th,td{text-align:left;padding:10px 8px;border-bottom:1px solid #252b38;vertical-align:middle}th{color:#98a2b3;font-size:12px;text-transform:uppercase;letter-spacing:.04em}code{font-size:12px;word-break:break-all}.account-button{margin:0;white-space:nowrap;display:flex;align-items:center;gap:8px}.account-dot{width:8px;height:8px;border-radius:50%;background:#3ddc84}.locked{text-align:center;padding-block:28px}.locked button{margin-top:8px}dialog{width:min(520px,calc(100vw - 28px));max-height:calc(100vh - 32px);overflow:auto;border:1px solid #303849;border-radius:18px;padding:0;background:#11151d;color:#eef2ff;box-shadow:0 24px 80px #000b}dialog::backdrop{background:#03050acc;backdrop-filter:blur(5px)}.dialog-shell{padding:20px}.dialog-header{display:flex;align-items:flex-start;justify-content:space-between;gap:16px}.dialog-header h2{margin:0}.close-button{margin:0;padding:5px 10px;background:#222938;font-size:20px;line-height:1}.tabs{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin:18px 0}.tab{margin:0;background:#222938;color:#aeb8ca}.tab.active{background:#6d5dfc;color:#fff}.auth-panel[hidden]{display:none}.auth-panel p{margin-top:0}.secondary{background:#2a3140}.danger{background:#9b2635}.account-summary{padding:13px;border:1px solid #303849;background:#0b0e14;border-radius:12px;margin:16px 0}.account-summary strong{display:block;font-size:18px}.profile-section{border-top:1px solid #303849;margin-top:20px;padding-top:20px}.scheduled{padding:12px;border-radius:10px;background:#3a2a13;border:1px solid #705225}@media(max-width:780px){.grid{grid-template-columns:1fr}.row{display:block}.wide{grid-column:auto}.table-wrap{overflow-x:auto}header{align-items:flex-start}.account-button{padding:9px 11px}.brand h1{font-size:23px}}
</style>
</head>
<body><main>
<header>
<div class="brand"><h1>Night Core dashboard</h1><p>Public songs and SFX library. Sign in only when you need account features.</p></div>
<button type="button" class="account-button" data-open-account><?php if ($loggedAccount !== null): ?><span class="account-dot"></span><?= $escape((string) $loggedAccount['userName']) ?><?php else: ?>Sign in / Register<?php endif; ?></button>
</header>
<?php if ($message !== ''): ?><div class="notice ok"><?= $escape($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="notice err"><?= $escape($error) ?></div><?php endif; ?>
<div class="grid">
<section class="card">
<h2>Upload limits</h2>
<div class="row"><div><span class="pill">Songs</span><div class="metric"><?= $songLimitMiB ?> MiB</div></div><div><span class="pill">SFX</span><div class="metric"><?= $sfxLimitMiB ?> MiB</div></div></div>
<p class="muted">Files are validated by the server before they are stored.</p>
</section>
<section class="card">
<h2>Library</h2>
<div class="row"><div><span class="pill">Songs</span><div class="metric"><?= count($songs) ?></div></div><div><span class="pill">SFX</span><div class="metric"><?= count($sfxRows) ?></div></div></div>
<p class="muted">Browse public media without signing in.</p>
</section>
<?php if (!$publicUploads): ?>
<section class="card wide"><div class="notice err">Media uploads are currently disabled by the server owner.</div></section>
<?php elseif ($loggedAccount === null): ?>
<section class="card wide locked"><h2>Uploads are locked</h2><p class="muted">Use the account button in the top-right corner to sign in or create a GDPS account.</p><button type="button" data-open-account>Sign in / Register</button></section>
<?php else: ?>
<section class="card wide"><span class="pill">Authenticated uploader</span><p>Signed in as <strong><?= $escape((string) $loggedAccount['userName']) ?></strong>.</p><p class="muted">Uploads are checked automatically for file type, integrity and available storage.</p></section>
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

<dialog id="account-dialog" data-open="<?= $authPanelOpen ? '1' : '0' ?>" data-tab="<?= $escape($authTab) ?>">
<div class="dialog-shell">
<div class="dialog-header"><div><span class="pill">GDPS account</span><h2><?= $loggedAccount !== null ? 'Your profile' : 'Account access' ?></h2></div><button type="button" class="close-button" data-close-account aria-label="Close">×</button></div>
<?php if ($loggedAccount !== null): ?>
<div class="account-summary"><strong><?= $escape((string) $loggedAccount['userName']) ?></strong><span class="muted">Account ID <?= (int) $loggedAccount['accountID'] ?></span></div>
<p class="muted">This secure session expires after 30 minutes of inactivity or 8 hours total.</p>
<form method="post"><input type="hidden" name="action" value="logout"><input type="hidden" name="csrf" value="<?= $escape($csrfValue) ?>"><button type="submit" class="secondary">Sign out</button></form>
<div class="profile-section">
<h3>Account deletion</h3>
<?php if ($deletionStatus === null): ?>
<p class="muted">Account deletion settings are temporarily unavailable.</p>
<?php elseif ((int) $deletionStatus['deletionScheduledAt'] > 0): ?>
<div class="scheduled"><strong>Deletion scheduled</strong><br><span class="muted"><?= $escape(gmdate('Y-m-d H:i', (int) $deletionStatus['deletionScheduledAt'])) ?> UTC</span></div>
<p class="muted">Your account remains active until that date. You can cancel the request before it becomes due.</p>
<form method="post"><input type="hidden" name="action" value="cancel_deletion"><input type="hidden" name="csrf" value="<?= $escape($csrfValue) ?>"><button type="submit" class="secondary">Cancel deletion</button></form>
<?php else: ?>
<p class="muted">Choose how long the account should remain active. When the period ends, login credentials and email are anonymized and the account is disabled. Published levels remain under a deleted-user name.</p>
<form method="post">
<input type="hidden" name="action" value="schedule_deletion"><input type="hidden" name="csrf" value="<?= $escape($csrfValue) ?>">
<label>Delete after</label>
<select name="retention_days" required><?php foreach ($deletion->retentionOptions() as $days): ?><option value="<?= $days ?>"<?= $days === 14 ? ' selected' : '' ?>><?= $days ?> days</option><?php endforeach; ?></select>
<label>Current password</label><input type="password" name="current_password" autocomplete="current-password" required>
<label>Type your username to confirm</label><input type="text" name="confirm_username" maxlength="20" autocomplete="off" required>
<button type="submit" class="danger">Schedule account deletion</button>
</form>
<?php endif; ?>
</div>
<?php else: ?>
<div class="tabs" role="tablist"><button type="button" class="tab" data-auth-tab="login" role="tab">Sign in</button><button type="button" class="tab" data-auth-tab="register" role="tab">Register</button></div>
<section class="auth-panel" data-auth-panel="login">
<p class="muted">Use the same username and password as in Geometry Dash. Passwords are verified on the server and are not stored in the browser session.</p>
<form method="post">
<input type="hidden" name="action" value="login"><input type="hidden" name="csrf" value="<?= $escape($csrfValue) ?>">
<label>Username</label><input type="text" name="username" maxlength="20" autocomplete="username" required>
<label>Password</label><input type="password" name="password" autocomplete="current-password" required>
<button type="submit">Sign in</button>
</form>
</section>
<section class="auth-panel" data-auth-panel="register" hidden>
<p class="muted">Creates a normal GDPS account using the same account database and password protection as the game.</p>
<form method="post">
<input type="hidden" name="action" value="register"><input type="hidden" name="csrf" value="<?= $escape($csrfValue) ?>">
<label>Username</label><input type="text" name="username" maxlength="20" pattern="[A-Za-z0-9_]+" autocomplete="username" required>
<label>Email</label><input type="email" name="email" maxlength="255" autocomplete="email" required>
<label>Password</label><input type="password" name="password" maxlength="128" autocomplete="new-password" required>
<label>Repeat password</label><input type="password" name="password_confirm" maxlength="128" autocomplete="new-password" required>
<small>Registration is protected automatically by the server.</small><br><button type="submit">Create account</button>
</form>
</section>
<?php endif; ?>
</div>
</dialog>
</main>
<script>
(() => {
    const dialog = document.getElementById('account-dialog');
    if (!dialog) return;
    const openDialog = () => typeof dialog.showModal === 'function' ? dialog.showModal() : dialog.setAttribute('open', '');
    const closeDialog = () => typeof dialog.close === 'function' ? dialog.close() : dialog.removeAttribute('open');
    const selectTab = (name) => {
        dialog.querySelectorAll('[data-auth-tab]').forEach((button) => {
            const active = button.dataset.authTab === name;
            button.classList.toggle('active', active);
            button.setAttribute('aria-selected', active ? 'true' : 'false');
        });
        dialog.querySelectorAll('[data-auth-panel]').forEach((panel) => {
            panel.hidden = panel.dataset.authPanel !== name;
        });
    };
    document.querySelectorAll('[data-open-account]').forEach((button) => button.addEventListener('click', openDialog));
    dialog.querySelectorAll('[data-close-account]').forEach((button) => button.addEventListener('click', closeDialog));
    dialog.querySelectorAll('[data-auth-tab]').forEach((button) => button.addEventListener('click', () => selectTab(button.dataset.authTab || 'login')));
    dialog.addEventListener('click', (event) => { if (event.target === dialog) closeDialog(); });
    selectTab(dialog.dataset.tab || 'login');
    if (dialog.dataset.open === '1') openDialog();
})();
</script>
</body></html>
