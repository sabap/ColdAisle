<?php
/**
 * ColdAisle — notifications API (live toast feed + badge count).
 */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

api_require_permission('view_notifications');

$user = AuthManager::user();
$uid = (int)($user['user_id'] ?? 0);
$method = api_method();

if ($method === 'GET') {
    $sinceId = max(0, (int)($_GET['since_id'] ?? 0));
    $limit = min(50, max(1, (int)($_GET['limit'] ?? 15)));

    try {
        $unread = (int)Database::fetchValue(
            'SELECT COUNT(*) FROM notifications WHERE (user_id = ? OR user_id IS NULL) AND is_read = 0',
            [$uid]
        );
    } catch (Throwable $e) {
        $unread = 0;
    }

    $rows = [];
    try {
        if ($sinceId > 0) {
            $rows = Database::fetchAll(
                'SELECT TOP ' . $limit . ' notification_id, title, message, category, entity_type, entity_id,
                        is_read, created_at
                 FROM notifications
                 WHERE (user_id = ? OR user_id IS NULL) AND notification_id > ?
                 ORDER BY notification_id ASC',
                [$uid, $sinceId]
            );
        } else {
            // Initial poll: only recent unread (avoid toast flood on every page load)
            $rows = Database::fetchAll(
                'SELECT TOP ' . $limit . ' notification_id, title, message, category, entity_type, entity_id,
                        is_read, created_at
                 FROM notifications
                 WHERE (user_id = ? OR user_id IS NULL) AND is_read = 0
                 ORDER BY notification_id DESC',
                [$uid]
            );
            // Return ascending so client can toast oldest-first
            $rows = array_reverse($rows);
        }
    } catch (Throwable $e) {
        $rows = [];
    }

    $items = [];
    $maxId = $sinceId;
    foreach ($rows as $r) {
        $id = (int)$r['notification_id'];
        if ($id > $maxId) {
            $maxId = $id;
        }
        $cat = strtolower((string)($r['category'] ?? 'info'));
        $toastType = 'info';
        if (in_array($cat, ['warning', 'critical', 'power', 'icmp', 'error', 'danger'], true)) {
            $toastType = in_array($cat, ['critical', 'error', 'danger'], true) ? 'error' : 'warning';
        } elseif (in_array($cat, ['success', 'ok'], true) || stripos((string)($r['title'] ?? ''), 'recovered') !== false) {
            $toastType = 'success';
        }
        // Heuristic from title for ICMP recovered / critical
        $title = (string)($r['title'] ?? '');
        if (stripos($title, 'DOWN') !== false || stripos($title, 'critical') !== false) {
            $toastType = 'error';
        } elseif (stripos($title, 'recovered') !== false) {
            $toastType = 'success';
        }

        $items[] = [
            'id' => $id,
            'title' => $title,
            'message' => mb_substr((string)($r['message'] ?? ''), 0, 280),
            'category' => $cat,
            'toast_type' => $toastType,
            'entity_type' => $r['entity_type'] ?? null,
            'entity_id' => isset($r['entity_id']) ? (int)$r['entity_id'] : null,
            'is_read' => !empty($r['is_read']),
            'created_at' => (string)($r['created_at'] ?? ''),
        ];
    }

    App::json([
        'ok' => true,
        'unread' => $unread,
        'max_id' => $maxId,
        'items' => $items,
    ]);
}

if ($method === 'POST') {
    api_require_csrf();
    $body = api_read_json();
    $action = (string)($body['action'] ?? $_POST['action'] ?? '');

    if ($action === 'mark_read') {
        $nid = (int)($body['notification_id'] ?? 0);
        if ($nid > 0) {
            Database::query(
                'UPDATE notifications SET is_read = 1 WHERE notification_id = ? AND (user_id = ? OR user_id IS NULL)',
                [$nid, $uid]
            );
        }
        App::json(['ok' => true]);
    }

    if ($action === 'mark_all') {
        Database::query(
            'UPDATE notifications SET is_read = 1 WHERE (user_id = ? OR user_id IS NULL) AND is_read = 0',
            [$uid]
        );
        App::json(['ok' => true]);
    }

    App::json(['error' => 'Unknown action'], 400);
}

App::json(['error' => 'Method not allowed'], 405);
