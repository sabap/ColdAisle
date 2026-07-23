<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$method = api_method();
$user = AuthManager::user();

/**
 * Who may log a cabinet audit certification.
 * Field / DC staff with audit or infrastructure rights (not pure viewers).
 */
function cabinet_audit_can_log(?array $user): bool
{
    if (!$user) {
        return false;
    }
    return AuthManager::can($user, 'edit_audits')
        || AuthManager::can($user, 'edit_infrastructure')
        || AuthManager::can($user, 'edit_devices_all');
}

function cabinet_audit_fetch(int $id): ?array
{
    return Database::fetchOne(
        'SELECT a.*, c.name AS cabinet_name, c.room_id,
                r.name AS room_name, dc.name AS dc_name
         FROM cabinet_audits a
         INNER JOIN cabinets c ON c.cabinet_id = a.cabinet_id
         LEFT JOIN rooms r ON r.room_id = c.room_id
         LEFT JOIN datacenters dc ON dc.datacenter_id = r.datacenter_id
         WHERE a.cabinet_audit_id = ?',
        [$id]
    );
}

try {
    if ($method === 'GET') {
        api_require_permission('view_cabinets');
        $cabinetId = (int)($_GET['cabinet_id'] ?? 0);
        $id = (int)($_GET['id'] ?? 0);
        $limit = max(1, min(200, (int)($_GET['limit'] ?? 50)));

        if ($id > 0) {
            $row = cabinet_audit_fetch($id);
            if (!$row) {
                App::json(['error' => 'Audit not found'], 404);
            }
            App::json(['audit' => $row]);
        }

        if ($cabinetId <= 0) {
            App::json(['error' => 'cabinet_id required'], 400);
        }

        $rows = Database::fetchAll(
            'SELECT TOP ' . $limit . ' a.cabinet_audit_id, a.cabinet_id, a.audited_by,
                    a.audited_by_name, a.certified, a.comments, a.audited_at, a.created_at
             FROM cabinet_audits a
             WHERE a.cabinet_id = ?
             ORDER BY a.audited_at DESC',
            [$cabinetId]
        );

        $last = $rows[0] ?? null;
        App::json([
            'audits' => $rows,
            'last_audit' => $last,
            'can_log' => cabinet_audit_can_log($user),
        ]);
    }

    if ($method === 'POST') {
        if (!cabinet_audit_can_log($user)) {
            App::json(['error' => 'You do not have permission to log cabinet audits.'], 403);
        }
        api_require_csrf();
        $data = api_read_json();
        $cabinetId = (int)($data['cabinet_id'] ?? 0);
        if ($cabinetId <= 0) {
            App::json(['error' => 'cabinet_id required'], 400);
        }
        $cab = Database::fetchOne(
            'SELECT cabinet_id, name FROM cabinets WHERE cabinet_id = ? AND is_active = 1',
            [$cabinetId]
        );
        if (!$cab) {
            App::json(['error' => 'Cabinet not found'], 404);
        }

        $certified = !empty($data['certified']);
        if (!$certified) {
            App::json(['error' => 'You must check the certification box to log this audit.'], 400);
        }

        $comments = trim((string)($data['comments'] ?? ''));
        if (mb_strlen($comments) > 4000) {
            $comments = mb_substr($comments, 0, 4000);
        }
        $display = trim((string)($user['display_name'] ?? ''));
        if ($display === '') {
            $display = (string)($user['username'] ?? 'User');
        }

        $newId = Database::insert('cabinet_audits', [
            'cabinet_id' => $cabinetId,
            'audited_by' => (int)($user['user_id'] ?? 0) ?: null,
            'audited_by_name' => $display,
            'certified' => 1,
            'comments' => $comments !== '' ? $comments : null,
        ]);

        AuditService::log(
            (int)($user['user_id'] ?? 0) ?: null,
            (string)($user['username'] ?? ''),
            'cabinet_audit',
            'cabinet',
            $cabinetId,
            [
                'cabinet_audit_id' => $newId,
                'cabinet_name' => $cab['name'],
                'certified' => true,
                'comments' => $comments !== '' ? $comments : null,
            ]
        );

        App::json([
            'ok' => true,
            'audit' => cabinet_audit_fetch((int)$newId),
        ], 201);
    }

    App::json(['error' => 'Method not allowed'], 405);
} catch (Throwable $e) {
    App::log('API cabinet_audits: ' . $e->getMessage(), 'error');
    App::json(['error' => $e->getMessage()], 500);
}
