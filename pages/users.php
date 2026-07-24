<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/App.php';
require_once dirname(__DIR__) . '/includes/layout.php';
App::boot();
$user = App::requirePermission('manage_users');

function users_normalize_color(?string $hex): string
{
    $hex = trim((string)$hex);
    if (preg_match('/^#?[0-9A-Fa-f]{6}$/', $hex)) {
        return '#' . ltrim($hex, '#');
    }
    return '#3b82f6';
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
                if (strlen($_POST['password'] ?? '') < 8) {
                    throw new RuntimeException('Password must be at least 8 characters.');
                }
                $hash = password_hash($_POST['password'], PASSWORD_DEFAULT);
            }
            Database::insert('users', [
                'username' => $username,
                'email' => trim($_POST['email'] ?? ''),
                'display_name' => trim($_POST['display_name'] ?? '') !== '' ? trim($_POST['display_name']) : null,
                'password_hash' => $hash,
                'auth_source' => $_POST['auth_source'] ?? 'local',
                'role_id' => (int)$_POST['role_id'],
                'department_id' => $_POST['department_id'] !== '' ? (int)$_POST['department_id'] : null,
                'is_active' => 1,
            ]);
            App::flash('success', 'User created.');
        }

        if ($action === 'update') {
            $uid = (int)$_POST['user_id'];
            if ($uid <= 0) {
                throw new RuntimeException('Select a user to update.');
            }
            $fields = [
                'email' => trim($_POST['email'] ?? ''),
                'display_name' => trim($_POST['display_name'] ?? '') !== '' ? trim($_POST['display_name']) : null,
                'role_id' => (int)$_POST['role_id'],
                'department_id' => $_POST['department_id'] !== '' ? (int)$_POST['department_id'] : null,
                'is_active' => !empty($_POST['is_active']) ? 1 : 0,
                'auth_source' => $_POST['auth_source'] ?? 'local',
                'updated_at' => date('Y-m-d H:i:s'),
            ];
            if (!empty($_POST['password'])) {
                if (strlen($_POST['password']) < 8) {
                    throw new RuntimeException('Password must be at least 8 characters.');
                }
                $fields['password_hash'] = password_hash($_POST['password'], PASSWORD_DEFAULT);
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
            <button type="button" class="btn btn-sm btn-primary" data-open-modal="modal-user">Add user</button>
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
            Map AD/Entra groups to platform roles. At login, the highest-privilege matching group wins.
            Applied when LDAPS/Entra sign-in is enabled.
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
            <form method="post" class="form-grid">
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
                    <input class="form-control" type="password" name="password" autocomplete="new-password"></div>
                <div class="form-row full app-modal-actions">
                    <button class="btn btn-primary" type="submit">Create user</button>
                    <button type="button" class="btn btn-secondary" data-modal-close>Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if ($editUser): ?>
<div class="app-modal" id="modal-user-edit" aria-hidden="false">
    <div class="app-modal-backdrop" data-modal-close-nav></div>
    <div class="app-modal-panel app-modal-panel-wide" role="dialog" aria-modal="true" aria-labelledby="modal-user-edit-title">
        <div class="app-modal-head">
            <h3 id="modal-user-edit-title">Edit user</h3>
            <a class="btn btn-ghost btn-sm" href="<?= App::e(App::url('pages/users.php')) ?>" aria-label="Close">✕</a>
        </div>
        <div class="app-modal-body">
            <form method="post" class="form-grid">
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
                    <input class="form-control" type="password" name="password" autocomplete="new-password"></div>
                <div class="form-row full"><label>
                    <input type="checkbox" name="is_active" value="1" <?= !empty($editUser['is_active']) ? 'checked' : '' ?>> Active
                </label></div>
                <div class="form-row full app-modal-actions">
                    <button class="btn btn-primary" type="submit">Save user</button>
                    <a class="btn btn-secondary" href="<?= App::e(App::url('pages/users.php')) ?>">Cancel</a>
                </div>
            </form>
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
})();
</script>
<?php layout_footer(); ?>
