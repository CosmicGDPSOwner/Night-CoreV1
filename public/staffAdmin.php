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
$staff = $app->staffAccess();
$repository = $staff->repository();
$db = $app->db();
$tables = $app->tables();
$confirmation = new SensitiveActionConfirmationService(
    $db,
    $tables,
    $app->schema(),
    $app->accountRepository(),
    $app->passwordService()
);
$panelSecurity = PanelSecurity::boot(
    'nightcore_staff_admin',
    'staff',
    $serverPolicy,
    new RepositoryAccountStateProvider($app->accountRepository()),
    true
);

const STAFF_LOGIN_WINDOW = 900;
const STAFF_LOGIN_MAX_FAILURES = 5;

$escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$field = static function (mixed $value, int $max): string {
    $value = str_replace("\0", '', trim((string) $value));
    return strlen($value) > $max ? substr($value, 0, $max) : $value;
};
$color = static function (mixed $value): string {
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }
    if (preg_match('/^#[0-9a-fA-F]{6}$/', $value) !== 1) {
        throw new RuntimeException('Colors must use #RRGGBB format.');
    }
    return strtolower($value);
};

$loginTable = $tables->get('core_staff_admin_login_attempts');
$auditTable = $tables->get('core_staff_admin_audit');
$securityTablesAvailable = $app->schema()->tableExists('core_staff_admin_login_attempts')
    && $app->schema()->tableExists('core_staff_admin_audit');
$clientAddress = ClientIp::detect(Config::getBool('TRUST_PROXY_HEADERS', false));
$loginThrottle = new PanelLoginThrottle($db, $loginTable, 'staff', $clientAddress);
$ipHash = $loginThrottle->identifier();

$audit = static function (
    int $actor,
    int $target,
    int $roleID,
    string $action,
    mixed $before,
    mixed $after
) use ($db, $auditTable, $ipHash): void {
    $encode = static fn(mixed $value): string => json_encode(
        $value,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    ) ?: '{}';
    $query = $db->prepare(
        'INSERT INTO ' . $auditTable
        . ' (actorAccountID, targetAccountID, roleID, action, beforeJson, afterJson, ipHash, createdAt)'
        . ' VALUES (:actor, :target, :roleID, :action, :beforeJson, :afterJson, :ipHash, :createdAt)'
    );
    $query->execute([
        ':actor' => $actor,
        ':target' => max(0, $target),
        ':roleID' => max(0, $roleID),
        ':action' => substr($action, 0, 48),
        ':beforeJson' => $encode($before),
        ':afterJson' => $encode($after),
        ':ipHash' => $ipHash,
        ':createdAt' => time(),
    ]);
};

$message = '';
$error = '';
$hadSession = $panelSecurity->accountId() > 0;
if ($hadSession && !$panelSecurity->validate(
    static fn(int $accountID, array $account, int $now): bool => $staff->has($accountID, 'staff.manage')
)) {
    $error = 'Staff session expired or permission was removed. Sign in again.';
}
$loggedAccountID = $panelSecurity->accountId();
$loggedAccount = $panelSecurity->account();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : '';
    try {
        $panelSecurity->requireCsrf($_POST['csrf'] ?? '');

        if ($action === 'login') {
            if (!$securityTablesAvailable) {
                throw new RuntimeException('Apply migration 0016 before using staff management.');
            }
            if ($loginThrottle->blocked(STAFF_LOGIN_MAX_FAILURES, STAFF_LOGIN_WINDOW)) {
                throw new RuntimeException('Sign-in temporarily unavailable. Try again later.');
            }
            $username = $field($_POST['username'] ?? '', 64);
            $password = (string) ($_POST['password'] ?? '');
            $account = $app->accountRepository()->findByUsername($username);
            $accountID = $account === null ? 0 : (int) $account['accountID'];
            $valid = $account !== null
                && (int) ($account['isActive'] ?? 0) === 1
                && !$app->accountRepository()->isAccountBanned($accountID)
                && !$app->accountRepository()->isDeletionDue($accountID)
                && $app->passwordService()->verifyPassword($password, (string) $account['password']);
            if (!$valid || $account === null) {
                $loginThrottle->record($accountID, false);
                throw new RuntimeException('Invalid username or password.');
            }
            if (!$staff->has($accountID, 'staff.manage')) {
                $loginThrottle->record($accountID, false);
                throw new RuntimeException('This account does not have staff management permission.');
            }
            $loginThrottle->record($accountID, true);
            $panelSecurity->signIn($account);
            $loggedAccountID = $panelSecurity->accountId();
            $loggedAccount = $panelSecurity->account();
            $audit($accountID, $accountID, 0, 'login', [], ['success' => true]);
            $message = 'Signed in as ' . (string) $account['userName'] . '.';
        } elseif ($action === 'logout') {
            if ($loggedAccountID > 0 && $securityTablesAvailable) {
                $audit($loggedAccountID, $loggedAccountID, 0, 'logout', [], []);
            }
            $panelSecurity->signOut();
            $loggedAccountID = 0;
            $loggedAccount = null;
            $message = 'Signed out.';
        } else {
            if ($loggedAccountID <= 0 || !$staff->has($loggedAccountID, 'staff.manage')) {
                throw new RuntimeException('Staff management access required.');
            }
            if (!$securityTablesAvailable) {
                throw new RuntimeException('Apply migration 0016 before changing staff roles.');
            }
            $confirmation->verifyForAction(
                $loggedAccountID,
                (string) ($_POST['current_password'] ?? '')
            );

            $actorIsOwner = $staff->isOwner($loggedAccountID);
            $actorIdentity = $staff->identity($loggedAccountID);
            $actorPriority = $actorIsOwner ? PHP_INT_MAX : (int) ($actorIdentity['priority'] ?? PHP_INT_MIN);

            if ($action === 'save_role') {
                $roleIDRaw = trim((string) ($_POST['role_id'] ?? ''));
                $roleID = $roleIDRaw !== '' ? (int) $roleIDRaw : null;
                if ($roleID !== null && $roleID <= 0) {
                    throw new RuntimeException('Invalid role ID.');
                }
                $before = $roleID === null
                    ? null
                    : ['role' => $repository->role($roleID), 'permissions' => $repository->permissionsForRole($roleID)];
                if ($roleID !== null && $before['role'] === null) {
                    throw new RuntimeException('Role was not found.');
                }
                if (!$actorIsOwner && $roleID !== null && (int) $before['role']['priority'] >= $actorPriority) {
                    throw new RuntimeException('You cannot edit a role at or above your own priority.');
                }
                $name = $field($_POST['name'] ?? '', 64);
                if ($name === '') {
                    throw new RuntimeException('Role name is required.');
                }
                $priority = max(-100000, min(100000, (int) ($_POST['priority'] ?? 0)));
                if (!$actorIsOwner && $priority >= $actorPriority) {
                    throw new RuntimeException('Role priority must be lower than your own.');
                }
                $permissions = isset($_POST['permissions']) && is_array($_POST['permissions'])
                    ? array_map('strval', $_POST['permissions'])
                    : [];
                if (!$actorIsOwner && in_array('staff.manage', $permissions, true)) {
                    throw new RuntimeException('Only an owner can grant staff.manage.');
                }
                $savedID = $repository->saveRole(
                    $roleID,
                    $name,
                    $priority,
                    max(0, min(2, (int) ($_POST['mod_badge_level'] ?? 0))),
                    $field($_POST['badge_text'] ?? '', 24),
                    $color($_POST['badge_color'] ?? ''),
                    $color($_POST['comment_color'] ?? ''),
                    $color($_POST['username_color'] ?? ''),
                    $permissions
                );
                $after = [
                    'role' => $repository->role($savedID),
                    'permissions' => $repository->permissionsForRole($savedID),
                ];
                $audit($loggedAccountID, 0, $savedID, $roleID === null ? 'role.create' : 'role.update', $before, $after);
                $message = 'Role saved. ID: ' . $savedID . '.';
            } elseif ($action === 'delete_role') {
                $roleID = (int) ($_POST['role_id'] ?? 0);
                $role = $repository->role($roleID);
                if ($roleID <= 0 || $role === null) {
                    throw new RuntimeException('Role was not found.');
                }
                if (!$actorIsOwner && (int) $role['priority'] >= $actorPriority) {
                    throw new RuntimeException('You cannot delete a role at or above your own priority.');
                }
                $before = ['role' => $role, 'permissions' => $repository->permissionsForRole($roleID)];
                if (!$repository->deleteRole($roleID)) {
                    throw new RuntimeException('Role was not found.');
                }
                $audit($loggedAccountID, 0, $roleID, 'role.delete', $before, null);
                $message = 'Role deleted. Staff assigned to it were unassigned.';
            } elseif ($action === 'assign_role') {
                $roleID = (int) ($_POST['role_id'] ?? 0);
                $role = $repository->role($roleID);
                $accountRef = $field($_POST['account'] ?? '', 64);
                if ($roleID <= 0 || $role === null || $accountRef === '') {
                    throw new RuntimeException('Account and role are required.');
                }
                if (!$actorIsOwner && (int) $role['priority'] >= $actorPriority) {
                    throw new RuntimeException('You cannot assign a role at or above your own priority.');
                }
                if (!$actorIsOwner && in_array('staff.manage', $repository->permissionsForRole($roleID), true)) {
                    throw new RuntimeException('Only an owner can assign staff.manage.');
                }
                $account = ctype_digit($accountRef)
                    ? $app->accountRepository()->findById((int) $accountRef)
                    : $app->accountRepository()->findByUsername($accountRef);
                if ($account === null) {
                    throw new RuntimeException('Account was not found.');
                }
                $targetAccountID = (int) $account['accountID'];
                if ($staff->isOwner($targetAccountID)) {
                    throw new RuntimeException('Owner accounts cannot be changed here.');
                }
                $before = $repository->roleForAccount($targetAccountID);
                if (!$actorIsOwner && $before !== null && (int) $before['priority'] >= $actorPriority) {
                    throw new RuntimeException('You cannot change staff at or above your own priority.');
                }
                $repository->assignRole($targetAccountID, $roleID, $loggedAccountID);
                $audit(
                    $loggedAccountID,
                    $targetAccountID,
                    $roleID,
                    'assignment.set',
                    $before,
                    $repository->roleForAccount($targetAccountID)
                );
                $message = 'Assigned ' . (string) $account['userName'] . ' to the selected role.';
            } elseif ($action === 'remove_assignment') {
                $targetAccountID = (int) ($_POST['account_id'] ?? 0);
                if ($targetAccountID <= 0 || $staff->isOwner($targetAccountID)) {
                    throw new RuntimeException('Invalid account.');
                }
                $before = $repository->roleForAccount($targetAccountID);
                if ($before === null) {
                    throw new RuntimeException('Staff assignment was not found.');
                }
                if (!$actorIsOwner && (int) $before['priority'] >= $actorPriority) {
                    throw new RuntimeException('You cannot remove staff at or above your own priority.');
                }
                $repository->removeAssignment($targetAccountID);
                $audit(
                    $loggedAccountID,
                    $targetAccountID,
                    (int) $before['roleID'],
                    'assignment.remove',
                    $before,
                    null
                );
                $message = 'Staff assignment removed.';
            } else {
                throw new RuntimeException('Unknown staff action.');
            }
        }
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

if ($loggedAccountID > 0 && !$staff->has($loggedAccountID, 'staff.manage')) {
    $panelSecurity->signOut();
    $loggedAccountID = 0;
    $loggedAccount = null;
}
$actorIsOwner = $loggedAccountID > 0 && $staff->isOwner($loggedAccountID);
$actorIdentity = $loggedAccountID > 0 ? $staff->identity($loggedAccountID) : null;
$actorPriority = $actorIsOwner ? PHP_INT_MAX : (int) ($actorIdentity['priority'] ?? PHP_INT_MIN);
$requireSensitivePassword = $loggedAccountID <= 0 || $confirmation->requiresPassword($loggedAccountID);
$csrfValue = $panelSecurity->csrfToken();
$allRoles = $loggedAccountID > 0 ? $repository->roles() : [];
$roles = array_values(array_filter(
    $allRoles,
    static fn(array $role): bool => $actorIsOwner || (int) $role['priority'] < $actorPriority
));
$permissionRows = $loggedAccountID > 0 ? $repository->permissions() : [];
$assignments = $loggedAccountID > 0 ? $repository->assignments() : [];
$rolePermissions = [];
foreach ($allRoles as $role) {
    $rolePermissions[(int) $role['roleID']] = array_flip($repository->permissionsForRole((int) $role['roleID']));
}
$sessionDescription = $panelSecurity->sessionDescription();
$nonce = $panelSecurity->nonce();
$panelSecurity->sendHeaders();
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Night Core staff</title>
<style nonce="<?= $escape($nonce) ?>">:root{color-scheme:dark}*{box-sizing:border-box}body{margin:0;background:#090b10;color:#eef2ff;font:15px/1.45 system-ui,-apple-system,Segoe UI,sans-serif}main{max-width:1200px;margin:0 auto;padding:28px 18px 60px}header{display:flex;justify-content:space-between;align-items:center;gap:16px;margin-bottom:20px}.brand h1{margin:0;font-size:28px}.brand p{margin:4px 0 0;color:#98a2b3}.card{background:#11151d;border:1px solid #252b38;border-radius:16px;padding:20px;margin-bottom:16px}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.grid .card{margin:0}.wide{grid-column:1/-1}h2,h3{margin:0 0 14px}label{display:block;margin:10px 0 5px;font-weight:650;color:#cbd5e1}input,select{width:100%;padding:10px 12px;border-radius:9px;border:1px solid #384152;background:#090c12;color:#fff}button{border:0;border-radius:9px;padding:10px 14px;background:#6d5dfc;color:#fff;font-weight:750;cursor:pointer;margin-top:12px}.danger{background:#9f3341}.ghost{background:#283142}.notice{padding:12px 14px;border-radius:11px;margin-bottom:16px}.ok{background:#143620;border:1px solid #245a35}.err{background:#421d22;border:1px solid #71303a}.muted,small{color:#98a2b3}.permissions{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:7px 16px;margin-top:10px}.permissions label{display:flex;gap:8px;align-items:flex-start;margin:0;font-weight:500}.permissions input{width:auto;margin-top:4px}.role-title{display:flex;justify-content:space-between;gap:12px;align-items:center}table{width:100%;border-collapse:collapse}th,td{text-align:left;padding:10px 8px;border-bottom:1px solid #252b38}th{font-size:12px;text-transform:uppercase;color:#98a2b3}.reauth{margin-top:14px;padding-top:12px;border-top:1px solid #252b38}.security-state{padding:10px 12px;border:1px solid #303849;border-radius:10px;background:#0b0e14;margin-bottom:16px}@media(max-width:780px){.grid,.permissions{grid-template-columns:1fr}.wide{grid-column:auto}header{align-items:flex-start;flex-direction:column}.table-wrap{overflow-x:auto}}</style></head><body><main>
<header><div class="brand"><h1>Night Core staff</h1><p>Roles, permissions and staff presentation for this GDPS.</p></div><?php if ($loggedAccount): ?><form method="post"><input type="hidden" name="csrf" value="<?= $escape($csrfValue) ?>"><input type="hidden" name="action" value="logout"><button class="ghost" type="submit">Sign out</button></form><?php endif; ?></header>
<?php if ($message !== ''): ?><div class="notice ok"><?= $escape($message) ?></div><?php endif; ?><?php if ($error !== ''): ?><div class="notice err"><?= $escape($error) ?></div><?php endif; ?>
<?php if (!$loggedAccount): ?>
<section class="card" style="max-width:520px;margin-inline:auto"><h2>Staff management login</h2><p class="muted">Repeated failed sign-in attempts are temporarily blocked.</p><form method="post"><input type="hidden" name="csrf" value="<?= $escape($csrfValue) ?>"><input type="hidden" name="action" value="login"><label>Username</label><input name="username" autocomplete="username" required><label>Password</label><input type="password" name="password" autocomplete="current-password" required><button type="submit">Sign in</button></form></section>
<?php else: ?>
<p class="muted">Signed in as <strong><?= $escape((string) $loggedAccount['userName']) ?></strong> (account <?= (int) $loggedAccount['accountID'] ?>). <?= $escape($sessionDescription) ?></p>
<div class="security-state"><strong>Per-action password confirmation: <?= $requireSensitivePassword ? 'enabled' : 'disabled' ?></strong><br><small>Change this setting in <code>/dashboard.php</code>. Staff login always requires a password.</small></div>
<div class="grid">
<section class="card"><h2>Create role</h2><form method="post"><input type="hidden" name="csrf" value="<?= $escape($csrfValue) ?>"><input type="hidden" name="action" value="save_role"><label>Name</label><input name="name" maxlength="64" required><label>Priority</label><input type="number" name="priority" value="10"><label>Geometry Dash badge</label><select name="mod_badge_level"><option value="0">None</option><option value="1">Moderator</option><option value="2">Elder / administrator</option></select><label>Badge text</label><input name="badge_text" maxlength="24" placeholder="MOD"><label>Badge color</label><input name="badge_color" placeholder="#7c3aed"><label>Comment color</label><input name="comment_color" placeholder="#a78bfa"><label>Username color</label><input name="username_color" placeholder="#c4b5fd"><h3 style="margin-top:18px">Permissions</h3><div class="permissions"><?php foreach ($permissionRows as $permission): $key=(string)$permission['permissionKey']; ?><label><input type="checkbox" name="permissions[]" value="<?= $escape($key) ?>"<?= (!$actorIsOwner && $key === 'staff.manage') ? ' disabled' : '' ?>><span><code><?= $escape($key) ?></code><br><small><?= $escape((string)$permission['description']) ?></small></span></label><?php endforeach; ?></div><?php if ($requireSensitivePassword): ?><div class="reauth"><label>Confirm current password</label><input type="password" name="current_password" autocomplete="current-password" required></div><?php endif; ?><button type="submit">Create role</button></form></section>
<section class="card"><h2>Assign staff</h2><form method="post"><input type="hidden" name="csrf" value="<?= $escape($csrfValue) ?>"><input type="hidden" name="action" value="assign_role"><label>Username or account ID</label><input name="account" required><label>Role</label><select name="role_id" required><option value="">Select role</option><?php foreach ($roles as $role): ?><option value="<?= (int)$role['roleID'] ?>"><?= $escape((string)$role['name']) ?> (<?= (int)$role['priority'] ?>)</option><?php endforeach; ?></select><?php if ($requireSensitivePassword): ?><label>Confirm current password</label><input type="password" name="current_password" autocomplete="current-password" required><?php endif; ?><button type="submit">Assign role</button></form><p class="muted">Owner accounts cannot be changed here. Only owners can grant staff.manage.</p></section>
<?php foreach ($roles as $role): $roleID=(int)$role['roleID']; ?>
<section class="card wide"><div class="role-title"><h2><?= $escape((string)$role['name']) ?></h2><span class="muted">Role #<?= $roleID ?> · priority <?= (int)$role['priority'] ?></span></div><form method="post"><input type="hidden" name="csrf" value="<?= $escape($csrfValue) ?>"><input type="hidden" name="action" value="save_role"><input type="hidden" name="role_id" value="<?= $roleID ?>"><div class="grid"><div><label>Name</label><input name="name" maxlength="64" value="<?= $escape((string)$role['name']) ?>" required><label>Priority</label><input type="number" name="priority" value="<?= (int)$role['priority'] ?>"><label>Geometry Dash badge</label><select name="mod_badge_level"><option value="0"<?= (int)$role['modBadgeLevel']===0?' selected':'' ?>>None</option><option value="1"<?= (int)$role['modBadgeLevel']===1?' selected':'' ?>>Moderator</option><option value="2"<?= (int)$role['modBadgeLevel']===2?' selected':'' ?>>Elder / administrator</option></select></div><div><label>Badge text</label><input name="badge_text" maxlength="24" value="<?= $escape((string)$role['badgeText']) ?>"><label>Badge color</label><input name="badge_color" value="<?= $escape((string)$role['badgeColor']) ?>"><label>Comment color</label><input name="comment_color" value="<?= $escape((string)$role['commentColor']) ?>"><label>Username color</label><input name="username_color" value="<?= $escape((string)$role['usernameColor']) ?>"></div></div><h3 style="margin-top:18px">Permissions</h3><div class="permissions"><?php foreach ($permissionRows as $permission): $key=(string)$permission['permissionKey']; ?><label><input type="checkbox" name="permissions[]" value="<?= $escape($key) ?>"<?= isset($rolePermissions[$roleID][$key])?' checked':'' ?><?= (!$actorIsOwner && $key==='staff.manage')?' disabled':'' ?>><span><code><?= $escape($key) ?></code><br><small><?= $escape((string)$permission['description']) ?></small></span></label><?php endforeach; ?></div><?php if ($requireSensitivePassword): ?><div class="reauth"><label>Confirm current password</label><input type="password" name="current_password" autocomplete="current-password" required></div><?php endif; ?><button type="submit">Save role</button></form><form method="post" data-confirm="Delete this role and unassign its members?"><input type="hidden" name="csrf" value="<?= $escape($csrfValue) ?>"><input type="hidden" name="action" value="delete_role"><input type="hidden" name="role_id" value="<?= $roleID ?>"><?php if ($requireSensitivePassword): ?><label>Confirm current password</label><input type="password" name="current_password" autocomplete="current-password" required><?php endif; ?><button class="danger" type="submit">Delete role</button></form></section>
<?php endforeach; ?>
<section class="card wide"><h2>Current staff</h2><div class="table-wrap"><table><thead><tr><th>Account</th><th>Role</th><th>Priority</th><th>Badge</th><th></th></tr></thead><tbody><?php foreach ($assignments as $assignment): $canManage=$actorIsOwner || (int)$assignment['priority'] < $actorPriority; ?><tr><td><strong><?= $escape((string)($assignment['userName'] ?? '')) ?></strong><br><small>#<?= (int)$assignment['accountID'] ?></small></td><td><?= $escape((string)$assignment['roleName']) ?></td><td><?= (int)$assignment['priority'] ?></td><td><?= (int)$assignment['modBadgeLevel'] ?> / <?= $escape((string)$assignment['badgeText']) ?></td><td><?php if ($canManage): ?><form method="post"><input type="hidden" name="csrf" value="<?= $escape($csrfValue) ?>"><input type="hidden" name="action" value="remove_assignment"><input type="hidden" name="account_id" value="<?= (int)$assignment['accountID'] ?>"><?php if ($requireSensitivePassword): ?><input type="password" name="current_password" autocomplete="current-password" placeholder="Current password" required><?php endif; ?><button class="danger" type="submit">Remove</button></form><?php else: ?><span class="muted">Protected</span><?php endif; ?></td></tr><?php endforeach; ?><?php if ($assignments === []): ?><tr><td colspan="5" class="muted">No staff assignments yet.</td></tr><?php endif; ?></tbody></table></div></section>
</div><?php endif; ?></main>
<script nonce="<?= $escape($nonce) ?>">document.querySelectorAll('form[data-confirm]').forEach((form)=>form.addEventListener('submit',(event)=>{if(!confirm(form.dataset.confirm||'Continue?'))event.preventDefault();}));</script>
</body></html>
