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

try {
    $notes = Database::fetchAll(
        'SELECT TOP 100 * FROM notifications
         WHERE user_id = ? OR user_id IS NULL
         ORDER BY created_at DESC',
        [(int)$user['user_id']]
    );
} catch (Throwable $e) {
    $notes = [];
}
if ($notes && class_exists('NotificationAlertStatus')) {
    $notes = NotificationAlertStatus::enrich($notes);
}

layout_header('Notifications', $user, 'dashboard');
?>
<div class="flex-between mb-2">
    <p class="text-muted mb-0">ICMP, power, environmental, disposal, audit, and system notices. Green check = condition cleared (history retained). Configure routing under Settings → Alerts &amp; notifications.</p>
    <form method="post">
        <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
        <input type="hidden" name="action" value="mark_all">
        <button class="btn btn-secondary btn-sm" type="submit">Mark all read</button>
    </form>
</div>
<div class="card">
    <div class="card-body flush">
        <table class="data">
            <thead>
                <tr>
                    <th>When</th>
                    <th>Status</th>
                    <th>Category</th>
                    <th>Title</th>
                    <th>Message</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($notes as $n):
                $state = (string)($n['alert_state'] ?? '');
                $cleared = !empty($n['is_cleared']) || $state === 'cleared';
                $active = $state === 'active' && !$cleared;
                $title = (string)($n['title'] ?? '');
                // Recovery events always show cleared
                if (stripos($title, 'recovered') !== false) {
                    $cleared = true;
                    $active = false;
                }
                ?>
                <tr style="<?= !empty($n['is_read']) ? '' : 'font-weight:600' ?>"
                    class="<?= $cleared ? 'notif-row-cleared' : ($active ? 'notif-row-active' : '') ?>">
                    <td style="white-space:nowrap;font-size:.85rem"><?= App::e((string)$n['created_at']) ?></td>
                    <td>
                        <?php if ($cleared): ?>
                            <span class="notif-state notif-state-cleared" title="Condition cleared / recovered">
                                <span class="notif-check" aria-hidden="true">✓</span> Cleared
                            </span>
                        <?php elseif ($active): ?>
                            <span class="notif-state notif-state-active" title="Condition still active">
                                <span class="notif-dot" aria-hidden="true"></span> Active
                            </span>
                        <?php else: ?>
                            <span class="text-muted" style="font-size:.8rem">—</span>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge"><?= App::e((string)($n['category'] ?? '')) ?></span></td>
                    <td><?= App::e($title) ?></td>
                    <td style="font-size:.85rem;max-width:28rem"><?= App::e((string)($n['message'] ?? '')) ?></td>
                    <td>
                        <?php if (empty($n['is_read'])): ?>
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
            <?php if (!$notes): ?><tr><td colspan="6" class="text-muted">No notifications.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<style>
  .notif-state {
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
    font-size: 0.78rem;
    font-weight: 650;
    white-space: nowrap;
  }
  .notif-state-cleared { color: #16a34a; }
  .notif-state-active { color: #ca8a04; }
  .notif-check {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 1.15rem;
    height: 1.15rem;
    border-radius: 999px;
    background: rgba(34, 197, 94, 0.15);
    border: 1px solid rgba(34, 197, 94, 0.45);
    color: #16a34a;
    font-size: 0.75rem;
    line-height: 1;
  }
  .notif-dot {
    width: 0.45rem;
    height: 0.45rem;
    border-radius: 999px;
    background: #eab308;
    box-shadow: 0 0 0 3px rgba(234, 179, 8, 0.2);
  }
  .notif-row-cleared td { opacity: 0.92; }
</style>
<?php layout_footer(); ?>
