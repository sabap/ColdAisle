<?php
/**
 * WinDCIM - Audit trail helper
 */
declare(strict_types=1);

class AuditService
{
    public static function log(
        ?int $userId,
        ?string $username,
        string $action,
        ?string $entityType = null,
        ?int $entityId = null,
        $details = null
    ): void {
        try {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
            if (is_string($ip) && str_contains($ip, ',')) {
                $ip = trim(explode(',', $ip)[0]);
            }
            Database::insert('audit_log', [
                'user_id' => $userId,
                'username' => $username,
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'details' => is_string($details) ? $details : ($details !== null ? json_encode($details) : null),
                'ip_address' => $ip,
            ]);
        } catch (Throwable $e) {
            App::log('Audit log failed: ' . $e->getMessage(), 'error');
        }
    }
}
