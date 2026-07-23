<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/App.php';
require_once dirname(__DIR__) . '/includes/layout.php';
App::boot();
$user = App::requirePermission('view_notifications');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && App::verifyCsrf($_POST['_csrf'] ?? '')) {
    if (($_POST['action'] ?? '') === 'mark_read') {
        Database::query(
            'UPDATE notifications SET is_read = 1 WHERE notification_id = ? AND (user_id = ? OR user_id IS NULL)',
            [(int)$_POST['notification_id'], (int)$user['user_id']]
        );
    }
    if (($_POST['action'] ?? '') === 'mark_all') {
        Database::query(
            'UPDATE notifications SET is_read = 1 WHERE (user_id = ? OR user_id IS NULL) AND is_read = 0',
            [(int)$user['user_id']]
        );
    }
    App::redirect('pages/notifications.php');
}

$notes = Database::fetchAll(
    'SELECT TOP 100 * FROM notifications
     WHERE user_id = ? OR user_id IS NULL
     ORDER BY created_at DESC',
    [(int)$user['user_id']]
);

layout_header('Notifications', $user, 'dashboard');
?>
<div class="flex-between mb-2">
    <p class="text-muted mb-0">Disposal alerts, system messages, and audit notices.</p>
    <form method="post">
        <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
        <input type="hidden" name="action" value="mark_all">
        <button class="btn btn-secondary btn-sm" type="submit">Mark all read</button>
    </form>
</div>
<div class="card">
    <div class="card-body flush">
        <table class="data">
            <thead><tr><th>When</th><th>Category</th><th>Title</th><th>Message</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($notes as $n): ?>
                <tr style="<?= $n['is_read'] ? '' : 'font-weight:600' ?>">
                    <td><?= App::e($n['created_at']) ?></td>
                    <td><span class="badge"><?= App::e($n['category']) ?></span></td>
                    <td><?= App::e($n['title']) ?></td>
                    <td><?= App::e($n['message']) ?></td>
                    <td>
                        <?php if (!$n['is_read']): ?>
                        <form method="post">
                            <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                            <input type="hidden" name="action" value="mark_read">
                            <input type="hidden" name="notification_id" value="<?= (int)$n['notification_id'] ?>">
                            <button class="btn btn-sm btn-ghost" type="submit">Mark read</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$notes): ?><tr><td colspan="5" class="text-muted">No notifications.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php layout_footer(); ?>
