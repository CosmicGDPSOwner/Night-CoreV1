<?php

declare(strict_types=1);

use NightCore\Core\AccountPolicy;
use NightCore\Core\Application;
use NightCore\Core\ClientIp;
use NightCore\Core\Config;
use NightCore\Domain\Accounts\SensitiveActionConfirmationService;
use NightCore\Web\Security\PanelLoginThrottle;
use NightCore\Web\Security\PanelSecurity;
use NightCore\Web\Security\RepositoryAccountStateProvider;

$root = dirname(__DIR__);
/** @var Application $app */
$app = require $root . '/bootstrap.php';
$serverPolicy = AccountPolicy::load($root);
$db = $app->db();
$tables = $app->tables();
$staff = $app->staffAccess();
$confirmation = new SensitiveActionConfirmationService(
    $db,
    $tables,
    $app->schema(),
    $app->accountRepository(),
    $app->passwordService()
);
$panelSecurity = PanelSecurity::boot(
    'nightcore_event_admin',
    'event',
    $serverPolicy,
    new RepositoryAccountStateProvider($app->accountRepository()),
    true
);

const EVENT_LOGIN_WINDOW = 900;
const EVENT_LOGIN_MAX_FAILURES = 5;

$escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$formatTime = static fn(int $timestamp): string => $timestamp > 0
    ? gmdate('Y-m-d H:i:s', $timestamp) . ' UTC'
    : '-';
$decodeRewards = static function (string $json): string {
    $value = json_decode($json, true);
    if (!is_array($value)) {
        return $json;
    }
    $parts = [];
    foreach ($value as $key => $amount) {
        $parts[] = (string) $key . ': ' . (int) $amount;
    }
    return implode(', ', $parts);
};
$canView = static function (int $id) use ($staff): bool {
    return $id > 0 && (
        $staff->isOwner($id)
        || $staff->has($id, 'events.create')
        || $staff->has($id, 'events.change')
        || $staff->has($id, 'events.set')
    );
};

$loginTableAvailable = $app->schema()->tableExists('core_staff_admin_login_attempts');
$loginThrottle = new PanelLoginThrottle(
    $db,
    $tables->raw('core_staff_admin_login_attempts'),
    'event',
    ClientIp::detect(Config::getBool('TRUST_PROXY_HEADERS', false))
);

$message = '';
$error = '';
$hadSession = $panelSecurity->accountId() > 0;
if ($hadSession && !$panelSecurity->validate(
    static fn(int $accountID, array $account, int $now): bool => $canView($accountID)
)) {
    $error = 'Event session expired or permission was removed. Sign in again.';
}
$accountID = $panelSecurity->accountId();
$loggedAccount = $panelSecurity->account();
$now = time();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : '';
    try {
        $panelSecurity->requireCsrf($_POST['csrf'] ?? '');

        if ($action === 'login') {
            if ($loginTableAvailable && $loginThrottle->blocked(EVENT_LOGIN_MAX_FAILURES, EVENT_LOGIN_WINDOW)) {
                throw new RuntimeException('Sign-in temporarily unavailable. Try again later.');
            }
            $username = trim((string) ($_POST['username'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            $account = $app->accountRepository()->findByUsername($username);
            $candidate = $account === null ? 0 : (int) $account['accountID'];
            $valid = $account !== null
                && (int) ($account['isActive'] ?? 0) === 1
                && !$app->accountRepository()->isAccountBanned($candidate)
                && !$app->accountRepository()->isDeletionDue($candidate)
                && $app->passwordService()->verifyPassword($password, (string) $account['password']);
            if (!$valid || $account === null) {
                if ($loginTableAvailable) {
                    $loginThrottle->record($candidate, false);
                }
                throw new RuntimeException('Invalid username or password.');
            }
            if (!$canView($candidate)) {
                if ($loginTableAvailable) {
                    $loginThrottle->record($candidate, false);
                }
                throw new RuntimeException('Event management permission required.');
            }
            if ($loginTableAvailable) {
                $loginThrottle->record($candidate, true);
            }
            $panelSecurity->signIn($account);
            $accountID = $panelSecurity->accountId();
            $loggedAccount = $panelSecurity->account();
            $message = 'Signed in.';
        } elseif ($action === 'logout') {
            $panelSecurity->signOut();
            $accountID = 0;
            $loggedAccount = null;
            $message = 'Signed out.';
        } else {
            if (!$canView($accountID)) {
                throw new RuntimeException('Event management permission required.');
            }
            $confirmation->verifyForAction(
                $accountID,
                (string) ($_POST['current_password'] ?? '')
            );
            $eventID = (int) ($_POST['event_id'] ?? 0);
            if ($eventID <= 0) {
                throw new RuntimeException('Invalid event.');
            }
            if ($action !== 'cancel' && $action !== 'end') {
                throw new RuntimeException('Unknown action.');
            }
            if (!$staff->isOwner($accountID) && !$staff->has($accountID, 'events.set')) {
                throw new RuntimeException('events.set permission required.');
            }

            $status = $action === 'cancel' ? 'cancelled' : 'ended';
            $event = $db->prepare(
                'SELECT levelID FROM ' . $tables->get('core_events')
                . ' WHERE eventID = :eventID LIMIT 1'
            );
            $event->execute([':eventID' => $eventID]);
            $levelID = $event->fetchColumn();
            if ($levelID === false) {
                throw new RuntimeException('Event not found.');
            }

            $db->beginTransaction();
            try {
                $update = $db->prepare(
                    'UPDATE ' . $tables->get('core_events')
                    . ' SET status = :status, endsAt = LEAST(endsAt, :endedAt),'
                    . ' updatedBy = :accountID, updatedAt = :updatedAt WHERE eventID = :eventID'
                );
                $update->execute([
                    ':status' => $status,
                    ':endedAt' => $now,
                    ':accountID' => $accountID,
                    ':updatedAt' => $now,
                    ':eventID' => $eventID,
                ]);
                $deleteSlot = $db->prepare(
                    'DELETE FROM ' . $tables->get('core_daily_levels')
                    . ' WHERE slotType = 2 AND slotID = :eventID'
                );
                $deleteSlot->execute([':eventID' => $eventID]);
                $audit = $db->prepare(
                    'INSERT INTO ' . $tables->get('core_event_audit')
                    . ' (eventID, levelID, accountID, action, detailsJson, createdAt)'
                    . ' VALUES (:eventID, :levelID, :accountID, :action, :details, :createdAt)'
                );
                $audit->execute([
                    ':eventID' => $eventID,
                    ':levelID' => (int) $levelID,
                    ':accountID' => $accountID,
                    ':action' => $action,
                    ':details' => '{}',
                    ':createdAt' => $now,
                ]);
                $db->commit();
            } catch (Throwable $exception) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                throw $exception;
            }
            $message = ucfirst($status) . ' event #' . $eventID . '.';
        }
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

if ($accountID > 0 && !$canView($accountID)) {
    $panelSecurity->signOut();
    $accountID = 0;
    $loggedAccount = null;
}
$requireSensitivePassword = $accountID <= 0 || $confirmation->requiresPassword($accountID);

if ($accountID > 0) {
    $eventsTable = $tables->get('core_events');
    $endExpired = $db->prepare(
        "UPDATE {$eventsTable} SET status = 'ended', updatedAt = :updatedAt"
        . " WHERE status IN ('scheduled','active') AND endsAt <= :endsAt"
    );
    $endExpired->execute([':updatedAt' => $now, ':endsAt' => $now]);
    $activate = $db->prepare(
        "UPDATE {$eventsTable} SET status = 'active', updatedAt = :updatedAt"
        . " WHERE status = 'scheduled' AND startsAt <= :startsAt AND endsAt > :endsAt"
    );
    $activate->execute([':updatedAt' => $now, ':startsAt' => $now, ':endsAt' => $now]);
    $deleteExpired = $db->prepare(
        'DELETE FROM ' . $tables->get('core_daily_levels')
        . ' WHERE slotType = 2 AND endsAt <= :endsAt'
    );
    $deleteExpired->execute([':endsAt' => $now]);
}

$events = [];
$claims = [];
$auditRows = [];
if ($accountID > 0) {
    $events = $db->query(
        'SELECT e.*, (SELECT COUNT(*) FROM ' . $tables->get('core_event_claims')
        . ' c WHERE c.eventID = e.eventID) AS claimCount FROM '
        . $tables->get('core_events') . ' e ORDER BY e.eventID DESC LIMIT 100'
    )->fetchAll() ?: [];
    $claims = $db->query(
        'SELECT c.eventID, c.accountID, a.userName, c.claimedAt, c.rewardJson FROM '
        . $tables->get('core_event_claims') . ' c LEFT JOIN ' . $tables->get('accounts')
        . ' a ON a.accountID = c.accountID ORDER BY c.claimedAt DESC LIMIT 200'
    )->fetchAll() ?: [];
    $auditRows = $db->query(
        'SELECT x.eventID, x.levelID, x.accountID, a.userName, x.action, x.detailsJson, x.createdAt FROM '
        . $tables->get('core_event_audit') . ' x LEFT JOIN ' . $tables->get('accounts')
        . ' a ON a.accountID = x.accountID ORDER BY x.auditID DESC LIMIT 200'
    )->fetchAll() ?: [];
}

$csrfValue = $panelSecurity->csrfToken();
$sessionDescription = $panelSecurity->sessionDescription();
$nonce = $panelSecurity->nonce();
$panelSecurity->sendHeaders();
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Night Core events</title><style nonce="<?= $escape($nonce) ?>">
:root{color-scheme:dark}*{box-sizing:border-box}body{margin:0;background:#090b10;color:#eef2ff;font:15px/1.45 system-ui,sans-serif}main{max-width:1250px;margin:auto;padding:28px 18px 60px}header{display:flex;justify-content:space-between;gap:16px;align-items:center}.card{background:#11151d;border:1px solid #252b38;border-radius:16px;padding:20px;margin:16px 0}.notice{padding:12px;border-radius:10px}.ok{background:#143620}.err{background:#421d22}input,button{padding:10px 12px;border-radius:9px;border:1px solid #384152;background:#090c12;color:#fff}button{background:#6d5dfc;font-weight:700;cursor:pointer}.danger{background:#9f3341}.muted{color:#98a2b3}table{width:100%;border-collapse:collapse}th,td{text-align:left;padding:9px 8px;border-bottom:1px solid #252b38;vertical-align:top}th{font-size:12px;text-transform:uppercase;color:#98a2b3}.wrap{overflow:auto}.status{font-weight:700}.active{color:#69db7c}.scheduled{color:#74c0fc}.ended,.cancelled{color:#adb5bd}form.inline{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.security-state{padding:10px 12px;border:1px solid #303849;border-radius:10px;background:#0b0e14;margin-top:16px}@media(max-width:700px){header{align-items:flex-start;flex-direction:column}}
</style></head><body><main><header><div><h1>Night Core events</h1><p class="muted">Event slots, rewards, claims and audit.</p></div><?php if ($loggedAccount): ?><form method="post"><input type="hidden" name="csrf" value="<?= $escape($csrfValue) ?>"><input type="hidden" name="action" value="logout"><button>Sign out</button></form><?php endif; ?></header>
<?php if ($message !== ''): ?><div class="notice ok"><?= $escape($message) ?></div><?php endif; ?><?php if ($error !== ''): ?><div class="notice err"><?= $escape($error) ?></div><?php endif; ?>
<?php if (!$loggedAccount): ?><section class="card" style="max-width:520px;margin-inline:auto"><h2>Event management login</h2><p class="muted">Repeated failed sign-in attempts are temporarily blocked when the staff security tables are available.</p><form method="post"><input type="hidden" name="csrf" value="<?= $escape($csrfValue) ?>"><input type="hidden" name="action" value="login"><p><input name="username" placeholder="Username" autocomplete="username" required style="width:100%"></p><p><input type="password" name="password" placeholder="Password" autocomplete="current-password" required style="width:100%"></p><button>Sign in</button></form></section><?php else: ?>
<p class="muted">Signed in as <strong><?= $escape((string)$loggedAccount['userName']) ?></strong>. <?= $escape($sessionDescription) ?> Commands: <code>!event</code>, <code>!eventchange</code>, <code>!eventset</code>.</p>
<div class="security-state"><strong>Per-action password confirmation: <?= $requireSensitivePassword ? 'enabled' : 'disabled' ?></strong><br><span class="muted">Change this setting in <code>/dashboard.php</code>. Event-panel login always requires a password. Geometry Dash commands are unaffected.</span></div>
<section class="card"><h2>Events</h2><div class="wrap"><table><thead><tr><th>ID</th><th>Level</th><th>Status</th><th>Window</th><th>Rewards</th><th>Claims</th><th>Action</th></tr></thead><tbody><?php foreach ($events as $event): ?><tr><td>#<?= (int)$event['eventID'] ?></td><td><?= (int)$event['levelID'] ?></td><td class="status <?= $escape((string)$event['status']) ?>"><?= $escape((string)$event['status']) ?></td><td><?= $escape($formatTime((int)$event['startsAt'])) ?><br><?= $escape($formatTime((int)$event['endsAt'])) ?></td><td><?= $escape($decodeRewards((string)$event['rewardJson'])) ?></td><td><?= (int)$event['claimCount'] ?></td><td><?php if (in_array((string)$event['status'], ['active','scheduled'], true)): ?><form class="inline" method="post" data-confirm="Change this event status?"><input type="hidden" name="csrf" value="<?= $escape($csrfValue) ?>"><input type="hidden" name="event_id" value="<?= (int)$event['eventID'] ?>"><?php if ($requireSensitivePassword): ?><input type="password" name="current_password" placeholder="Current password" autocomplete="current-password" required><?php endif; ?><button name="action" value="end">End</button><button class="danger" name="action" value="cancel">Cancel</button></form><?php endif; ?></td></tr><?php endforeach; ?><?php if ($events === []): ?><tr><td colspan="7" class="muted">No events.</td></tr><?php endif; ?></tbody></table></div></section>
<section class="card"><h2>Reward claims / saves</h2><p class="muted">A row appears after the server records a claim. Duplicate event + account claims are rejected.</p><div class="wrap"><table><thead><tr><th>Event</th><th>Account</th><th>Claimed</th><th>Reward snapshot</th></tr></thead><tbody><?php foreach ($claims as $claim): ?><tr><td>#<?= (int)$claim['eventID'] ?></td><td><?= $escape((string)($claim['userName'] ?? '')) ?> #<?= (int)$claim['accountID'] ?></td><td><?= $escape($formatTime((int)$claim['claimedAt'])) ?></td><td><?= $escape($decodeRewards((string)$claim['rewardJson'])) ?></td></tr><?php endforeach; ?><?php if ($claims === []): ?><tr><td colspan="4" class="muted">No reward claims recorded.</td></tr><?php endif; ?></tbody></table></div></section>
<section class="card"><h2>Audit</h2><div class="wrap"><table><thead><tr><th>Time</th><th>Event</th><th>Account</th><th>Action</th><th>Details</th></tr></thead><tbody><?php foreach ($auditRows as $row): ?><tr><td><?= $escape($formatTime((int)$row['createdAt'])) ?></td><td>#<?= (int)$row['eventID'] ?> / level <?= (int)$row['levelID'] ?></td><td><?= $escape((string)($row['userName'] ?? '')) ?> #<?= (int)$row['accountID'] ?></td><td><?= $escape((string)$row['action']) ?></td><td><code><?= $escape((string)$row['detailsJson']) ?></code></td></tr><?php endforeach; ?><?php if ($auditRows === []): ?><tr><td colspan="5" class="muted">No audit entries.</td></tr><?php endif; ?></tbody></table></div></section>
<?php endif; ?></main><script nonce="<?= $escape($nonce) ?>">document.querySelectorAll('form[data-confirm]').forEach((form)=>form.addEventListener('submit',(event)=>{if(!confirm(form.dataset.confirm||'Continue?'))event.preventDefault();}));</script></body></html>
