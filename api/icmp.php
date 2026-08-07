<?php
/**
 * ICMP monitor API: enable/disable, status, ping now, batch, simulate (testing mode).
 */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once dirname(__DIR__) . '/src/Services/IcmpMonitorService.php';
if (is_file(dirname(__DIR__) . '/src/Services/SnmpDiscover.php')) {
    require_once dirname(__DIR__) . '/src/Services/SnmpDiscover.php';
}

$method = api_method();
$user = AuthManager::user();

/**
 * @return list<int>
 */
function icmp_parse_ids(array $data): array
{
    $raw = $data['ids'] ?? $data['id'] ?? [];
    if (!is_array($raw)) {
        $raw = [$raw];
    }
    $ids = [];
    foreach ($raw as $v) {
        $n = (int)$v;
        if ($n > 0) {
            $ids[$n] = true;
        }
    }
    return array_keys($ids);
}

/**
 * @return array{ok:bool,row:?array,error?:string}
 */
function icmp_load_entity(string $kind, int $id, array $user): array
{
    if ($kind === 'pdu') {
        if (!AuthManager::canEditPower($user) && !AuthManager::canEditSnmp($user)) {
            return ['ok' => false, 'row' => null, 'error' => 'Forbidden'];
        }
        $row = Database::fetchOne('SELECT * FROM pdus WHERE pdu_id = ? AND is_active = 1', [$id]);
    } else {
        $row = Database::fetchOne('SELECT * FROM devices WHERE device_id = ? AND is_active = 1', [$id]);
        if ($row && !AuthManager::canEditDevice($user, $row) && !AuthManager::canEditSnmp($user)) {
            return ['ok' => false, 'row' => null, 'error' => 'Forbidden'];
        }
    }
    if (!$row) {
        return ['ok' => false, 'row' => null, 'error' => 'Not found'];
    }
    return ['ok' => true, 'row' => $row];
}

try {
    if ($method === 'GET') {
        // Feature flags for UI
        if (isset($_GET['meta'])) {
            App::json([
                'ok' => true,
                'testing_mode' => IcmpMonitorService::testingModeEnabled(),
                'settings' => IcmpMonitorService::settings(),
            ]);
        }

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
            'testing_mode' => IcmpMonitorService::testingModeEnabled(),
        ]);
    }

    if ($method === 'POST') {
        api_require_csrf();
        $data = api_read_json();
        $action = trim((string)($data['action'] ?? ''));
        $kind = strtolower(trim((string)($data['kind'] ?? '')));

        // --- Batch actions ---
        if (in_array($action, [
            'set_monitor_batch',
            'simulate_outage_batch',
            'simulate_recovery_batch',
        ], true)) {
            if (!in_array($kind, ['device', 'pdu'], true)) {
                App::json(['error' => 'kind=device|pdu required'], 400);
            }
            $ids = icmp_parse_ids($data);
            if ($ids === []) {
                App::json(['error' => 'ids required (array of device/pdu ids)'], 400);
            }
            if (count($ids) > 200) {
                App::json(['error' => 'Maximum 200 ids per batch'], 400);
            }
            if (str_starts_with($action, 'simulate_') && !IcmpMonitorService::testingModeEnabled()) {
                App::json([
                    'error' => 'Testing mode is off. Enable it under Settings → Diagnostics (Global Admin).',
                ], 403);
            }

            $enabled = !empty($data['enabled']);
            $results = [];
            $ok = 0;
            $fail = 0;
            foreach ($ids as $id) {
                $loaded = icmp_load_entity($kind, $id, $user);
                if (!$loaded['ok'] || !$loaded['row']) {
                    $results[] = ['id' => $id, 'ok' => false, 'error' => $loaded['error'] ?? 'Failed'];
                    $fail++;
                    continue;
                }
                $row = $loaded['row'];
                try {
                    if ($action === 'set_monitor_batch') {
                        $host = $kind === 'pdu'
                            ? IcmpMonitorService::hostFromPdu($row)
                            : IcmpMonitorService::hostFromDevice($row);
                        if ($enabled && $host === '') {
                            $results[] = [
                                'id' => $id,
                                'ok' => false,
                                'error' => 'No OS/IP address for ICMP',
                            ];
                            $fail++;
                            continue;
                        }
                        $table = $kind === 'pdu' ? 'pdus' : 'devices';
                        $pk = $kind === 'pdu' ? 'pdu_id' : 'device_id';
                        Database::update($table, [
                            'icmp_monitor' => $enabled ? 1 : 0,
                        ], $pk . ' = :id', [':id' => $id]);
                        if ($enabled) {
                            $row['icmp_monitor'] = 1;
                            IcmpMonitorService::checkEntity($kind, $row);
                        }
                        $fresh = $kind === 'pdu'
                            ? Database::fetchOne('SELECT * FROM pdus WHERE pdu_id = ?', [$id])
                            : Database::fetchOne('SELECT * FROM devices WHERE device_id = ?', [$id]);
                        $results[] = [
                            'id' => $id,
                            'ok' => true,
                            'icmp_monitor' => $enabled,
                            'status' => IcmpMonitorService::statusFromRow($kind, $fresh ?: $row),
                        ];
                        $ok++;
                    } elseif ($action === 'simulate_outage_batch') {
                        $sim = IcmpMonitorService::simulateOutage($kind, $row);
                        $results[] = ['id' => $id, 'ok' => true] + $sim;
                        $ok++;
                    } else {
                        $sim = IcmpMonitorService::simulateRecovery($kind, $row);
                        $results[] = ['id' => $id, 'ok' => true] + $sim;
                        $ok++;
                    }
                } catch (Throwable $e) {
                    $results[] = ['id' => $id, 'ok' => false, 'error' => $e->getMessage()];
                    $fail++;
                }
            }

            $auditAction = match ($action) {
                'set_monitor_batch' => $enabled ? 'icmp_monitor_batch_on' : 'icmp_monitor_batch_off',
                'simulate_outage_batch' => 'icmp_simulate_outage_batch',
                default => 'icmp_simulate_recovery_batch',
            };
            AuditService::log(
                (int)$user['user_id'],
                $user['username'],
                $auditAction,
                $kind,
                null,
                ['ids' => $ids, 'ok' => $ok, 'fail' => $fail, 'enabled' => $enabled]
            );

            $msg = match ($action) {
                'set_monitor_batch' => ($enabled ? 'ICMP monitor enabled' : 'ICMP monitor disabled')
                    . " on {$ok} of " . count($ids) . ($fail ? " ({$fail} failed)" : ''),
                'simulate_outage_batch' => "Simulated outage on {$ok} of " . count($ids)
                    . ($fail ? " ({$fail} failed)" : ''),
                default => "Simulated recovery on {$ok} of " . count($ids)
                    . ($fail ? " ({$fail} failed)" : ''),
            };

            App::json([
                'ok' => $fail === 0,
                'message' => $msg,
                'ok_count' => $ok,
                'fail_count' => $fail,
                'results' => $results,
                'testing_mode' => IcmpMonitorService::testingModeEnabled(),
            ]);
        }

        // --- Single-entity actions ---
        $id = (int)($data['id'] ?? 0);
        if (!in_array($kind, ['device', 'pdu'], true) || $id < 1) {
            App::json(['error' => 'kind=device|pdu and id required'], 400);
        }

        $loaded = icmp_load_entity($kind, $id, $user);
        if (!$loaded['ok']) {
            $code = ($loaded['error'] ?? '') === 'Forbidden' ? 403 : 404;
            App::json(['error' => $loaded['error'] ?? 'Failed'], $code);
        }
        $row = $loaded['row'];

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

        if ($action === 'simulate_outage' || $action === 'simulate_recovery') {
            if (!IcmpMonitorService::testingModeEnabled()) {
                App::json([
                    'error' => 'Testing mode is off. Enable it under Settings → Diagnostics (Global Admin).',
                ], 403);
            }
            $sim = $action === 'simulate_outage'
                ? IcmpMonitorService::simulateOutage($kind, $row)
                : IcmpMonitorService::simulateRecovery($kind, $row);
            AuditService::log(
                (int)$user['user_id'],
                $user['username'],
                $action,
                $kind,
                $id,
                ['testing' => true]
            );
            App::json([
                'ok' => true,
                'icmp_monitor' => true,
                'status' => $sim['status'],
                'message' => $sim['message'],
                'alerted' => !empty($sim['alerted']),
                'testing_mode' => true,
            ]);
        }

        App::json([
            'error' => 'Unknown action. Use set_monitor, set_monitor_batch, ping_now, simulate_outage, simulate_recovery, or batch variants.',
        ], 400);
    }

    App::json(['error' => 'Method not allowed'], 405);
} catch (Throwable $e) {
    App::log('API icmp: ' . $e->getMessage(), 'error');
    App::json(['error' => $e->getMessage()], 500);
}
