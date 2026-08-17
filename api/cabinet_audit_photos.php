<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$method = api_method();
$user = AuthManager::user();

function field_audit_can_log(?array $user): bool
{
    if (!$user) {
        return false;
    }
    return AuthManager::can($user, 'edit_audits')
        || AuthManager::can($user, 'edit_infrastructure')
        || AuthManager::can($user, 'edit_devices_all');
}

try {
    if ($method === 'GET') {
        api_require_permission('view_cabinets');
        $auditId = (int)($_GET['audit_id'] ?? 0);
        if ($auditId < 1) {
            App::json(['error' => 'audit_id required'], 400);
        }
        App::json(['photos' => FieldAuditService::photosForAudit($auditId)]);
    }

    if ($method === 'POST') {
        if (!field_audit_can_log($user)) {
            App::json(['error' => 'You do not have permission to attach audit photos.'], 403);
        }
        api_require_csrf();
        $auditId = (int)($_POST['cabinet_audit_id'] ?? $_POST['audit_id'] ?? 0);
        if ($auditId < 1) {
            App::json(['error' => 'cabinet_audit_id required'], 400);
        }
        $audit = Database::fetchOne(
            'SELECT cabinet_audit_id, cabinet_id FROM cabinet_audits WHERE cabinet_audit_id = ?',
            [$auditId]
        );
        if (!$audit) {
            App::json(['error' => 'Audit not found'], 404);
        }
        $file = $_FILES['photo'] ?? $_FILES['file'] ?? null;
        if (!is_array($file)) {
            App::json(['error' => 'Choose a photo (field name: photo).'], 400);
        }
        $saved = FieldAuditService::savePhoto(
            $auditId,
            (int)$audit['cabinet_id'],
            $file,
            [
                'position_u' => $_POST['position_u'] ?? null,
                'face' => $_POST['face'] ?? '',
                'caption' => $_POST['caption'] ?? '',
            ]
        );
        App::json(['ok' => true, 'photo' => $saved], 201);
    }

    App::json(['error' => 'Method not allowed'], 405);
} catch (Throwable $e) {
    App::log('API cabinet_audit_photos: ' . $e->getMessage(), 'error');
    App::json(['error' => $e->getMessage()], 500);
}
