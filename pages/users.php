<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/App.php';
require_once dirname(__DIR__) . '/includes/layout.php';
App::boot();
$user = App::requirePermission('manage_users');
$isGlobalAdmin = AuthManager::isAdmin($user);

function users_normalize_color(?string $hex): string
{
    $hex = trim((string)$hex);
    if (preg_match('/^#?[0-9A-Fa-f]{6}$/', $hex)) {
        return '#' . ltrim($hex, '#');
    }
    return '#3b82f6';
}

/**
 * Validate a local password + confirmation. Empty pair is allowed when $required is false
 * (edit user: leave both blank to keep the current password).
 */
function users_local_password_from_post(bool $required): ?string
{
    $password = (string)($_POST['password'] ?? '');
    $confirm = (string)($_POST['password_confirm'] ?? '');
    if ($password === '' && $confirm === '') {
        if ($required) {
            throw new RuntimeException('Password and confirmation are required for local accounts.');
        }
        return null;
    }
    if ($password !== $confirm) {
        throw new RuntimeException('Password and confirmation do not match.');
    }
    if (strlen($password) < 8) {
        throw new RuntimeException('Password must be at least 8 characters.');
    }
    return $password;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && App::verifyCsrf($_POST['_csrf'] ?? '')) {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'create') {
            $username = trim($_POST['username'] ?? '');
            if ($username === '') {
                throw new RuntimeException('Username required.');
            }
            $hash = null;
            if (($_POST['auth_source'] ?? 'local') === 'local') {
                $plain = users_local_password_from_post(true);
                $hash = password_hash((string)$plain, PASSWORD_DEFAULT);
            }
            $email = trim($_POST['email'] ?? '');
            $authSource = $_POST['auth_source'] ?? 'local';
            $newId = Database::insert('users', [
                'username' => $username,
                'email' => $email,
                'display_name' => trim($_POST['display_name'] ?? '') !== '' ? trim($_POST['display_name']) : null,
                'password_hash' => $hash,
                'auth_source' => $authSource,
                'role_id' => (int)$_POST['role_id'],
                'department_id' => $_POST['department_id'] !== '' ? (int)$_POST['department_id'] : null,
                'is_active' => 1,
            ]);
            $flash = 'User created.';
            if (!empty($_POST['send_welcome']) && class_exists('ProductMailService')) {
                $welcome = ProductMailService::sendWelcome([
                    'user_id' => $newId,
                    'username' => $username,
                    'email' => $email,
                    'display_name' => trim($_POST['display_name'] ?? ''),
                    'auth_source' => $authSource,
                ], true);
                if (!empty($welcome['ok'])) {
                    $flash .= ' Welcome email sent.';
                } else {
                    $flash .= ' Welcome email not sent: ' . ($welcome['message'] ?? 'unknown error');
                }
            }
            App::flash('success', $flash);
        }

        if ($action === 'create_api_service') {
            if (!$isGlobalAdmin) {
                throw new RuntimeException('Only Global Admin can create API service accounts.');
            }
            if (!class_exists('ApiTokenService')) {
                throw new RuntimeException('ApiTokenService is not deployed.');
            }
            $username = ApiTokenService::normalizeUsername((string)($_POST['service_name'] ?? ''));
            $exists = Database::fetchOne('SELECT user_id FROM users WHERE username = ?', [$username]);
            if ($exists) {
                throw new RuntimeException('That service account already exists: ' . $username);
            }
            $roleId = (int)($_POST['role_id'] ?? 0);
            $role = Database::fetchOne('SELECT * FROM roles WHERE role_id = ?', [$roleId]);
            if (!$role) {
                throw new RuntimeException('Select a role for the service account.');
            }
            $roleName = (string)($role['name'] ?? '');
            $perms = json_decode((string)($role['permissions'] ?? '[]'), true) ?: [];
            if (in_array($roleName, ['Global Admin', 'Administrator'], true) || in_array('*', $perms, true)) {
                throw new RuntimeException('Do not give API service accounts Global Admin. Pick Viewer (read) or a narrower role.');
            }
            $display = trim((string)($_POST['display_name'] ?? ''));
            if ($display === '') {
                $display = $username;
            }
            $email = trim((string)($_POST['email'] ?? ''));
            if ($email === '') {
                $email = $username . '@api.local';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Email is not valid.');
            }
            $newId = (int)Database::insert('users', [
                'username' => $username,
                'email' => $email,
                'display_name' => $display,
                'password_hash' => null,
                'auth_source' => 'api',
                'role_id' => $roleId,
                'department_id' => !empty($_POST['department_id']) ? (int)$_POST['department_id'] : null,
                'is_active' => 1,
                'is_service_account' => 1,
                'can_login' => 0,
                'must_change_password' => 0,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            AuditService::log((int)$user['user_id'], $user['username'], 'api_service_create', 'user', $newId, [
                'username' => $username,
                'role' => $roleName,
            ]);
            $flash = 'API service account ' . $username . ' created. It cannot sign in to the website.';
            $wantToken = !isset($_POST['create_token']) || !empty($_POST['create_token']);
            if ($wantToken) {
                $scope = strtolower(trim((string)($_POST['token_scope'] ?? 'read')));
                if ($scope !== 'write') {
                    $scope = 'read';
                }
                $expDays = (int)($_POST['token_expires_days'] ?? 0);
                $expires = $expDays > 0 ? new DateTimeImmutable('+' . $expDays . ' days') : null;
                $tokName = trim((string)($_POST['token_name'] ?? 'Initial')) ?: 'Initial';
                $tok = ApiTokenService::createToken($newId, $tokName, $scope, (int)$user['user_id'], $expires);
                $_SESSION['_api_token_once'] = [
                    'username' => $username,
                    'token' => $tok['token'],
                    'prefix' => $tok['prefix'],
                    'scopes' => $scope,
                    'name' => $tokName,
                ];
                $flash .= ' Copy the API token now — it will not be shown again.';
            }
            App::flash('success', $flash);
            App::redirect('pages/users.php?edit_user=' . $newId);
        }

        if ($action === 'create_api_token') {
            if (!$isGlobalAdmin) {
                throw new RuntimeException('Only Global Admin can create API tokens.');
            }
            $uid = (int)($_POST['user_id'] ?? 0);
            $svc = Database::fetchOne('SELECT * FROM users WHERE user_id = ?', [$uid]);
            if (!$svc || !ApiTokenService::isServiceAccount($svc)) {
                throw new RuntimeException('Tokens can only be created for API service accounts.');
            }
            $scope = strtolower(trim((string)($_POST['token_scope'] ?? 'read')));
            if ($scope !== 'write') {
                $scope = 'read';
            }
            $expDays = (int)($_POST['token_expires_days'] ?? 0);
            $expires = $expDays > 0 ? new DateTimeImmutable('+' . $expDays . ' days') : null;
            $tokName = trim((string)($_POST['token_name'] ?? 'Token')) ?: 'Token';
            $tok = ApiTokenService::createToken($uid, $tokName, $scope, (int)$user['user_id'], $expires);
            AuditService::log((int)$user['user_id'], $user['username'], 'api_token_create', 'user', $uid, [
                'token_prefix' => $tok['prefix'],
                'scopes' => $scope,
            ]);
            $_SESSION['_api_token_once'] = [
                'username' => (string)$svc['username'],
                'token' => $tok['token'],
                'prefix' => $tok['prefix'],
                'scopes' => $scope,
                'name' => $tokName,
            ];
            App::flash('success', 'Token created. Copy it now — it will not be shown again.');
            App::redirect('pages/users.php?edit_user=' . $uid);
        }

        if ($action === 'revoke_api_token') {
            if (!$isGlobalAdmin) {
                throw new RuntimeException('Only Global Admin can revoke API tokens.');
            }
            $uid = (int)($_POST['user_id'] ?? 0);
            $tid = (int)($_POST['token_id'] ?? 0);
            ApiTokenService::revokeToken($tid, $uid);
            AuditService::log((int)$user['user_id'], $user['username'], 'api_token_revoke', 'user', $uid, [
                'token_id' => $tid,
            ]);
            App::flash('success', 'API token revoked.');
            App::redirect('pages/users.php?edit_user=' . $uid);
        }

        if ($action === 'update') {
            $uid = (int)$_POST['user_id'];
            if ($uid <= 0) {
                throw new RuntimeException('Select a user to update.');
            }
            $existing = Database::fetchOne('SELECT * FROM users WHERE user_id = ?', [$uid]);
            if (!$existing) {
                throw new RuntimeException('User not found.');
            }
            $isSvc = class_exists('ApiTokenService') && ApiTokenService::isServiceAccount($existing);
            $fields = [
                'email' => trim($_POST['email'] ?? ''),
                'display_name' => trim($_POST['display_name'] ?? '') !== '' ? trim($_POST['display_name']) : null,
                'role_id' => (int)$_POST['role_id'],
                'department_id' => $_POST['department_id'] !== '' ? (int)$_POST['department_id'] : null,
                'is_active' => !empty($_POST['is_active']) ? 1 : 0,
                'auth_source' => $isSvc ? 'api' : ($_POST['auth_source'] ?? 'local'),
                'updated_at' => date('Y-m-d H:i:s'),
            ];
            if ($isSvc) {
                $role = Database::fetchOne('SELECT name, permissions FROM roles WHERE role_id = ?', [(int)$fields['role_id']]);
                $perms = $role ? (json_decode((string)($role['permissions'] ?? '[]'), true) ?: []) : [];
                if ($role && (in_array((string)$role['name'], ['Global Admin', 'Administrator'], true) || in_array('*', $perms, true))) {
                    throw new RuntimeException('Do not give API service accounts Global Admin.');
                }
                $fields['is_service_account'] = 1;
                $fields['can_login'] = 0;
            } else {
                $plain = users_local_password_from_post(false);
                if ($plain !== null) {
                    $fields['password_hash'] = password_hash($plain, PASSWORD_DEFAULT);
                }
            }
            Database::update('users', $fields, 'user_id = :id', [':id' => $uid]);
            App::flash('success', 'User updated.');
        }

        if ($action === 'add_department') {
            $name = trim($_POST['name'] ?? '');
            if ($name === '') {
                throw new RuntimeException('Department name is required.');
            }
            Database::insert('departments', [
                'name' => $name,
                'code' => trim($_POST['code'] ?? '') !== '' ? trim($_POST['code']) : null,
                'manager_name' => trim($_POST['manager_name'] ?? '') !== '' ? trim($_POST['manager_name']) : null,
                'contact_email' => trim($_POST['contact_email'] ?? '') !== '' ? trim($_POST['contact_email']) : null,
                'contact_phone' => trim($_POST['contact_phone'] ?? '') !== '' ? trim($_POST['contact_phone']) : null,
                'color_hex' => users_normalize_color($_POST['color_hex'] ?? null),
                'notes' => trim($_POST['notes'] ?? '') !== '' ? trim($_POST['notes']) : null,
                'is_active' => 1,
            ]);
            App::flash('success', 'Department added.');
        }

        if ($action === 'update_department') {
            $did = (int)($_POST['department_id'] ?? 0);
            if ($did <= 0) {
                throw new RuntimeException('Department required.');
            }
            $name = trim($_POST['name'] ?? '');
            if ($name === '') {
                throw new RuntimeException('Department name is required.');
            }
            Database::update('departments', [
                'name' => $name,
                'code' => trim($_POST['code'] ?? '') !== '' ? trim($_POST['code']) : null,
                'manager_name' => trim($_POST['manager_name'] ?? '') !== '' ? trim($_POST['manager_name']) : null,
                'contact_email' => trim($_POST['contact_email'] ?? '') !== '' ? trim($_POST['contact_email']) : null,
                'contact_phone' => trim($_POST['contact_phone'] ?? '') !== '' ? trim($_POST['contact_phone']) : null,
                'color_hex' => users_normalize_color($_POST['color_hex'] ?? null),
                'notes' => trim($_POST['notes'] ?? '') !== '' ? trim($_POST['notes']) : null,
                'is_active' => !empty($_POST['is_active']) ? 1 : 0,
            ], 'department_id = :id', [':id' => $did]);
            App::flash('success', 'Department updated.');
        }

        if ($action === 'add_group_map') {
            $did = (int)($_POST['department_id'] ?? 0);
            $source = strtolower(trim($_POST['auth_source'] ?? ''));
            $gid = trim($_POST['group_id'] ?? '');
            if ($did <= 0 || $gid === '') {
                throw new RuntimeException('Department and group id are required.');
            }
            if (!in_array($source, ['ldaps', 'entra'], true)) {
                throw new RuntimeException('Auth source must be ldaps or entra.');
            }
            Database::insert('department_group_maps', [
                'department_id' => $did,
                'auth_source' => $source,
                'group_id' => $gid,
                'group_name' => trim($_POST['group_name'] ?? '') !== '' ? trim($_POST['group_name']) : null,
                'notes' => trim($_POST['notes'] ?? '') !== '' ? trim($_POST['notes']) : null,
                'is_active' => 1,
            ]);
            App::flash('success', 'Security group mapping added (applied when LDAPS/Entra login is enabled).');
        }

        if ($action === 'delete_group_map') {
            $mid = (int)($_POST['map_id'] ?? 0);
            if ($mid > 0) {
                Database::delete('department_group_maps', 'map_id = ?', [$mid]);
                App::flash('success', 'Group mapping removed.');
            }
        }

        if ($action === 'add_role_group_map') {
            $rid = (int)($_POST['role_id'] ?? 0);
            $source = strtolower(trim($_POST['auth_source'] ?? ''));
            $gid = trim($_POST['group_id'] ?? '');
            if ($rid <= 0 || $gid === '') {
                throw new RuntimeException('Role and group id are required.');
            }
            if (!in_array($source, ['ldaps', 'entra'], true)) {
                throw new RuntimeException('Auth source must be ldaps or entra.');
            }
            Database::insert('role_group_maps', [
                'role_id' => $rid,
                'auth_source' => $source,
                'group_id' => $gid,
                'group_name' => trim($_POST['group_name'] ?? '') !== '' ? trim($_POST['group_name']) : null,
                'notes' => trim($_POST['notes'] ?? '') !== '' ? trim($_POST['notes']) : null,
                'is_active' => 1,
            ]);
            App::flash('success', 'Role ← security group mapping added (applied at LDAPS/Entra login).');
        }
        if ($action === 'delete_role_group_map') {
            $mid = (int)($_POST['map_id'] ?? 0);
            if ($mid > 0) {
                Database::delete('role_group_maps', 'map_id = ?', [$mid]);
                App::flash('success', 'Role group mapping removed.');
            }
        }
    } catch (Throwable $e) {
        App::flash('error', $e->getMessage());
    }
    App::redirect('pages/users.php');
}

$users = Database::fetchAll(
    'SELECT u.*, r.name AS role_name, d.name AS department_name, d.color_hex AS department_color
     FROM users u
     INNER JOIN roles r ON r.role_id = u.role_id
     LEFT JOIN departments d ON d.department_id = u.department_id
     ORDER BY u.username'
);
$onlineMap = AuthManager::onlineUserIdMap();
$roles = Database::fetchAll('SELECT * FROM roles ORDER BY name');
// Prefer the four platform roles first in UI
$roleOrder = ['Global Admin' => 1, 'Administrator' => 2, 'Data Center Admin' => 3, 'Department Admin' => 4, 'Viewer' => 5];
usort($roles, static function ($a, $b) use ($roleOrder) {
    $oa = $roleOrder[$a['name'] ?? ''] ?? 50;
    $ob = $roleOrder[$b['name'] ?? ''] ?? 50;
    if ($oa !== $ob) {
        return $oa <=> $ob;
    }
    return strcasecmp((string)$a['name'], (string)$b['name']);
});
$departments = Database::fetchAll(
    'SELECT d.*,
            (SELECT COUNT(*) FROM users u WHERE u.department_id = d.department_id AND u.is_active = 1) AS user_count,
            (SELECT COUNT(*) FROM devices dev WHERE dev.department_id = d.department_id AND dev.is_active = 1) AS device_count
     FROM departments d
     ORDER BY d.name'
);
$groupMaps = [];
try {
    $groupMaps = Database::fetchAll(
        'SELECT m.*, d.name AS department_name, d.color_hex
         FROM department_group_maps m
         INNER JOIN departments d ON d.department_id = m.department_id
         ORDER BY d.name, m.auth_source, m.group_name'
    );
} catch (Throwable $e) {
    $groupMaps = [];
}
$roleGroupMaps = [];
try {
    $roleGroupMaps = Database::fetchAll(
        'SELECT m.*, r.name AS role_name
         FROM role_group_maps m
         INNER JOIN roles r ON r.role_id = m.role_id
         ORDER BY r.name, m.auth_source, m.group_name'
    );
} catch (Throwable $e) {
    $roleGroupMaps = [];
}

$editUserId = (int)($_GET['edit_user'] ?? 0);
$editUser = null;
foreach ($users as $u) {
    if ((int)$u['user_id'] === $editUserId) {
        $editUser = $u;
        break;
    }
}
$editUserIsSvc = $editUser && class_exists('ApiTokenService') && ApiTokenService::isServiceAccount($editUser);
$editUserTokens = ($editUserIsSvc && class_exists('ApiTokenService'))
    ? ApiTokenService::listForUser((int)$editUser['user_id'])
    : [];
$apiTokenOnce = null;
if (!empty($_SESSION['_api_token_once']) && is_array($_SESSION['_api_token_once'])) {
    $apiTokenOnce = $_SESSION['_api_token_once'];
    unset($_SESSION['_api_token_once']);
}

$editDeptId = (int)($_GET['edit_dept'] ?? 0);
$editDept = null;
foreach ($departments as $d) {
    if ((int)$d['department_id'] === $editDeptId) {
        $editDept = $d;
        break;
    }
}

layout_header('Users & Departments', $user, 'users');
?>
<?php if ($apiTokenOnce): ?>
<div class="alert alert-warning" style="margin-bottom:1rem" id="api-token-once">
    <strong>Copy this API token now.</strong> It will not be shown again.
    <div class="text-muted" style="font-size:.85rem;margin:.35rem 0">
        Account <code><?= App::e((string)$apiTokenOnce['username']) ?></code>
        · <?= App::e((string)($apiTokenOnce['name'] ?? 'Token')) ?>
        · scope <?= App::e((string)($apiTokenOnce['scopes'] ?? 'read')) ?>
    </div>
    <input class="form-control" readonly id="api_token_once_value"
           value="<?= App::e((string)$apiTokenOnce['token']) ?>"
           onclick="this.select()" style="font-family:ui-monospace,Consolas,monospace;font-size:.85rem">
    <p class="text-muted" style="font-size:.8rem;margin:.5rem 0 0">
        Header: <code>Authorization: Bearer <?= App::e((string)$apiTokenOnce['token']) ?></code>
        · Probe: <code><?= App::e(App::url('api/v1.php')) ?></code>
    </p>
</div>
<?php endif; ?>

<div class="metrics">
    <div class="metric-card"><div class="label">Users</div><div class="value"><?= count($users) ?></div></div>
    <div class="metric-card"><div class="label">Departments</div><div class="value"><?= count($departments) ?></div></div>
    <div class="metric-card accent"><div class="label">Active users</div>
        <div class="value"><?= count(array_filter($users, static fn($u) => !empty($u['is_active']))) ?></div>
    </div>
    <div class="metric-card"><div class="label">Group maps</div><div class="value"><?= count($groupMaps) ?></div></div>
</div>

<p class="text-muted mb-2" style="font-size:.9rem">
    <strong>Roles:</strong> Viewer (read-only) · Department Admin (edit own department’s devices) ·
    Data Center Admin (infrastructure + all devices + power) · Global Admin (settings &amp; users).
    Department colors outline devices in the rack view. AD/Entra group maps assign roles/departments at login later.
</p>

<div class="card mb-2">
    <div class="card-header"><h2>Platform roles</h2></div>
    <div class="card-body flush">
        <table class="data">
            <thead><tr><th>Role</th><th>Description</th><th>Permissions</th></tr></thead>
            <tbody>
            <?php foreach ($roles as $r):
                if (!in_array($r['name'], ['Viewer', 'Department Admin', 'Data Center Admin', 'Global Admin', 'Administrator'], true)) {
                    continue;
                }
                $perms = json_decode($r['permissions'] ?? '[]', true) ?: [];
                $permLabel = in_array('*', $perms, true) ? 'Full (*)' : (count($perms) . ' keys');
                ?>
                <tr>
                    <td><strong><?= App::e($r['name']) ?></strong></td>
                    <td style="font-size:.88rem"><?= App::e($r['description'] ?? '') ?></td>
                    <td><span class="badge badge-info"><?= App::e($permLabel) ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="users-admin-stack">
    <!-- Departments -->
    <div class="card">
        <div class="card-header flex-between">
            <h2>Departments</h2>
            <button type="button" class="btn btn-sm btn-primary" data-open-modal="modal-dept">Add department</button>
        </div>
        <div class="card-body flush">
            <table class="data table-fit">
                <thead>
                <tr>
                    <th class="col-swatch"></th><th>Name</th><th>Code</th><th>Users</th><th>Dev</th><th class="col-actions"></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($departments as $d):
                    $color = users_normalize_color($d['color_hex'] ?? null);
                    ?>
                    <tr>
                        <td class="col-swatch">
                            <span class="dept-swatch" style="background:<?= App::e($color) ?>" title="<?= App::e($color) ?>"></span>
                        </td>
                        <td>
                            <strong><?= App::e($d['name']) ?></strong>
                            <?php if (empty($d['is_active'])): ?>
                                <span class="badge badge-danger" style="margin-left:.25rem">Off</span>
                            <?php endif; ?>
                            <?php if (!empty($d['manager_name'])): ?>
                                <div class="text-muted" style="font-size:.75rem"><?= App::e($d['manager_name']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?= App::e($d['code'] ?? '—') ?></td>
                        <td><?= (int)($d['user_count'] ?? 0) ?></td>
                        <td><?= (int)($d['device_count'] ?? 0) ?></td>
                        <td class="actions col-actions">
                            <a class="btn btn-sm btn-secondary" href="?edit_dept=<?= (int)$d['department_id'] ?>">Edit</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$departments): ?>
                    <tr><td colspan="6" class="text-muted">No departments yet. Use <strong>Add department</strong> to create one.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Users -->
    <div class="card users-admin-card">
        <div class="card-header flex-between">
            <h2>Users</h2>
            <div class="flex gap-1" style="flex-wrap:wrap">
                <?php if ($isGlobalAdmin): ?>
                    <button type="button" class="btn btn-sm btn-secondary" data-open-modal="modal-api-service">Create API-Service Account</button>
                <?php endif; ?>
                <button type="button" class="btn btn-sm btn-primary" data-open-modal="modal-user">Add user</button>
            </div>
        </div>
        <div class="card-body flush">
            <table class="data table-fit users-table">
                <thead>
                <tr>
                    <th>User</th>
                    <th>Role</th>
                    <th>Department</th>
                    <th>Status</th>
                    <th class="col-actions"></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($users as $u): ?>
                    <tr>
                        <td>
                            <strong class="user-cell-name"><?= App::e($u['username']) ?></strong>
                            <?php if (class_exists('ApiTokenService') && ApiTokenService::isServiceAccount($u)): ?>
                                <span class="badge badge-info" title="Cannot sign in to the website">API</span>
                            <?php endif; ?>
                            <?php if (!empty($onlineMap[(int)$u['user_id']])): ?>
                                <span class="badge badge-success user-online-badge" title="Signed in within the last ~2 minutes">Online</span>
                            <?php endif; ?>
                            <?php if (!empty($u['display_name'])): ?>
                                <div class="text-muted user-cell-sub"><?= App::e($u['display_name']) ?></div>
                            <?php endif; ?>
                            <div class="text-muted user-cell-sub"><?= App::e($u['email']) ?></div>
                        </td>
                        <td><span class="badge badge-info"><?= App::e($u['role_name']) ?></span></td>
                        <td>
                            <?php if (!empty($u['department_name'])): ?>
                                <span class="dept-chip">
                                    <span class="dept-swatch sm" style="background:<?= App::e(users_normalize_color($u['department_color'] ?? null)) ?>"></span>
                                    <span class="dept-chip-text"><?= App::e($u['department_name']) ?></span>
                                </span>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="user-status-cell">
                            <span class="badge"><?= App::e($u['auth_source']) ?></span>
                            <?= !empty($u['is_active'])
                                ? '<span class="badge badge-success">Active</span>'
                                : '<span class="badge badge-danger">Off</span>' ?>
                        </td>
                        <td class="actions col-actions">
                            <a class="btn btn-sm btn-secondary" href="?edit_user=<?= (int)$u['user_id'] ?>">Edit</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$users): ?>
                    <tr><td colspan="5" class="text-muted">No users yet. Use <strong>Add user</strong> to create one.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div><!-- /.users-admin-stack -->

<!-- Security group → role (AD / Entra) -->
<div class="card">
    <div class="card-header flex-between">
        <h2>Security group → role mapping</h2>
        <button type="button" class="btn btn-sm btn-primary" data-open-modal="modal-role-map">Add mapping</button>
    </div>
    <div class="card-body">
        <p class="text-muted" style="font-size:.88rem;margin-top:0">
            Map AD/Entra groups to platform roles. At LDAPS login, membership is read from
            <code>memberOf</code> (including nested groups) and the <strong>highest-privilege</strong> matching map wins
            (Global Admin &gt; Data Center Admin &gt; Operator &gt; Department Admin &gt; Auditor &gt; Viewer).
            Role is re-evaluated on every login. Group ID may be the full DN, CN, or Entra object ID
            (display name is also matched).
            <strong>Account creation:</strong> with org-wide Base DN, a first-time LDAPS user is provisioned
            only if they match <em>any</em> of these role maps (see Settings → LDAPS → Require security group mapping).
            Map a Viewer (or C-Suite) group here so executives can sign in without being in the IT OU.
        </p>
        <div class="table-wrap">
            <table class="data">
                <thead>
                <tr><th>Role</th><th>Source</th><th>Group name</th><th>Group ID</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($roleGroupMaps as $m): ?>
                    <tr>
                        <td><span class="badge badge-info"><?= App::e($m['role_name']) ?></span></td>
                        <td><span class="badge"><?= App::e($m['auth_source']) ?></span></td>
                        <td><?= App::e($m['group_name'] ?? '—') ?></td>
                        <td><code style="font-size:.78rem"><?= App::e($m['group_id']) ?></code></td>
                        <td class="actions">
                            <form method="post" style="display:inline" onsubmit="return confirm('Remove this mapping?');">
                                <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                                <input type="hidden" name="action" value="delete_role_group_map">
                                <input type="hidden" name="map_id" value="<?= (int)$m['map_id'] ?>">
                                <button class="btn btn-sm btn-danger" type="submit">Remove</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$roleGroupMaps): ?>
                    <tr><td colspan="5" class="text-muted">No role group mappings yet.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Security group → department -->
<div class="card">
    <div class="card-header flex-between">
        <h2>Security group → department mapping</h2>
        <button type="button" class="btn btn-sm btn-primary" data-open-modal="modal-dept-map">Add mapping</button>
    </div>
    <div class="card-body">
        <p class="text-muted" style="font-size:.88rem;margin-top:0">
            When LDAPS or Entra ID sign-in is enabled, matching group membership can assign the user’s department automatically.
        </p>
        <div class="table-wrap">
            <table class="data">
                <thead>
                <tr><th>Department</th><th>Source</th><th>Group name</th><th>Group ID</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($groupMaps as $m): ?>
                    <tr>
                        <td>
                            <span class="dept-chip">
                                <span class="dept-swatch sm" style="background:<?= App::e(users_normalize_color($m['color_hex'] ?? null)) ?>"></span>
                                <?= App::e($m['department_name']) ?>
                            </span>
                        </td>
                        <td><span class="badge"><?= App::e($m['auth_source']) ?></span></td>
                        <td><?= App::e($m['group_name'] ?? '—') ?></td>
                        <td><code style="font-size:.78rem"><?= App::e($m['group_id']) ?></code></td>
                        <td class="actions">
                            <form method="post" style="display:inline" onsubmit="return confirm('Remove this mapping?');">
                                <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                                <input type="hidden" name="action" value="delete_group_map">
                                <input type="hidden" name="map_id" value="<?= (int)$m['map_id'] ?>">
                                <button class="btn btn-sm btn-danger" type="submit">Remove</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$groupMaps): ?>
                    <tr><td colspan="5" class="text-muted">No group mappings yet.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add department modal -->
<div class="app-modal" id="modal-dept" hidden aria-hidden="true">
    <div class="app-modal-backdrop" data-modal-close></div>
    <div class="app-modal-panel" role="dialog" aria-modal="true" aria-labelledby="modal-dept-title">
        <div class="app-modal-head">
            <h3 id="modal-dept-title">Add department</h3>
            <button type="button" class="btn btn-ghost btn-sm" data-modal-close aria-label="Close">✕</button>
        </div>
        <div class="app-modal-body">
            <form method="post" class="form-grid">
                <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                <input type="hidden" name="action" value="add_department">
                <div class="form-row"><label>Name *</label>
                    <input class="form-control" name="name" required placeholder="Infrastructure, Applications, Info Sec…"></div>
                <div class="form-row"><label>Code</label>
                    <input class="form-control" name="code" placeholder="INFRA, APP, ISEC"></div>
                <div class="form-row"><label>Color</label>
                    <input class="form-control" type="color" name="color_hex" value="#3b82f6" title="Used for rack device outline"></div>
                <div class="form-row"><label>Manager</label>
                    <input class="form-control" name="manager_name"></div>
                <div class="form-row"><label>Contact email</label>
                    <input class="form-control" type="email" name="contact_email"></div>
                <div class="form-row"><label>Contact phone</label>
                    <input class="form-control" name="contact_phone"></div>
                <div class="form-row full"><label>Notes</label>
                    <textarea class="form-control" name="notes" rows="2"></textarea></div>
                <div class="form-row full app-modal-actions">
                    <button class="btn btn-primary" type="submit">Add department</button>
                    <button type="button" class="btn btn-secondary" data-modal-close>Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if ($editDept): ?>
<div class="app-modal" id="modal-dept-edit" aria-hidden="false">
    <div class="app-modal-backdrop" data-modal-close-nav></div>
    <div class="app-modal-panel" role="dialog" aria-modal="true" aria-labelledby="modal-dept-edit-title">
        <div class="app-modal-head">
            <h3 id="modal-dept-edit-title">Edit department</h3>
            <a class="btn btn-ghost btn-sm" href="<?= App::e(App::url('pages/users.php')) ?>" aria-label="Close">✕</a>
        </div>
        <div class="app-modal-body">
            <form method="post" class="form-grid">
                <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                <input type="hidden" name="action" value="update_department">
                <input type="hidden" name="department_id" value="<?= (int)$editDept['department_id'] ?>">
                <div class="form-row"><label>Name *</label>
                    <input class="form-control" name="name" required value="<?= App::e($editDept['name'] ?? '') ?>"></div>
                <div class="form-row"><label>Code</label>
                    <input class="form-control" name="code" value="<?= App::e($editDept['code'] ?? '') ?>"></div>
                <div class="form-row"><label>Color</label>
                    <input class="form-control" type="color" name="color_hex"
                           value="<?= App::e(users_normalize_color($editDept['color_hex'] ?? '#3b82f6')) ?>"></div>
                <div class="form-row"><label>Manager</label>
                    <input class="form-control" name="manager_name" value="<?= App::e($editDept['manager_name'] ?? '') ?>"></div>
                <div class="form-row"><label>Contact email</label>
                    <input class="form-control" type="email" name="contact_email" value="<?= App::e($editDept['contact_email'] ?? '') ?>"></div>
                <div class="form-row"><label>Contact phone</label>
                    <input class="form-control" name="contact_phone" value="<?= App::e($editDept['contact_phone'] ?? '') ?>"></div>
                <div class="form-row full"><label>Notes</label>
                    <textarea class="form-control" name="notes" rows="2"><?= App::e($editDept['notes'] ?? '') ?></textarea></div>
                <div class="form-row full"><label>
                    <input type="checkbox" name="is_active" value="1" <?= !empty($editDept['is_active']) ? 'checked' : '' ?>> Active
                </label></div>
                <div class="form-row full app-modal-actions">
                    <button class="btn btn-primary" type="submit">Save department</button>
                    <a class="btn btn-secondary" href="<?= App::e(App::url('pages/users.php')) ?>">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Add user modal -->
<div class="app-modal" id="modal-user" hidden aria-hidden="true">
    <div class="app-modal-backdrop" data-modal-close></div>
    <div class="app-modal-panel app-modal-panel-wide" role="dialog" aria-modal="true" aria-labelledby="modal-user-title">
        <div class="app-modal-head">
            <h3 id="modal-user-title">Add user</h3>
            <button type="button" class="btn btn-ghost btn-sm" data-modal-close aria-label="Close">✕</button>
        </div>
        <div class="app-modal-body">
            <form method="post" class="form-grid" data-password-confirm="create">
                <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                <input type="hidden" name="action" value="create">
                <div class="form-row"><label>Username *</label>
                    <input class="form-control" name="username" required autocomplete="off"></div>
                <div class="form-row"><label>Display name</label>
                    <input class="form-control" name="display_name"></div>
                <div class="form-row"><label>Email *</label>
                    <input class="form-control" type="email" name="email" required></div>
                <div class="form-row"><label>Role</label>
                    <select class="form-control" name="role_id">
                        <?php foreach ($roles as $r): ?>
                            <option value="<?= (int)$r['role_id'] ?>"><?= App::e($r['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row"><label>Department</label>
                    <select class="form-control" name="department_id">
                        <option value="">— None —</option>
                        <?php foreach ($departments as $d):
                            if (empty($d['is_active'])) {
                                continue;
                            }
                            ?>
                            <option value="<?= (int)$d['department_id'] ?>"><?= App::e($d['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row"><label>Auth source</label>
                    <select class="form-control" name="auth_source">
                        <?php foreach (['local' => 'Local', 'ldaps' => 'LDAPS', 'entra' => 'Entra ID'] as $val => $lab): ?>
                            <option value="<?= $val ?>"><?= $lab ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row"><label>Password (local)</label>
                    <input class="form-control" type="password" name="password" id="user_create_password"
                           autocomplete="new-password" minlength="8"></div>
                <div class="form-row"><label>Confirm password</label>
                    <input class="form-control" type="password" name="password_confirm" id="user_create_password_confirm"
                           autocomplete="new-password" minlength="8"></div>
                <div class="form-row full"><label>
                    <input type="checkbox" name="send_welcome" value="1" checked>
                    Send welcome email (login link + set-password link for local accounts)
                </label>
                    <p class="text-muted" style="font-size:.75rem;margin:.25rem 0 0">
                        Requires SMTP under Settings → Email. Password is never included in the message.
                    </p>
                </div>
                <div class="form-row full app-modal-actions">
                    <button class="btn btn-primary" type="submit">Create user</button>
                    <button type="button" class="btn btn-secondary" data-modal-close>Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if ($isGlobalAdmin): ?>
<div class="app-modal" id="modal-api-service" hidden aria-hidden="true">
    <div class="app-modal-backdrop" data-modal-close></div>
    <div class="app-modal-panel" role="dialog" aria-modal="true" aria-labelledby="modal-api-service-title">
        <div class="app-modal-head">
            <h3 id="modal-api-service-title">Create API-Service Account</h3>
            <button type="button" class="btn btn-ghost btn-sm" data-modal-close aria-label="Close">✕</button>
        </div>
        <div class="app-modal-body">
            <p class="text-muted" style="font-size:.85rem;margin-top:0">
                Creates a robot account that <strong>cannot sign in</strong> to the website.
                Username is always <code>api-service-</code> plus the short name you enter.
                Give it a least-privilege role (Viewer for read). Global Admin is not allowed.
            </p>
            <form method="post" class="form-grid" id="api_service_form">
                <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                <input type="hidden" name="action" value="create_api_service">
                <div class="form-row full"><label>Short name</label>
                    <input class="form-control" name="service_name" id="api_service_name" required
                           placeholder="servicenow" autocomplete="off"
                           pattern="[A-Za-z0-9-]+" title="Letters, numbers, hyphens">
                    <p class="text-muted" style="font-size:.8rem;margin:.3rem 0 0">
                        Username will be <code id="api_service_preview">api-service-…</code>
                    </p>
                </div>
                <div class="form-row"><label>Display name</label>
                    <input class="form-control" name="display_name" placeholder="ServiceNow integration"></div>
                <div class="form-row"><label>Email (optional)</label>
                    <input class="form-control" type="email" name="email" placeholder="Defaults to username@api.local"></div>
                <div class="form-row"><label>Role</label>
                    <select class="form-control" name="role_id" required>
                        <?php foreach ($roles as $r):
                            if (in_array($r['name'], ['Global Admin', 'Administrator'], true)) {
                                continue;
                            }
                            ?>
                            <option value="<?= (int)$r['role_id'] ?>" <?= ($r['name'] ?? '') === 'Viewer' ? 'selected' : '' ?>>
                                <?= App::e($r['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row"><label>Department</label>
                    <select class="form-control" name="department_id">
                        <option value="">— None —</option>
                        <?php foreach ($departments as $d):
                            if (empty($d['is_active'])) {
                                continue;
                            }
                            ?>
                            <option value="<?= (int)$d['department_id'] ?>"><?= App::e($d['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row full"><label>
                    <input type="checkbox" name="create_token" value="1" checked>
                    Create an API token now (shown once)
                </label></div>
                <div class="form-row"><label>Token name</label>
                    <input class="form-control" name="token_name" value="Initial"></div>
                <div class="form-row"><label>Token scope</label>
                    <select class="form-control" name="token_scope">
                        <option value="read">Read only (recommended)</option>
                        <option value="write">Read + write</option>
                    </select>
                </div>
                <div class="form-row"><label>Token expires</label>
                    <select class="form-control" name="token_expires_days">
                        <option value="0">Never</option>
                        <option value="90">90 days</option>
                        <option value="365">1 year</option>
                    </select>
                </div>
                <div class="form-row full app-modal-actions">
                    <button class="btn btn-primary" type="submit">Create API-Service Account</button>
                    <button type="button" class="btn btn-secondary" data-modal-close>Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($editUser): ?>
<div class="app-modal" id="modal-user-edit" aria-hidden="false">
    <div class="app-modal-backdrop" data-modal-close-nav></div>
    <div class="app-modal-panel app-modal-panel-wide" role="dialog" aria-modal="true" aria-labelledby="modal-user-edit-title">
        <div class="app-modal-head">
            <h3 id="modal-user-edit-title">Edit user</h3>
            <a class="btn btn-ghost btn-sm" href="<?= App::e(App::url('pages/users.php')) ?>" aria-label="Close">✕</a>
        </div>
        <div class="app-modal-body">
            <form method="post" class="form-grid" data-password-confirm="update">
                <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="user_id" value="<?= (int)$editUser['user_id'] ?>">
                <div class="form-row"><label>Username</label>
                    <input class="form-control" value="<?= App::e($editUser['username']) ?>" readonly></div>
                <div class="form-row"><label>Display name</label>
                    <input class="form-control" name="display_name" value="<?= App::e($editUser['display_name'] ?? '') ?>"></div>
                <div class="form-row"><label>Email *</label>
                    <input class="form-control" type="email" name="email" required value="<?= App::e($editUser['email'] ?? '') ?>"></div>
                <div class="form-row"><label>Role</label>
                    <select class="form-control" name="role_id">
                        <?php foreach ($roles as $r): ?>
                            <option value="<?= (int)$r['role_id'] ?>"
                                <?= (int)($editUser['role_id'] ?? 0) === (int)$r['role_id'] ? 'selected' : '' ?>>
                                <?= App::e($r['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row"><label>Department</label>
                    <select class="form-control" name="department_id">
                        <option value="">— None —</option>
                        <?php foreach ($departments as $d):
                            if (empty($d['is_active']) && (int)($editUser['department_id'] ?? 0) !== (int)$d['department_id']) {
                                continue;
                            }
                            ?>
                            <option value="<?= (int)$d['department_id'] ?>"
                                <?= (int)($editUser['department_id'] ?? 0) === (int)$d['department_id'] ? 'selected' : '' ?>>
                                <?= App::e($d['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($editUserIsSvc): ?>
                <div class="form-row full">
                    <p class="text-muted" style="font-size:.85rem;margin:0">
                        API service account — cannot sign in to the website. Access is by token only.
                        Do not assign Global Admin.
                    </p>
                </div>
                <?php else: ?>
                <div class="form-row"><label>Auth source</label>
                    <select class="form-control" name="auth_source">
                        <?php foreach (['local' => 'Local', 'ldaps' => 'LDAPS', 'entra' => 'Entra ID'] as $val => $lab): ?>
                            <option value="<?= $val ?>"
                                <?= ($editUser['auth_source'] ?? 'local') === $val ? 'selected' : '' ?>>
                                <?= $lab ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row"><label>New password (optional)</label>
                    <input class="form-control" type="password" name="password" id="user_edit_password"
                           autocomplete="new-password" minlength="8"
                           placeholder="Leave both blank to keep current"></div>
                <div class="form-row"><label>Confirm new password</label>
                    <input class="form-control" type="password" name="password_confirm" id="user_edit_password_confirm"
                           autocomplete="new-password" minlength="8"></div>
                <?php endif; ?>
                <div class="form-row full"><label>
                    <input type="checkbox" name="is_active" value="1" <?= !empty($editUser['is_active']) ? 'checked' : '' ?>> Active
                </label></div>
                <div class="form-row full app-modal-actions">
                    <button class="btn btn-primary" type="submit">Save user</button>
                    <a class="btn btn-secondary" href="<?= App::e(App::url('pages/users.php')) ?>">Cancel</a>
                </div>
            </form>
            <?php if ($editUserIsSvc && $isGlobalAdmin): ?>
            <div style="margin-top:1.25rem;padding-top:1rem;border-top:1px solid var(--border,#2a3648)">
                <h4 style="margin:0 0 .5rem;font-size:.95rem">API tokens</h4>
                <p class="text-muted" style="font-size:.8rem;margin:0 0 .75rem">
                    Tokens are shown once. Send them as
                    <code>Authorization: Bearer ca_live_…</code> to
                    <code><?= App::e(App::url('api/v1.php')) ?></code>
                </p>
                <?php if ($editUserTokens): ?>
                <table class="data" style="margin-bottom:.75rem">
                    <thead>
                    <tr><th>Name</th><th>Prefix</th><th>Scope</th><th>Last used</th><th>Expires</th><th></th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($editUserTokens as $t):
                        $rev = !empty($t['revoked_at']);
                        ?>
                        <tr>
                            <td><?= App::e((string)$t['name']) ?><?= $rev ? ' <span class="badge badge-danger">Revoked</span>' : '' ?></td>
                            <td><code><?= App::e((string)$t['token_prefix']) ?>…</code></td>
                            <td><?= App::e((string)$t['scopes']) ?></td>
                            <td style="font-size:.8rem"><?= App::e((string)($t['last_used_at'] ?: '—')) ?></td>
                            <td style="font-size:.8rem"><?= App::e((string)($t['expires_at'] ?: 'never')) ?></td>
                            <td>
                                <?php if (!$rev): ?>
                                <form method="post" onsubmit="return confirm('Revoke this token? The other system will stop working.');">
                                    <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                                    <input type="hidden" name="action" value="revoke_api_token">
                                    <input type="hidden" name="user_id" value="<?= (int)$editUser['user_id'] ?>">
                                    <input type="hidden" name="token_id" value="<?= (int)$t['token_id'] ?>">
                                    <button class="btn btn-sm btn-ghost" type="submit">Revoke</button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                    <p class="text-muted" style="font-size:.85rem">No tokens yet.</p>
                <?php endif; ?>
                <form method="post" class="form-grid">
                    <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                    <input type="hidden" name="action" value="create_api_token">
                    <input type="hidden" name="user_id" value="<?= (int)$editUser['user_id'] ?>">
                    <div class="form-row"><label>Token name</label>
                        <input class="form-control" name="token_name" value="Token" required></div>
                    <div class="form-row"><label>Scope</label>
                        <select class="form-control" name="token_scope">
                            <option value="read">Read only</option>
                            <option value="write">Read + write (future writes)</option>
                        </select>
                    </div>
                    <div class="form-row"><label>Expires</label>
                        <select class="form-control" name="token_expires_days">
                            <option value="0">Never</option>
                            <option value="90">90 days</option>
                            <option value="365">1 year</option>
                        </select>
                    </div>
                    <div class="form-row">
                        <button class="btn btn-secondary" type="submit">Create token</button>
                    </div>
                </form>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Add role mapping modal (always empty form) -->
<div class="app-modal" id="modal-role-map" hidden aria-hidden="true">
    <div class="app-modal-backdrop" data-modal-close></div>
    <div class="app-modal-panel" role="dialog" aria-modal="true" aria-labelledby="modal-role-map-title">
        <div class="app-modal-head">
            <h3 id="modal-role-map-title">Add role mapping</h3>
            <button type="button" class="btn btn-ghost btn-sm" data-modal-close aria-label="Close">✕</button>
        </div>
        <div class="app-modal-body">
            <form method="post" class="form-grid">
                <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                <input type="hidden" name="action" value="add_role_group_map">
                <div class="form-row full"><label>Role</label>
                    <select class="form-control" name="role_id" required>
                        <option value="">—</option>
                        <?php foreach ($roles as $r):
                            if (!in_array($r['name'], ['Viewer', 'Department Admin', 'Data Center Admin', 'Global Admin', 'Administrator'], true)) {
                                continue;
                            }
                            ?>
                            <option value="<?= (int)$r['role_id'] ?>"><?= App::e($r['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row"><label>Auth source</label>
                    <select class="form-control" name="auth_source">
                        <option value="ldaps">LDAPS</option>
                        <option value="entra">Entra ID</option>
                    </select>
                </div>
                <div class="form-row"><label>Group name (display)</label>
                    <input class="form-control" name="group_name" placeholder="DCIM-DataCenter-Admins"></div>
                <div class="form-row full"><label>Group ID *</label>
                    <input class="form-control" name="group_id" required
                           placeholder="LDAP DN / SID or Entra object ID"></div>
                <div class="form-row full"><label>Notes</label>
                    <input class="form-control" name="notes" placeholder="Optional"></div>
                <div class="form-row full app-modal-actions">
                    <button class="btn btn-primary" type="submit">Add role map</button>
                    <button type="button" class="btn btn-secondary" data-modal-close>Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="app-modal" id="modal-dept-map" hidden aria-hidden="true">
    <div class="app-modal-backdrop" data-modal-close></div>
    <div class="app-modal-panel" role="dialog" aria-modal="true" aria-labelledby="modal-dept-map-title">
        <div class="app-modal-head">
            <h3 id="modal-dept-map-title">Add department mapping</h3>
            <button type="button" class="btn btn-ghost btn-sm" data-modal-close aria-label="Close">✕</button>
        </div>
        <div class="app-modal-body">
            <form method="post" class="form-grid">
                <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                <input type="hidden" name="action" value="add_group_map">
                <div class="form-row full"><label>Department</label>
                    <select class="form-control" name="department_id" required>
                        <option value="">—</option>
                        <?php foreach ($departments as $d): ?>
                            <?php if (empty($d['is_active'])) {
                                continue;
                            } ?>
                            <option value="<?= (int)$d['department_id'] ?>"><?= App::e($d['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row"><label>Auth source</label>
                    <select class="form-control" name="auth_source">
                        <option value="ldaps">LDAPS</option>
                        <option value="entra">Entra ID</option>
                    </select>
                </div>
                <div class="form-row"><label>Group name (display)</label>
                    <input class="form-control" name="group_name" placeholder="DCIM-Infrastructure"></div>
                <div class="form-row full"><label>Group ID *</label>
                    <input class="form-control" name="group_id" required
                           placeholder="LDAP DN / SID or Entra object ID"></div>
                <div class="form-row full"><label>Notes</label>
                    <input class="form-control" name="notes" placeholder="Optional"></div>
                <div class="form-row full app-modal-actions">
                    <button class="btn btn-primary" type="submit">Add group map</button>
                    <button type="button" class="btn btn-secondary" data-modal-close>Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
/* Stack Departments + Users for all screen widths */
.users-admin-stack {
  display: flex;
  flex-direction: column;
  gap: 1.25rem;
  margin-bottom: 1.25rem;
}
.users-admin-stack > .card {
  width: 100%;
  max-width: 100%;
  min-width: 0;
}
table.data.table-fit {
  width: 100%;
  table-layout: auto;
}
table.data.table-fit th,
table.data.table-fit td {
  white-space: normal;
  vertical-align: middle;
  padding: .7rem .85rem;
  line-height: 1.35;
}
table.data.table-fit th {
  white-space: nowrap;
}
table.data.table-fit .col-swatch {
  width: 2.25rem;
  text-align: center;
}
table.data.table-fit .col-actions,
table.data.table-fit td.col-actions {
  width: 1%;
  white-space: nowrap;
  text-align: right;
  vertical-align: middle;
}
.users-table .user-cell-name {
  display: block;
  font-size: .92rem;
  line-height: 1.3;
}
.users-table .user-cell-sub {
  font-size: .78rem;
  line-height: 1.3;
  margin-top: .1rem;
}
.users-table .user-status-cell {
  white-space: nowrap;
  vertical-align: middle;
}
.users-table .user-status-cell .badge {
  margin-right: .2rem;
}
.users-table .dept-chip {
  display: inline-flex;
  align-items: center;
  gap: .35rem;
  max-width: 100%;
}
.dept-swatch {
  display: inline-block;
  width: 1.15rem;
  height: 1.15rem;
  border-radius: 4px;
  border: 1px solid rgba(255,255,255,.2);
  vertical-align: middle;
  box-shadow: inset 0 0 0 1px rgba(0,0,0,.25);
  flex-shrink: 0;
}
.dept-swatch.sm { width: .85rem; height: .85rem; border-radius: 3px; }
.dept-chip {
  display: inline-flex;
  align-items: center;
  gap: .35rem;
  font-size: .88rem;
}
.card-header.flex-between {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: .75rem;
  flex-wrap: wrap;
}
.card-header.flex-between h2 {
  margin: 0;
}
</style>
<script>
(function () {
    function openModal(id) {
        var el = document.getElementById(id);
        if (!el) return;
        el.hidden = false;
        el.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        var focus = el.querySelector('input:not([type=hidden]), select, textarea, button');
        if (focus) setTimeout(function () { focus.focus(); }, 50);
    }
    function closeModal(el) {
        if (!el) return;
        el.hidden = true;
        el.setAttribute('aria-hidden', 'true');
        // only unlock scroll if no other modal open
        if (!document.querySelector('.app-modal:not([hidden])')) {
            document.body.style.overflow = '';
        }
    }
    document.querySelectorAll('[data-open-modal]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            openModal(btn.getAttribute('data-open-modal'));
        });
    });
    document.querySelectorAll('[data-modal-close]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            closeModal(btn.closest('.app-modal'));
        });
    });
    document.querySelectorAll('[data-modal-close-nav]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            window.location.href = <?= json_encode(App::url('pages/users.php')) ?>;
        });
    });
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        var open = document.querySelector('.app-modal:not([hidden])');
        if (!open) return;
        // Edit modals navigate home; add modals just close
        if (open.id === 'modal-dept-edit' || open.id === 'modal-user-edit') {
            window.location.href = <?= json_encode(App::url('pages/users.php')) ?>;
        } else {
            closeModal(open);
        }
    });
    // Edit modals rendered without [hidden] — lock scroll
    if (document.getElementById('modal-dept-edit') || document.getElementById('modal-user-edit')) {
        document.body.style.overflow = 'hidden';
    }

    var nameEl = document.getElementById('api_service_name');
    var prevEl = document.getElementById('api_service_preview');
    if (nameEl && prevEl) {
        function previewApiName() {
            var s = (nameEl.value || '').trim().toLowerCase().replace(/^api-service-/, '');
            s = s.replace(/[^a-z0-9-]+/g, '-').replace(/^-+|-+$/g, '');
            prevEl.textContent = 'api-service-' + (s || '…');
        }
        nameEl.addEventListener('input', previewApiName);
        previewApiName();
    }

    document.querySelectorAll('form[data-password-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            var p = form.querySelector('[name="password"]');
            var c = form.querySelector('[name="password_confirm"]');
            if (!p || !c) return;
            var a = p.value || '';
            var b = c.value || '';
            var required = form.getAttribute('data-password-confirm') === 'create'
                && (form.querySelector('[name="auth_source"]') || {}).value === 'local';
            if (!required && a === '' && b === '') return;
            if (a !== b) {
                e.preventDefault();
                (c.setCustomValidity ? c.setCustomValidity('Password and confirmation do not match.') : null);
                if (c.reportValidity) c.reportValidity();
                else alert('Password and confirmation do not match.');
                c.addEventListener('input', function () { c.setCustomValidity(''); }, { once: true });
                p.addEventListener('input', function () { c.setCustomValidity(''); }, { once: true });
            }
        });
    });
})();
</script>
<?php layout_footer(); ?>
