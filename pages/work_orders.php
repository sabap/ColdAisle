<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/App.php';
require_once dirname(__DIR__) . '/includes/layout.php';
require_once dirname(__DIR__) . '/includes/work_order_helpers.php';
App::boot();
$user = App::requirePermission('view_work_orders');
$canEdit = AuthManager::canEditWorkOrders($user);

$types = work_order_types();
$statuses = work_order_statuses();
$itemStatuses = work_order_item_statuses();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$actionGet = (string)($_GET['action'] ?? '');
$startDeviceId = isset($_GET['device_id']) ? (int)$_GET['device_id'] : 0;
$filterStatus = strtolower(trim((string)($_GET['status'] ?? '')));
$filterTicket = trim((string)($_GET['ticket'] ?? ''));
$filterWeek = !empty($_GET['week']);

// Ensure tables exist on first hit
try {
    if (class_exists('Schema')) {
        // Schema::ensure already runs at boot; no-op if present
    }
} catch (Throwable $e) {
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && App::verifyCsrf($_POST['_csrf'] ?? '')) {
    $action = (string)($_POST['action'] ?? '');
    try {
        if (!$canEdit && $action !== '') {
            throw new RuntimeException('You do not have permission to manage work orders.');
        }

        if ($action === 'create') {
            $title = trim((string)($_POST['title'] ?? ''));
            if ($title === '') {
                throw new RuntimeException('Title is required.');
            }
            $workType = (string)($_POST['work_type'] ?? 'move');
            if (!isset($types[$workType])) {
                $workType = 'move';
            }
            $checklist = work_order_default_checklist($workType);
            $woId = Database::insert('work_orders', [
                'title' => $title,
                'work_type' => $workType,
                'status' => 'draft',
                'change_ticket' => work_order_null($_POST['change_ticket'] ?? null),
                'requested_by' => (int)$user['user_id'],
                'assigned_to' => !empty($_POST['assigned_to']) ? (int)$_POST['assigned_to'] : null,
                'scheduled_date' => work_order_null($_POST['scheduled_date'] ?? null),
                'notes' => work_order_null($_POST['notes'] ?? null),
                'checklist_json' => work_order_checklist_encode($checklist),
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            $seedDevice = (int)($_POST['device_id'] ?? 0);
            if ($seedDevice > 0 && $woId) {
                $dev = Database::fetchOne(
                    'SELECT device_id, cabinet_id, position_u FROM devices WHERE device_id = ? AND is_active = 1',
                    [$seedDevice]
                );
                if ($dev) {
                    Database::insert('work_order_items', [
                        'work_order_id' => (int)$woId,
                        'device_id' => $seedDevice,
                        'from_cabinet_id' => $dev['cabinet_id'] !== null ? (int)$dev['cabinet_id'] : null,
                        'from_position_u' => $dev['position_u'] !== null ? (int)$dev['position_u'] : null,
                        'to_cabinet_id' => !empty($_POST['to_cabinet_id']) ? (int)$_POST['to_cabinet_id'] : null,
                        'to_position_u' => $_POST['to_position_u'] !== '' && $_POST['to_position_u'] !== null
                            ? (int)$_POST['to_position_u'] : null,
                        'item_status' => 'pending',
                        'sort_order' => 0,
                    ]);
                }
            }
            AuditService::log((int)$user['user_id'], $user['username'], 'work_order_create', 'work_order', (int)$woId, [
                'title' => $title,
            ]);
            $sdpExtra = '';
            if (work_order_itsm_ready() && class_exists('ItsmService') && ItsmService::autoCreate()) {
                try {
                    $disp = ItsmService::tryCreateFromWorkOrder((int)$woId, true);
                    if ($disp) {
                        $sdpExtra = ' ' . ItsmService::label() . ' ticket #' . $disp . ' created.';
                    }
                } catch (Throwable $e) {
                    App::flash('warning', 'Work order created, but ' . ItsmService::label() . ' create failed: ' . $e->getMessage());
                    App::redirect('pages/work_orders.php?id=' . (int)$woId);
                }
            }
            App::flash('success', 'Work order created.' . $sdpExtra);
            App::redirect('pages/work_orders.php?id=' . (int)$woId);
        }

        if ($action === 'update_header') {
            $woId = (int)($_POST['work_order_id'] ?? 0);
            $wo = Database::fetchOne('SELECT * FROM work_orders WHERE work_order_id = ?', [$woId]);
            if (!$wo) {
                throw new RuntimeException('Work order not found.');
            }
            if (in_array((string)$wo['status'], ['completed', 'cancelled'], true)) {
                throw new RuntimeException('Cannot edit a closed work order.');
            }
            $title = trim((string)($_POST['title'] ?? ''));
            if ($title === '') {
                throw new RuntimeException('Title is required.');
            }
            $workType = (string)($_POST['work_type'] ?? $wo['work_type']);
            if (!isset($types[$workType])) {
                $workType = (string)$wo['work_type'];
            }
            Database::update('work_orders', [
                'title' => $title,
                'work_type' => $workType,
                'change_ticket' => work_order_null($_POST['change_ticket'] ?? null),
                'assigned_to' => !empty($_POST['assigned_to']) ? (int)$_POST['assigned_to'] : null,
                'scheduled_date' => work_order_null($_POST['scheduled_date'] ?? null),
                'notes' => work_order_null($_POST['notes'] ?? null),
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'work_order_id = :id', [':id' => $woId]);
            App::flash('success', 'Work order updated.');
            App::redirect('pages/work_orders.php?id=' . $woId);
        }

        if ($action === 'set_status') {
            $woId = (int)($_POST['work_order_id'] ?? 0);
            $newStatus = (string)($_POST['status'] ?? '');
            $wo = Database::fetchOne('SELECT * FROM work_orders WHERE work_order_id = ?', [$woId]);
            if (!$wo) {
                throw new RuntimeException('Work order not found.');
            }
            if (!isset($statuses[$newStatus])) {
                throw new RuntimeException('Invalid status.');
            }
            $cur = (string)$wo['status'];
            $allowed = [
                'draft' => ['planned', 'cancelled'],
                'planned' => ['in_progress', 'draft', 'cancelled'],
                'in_progress' => ['completed', 'planned', 'cancelled'],
                'completed' => [],
                'cancelled' => ['draft'],
            ];
            if (!in_array($newStatus, $allowed[$cur] ?? [], true) && $newStatus !== $cur) {
                throw new RuntimeException("Cannot change status from {$cur} to {$newStatus}.");
            }
            $patch = [
                'status' => $newStatus,
                'updated_at' => date('Y-m-d H:i:s'),
            ];
            if ($newStatus === 'completed') {
                $patch['completed_at'] = date('Y-m-d H:i:s');
            }
            if ($newStatus !== 'completed') {
                $patch['completed_at'] = null;
            }
            Database::update('work_orders', $patch, 'work_order_id = :id', [':id' => $woId]);

            $apply = !empty($_POST['apply_inventory']);
            $msg = 'Status set to ' . ($statuses[$newStatus] ?? $newStatus) . '.';
            $flashKind = 'success';
            if ($newStatus === 'completed' && $apply) {
                $ar = work_order_apply_destinations($woId, $user);
                $msg .= sprintf(' Applied %d device location(s).', (int)$ar['applied']);
                if ($ar['skipped'] > 0) {
                    $msg .= sprintf(' Skipped %d.', (int)$ar['skipped']);
                }
                if ($ar['errors']) {
                    $msg .= ' ' . implode(' ', array_slice($ar['errors'], 0, 3));
                    $flashKind = 'warning';
                    AuditService::log((int)$user['user_id'], $user['username'], 'work_order_complete', 'work_order', $woId, [
                        'apply' => true,
                        'applied' => $ar['applied'],
                        'skipped' => $ar['skipped'],
                    ]);
                }
            }
            $itsmErr = work_order_itsm_after_status($woId, $cur, $newStatus, $user);
            if ($itsmErr) {
                $msg .= ' Ticketing update failed: ' . $itsmErr;
                $flashKind = 'warning';
            }
            AuditService::log((int)$user['user_id'], $user['username'], 'work_order_status', 'work_order', $woId, [
                'from' => $cur,
                'to' => $newStatus,
                'apply' => $apply,
            ]);
            App::flash($flashKind, $msg);
            App::redirect('pages/work_orders.php?id=' . $woId);
        }

        if ($action === 'apply_inventory') {
            $woId = (int)($_POST['work_order_id'] ?? 0);
            $wo = Database::fetchOne('SELECT * FROM work_orders WHERE work_order_id = ?', [$woId]);
            if (!$wo) {
                throw new RuntimeException('Work order not found.');
            }
            if (!in_array((string)$wo['status'], ['in_progress', 'completed'], true)) {
                throw new RuntimeException('Apply inventory only when in progress or completed.');
            }
            $ar = work_order_apply_destinations($woId, $user);
            $msg = sprintf('Applied %d device location(s).', (int)$ar['applied']);
            if ($ar['skipped'] > 0) {
                $msg .= sprintf(' Skipped %d.', (int)$ar['skipped']);
            }
            if ($ar['errors']) {
                App::flash('warning', $msg . ' ' . implode(' ', array_slice($ar['errors'], 0, 5)));
            } else {
                App::flash('success', $msg);
            }
            AuditService::log((int)$user['user_id'], $user['username'], 'work_order_apply', 'work_order', $woId, $ar);
            App::redirect('pages/work_orders.php?id=' . $woId);
        }

        if ($action === 'toggle_checklist') {
            $woId = (int)($_POST['work_order_id'] ?? 0);
            $checkId = (string)($_POST['check_id'] ?? '');
            $wo = Database::fetchOne('SELECT * FROM work_orders WHERE work_order_id = ?', [$woId]);
            if (!$wo) {
                throw new RuntimeException('Work order not found.');
            }
            if (in_array((string)$wo['status'], ['completed', 'cancelled'], true)) {
                throw new RuntimeException('Cannot change checklist on a closed work order.');
            }
            $list = work_order_checklist_decode($wo['checklist_json'] ?? null);
            if ($list === []) {
                $list = work_order_default_checklist((string)($wo['work_type'] ?? 'move'));
            }
            $now = date('Y-m-d H:i:s');
            $who = (string)($user['display_name'] ?? $user['username'] ?? '');
            foreach ($list as &$c) {
                if ($c['id'] === $checkId) {
                    $c['done'] = empty($c['done']);
                    $c['done_at'] = $c['done'] ? $now : null;
                    $c['done_by'] = $c['done'] ? $who : null;
                }
            }
            unset($c);
            Database::update('work_orders', [
                'checklist_json' => work_order_checklist_encode($list),
                'updated_at' => $now,
            ], 'work_order_id = :id', [':id' => $woId]);
            App::redirect('pages/work_orders.php?id=' . $woId . '#checklist');
        }

        if ($action === 'add_item') {
            $woId = (int)($_POST['work_order_id'] ?? 0);
            $deviceId = (int)($_POST['device_id'] ?? 0);
            $wo = Database::fetchOne('SELECT * FROM work_orders WHERE work_order_id = ?', [$woId]);
            if (!$wo) {
                throw new RuntimeException('Work order not found.');
            }
            if (in_array((string)$wo['status'], ['completed', 'cancelled'], true)) {
                throw new RuntimeException('Cannot add items to a closed work order.');
            }
            if ($deviceId <= 0) {
                throw new RuntimeException('Select a device.');
            }
            $dev = Database::fetchOne(
                'SELECT device_id, cabinet_id, position_u, label FROM devices WHERE device_id = ? AND is_active = 1',
                [$deviceId]
            );
            if (!$dev) {
                throw new RuntimeException('Device not found.');
            }
            $exists = Database::fetchOne(
                'SELECT item_id FROM work_order_items WHERE work_order_id = ? AND device_id = ?',
                [$woId, $deviceId]
            );
            if ($exists) {
                throw new RuntimeException('Device is already on this work order.');
            }
            $maxSort = (int)Database::fetchValue(
                'SELECT ISNULL(MAX(sort_order),0) FROM work_order_items WHERE work_order_id = ?',
                [$woId]
            );
            $toCab = !empty($_POST['to_cabinet_id']) ? (int)$_POST['to_cabinet_id'] : null;
            $toU = isset($_POST['to_position_u']) && $_POST['to_position_u'] !== ''
                ? (int)$_POST['to_position_u'] : null;
            Database::insert('work_order_items', [
                'work_order_id' => $woId,
                'device_id' => $deviceId,
                'from_cabinet_id' => $dev['cabinet_id'] !== null ? (int)$dev['cabinet_id'] : null,
                'from_position_u' => $dev['position_u'] !== null ? (int)$dev['position_u'] : null,
                'to_cabinet_id' => $toCab,
                'to_position_u' => $toU,
                'item_status' => 'pending',
                'notes' => work_order_null($_POST['item_notes'] ?? null),
                'sort_order' => $maxSort + 1,
            ]);
            Database::update('work_orders', ['updated_at' => date('Y-m-d H:i:s')], 'work_order_id = :id', [':id' => $woId]);
            App::flash('success', 'Added ' . (string)$dev['label'] . '.');
            App::redirect('pages/work_orders.php?id=' . $woId . '#items');
        }

        if ($action === 'sdp_create' || $action === 'itsm_create') {
            $woId = (int)($_POST['work_order_id'] ?? 0);
            if (!work_order_itsm_ready()) {
                throw new RuntimeException('Ticketing is not configured.');
            }
            $link = ItsmService::createFromWorkOrder($woId);
            AuditService::log((int)$user['user_id'], $user['username'], 'work_order_itsm_create', 'work_order', $woId, $link);
            $disp = (string)($link['display_id'] ?: $link['id']);
            App::flash('success', ItsmService::label() . ' ticket #' . $disp . ' created.');
            App::redirect('pages/work_orders.php?id=' . $woId);
        }

        if ($action === 'sdp_link' || $action === 'itsm_link') {
            $woId = (int)($_POST['work_order_id'] ?? 0);
            $ticket = trim((string)($_POST['sdp_ticket'] ?? $_POST['itsm_ticket'] ?? ''));
            if (!work_order_itsm_ready()) {
                throw new RuntimeException('Ticketing is not configured.');
            }
            $link = ItsmService::linkExisting($woId, $ticket);
            AuditService::log((int)$user['user_id'], $user['username'], 'work_order_itsm_link', 'work_order', $woId, $link);
            $disp = (string)($link['display_id'] ?: $link['id']);
            App::flash('success', 'Linked ' . ItsmService::label() . ' ticket #' . $disp . '.');
            App::redirect('pages/work_orders.php?id=' . $woId);
        }

        if ($action === 'sdp_unlink' || $action === 'itsm_unlink') {
            $woId = (int)($_POST['work_order_id'] ?? 0);
            $wo = Database::fetchOne('SELECT * FROM work_orders WHERE work_order_id = ?', [$woId]);
            if (!$wo) {
                throw new RuntimeException('Work order not found.');
            }
            if (class_exists('ItsmService')) {
                ItsmService::unlink($woId);
            }
            AuditService::log((int)$user['user_id'], $user['username'], 'work_order_itsm_unlink', 'work_order', $woId, [
                'itsm_request_id' => $wo['itsm_request_id'] ?? null,
            ]);
            App::flash('success', 'Unlinked ticketing ticket (the remote ticket was not deleted).');
            App::redirect('pages/work_orders.php?id=' . $woId);
        }

        if ($action === 'sdp_pull' || $action === 'itsm_pull') {
            $woId = (int)($_POST['work_order_id'] ?? 0);
            if (!work_order_itsm_ready()) {
                throw new RuntimeException('Ticketing is not configured.');
            }
            $result = ItsmService::pullFromWorkOrder($woId);
            AuditService::log((int)$user['user_id'], $user['username'], 'work_order_itsm_pull', 'work_order', $woId, $result);
            App::flash('success', (string)($result['detail'] ?? 'Pulled the latest remote ticket.'));
            App::redirect('pages/work_orders.php?id=' . $woId);
        }

        if ($action === 'sdp_push_note' || $action === 'itsm_push_note') {
            $woId = (int)($_POST['work_order_id'] ?? 0);
            if (!work_order_itsm_ready()) {
                throw new RuntimeException('Ticketing is not configured.');
            }
            $wo = Database::fetchOne('SELECT * FROM work_orders WHERE work_order_id = ?', [$woId]);
            if (!$wo) {
                throw new RuntimeException('Work order not found.');
            }
            $st = (string)($wo['status'] ?? '');
            if (trim((string)($wo['itsm_request_id'] ?? '')) === '') {
                throw new RuntimeException('This work order is not linked to a ticketing ticket.');
            }
            $who = (string)($user['display_name'] ?? $user['username'] ?? 'ColdAisle');
            $stLab = $statuses[$st] ?? $st;
            $html = '<p>ColdAisle work order <strong>' . htmlspecialchars((string)$wo['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '</strong> (#' . $woId . ') is <strong>' . htmlspecialchars($stLab, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '</strong> (note by ' . htmlspecialchars($who, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ').</p>'
                . '<p><a href="' . htmlspecialchars(App::url('pages/work_orders.php?id=' . $woId), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '">Open work order</a></p>';
            ItsmService::addNote($woId, $html, false);
            App::flash('success', 'Posted a status note to ' . ItsmService::label() . '.');
            App::redirect('pages/work_orders.php?id=' . $woId);
        }

        if ($action === 'update_item') {
            $itemId = (int)($_POST['item_id'] ?? 0);
            $woId = (int)($_POST['work_order_id'] ?? 0);
            $item = Database::fetchOne(
                'SELECT * FROM work_order_items WHERE item_id = ? AND work_order_id = ?',
                [$itemId, $woId]
            );
            if (!$item) {
                throw new RuntimeException('Item not found.');
            }
            $wo = Database::fetchOne('SELECT status FROM work_orders WHERE work_order_id = ?', [$woId]);
            if (!$wo || in_array((string)$wo['status'], ['completed', 'cancelled'], true)) {
                throw new RuntimeException('Cannot edit items on a closed work order.');
            }
            $itemStatus = (string)($_POST['item_status'] ?? $item['item_status']);
            if (!isset($itemStatuses[$itemStatus])) {
                $itemStatus = (string)$item['item_status'];
            }
            $patch = [
                'to_cabinet_id' => !empty($_POST['to_cabinet_id']) ? (int)$_POST['to_cabinet_id'] : null,
                'to_position_u' => isset($_POST['to_position_u']) && $_POST['to_position_u'] !== ''
                    ? (int)$_POST['to_position_u'] : null,
                'item_status' => $itemStatus,
                'notes' => work_order_null($_POST['item_notes'] ?? null),
            ];
            if ($itemStatus === 'done' && empty($item['completed_at'])) {
                $patch['completed_at'] = date('Y-m-d H:i:s');
            }
            if ($itemStatus !== 'done') {
                $patch['completed_at'] = null;
            }
            Database::update('work_order_items', $patch, 'item_id = :id', [':id' => $itemId]);
            Database::update('work_orders', ['updated_at' => date('Y-m-d H:i:s')], 'work_order_id = :id', [':id' => $woId]);
            App::flash('success', 'Item updated.');
            App::redirect('pages/work_orders.php?id=' . $woId . '#items');
        }

        if ($action === 'remove_item') {
            $itemId = (int)($_POST['item_id'] ?? 0);
            $woId = (int)($_POST['work_order_id'] ?? 0);
            $wo = Database::fetchOne('SELECT status FROM work_orders WHERE work_order_id = ?', [$woId]);
            if (!$wo || !in_array((string)$wo['status'], ['draft', 'planned'], true)) {
                throw new RuntimeException('Items can only be removed in draft or planned status.');
            }
            Database::delete('work_order_items', 'item_id = ? AND work_order_id = ?', [$itemId, $woId]);
            Database::update('work_orders', ['updated_at' => date('Y-m-d H:i:s')], 'work_order_id = :id', [':id' => $woId]);
            App::flash('success', 'Item removed.');
            App::redirect('pages/work_orders.php?id=' . $woId . '#items');
        }

        if ($action === 'add_from_cabinet') {
            $woId = (int)($_POST['work_order_id'] ?? 0);
            $fromCab = (int)($_POST['from_cabinet_id'] ?? 0);
            $toCab = !empty($_POST['to_cabinet_id']) ? (int)$_POST['to_cabinet_id'] : null;
            $wo = Database::fetchOne('SELECT * FROM work_orders WHERE work_order_id = ?', [$woId]);
            if (!$wo) {
                throw new RuntimeException('Work order not found.');
            }
            if (in_array((string)$wo['status'], ['completed', 'cancelled'], true)) {
                throw new RuntimeException('Cannot add items to a closed work order.');
            }
            if ($fromCab <= 0) {
                throw new RuntimeException('Select a source cabinet.');
            }
            $cab = Database::fetchOne(
                'SELECT cabinet_id, name FROM cabinets WHERE cabinet_id = ? AND is_active = 1',
                [$fromCab]
            );
            if (!$cab) {
                throw new RuntimeException('Source cabinet not found.');
            }
            $ar = work_order_add_from_cabinet($woId, $fromCab, $toCab, true);
            $msg = sprintf(
                'From %s: added %d device(s), skipped %d already on this WO.',
                (string)$cab['name'],
                (int)$ar['added'],
                (int)$ar['skipped']
            );
            if ($ar['errors'] && $ar['added'] < 1) {
                throw new RuntimeException($msg . ' ' . implode(' ', array_slice($ar['errors'], 0, 2)));
            }
            AuditService::log((int)$user['user_id'], $user['username'], 'work_order_bulk_cabinet', 'work_order', $woId, [
                'from_cabinet_id' => $fromCab,
                'to_cabinet_id' => $toCab,
                'added' => $ar['added'],
                'skipped' => $ar['skipped'],
            ]);
            if ($ar['errors'] && $ar['added'] > 0) {
                App::flash('warning', $msg);
            } else {
                App::flash('success', $msg);
            }
            App::redirect('pages/work_orders.php?id=' . $woId . '#items');
        }
    } catch (Throwable $e) {
        App::flash('error', $e->getMessage());
        $rid = (int)($_POST['work_order_id'] ?? 0);
        App::redirect($rid > 0 ? 'pages/work_orders.php?id=' . $rid : 'pages/work_orders.php');
    }
}

// ---------- Detail ----------
if ($id > 0) {
    $wo = null;
    try {
        $wo = Database::fetchOne(
            'SELECT w.*,
                    ru.display_name AS requested_by_name, ru.username AS requested_by_user,
                    au.display_name AS assigned_to_name, au.username AS assigned_to_user
             FROM work_orders w
             LEFT JOIN users ru ON ru.user_id = w.requested_by
             LEFT JOIN users au ON au.user_id = w.assigned_to
             WHERE w.work_order_id = ?',
            [$id]
        );
    } catch (Throwable $e) {
        $wo = null;
    }
    if (!$wo) {
        App::flash('error', 'Work order not found.');
        App::redirect('pages/work_orders.php');
    }

    $items = [];
    try {
        $items = Database::fetchAll(
            'SELECT i.*,
                    d.label AS device_label, d.serial_no, d.asset_tag,
                    fc.name AS from_cabinet_name,
                    tc.name AS to_cabinet_name
             FROM work_order_items i
             INNER JOIN devices d ON d.device_id = i.device_id
             LEFT JOIN cabinets fc ON fc.cabinet_id = i.from_cabinet_id
             LEFT JOIN cabinets tc ON tc.cabinet_id = i.to_cabinet_id
             WHERE i.work_order_id = ?
             ORDER BY i.sort_order, i.item_id',
            [$id]
        );
    } catch (Throwable $e) {
        $items = [];
    }

    $checklist = work_order_checklist_decode($wo['checklist_json'] ?? null);
    if ($checklist === []) {
        $checklist = work_order_default_checklist((string)($wo['work_type'] ?? 'move'));
    }
    $checkDone = count(array_filter($checklist, static fn($c) => !empty($c['done'])));
    $checkTotal = count($checklist);

    $cabinets = Database::fetchAll(
        'SELECT c.cabinet_id, c.name, c.u_height, r.name AS room_name
         FROM cabinets c
         LEFT JOIN rooms r ON r.room_id = c.room_id
         WHERE c.is_active = 1
         ORDER BY c.name'
    );
    $devices = Database::fetchAll(
        'SELECT device_id, label, cabinet_id, position_u
         FROM devices WHERE is_active = 1 ORDER BY label'
    );
    $users = [];
    try {
        $users = Database::fetchAll(
            'SELECT user_id, display_name, username FROM users WHERE is_active = 1 ORDER BY display_name, username'
        );
    } catch (Throwable $e) {
        $users = [];
    }

    $st = (string)$wo['status'];
    $closed = in_array($st, ['completed', 'cancelled'], true);
    $statusCls = work_order_status_badge_class($st);

    layout_header('Work order: ' . (string)$wo['title'], $user, 'work_orders');
    ?>
    <div class="flex-between mb-2">
        <div>
            <span class="badge <?= App::e($statusCls) ?>"><?= App::e($statuses[$st] ?? $st) ?></span>
            <span class="badge"><?= App::e($types[$wo['work_type'] ?? ''] ?? (string)$wo['work_type']) ?></span>
            <?php if (!empty($wo['change_ticket'])): ?>
                <span class="text-muted" style="font-size:.9rem">Ticket <?= App::e((string)$wo['change_ticket']) ?></span>
            <?php endif; ?>
            <?php if (!empty($wo['itsm_request_id']) || !empty($wo['itsm_display_id'])): ?>
                <?php
                $sdpHref = trim((string)($wo['itsm_url'] ?? ''));
                $sdpLab = (string)($wo['itsm_display_id'] ?: $wo['itsm_request_id']);
                ?>
                <span class="badge badge-info"><?= App::e(class_exists('ItsmService') ? ItsmService::label((string)($wo['itsm_provider'] ?? '')) : 'ITSM') ?> #<?= App::e($sdpLab) ?></span>
                <?php if ($sdpHref !== ''): ?>
                    <a href="<?= App::e($sdpHref) ?>" target="_blank" rel="noopener" style="font-size:.85rem">Open ticket</a>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <div class="flex gap-1" style="flex-wrap:wrap">
            <a class="btn btn-secondary" href="<?= App::e(App::url('pages/work_orders.php')) ?>">← All work orders</a>
            <?php if ($canEdit && !$closed): ?>
                <?php if ($st === 'draft'): ?>
                    <form method="post" style="display:inline">
                        <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                        <input type="hidden" name="action" value="set_status">
                        <input type="hidden" name="work_order_id" value="<?= $id ?>">
                        <input type="hidden" name="status" value="planned">
                        <button class="btn btn-primary" type="submit">Mark planned</button>
                    </form>
                <?php endif; ?>
                <?php if ($st === 'planned'): ?>
                    <form method="post" style="display:inline">
                        <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                        <input type="hidden" name="action" value="set_status">
                        <input type="hidden" name="work_order_id" value="<?= $id ?>">
                        <input type="hidden" name="status" value="in_progress">
                        <button class="btn btn-primary" type="submit">Start work</button>
                    </form>
                <?php endif; ?>
                <?php if ($st === 'in_progress'): ?>
                    <form method="post" style="display:inline"
                          onsubmit="return confirm('Complete this work order?');">
                        <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                        <input type="hidden" name="action" value="set_status">
                        <input type="hidden" name="work_order_id" value="<?= $id ?>">
                        <input type="hidden" name="status" value="completed">
                        <label style="font-size:.8rem;margin-right:.4rem">
                            <input type="checkbox" name="apply_inventory" value="1" checked>
                            Apply destinations to inventory
                        </label>
                        <button class="btn btn-primary" type="submit">Complete</button>
                    </form>
                    <form method="post" style="display:inline"
                          onsubmit="return confirm('Update device cabinet/U from done items now?');">
                        <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                        <input type="hidden" name="action" value="apply_inventory">
                        <input type="hidden" name="work_order_id" value="<?= $id ?>">
                        <button class="btn btn-secondary" type="submit">Apply inventory now</button>
                    </form>
                <?php endif; ?>
                <?php if (in_array($st, ['draft', 'planned', 'in_progress'], true)): ?>
                    <form method="post" style="display:inline"
                          onsubmit="return confirm('Cancel this work order? Devices are not moved.');">
                        <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                        <input type="hidden" name="action" value="set_status">
                        <input type="hidden" name="work_order_id" value="<?= $id ?>">
                        <input type="hidden" name="status" value="cancelled">
                        <button class="btn btn-ghost" type="submit">Cancel</button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
            <?php if ($canEdit && $st === 'completed'): ?>
                <form method="post" style="display:inline"
                      onsubmit="return confirm('Re-apply destinations for done items?');">
                    <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                    <input type="hidden" name="action" value="apply_inventory">
                    <input type="hidden" name="work_order_id" value="<?= $id ?>">
                    <button class="btn btn-secondary btn-sm" type="submit">Re-apply inventory</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="metrics mb-2">
        <div class="metric-card">
            <div class="label">Devices</div>
            <div class="value"><?= count($items) ?></div>
            <div class="sub">
                <?= count(array_filter($items, static fn($i) => ($i['item_status'] ?? '') === 'done')) ?> done
            </div>
        </div>
        <div class="metric-card accent">
            <div class="label">Checklist</div>
            <div class="value"><?= (int)$checkDone ?>/<?= (int)$checkTotal ?></div>
        </div>
        <div class="metric-card">
            <div class="label">Scheduled</div>
            <div class="value" style="font-size:1.1rem">
                <?= !empty($wo['scheduled_date']) ? App::e((string)$wo['scheduled_date']) : '—' ?>
            </div>
        </div>
        <div class="metric-card">
            <div class="label">Requested by</div>
            <div class="value" style="font-size:1rem">
                <?= App::e((string)($wo['requested_by_name'] ?: $wo['requested_by_user'] ?: '—')) ?>
            </div>
        </div>
    </div>

    <div class="split-2" style="align-items:start">
        <div class="card mb-2">
            <div class="card-header"><h2>Details</h2></div>
            <div class="card-body">
                <?php if ($canEdit && !$closed): ?>
                <form method="post" class="form-grid">
                    <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                    <input type="hidden" name="action" value="update_header">
                    <input type="hidden" name="work_order_id" value="<?= $id ?>">
                    <div class="form-row full"><label>Title</label>
                        <input class="form-control" name="title" required value="<?= App::e((string)$wo['title']) ?>"></div>
                    <div class="form-row"><label>Type</label>
                        <select class="form-control" name="work_type">
                            <?php foreach ($types as $k => $lab): ?>
                                <option value="<?= App::e($k) ?>" <?= ($wo['work_type'] ?? '') === $k ? 'selected' : '' ?>>
                                    <?= App::e($lab) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-row"><label>Change ticket</label>
                        <input class="form-control" name="change_ticket" value="<?= App::e((string)($wo['change_ticket'] ?? '')) ?>"
                               placeholder="CHG-12345"></div>
                    <div class="form-row"><label>Scheduled date</label>
                        <input class="form-control" type="date" name="scheduled_date"
                               value="<?= App::e((string)($wo['scheduled_date'] ?? '')) ?>"></div>
                    <div class="form-row"><label>Assigned to</label>
                        <select class="form-control" name="assigned_to">
                            <option value="">—</option>
                            <?php foreach ($users as $u): ?>
                                <option value="<?= (int)$u['user_id'] ?>"
                                    <?= (int)($wo['assigned_to'] ?? 0) === (int)$u['user_id'] ? 'selected' : '' ?>>
                                    <?= App::e((string)($u['display_name'] ?: $u['username'])) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-row full"><label>Notes</label>
                        <textarea class="form-control" name="notes" rows="3"><?= App::e((string)($wo['notes'] ?? '')) ?></textarea>
                    </div>
                    <div class="form-row full">
                        <button class="btn btn-primary" type="submit">Save details</button>
                    </div>
                </form>
                <?php else: ?>
                <dl class="detail-grid" style="margin:0">
                    <dt>Title</dt><dd><?= App::e((string)$wo['title']) ?></dd>
                    <dt>Ticket</dt><dd><?= App::e((string)($wo['change_ticket'] ?: '—')) ?></dd>
                    <dt>Assigned</dt>
                    <dd><?= App::e((string)($wo['assigned_to_name'] ?: $wo['assigned_to_user'] ?: '—')) ?></dd>
                    <dt>Notes</dt><dd><?= nl2br(App::e((string)($wo['notes'] ?: '—'))) ?></dd>
                    <?php if (!empty($wo['completed_at'])): ?>
                        <dt>Completed</dt><dd><?= App::e((string)$wo['completed_at']) ?></dd>
                    <?php endif; ?>
                </dl>
                <?php endif; ?>
            </div>
        </div>

        <?php
        $itsmName = class_exists('ItsmService') ? ItsmService::label() : 'Ticketing';
        $itsmReady = work_order_itsm_ready();
        ?>
        <?php if ($itsmReady || !empty($wo['itsm_request_id']) || !empty($wo['itsm_display_id'])): ?>
        <div class="card mb-2" id="ticketing">
            <div class="card-header flex-between">
                <h2><?= App::e($itsmName) ?></h2>
                <?php if (!empty($wo['itsm_last_sync_at'])): ?>
                    <span class="text-muted" style="font-size:.8rem">Last sync <?= App::e((string)$wo['itsm_last_sync_at']) ?></span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if (!empty($wo['itsm_last_error'])): ?>
                    <p class="alert alert-warning" style="font-size:.85rem"><?= App::e((string)$wo['itsm_last_error']) ?></p>
                <?php endif; ?>
                <?php
                $sdpLinked = !empty($wo['itsm_request_id']) || !empty($wo['itsm_display_id']);
                $sdpHref = trim((string)($wo['itsm_url'] ?? ''));
                $sdpLab = (string)($wo['itsm_display_id'] ?: $wo['change_ticket'] ?: $wo['itsm_request_id'] ?: '');
                ?>
                <?php if ($sdpLinked): ?>
                    <p style="margin-top:0">
                        Linked request
                        <strong>#<?= App::e($sdpLab !== '' ? $sdpLab : '—') ?></strong>
                        <?php if ($sdpHref !== ''): ?>
                            · <a href="<?= App::e($sdpHref) ?>" target="_blank" rel="noopener">Open in <?= App::e($itsmName) ?></a>
                        <?php endif; ?>
                    </p>
                    <?php if ($canEdit && $itsmReady): ?>
                        <div class="flex gap-1" style="flex-wrap:wrap">
                            <form method="post" style="display:inline">
                                <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                                <input type="hidden" name="action" value="itsm_pull">
                                <input type="hidden" name="work_order_id" value="<?= $id ?>">
                                <button class="btn btn-secondary btn-sm" type="submit">Refresh from <?= App::e($itsmName) ?></button>
                            </form>
                            <form method="post" style="display:inline">
                                <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                                <input type="hidden" name="action" value="itsm_push_note">
                                <input type="hidden" name="work_order_id" value="<?= $id ?>">
                                <button class="btn btn-secondary btn-sm" type="submit">Push status note</button>
                            </form>
                            <form method="post" style="display:inline"
                                  onsubmit="return confirm('Unlink this ticket? The remote ticket is not deleted.');">
                                <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                                <input type="hidden" name="action" value="itsm_unlink">
                                <input type="hidden" name="work_order_id" value="<?= $id ?>">
                                <button class="btn btn-ghost btn-sm" type="submit">Unlink</button>
                            </form>
                        </div>
                    <?php endif; ?>
                <?php elseif ($canEdit && $itsmReady && !$closed): ?>
                    <p class="text-muted" style="font-size:.85rem;margin-top:0">
                        No remote ticket yet. Create one now, or paste an existing id / number / key.
                    </p>
                    <form method="post" style="margin-bottom:.75rem">
                        <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                        <input type="hidden" name="action" value="itsm_create">
                        <input type="hidden" name="work_order_id" value="<?= $id ?>">
                        <button class="btn btn-primary btn-sm" type="submit">Create <?= App::e($itsmName) ?> ticket</button>
                    </form>
                    <form method="post" class="form-grid">
                        <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                        <input type="hidden" name="action" value="itsm_link">
                        <input type="hidden" name="work_order_id" value="<?= $id ?>">
                        <div class="form-row"><label>Existing ticket</label>
                            <input class="form-control" name="itsm_ticket" placeholder="Number, key, or id" required></div>
                        <div class="form-row">
                            <button class="btn btn-secondary" type="submit">Link existing</button>
                        </div>
                    </form>
                <?php else: ?>
                    <p class="text-muted mb-0" style="font-size:.85rem">
                        Configure a ticketing system under
                        <a href="<?= App::e(App::url('pages/settings.php#sdp')) ?>">Settings → Ticketing</a>.
                    </p>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="card mb-2" id="checklist">
            <div class="card-header flex-between">
                <h2>Checklist</h2>
                <span class="text-muted" style="font-size:.85rem"><?= (int)$checkDone ?>/<?= (int)$checkTotal ?></span>
            </div>
            <div class="card-body">
                <?php if (!$checklist): ?>
                    <p class="text-muted mb-0">No checklist items.</p>
                <?php else: ?>
                    <ul style="list-style:none;padding:0;margin:0">
                    <?php foreach ($checklist as $c): ?>
                        <li style="display:flex;align-items:flex-start;gap:.6rem;padding:.4rem 0;border-bottom:1px solid var(--border,#334155)">
                            <?php if ($canEdit && !$closed): ?>
                            <form method="post" style="margin:0">
                                <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                                <input type="hidden" name="action" value="toggle_checklist">
                                <input type="hidden" name="work_order_id" value="<?= $id ?>">
                                <input type="hidden" name="check_id" value="<?= App::e((string)$c['id']) ?>">
                                <button type="submit" class="btn btn-sm <?= !empty($c['done']) ? 'btn-primary' : 'btn-secondary' ?>"
                                        title="Toggle">
                                    <?= !empty($c['done']) ? '✓' : '○' ?>
                                </button>
                            </form>
                            <?php else: ?>
                                <span><?= !empty($c['done']) ? '✓' : '○' ?></span>
                            <?php endif; ?>
                            <div>
                                <div style="<?= !empty($c['done']) ? 'text-decoration:line-through;opacity:.75' : '' ?>">
                                    <?= App::e((string)$c['label']) ?>
                                </div>
                                <?php if (!empty($c['done']) && (!empty($c['done_by']) || !empty($c['done_at']))): ?>
                                    <div class="text-muted" style="font-size:.72rem">
                                        <?= App::e(trim(($c['done_by'] ?? '') . ' · ' . ($c['done_at'] ?? ''), ' ·')) ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="card mb-2" id="items">
        <div class="card-header flex-between">
            <h2>Devices</h2>
            <span class="text-muted" style="font-size:.85rem"><?= count($items) ?> line(s)</span>
        </div>
        <div class="card-body flush">
            <table class="data">
                <thead>
                <tr>
                    <th>Device</th>
                    <th>From</th>
                    <th>To</th>
                    <th>Status</th>
                    <th>Notes</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($items as $it): ?>
                    <tr>
                        <td>
                            <a href="<?= App::e(App::url('pages/devices.php?id=' . (int)$it['device_id'])) ?>">
                                <?= App::e((string)$it['device_label']) ?>
                            </a>
                            <?php if (!empty($it['serial_no']) || !empty($it['asset_tag'])): ?>
                                <div class="text-muted" style="font-size:.72rem">
                                    <?= App::e(trim(($it['serial_no'] ?? '') . ' / ' . ($it['asset_tag'] ?? ''), ' /')) ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:.85rem">
                            <?= App::e((string)($it['from_cabinet_name'] ?: '—')) ?>
                            <?php if ($it['from_position_u'] !== null && $it['from_position_u'] !== ''): ?>
                                U<?= (int)$it['from_position_u'] ?>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:.85rem">
                            <?php if ($canEdit && !$closed): ?>
                            <form method="post" class="form-grid" style="gap:.35rem;grid-template-columns:1fr 4rem auto;max-width:22rem">
                                <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                                <input type="hidden" name="action" value="update_item">
                                <input type="hidden" name="work_order_id" value="<?= $id ?>">
                                <input type="hidden" name="item_id" value="<?= (int)$it['item_id'] ?>">
                                <select class="form-control" name="to_cabinet_id" style="font-size:.8rem">
                                    <option value="">— cabinet —</option>
                                    <?php foreach ($cabinets as $c): ?>
                                        <option value="<?= (int)$c['cabinet_id'] ?>"
                                            <?= (int)($it['to_cabinet_id'] ?? 0) === (int)$c['cabinet_id'] ? 'selected' : '' ?>>
                                            <?= App::e((string)$c['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <input class="form-control" type="number" name="to_position_u" min="1" max="60"
                                       placeholder="U" style="font-size:.8rem"
                                       value="<?= $it['to_position_u'] !== null && $it['to_position_u'] !== '' ? (int)$it['to_position_u'] : '' ?>">
                                <input type="hidden" name="item_status" value="<?= App::e((string)$it['item_status']) ?>">
                                <input type="hidden" name="item_notes" value="<?= App::e((string)($it['notes'] ?? '')) ?>">
                                <button class="btn btn-sm btn-secondary" type="submit">Save</button>
                            </form>
                            <?php else: ?>
                                <?= App::e((string)($it['to_cabinet_name'] ?: '—')) ?>
                                <?php if ($it['to_position_u'] !== null && $it['to_position_u'] !== ''): ?>
                                    U<?= (int)$it['to_position_u'] ?>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($canEdit && !$closed): ?>
                            <form method="post" style="display:inline">
                                <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                                <input type="hidden" name="action" value="update_item">
                                <input type="hidden" name="work_order_id" value="<?= $id ?>">
                                <input type="hidden" name="item_id" value="<?= (int)$it['item_id'] ?>">
                                <input type="hidden" name="to_cabinet_id" value="<?= (int)($it['to_cabinet_id'] ?? 0) ?>">
                                <input type="hidden" name="to_position_u" value="<?= App::e((string)($it['to_position_u'] ?? '')) ?>">
                                <input type="hidden" name="item_notes" value="<?= App::e((string)($it['notes'] ?? '')) ?>">
                                <select class="form-control" name="item_status" style="font-size:.8rem;width:auto"
                                        onchange="this.form.submit()">
                                    <?php foreach ($itemStatuses as $ik => $il): ?>
                                        <option value="<?= App::e($ik) ?>" <?= ($it['item_status'] ?? '') === $ik ? 'selected' : '' ?>>
                                            <?= App::e($il) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </form>
                            <?php else: ?>
                                <span class="badge"><?= App::e($itemStatuses[$it['item_status'] ?? ''] ?? (string)$it['item_status']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="text-muted" style="font-size:.8rem"><?= App::e((string)($it['notes'] ?? '')) ?></td>
                        <td class="actions">
                            <?php if ($canEdit && in_array($st, ['draft', 'planned'], true)): ?>
                            <form method="post" style="display:inline"
                                  onsubmit="return confirm('Remove this device from the work order?');">
                                <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                                <input type="hidden" name="action" value="remove_item">
                                <input type="hidden" name="work_order_id" value="<?= $id ?>">
                                <input type="hidden" name="item_id" value="<?= (int)$it['item_id'] ?>">
                                <button class="btn btn-sm btn-ghost" type="submit">Remove</button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$items): ?>
                    <tr><td colspan="6" class="text-muted">No devices yet. Add one below.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($canEdit && !$closed): ?>
        <div class="card-body" style="border-top:1px solid var(--border,#334155)">
            <form method="post" class="form-grid">
                <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                <input type="hidden" name="action" value="add_item">
                <input type="hidden" name="work_order_id" value="<?= $id ?>">
                <div class="form-row"><label>Add device</label>
                    <select class="form-control" name="device_id" required>
                        <option value="">— select —</option>
                        <?php
                        $onWo = array_fill_keys(array_map(static fn($i) => (int)$i['device_id'], $items), true);
                        foreach ($devices as $d):
                            if (isset($onWo[(int)$d['device_id']])) {
                                continue;
                            }
                            ?>
                            <option value="<?= (int)$d['device_id'] ?>">
                                <?= App::e((string)$d['label']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row"><label>To cabinet</label>
                    <select class="form-control" name="to_cabinet_id">
                        <option value="">— later —</option>
                        <?php foreach ($cabinets as $c): ?>
                            <option value="<?= (int)$c['cabinet_id'] ?>"><?= App::e((string)$c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row"><label>To U</label>
                    <input class="form-control" type="number" name="to_position_u" min="1" max="60" placeholder="optional"></div>
                <div class="form-row"><label>Notes</label>
                    <input class="form-control" name="item_notes"></div>
                <div class="form-row" style="align-self:end">
                    <button class="btn btn-primary" type="submit">Add device</button>
                </div>
            </form>
            <p class="text-muted mb-0" style="font-size:.75rem;margin-top:.5rem">
                Source cabinet/U is snapshotted from inventory when the device is added.
            </p>
            <hr style="border:none;border-top:1px solid var(--border,#334155);margin:1rem 0">
            <strong style="font-size:.9rem">Bulk add from cabinet</strong>
            <p class="text-muted" style="font-size:.8rem;margin:.35rem 0 .5rem">
                Adds all active rack-mounted devices in the source cabinet (skips blades/modules and devices already on this WO).
                Optional shared destination cabinet; set U positions per line after.
            </p>
            <form method="post" class="form-grid"
                  onsubmit="return confirm('Add all rack devices from the selected source cabinet?');">
                <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                <input type="hidden" name="action" value="add_from_cabinet">
                <input type="hidden" name="work_order_id" value="<?= $id ?>">
                <div class="form-row"><label>Source cabinet</label>
                    <select class="form-control" name="from_cabinet_id" required>
                        <option value="">— select —</option>
                        <?php foreach ($cabinets as $c): ?>
                            <option value="<?= (int)$c['cabinet_id'] ?>">
                                <?= App::e((string)$c['name']) ?>
                                <?php if (!empty($c['room_name'])): ?>
                                    (<?= App::e((string)$c['room_name']) ?>)
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row"><label>Shared destination (optional)</label>
                    <select class="form-control" name="to_cabinet_id">
                        <option value="">— set per device later —</option>
                        <?php foreach ($cabinets as $c): ?>
                            <option value="<?= (int)$c['cabinet_id'] ?>"><?= App::e((string)$c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row" style="align-self:end">
                    <button class="btn btn-secondary" type="submit">Add all from cabinet</button>
                </div>
            </form>
        </div>
        <?php endif; ?>
    </div>

    <?php
    // Cable pathways for devices on this WO (calculate / show raceway routes)
    $woCables = [];
    $deviceIdsOnWo = array_values(array_unique(array_map(
        static fn($it) => (int)($it['device_id'] ?? 0),
        $items
    )));
    $deviceIdsOnWo = array_values(array_filter($deviceIdsOnWo, static fn($d) => $d > 0));
    if ($deviceIdsOnWo !== [] && class_exists('CableRouteService')) {
        try {
            $ph = implode(',', array_fill(0, count($deviceIdsOnWo), '?'));
            $woCables = Database::fetchAll(
                "SELECT c.cable_id, c.cable_label, c.media_type, c.speed, c.path_id, c.path_route_json,
                        da.device_id AS a_device_id, da.label AS a_device, da.cabinet_id AS a_cabinet_id,
                        ca.name AS a_cabinet_name, ca.room_id AS a_room_id,
                        db.device_id AS b_device_id, db.label AS b_device, db.cabinet_id AS b_cabinet_id,
                        cb.name AS b_cabinet_name, cb.room_id AS b_room_id,
                        cp.path_code, cp.name AS path_name
                 FROM cables c
                 LEFT JOIN device_ports pa ON pa.port_id = c.a_port_id
                 LEFT JOIN devices da ON da.device_id = pa.device_id
                 LEFT JOIN cabinets ca ON ca.cabinet_id = da.cabinet_id
                 LEFT JOIN device_ports pb ON pb.port_id = c.b_port_id
                 LEFT JOIN devices db ON db.device_id = pb.device_id
                 LEFT JOIN cabinets cb ON cb.cabinet_id = db.cabinet_id
                 LEFT JOIN cable_paths cp ON cp.path_id = c.path_id
                 WHERE (c.status IS NULL OR c.status <> 'retired')
                   AND (pa.device_id IN ($ph) OR pb.device_id IN ($ph))
                 ORDER BY c.cable_id",
                array_merge($deviceIdsOnWo, $deviceIdsOnWo)
            );
        } catch (Throwable $e) {
            $woCables = [];
        }
    }
    $canEditCables = AuthManager::can($user, 'edit_cables')
        || AuthManager::can($user, 'edit_infrastructure')
        || AuthManager::isAdmin($user);
    ?>
    <div class="card mb-2" id="cable-paths">
        <div class="card-header flex-between">
            <h2>Cable pathways</h2>
            <a class="btn btn-ghost btn-sm" href="<?= App::e(App::url('pages/cables.php')) ?>">Cabling</a>
        </div>
        <div class="card-body flush">
            <?php if (!$items): ?>
                <p class="text-muted" style="padding:1rem;margin:0">Add devices to this work order first.</p>
            <?php elseif (!$woCables): ?>
                <p class="text-muted" style="padding:1rem;margin:0">
                    No cable connections recorded for devices on this work order yet.
                    Record port-to-port links under Cabling, then use
                    <strong>Calculate shortest path</strong> to suggest a multi-hop raceway route
                    (e.g. Cabinet → RS-A → IRC → RS-B → Cabinet).
                </p>
            <?php else: ?>
                <table class="data">
                    <thead>
                    <tr>
                        <th>Cable</th>
                        <th>A</th>
                        <th>B</th>
                        <th>Route</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($woCables as $wc):
                        $hops = CableRouteService::pathIdsForCable($wc);
                        $roomFp = (int)($wc['a_room_id'] ?? $wc['b_room_id'] ?? 0);
                        $showUrl = App::url('pages/floorplan.php?' . http_build_query(array_filter([
                            'room_id' => $roomFp > 0 ? $roomFp : null,
                            'cable_id' => (int)$wc['cable_id'],
                            'show_routes' => 1,
                            'calculate' => 1,
                        ])));
                        $routeTxt = $hops !== []
                            ? (count($hops) . ' hop(s)' . (!empty($wc['path_code']) || !empty($wc['path_name'])
                                ? ' · ' . ($wc['path_code'] ?: $wc['path_name'])
                                : ''))
                            : '— no path —';
                        ?>
                        <tr>
                            <td>
                                <?= App::e($wc['cable_label'] ?: ('#' . $wc['cable_id'])) ?>
                                <?php if (!empty($wc['media_type'])): ?>
                                    <span class="text-muted" style="font-size:.75rem">(<?= App::e((string)$wc['media_type']) ?>)</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:.85rem">
                                <a href="<?= App::e(App::url('pages/devices.php?id=' . (int)$wc['a_device_id'])) ?>">
                                    <?= App::e((string)($wc['a_device'] ?? '—')) ?>
                                </a>
                                <?php if (!empty($wc['a_cabinet_name'])): ?>
                                    <div class="text-muted" style="font-size:.72rem"><?= App::e((string)$wc['a_cabinet_name']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:.85rem">
                                <a href="<?= App::e(App::url('pages/devices.php?id=' . (int)$wc['b_device_id'])) ?>">
                                    <?= App::e((string)($wc['b_device'] ?? '—')) ?>
                                </a>
                                <?php if (!empty($wc['b_cabinet_name'])): ?>
                                    <div class="text-muted" style="font-size:.72rem"><?= App::e((string)$wc['b_cabinet_name']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:.85rem"><?= App::e($routeTxt) ?></td>
                            <td style="white-space:nowrap">
                                <a class="btn btn-ghost btn-sm" href="<?= App::e($showUrl) ?>">Show path</a>
                                <?php if ($canEditCables && !empty($wc['a_cabinet_id']) && !empty($wc['b_cabinet_id'])): ?>
                                <button type="button" class="btn btn-secondary btn-sm wo-calc-path"
                                        data-cable-id="<?= (int)$wc['cable_id'] ?>"
                                        data-from="<?= (int)$wc['a_cabinet_id'] ?>"
                                        data-to="<?= (int)$wc['b_cabinet_id'] ?>">
                                    Calculate shortest path
                                </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="text-muted" style="font-size:.75rem;padding:.5rem 1rem;margin:0">
                    <strong>Calculate shortest path</strong> finds the shortest multi-hop raceway sequence
                    between cabinets and applies it to the cable. <strong>Show path</strong> draws it on the floor plan
                    (media color + speed end dots). Dashed lines are calculated previews when no path is saved yet.
                </p>
            <?php endif; ?>
        </div>
    </div>
    <?php if ($canEditCables && $woCables): ?>
    <script>
    (function () {
      if (!window.ColdAisle || !ColdAisle.api) return;
      document.querySelectorAll('.wo-calc-path').forEach(function (btn) {
        btn.addEventListener('click', function () {
          var cableId = Number(btn.getAttribute('data-cable-id') || 0);
          if (!(cableId > 0)) return;
          if (!confirm('Calculate shortest raceway route and apply it to this cable?')) return;
          btn.disabled = true;
          ColdAisle.api('api/cables.php?entity=routes', {
            method: 'POST',
            body: {
              action: 'calculate_and_apply',
              cable_id: cableId,
              from_cabinet_id: Number(btn.getAttribute('data-from') || 0),
              to_cabinet_id: Number(btn.getAttribute('data-to') || 0),
            },
          }).then(function (data) {
            btn.disabled = false;
            ColdAisle.toast(data.message || 'Route applied', 'success');
            window.location.reload();
          }).catch(function (e) {
            btn.disabled = false;
            ColdAisle.toast((e && e.message) || 'Route failed', 'error');
          });
        });
      });
    })();
    </script>
    <?php endif; ?>
    <?php
    layout_footer();
    return;
}

// ---------- New form ----------
if ($actionGet === 'new') {
    if (!$canEdit) {
        App::flash('error', 'You do not have permission to create work orders.');
        App::redirect('pages/work_orders.php');
    }
    $seed = null;
    if ($startDeviceId > 0) {
        $seed = Database::fetchOne(
            'SELECT device_id, label, cabinet_id, position_u FROM devices WHERE device_id = ? AND is_active = 1',
            [$startDeviceId]
        );
    }
    $cabinets = Database::fetchAll(
        'SELECT cabinet_id, name FROM cabinets WHERE is_active = 1 ORDER BY name'
    );
    $users = [];
    try {
        $users = Database::fetchAll(
            'SELECT user_id, display_name, username FROM users WHERE is_active = 1 ORDER BY display_name, username'
        );
    } catch (Throwable $e) {
        $users = [];
    }
    layout_header('New work order', $user, 'work_orders');
    ?>
    <div class="flex-between mb-2">
        <p class="text-muted mb-0">Plan a rack move or change with ticket, destinations, and checklist.</p>
        <a class="btn btn-secondary" href="<?= App::e(App::url('pages/work_orders.php')) ?>">Cancel</a>
    </div>
    <div class="card">
        <div class="card-body">
            <form method="post" class="form-grid">
                <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                <input type="hidden" name="action" value="create">
                <?php if ($seed): ?>
                    <input type="hidden" name="device_id" value="<?= (int)$seed['device_id'] ?>">
                <?php endif; ?>
                <div class="form-row full"><label>Title</label>
                    <input class="form-control" name="title" required
                           value="<?= $seed ? App::e('Move ' . (string)$seed['label']) : '' ?>"
                           placeholder="e.g. Move app servers Z1-RA → Z1-RB"></div>
                <div class="form-row"><label>Type</label>
                    <select class="form-control" name="work_type">
                        <?php foreach ($types as $k => $lab): ?>
                            <option value="<?= App::e($k) ?>"><?= App::e($lab) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row"><label>Change ticket</label>
                    <input class="form-control" name="change_ticket" placeholder="CHG-…"></div>
                <?php if (work_order_itsm_ready()): ?>
                <div class="form-row full">
                    <p class="text-muted mb-0" style="font-size:.8rem">
                        <?php if (class_exists('ItsmService') && ItsmService::autoCreate()): ?>
                            A <?= App::e(ItsmService::label()) ?> ticket will be created when you save
                            (Settings → Ticketing).
                        <?php else: ?>
                            After save, you can create or link a <?= App::e(ItsmService::label()) ?> ticket from the work order.
                        <?php endif; ?>
                    </p>
                </div>
                <?php endif; ?>
                <div class="form-row"><label>Scheduled date</label>
                    <input class="form-control" type="date" name="scheduled_date"></div>
                <div class="form-row"><label>Assigned to</label>
                    <select class="form-control" name="assigned_to">
                        <option value="">—</option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?= (int)$u['user_id'] ?>">
                                <?= App::e((string)($u['display_name'] ?: $u['username'])) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($seed): ?>
                <div class="form-row full">
                    <p class="text-muted mb-0" style="font-size:.85rem">
                        Will include device <strong><?= App::e((string)$seed['label']) ?></strong>
                        (source snapshotted from inventory).
                    </p>
                </div>
                <div class="form-row"><label>Initial destination cabinet</label>
                    <select class="form-control" name="to_cabinet_id">
                        <option value="">— set later —</option>
                        <?php foreach ($cabinets as $c): ?>
                            <option value="<?= (int)$c['cabinet_id'] ?>"><?= App::e((string)$c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row"><label>Initial destination U</label>
                    <input class="form-control" type="number" name="to_position_u" min="1" max="60"></div>
                <?php endif; ?>
                <div class="form-row full"><label>Notes</label>
                    <textarea class="form-control" name="notes" rows="3"></textarea></div>
                <div class="form-row full">
                    <button class="btn btn-primary" type="submit">Create work order</button>
                </div>
            </form>
        </div>
    </div>
    <?php
    layout_footer();
    return;
}

// ---------- List ----------
$weekRange = work_order_week_range();
$weekMoves = work_order_moves_this_week();
$weekDeviceCount = 0;
foreach ($weekMoves as $wm) {
    $weekDeviceCount += (int)($wm['item_count'] ?? 0);
}

$list = [];
try {
    $sql = 'SELECT w.*,
                   (SELECT COUNT(*) FROM work_order_items i WHERE i.work_order_id = w.work_order_id) AS item_count,
                   ru.display_name AS requested_by_name
            FROM work_orders w
            LEFT JOIN users ru ON ru.user_id = w.requested_by
            WHERE 1=1';
    $params = [];
    if ($filterWeek) {
        $sql .= ' AND w.status IN (\'draft\',\'planned\',\'in_progress\')
                  AND w.scheduled_date IS NOT NULL
                  AND w.scheduled_date >= ? AND w.scheduled_date <= ?';
        $params[] = $weekRange['start'];
        $params[] = $weekRange['end'];
    }
    if ($filterStatus !== '' && isset($statuses[$filterStatus])) {
        $sql .= ' AND w.status = ?';
        $params[] = $filterStatus;
    }
    if ($filterTicket !== '') {
        $sql .= ' AND w.change_ticket LIKE ?';
        $params[] = '%' . $filterTicket . '%';
    }
    $sql .= ' ORDER BY
        CASE w.status
            WHEN \'in_progress\' THEN 0
            WHEN \'planned\' THEN 1
            WHEN \'draft\' THEN 2
            WHEN \'completed\' THEN 3
            ELSE 4 END,
        w.scheduled_date, w.updated_at DESC';
    $list = Database::fetchAll($sql, $params);
} catch (Throwable $e) {
    $list = [];
    App::flash('error', 'Work order tables not ready yet — open Settings or wait for schema ensure. ' . $e->getMessage());
}

layout_header('Work orders', $user, 'work_orders');
?>
<div class="flex-between mb-2">
    <p class="text-muted mb-0" style="font-size:.92rem">
        Plan rack moves and changes: ticket, from/to cabinet, checklist, optional inventory apply.
    </p>
    <div class="flex gap-1">
        <?php if ($canEdit): ?>
            <a class="btn btn-primary" href="<?= App::e(App::url('pages/work_orders.php?action=new')) ?>">+ New work order</a>
        <?php endif; ?>
    </div>
</div>

<div class="card mb-2 <?= $weekMoves ? 'settings-feature-active' : '' ?>" id="moves-this-week">
    <div class="card-header flex-between">
        <h2 style="margin:0;font-size:1.05rem">Moves this week</h2>
        <span class="text-muted" style="font-size:.85rem"><?= App::e($weekRange['label']) ?></span>
    </div>
    <div class="card-body" style="padding-bottom:.35rem">
        <div class="metrics" style="margin:0">
            <a class="metric-card <?= $weekMoves ? 'warning' : '' ?>"
               href="<?= App::e(App::url('pages/work_orders.php?week=1')) ?>"
               style="color:inherit;text-decoration:none">
                <div class="label">Open WOs scheduled</div>
                <div class="value"><?= count($weekMoves) ?></div>
                <div class="sub">draft / planned / in progress · click to filter</div>
            </a>
            <div class="metric-card accent">
                <div class="label">Devices on those WOs</div>
                <div class="value"><?= (int)$weekDeviceCount ?></div>
                <div class="sub">line items (all statuses)</div>
            </div>
            <div class="metric-card">
                <div class="label">In progress this week</div>
                <div class="value">
                    <?= count(array_filter($weekMoves, static fn($w) => ($w['status'] ?? '') === 'in_progress')) ?>
                </div>
            </div>
        </div>
    </div>
    <?php if ($weekMoves): ?>
    <div class="card-body flush">
        <table class="data">
            <thead>
            <tr>
                <th>Scheduled</th>
                <th>Title</th>
                <th>Ticket</th>
                <th>Status</th>
                <th>Devices</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($weekMoves as $wm): ?>
                <tr>
                    <td><?= App::e((string)($wm['scheduled_date'] ?? '—')) ?></td>
                    <td>
                        <a href="<?= App::e(App::url('pages/work_orders.php?id=' . (int)$wm['work_order_id'])) ?>">
                            <?= App::e((string)$wm['title']) ?>
                        </a>
                    </td>
                    <td><?= App::e((string)($wm['change_ticket'] ?: '—')) ?></td>
                    <td>
                        <span class="badge <?= App::e(work_order_status_badge_class((string)$wm['status'])) ?>">
                            <?= App::e($statuses[$wm['status'] ?? ''] ?? (string)$wm['status']) ?>
                        </span>
                    </td>
                    <td>
                        <?= (int)($wm['done_count'] ?? 0) ?>/<?= (int)($wm['item_count'] ?? 0) ?>
                        <span class="text-muted" style="font-size:.75rem">done</span>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="card-body">
        <p class="text-muted mb-0" style="font-size:.85rem">
            No open work orders scheduled for this calendar week.
            Set a <strong>scheduled date</strong> on a WO to see it here.
        </p>
    </div>
    <?php endif; ?>
</div>

<div class="card mb-2">
    <div class="card-body">
        <form method="get" class="form-grid" style="align-items:end">
            <div class="form-row"><label>Status</label>
                <select class="form-control" name="status">
                    <option value="">All</option>
                    <?php foreach ($statuses as $k => $lab): ?>
                        <option value="<?= App::e($k) ?>" <?= $filterStatus === $k ? 'selected' : '' ?>><?= App::e($lab) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row"><label>Ticket contains</label>
                <input class="form-control" name="ticket" value="<?= App::e($filterTicket) ?>"></div>
            <div class="form-row full">
                <label style="display:flex;align-items:center;gap:.4rem;font-size:.85rem">
                    <input type="checkbox" name="week" value="1" <?= $filterWeek ? 'checked' : '' ?>>
                    Only moves scheduled this week (<?= App::e($weekRange['label']) ?>)
                </label>
            </div>
            <div class="form-row">
                <button class="btn btn-secondary" type="submit">Filter</button>
                <?php if ($filterWeek || $filterStatus !== '' || $filterTicket !== ''): ?>
                    <a class="btn btn-ghost" href="<?= App::e(App::url('pages/work_orders.php')) ?>">Clear</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body flush">
        <table class="data">
            <thead>
            <tr>
                <th>Title</th>
                <th>Type</th>
                <th>Ticket</th>
                <th>Status</th>
                <th>Scheduled</th>
                <th>Devices</th>
                <th>Updated</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($list as $row): ?>
                <tr>
                    <td>
                        <a href="<?= App::e(App::url('pages/work_orders.php?id=' . (int)$row['work_order_id'])) ?>">
                            <strong><?= App::e((string)$row['title']) ?></strong>
                        </a>
                    </td>
                    <td><?= App::e($types[$row['work_type'] ?? ''] ?? (string)$row['work_type']) ?></td>
                    <td><?= App::e((string)($row['change_ticket'] ?: '—')) ?></td>
                    <td>
                        <span class="badge <?= App::e(work_order_status_badge_class((string)$row['status'])) ?>">
                            <?= App::e($statuses[$row['status'] ?? ''] ?? (string)$row['status']) ?>
                        </span>
                    </td>
                    <td><?= App::e((string)($row['scheduled_date'] ?: '—')) ?></td>
                    <td><?= (int)($row['item_count'] ?? 0) ?></td>
                    <td style="font-size:.85rem"><?= App::e((string)($row['updated_at'] ?? '—')) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$list): ?>
                <tr>
                    <td colspan="7" class="text-muted">
                        No work orders yet.
                        <?php if ($canEdit): ?>
                            <a href="<?= App::e(App::url('pages/work_orders.php?action=new')) ?>">Create one</a>.
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php layout_footer(); ?>
