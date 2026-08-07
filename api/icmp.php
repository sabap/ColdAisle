<?php
/**
 * ICMP monitor API: enable/disable, status, ping now for devices and PDUs.
 */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once dirname(__DIR__) . '/src/Services/IcmpMonitorService.php';
if (is_file(dirname(__DIR__) . '/src/Services/SnmpDiscover.php')) {
    require_once dirname(__DIR__) . '/src/Services/SnmpDiscover.php';
}

$method = api_method();
$user = AuthManager::user();

try {
    if ($method === 'GET') {
        $kind = strtolower(trim((string)($_GET['kind'] ?? '')));
        $id = (int)($_GET['id'] ?? 0);
        if (!in_array($kind, ['device', 'pdu'], true) || $id < 1) {
            App::json(['error' => 'kind=device|pdu and id required'], 400);
        }
        $row = $kind === 'pdu'
            ? Database::fetchOne('SELECT * FROM pdus WHERE pdu_id = ? AND is_active = 1', [$id])
            : Database::fetchOne('SELECT * FROM devices WHERE device_id = ? AND is_active = 1', [$id]);
        if (!$row) {
            App::json(['error' => 'Not found'], 404);
        }
        $st = IcmpMonitorService::statusFromRow($kind, $row);
        App::json([
            'ok' => true,
            'kind' => $kind,
            'id' => $id,
            'icmp_monitor' => !empty($row['icmp_monitor']),
            'status' => $st,
            'settings' => IcmpMonitorService::settings(),
        ]);
    }

    if ($method === 'POST') {
        api_require_csrf();
        $data = api_read_json();
        $action = trim((string)($data['action'] ?? ''));
        $kind = strtolower(trim((string)($data['kind'] ?? '')));
        $id = (int)($data['id'] ?? 0);
        if (!in_array($kind, ['device', 'pdu'], true) || $id < 1) {
            App::json(['error' => 'kind=device|pdu and id required'], 400);
        }

        if ($kind === 'pdu') {
            if (!AuthManager::canEditPower($user) && !AuthManager::canEditSnmp($user)) {
                App::json(['error' => 'Forbidden'], 403);
            }
            $row = Database::fetchOne('SELECT * FROM pdus WHERE pdu_id = ? AND is_active = 1', [$id]);
        } else {
            $row = Database::fetchOne('SELECT * FROM devices WHERE device_id = ? AND is_active = 1', [$id]);
            if ($row && !AuthManager::canEditDevice($user, $row) && !AuthManager::canEditSnmp($user)) {
                App::json(['error' => 'Forbidden'], 403);
            }
        }
        if (!$row) {
            App::json(['error' => 'Not found'], 404);
        }

        if ($action === 'set_monitor') {
            $enabled = !empty($data['enabled']);
            $host = $kind === 'pdu'
                ? IcmpMonitorService::hostFromPdu($row)
                : IcmpMonitorService::hostFromDevice($row);
            if ($enabled && $host === '') {
                App::json([
                    'error' => $kind === 'pdu'
                        ? 'Set an IP address on this PDU before enabling ICMP monitor.'
                        : 'Set management IP or primary IP (OS address) before enabling ICMP monitor. iDRAC is not used for OS up/down.',
                ], 400);
            }
            $table = $kind === 'pdu' ? 'pdus' : 'devices';
            $pk = $kind === 'pdu' ? 'pdu_id' : 'device_id';
            Database::update($table, [
                'icmp_monitor' => $enabled ? 1 : 0,
            ], $pk . ' = :id', [':id' => $id]);

            AuditService::log(
                (int)$user['user_id'],
                $user['username'],
                $enabled ? 'icmp_monitor_on' : 'icmp_monitor_off',
                $kind,
                $id,
                ['host' => $host]
            );

            // Immediate check when enabling
            $check = null;
            if ($enabled) {
                $row['icmp_monitor'] = 1;
                $check = IcmpMonitorService::checkEntity($kind, $row);
                $row = $kind === 'pdu'
                    ? Database::fetchOne('SELECT * FROM pdus WHERE pdu_id = ?', [$id])
                    : Database::fetchOne('SELECT * FROM devices WHERE device_id = ?', [$id]);
            }
            $st = IcmpMonitorService::statusFromRow($kind, $row ?: []);
            App::json([
                'ok' => true,
                'icmp_monitor' => $enabled,
                'status' => $st,
                'check' => $check,
                'message' => $enabled
                    ? 'ICMP monitor enabled — host will be pinged by the scheduler.'
                    : 'ICMP monitor disabled.',
            ]);
        }

        if ($action === 'ping_now') {
            $host = $kind === 'pdu'
                ? IcmpMonitorService::hostFromPdu($row)
                : IcmpMonitorService::hostFromDevice($row);
            if ($host === '') {
                App::json(['error' => 'No IP/hostname to ping.'], 400);
            }
            // Force a check even if monitor is off (ad-hoc), but still update counters if monitor on
            $result = IcmpMonitorService::checkEntity($kind, $row);
            $fresh = $kind === 'pdu'
                ? Database::fetchOne('SELECT * FROM pdus WHERE pdu_id = ?', [$id])
                : Database::fetchOne('SELECT * FROM devices WHERE device_id = ?', [$id]);
            $st = IcmpMonitorService::statusFromRow($kind, $fresh ?: $row);
            App::json([
                'ok' => true,
                'result' => $result,
                'status' => $st,
                'message' => $result['ok']
                    ? ('Reachable' . ($result['rtt_ms'] !== null ? ' · ' . $result['rtt_ms'] . ' ms' : ''))
                    : ('Unreachable' . ($result['error'] ? ' — ' . $result['error'] : '')),
            ]);
        }

        App::json(['error' => 'Unknown action. Use set_monitor or ping_now.'], 400);
    }

    App::json(['error' => 'Method not allowed'], 405);
} catch (Throwable $e) {
    App::log('API icmp: ' . $e->getMessage(), 'error');
    App::json(['error' => $e->getMessage()], 500);
}
