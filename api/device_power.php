<?php
/**
 * WinDCIM - Device power supply line items API
 */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$method = api_method();
$user = AuthManager::user();

try {
    if ($method === 'GET') {
        $deviceId = (int)($_GET['device_id'] ?? 0);
        if (!$deviceId) {
            App::json(['error' => 'device_id required'], 400);
        }
        $rows = Database::fetchAll(
            'SELECT ps.*,
                    p.name AS pdu_name,
                    o.outlet_number,
                    o.outlet_type AS pdu_outlet_type
             FROM device_power_supplies ps
             LEFT JOIN pdus p ON p.pdu_id = ps.pdu_id
             LEFT JOIN pdu_outlets o ON o.outlet_id = ps.pdu_outlet_id
             WHERE ps.device_id = ?
             ORDER BY ps.sort_order, ps.power_supply_id',
            [$deviceId]
        );
        App::json(['power_supplies' => $rows]);
    }

    if ($method === 'POST') {
        api_require_csrf();
        $d = api_read_json();
        $deviceId = (int)($d['device_id'] ?? 0);
        if (!$deviceId) {
            App::json(['error' => 'device_id required'], 400);
        }
        $dev = Database::fetchOne('SELECT device_id, department_id FROM devices WHERE device_id = ?', [$deviceId]);
        if (!$dev || !AuthManager::canEditDevice($user, $dev)) {
            App::json(['error' => 'Forbidden — department ownership'], 403);
        }
        $id = Database::insert('device_power_supplies', [
            'device_id' => $deviceId,
            'name' => trim((string)($d['name'] ?? 'PSU')) ?: 'PSU',
            'watts' => isset($d['watts']) && $d['watts'] !== '' ? (float)$d['watts'] : null,
            'connector_type' => ($d['connector_type'] ?? '') !== '' ? (string)$d['connector_type'] : null,
            'pdu_id' => !empty($d['pdu_id']) ? (int)$d['pdu_id'] : null,
            'pdu_outlet_id' => !empty($d['pdu_outlet_id']) ? (int)$d['pdu_outlet_id'] : null,
            'sort_order' => (int)($d['sort_order'] ?? 0),
            'notes' => ($d['notes'] ?? '') !== '' ? (string)$d['notes'] : null,
        ]);
        // Mirror link on outlet if set
        if (!empty($d['pdu_outlet_id'])) {
            Database::update('pdu_outlets', [
                'connected_device_id' => $deviceId,
                'device_power_supply_id' => $id,
            ], 'outlet_id = :id', [':id' => (int)$d['pdu_outlet_id']]);
        }
        App::json([
            'power_supply' => Database::fetchOne(
                'SELECT * FROM device_power_supplies WHERE power_supply_id = ?',
                [$id]
            ),
        ], 201);
    }

    if ($method === 'PUT' || $method === 'PATCH') {
        api_require_csrf();
        $d = api_read_json();
        $id = (int)($d['power_supply_id'] ?? 0);
        if (!$id) {
            App::json(['error' => 'power_supply_id required'], 400);
        }
        $existing = Database::fetchOne(
            'SELECT * FROM device_power_supplies WHERE power_supply_id = ?',
            [$id]
        );
        if (!$existing) {
            App::json(['error' => 'Not found'], 404);
        }
        $dev = Database::fetchOne(
            'SELECT device_id, department_id FROM devices WHERE device_id = ?',
            [(int)$existing['device_id']]
        );
        if (!$dev || !AuthManager::canEditDevice($user, $dev)) {
            App::json(['error' => 'Forbidden — department ownership'], 403);
        }
        $fields = [];
        foreach (['name', 'watts', 'connector_type', 'pdu_id', 'pdu_outlet_id', 'sort_order', 'notes'] as $k) {
            if (array_key_exists($k, $d)) {
                $fields[$k] = $d[$k] === '' ? null : $d[$k];
            }
        }
        if ($fields) {
            Database::update('device_power_supplies', $fields, 'power_supply_id = :id', [':id' => $id]);
        }
        // Clear old outlet link if outlet changed
        $newOutlet = array_key_exists('pdu_outlet_id', $fields)
            ? $fields['pdu_outlet_id']
            : $existing['pdu_outlet_id'];
        if (!empty($existing['pdu_outlet_id']) && (int)$existing['pdu_outlet_id'] !== (int)$newOutlet) {
            Database::update('pdu_outlets', [
                'connected_device_id' => null,
                'device_power_supply_id' => null,
            ], 'outlet_id = :id', [':id' => (int)$existing['pdu_outlet_id']]);
        }
        if ($newOutlet) {
            Database::update('pdu_outlets', [
                'connected_device_id' => (int)$existing['device_id'],
                'device_power_supply_id' => $id,
            ], 'outlet_id = :id', [':id' => (int)$newOutlet]);
        }
        App::json([
            'power_supply' => Database::fetchOne(
                'SELECT * FROM device_power_supplies WHERE power_supply_id = ?',
                [$id]
            ),
        ]);
    }

    if ($method === 'DELETE') {
        api_require_csrf();
        $id = (int)($_GET['id'] ?? 0);
        $row = Database::fetchOne('SELECT * FROM device_power_supplies WHERE power_supply_id = ?', [$id]);
        if ($row) {
            $dev = Database::fetchOne(
                'SELECT device_id, department_id FROM devices WHERE device_id = ?',
                [(int)$row['device_id']]
            );
            if (!$dev || !AuthManager::canEditDevice($user, $dev)) {
                App::json(['error' => 'Forbidden — department ownership'], 403);
            }
        }
        if ($row && !empty($row['pdu_outlet_id'])) {
            Database::update('pdu_outlets', [
                'connected_device_id' => null,
                'device_power_supply_id' => null,
            ], 'outlet_id = :id', [':id' => (int)$row['pdu_outlet_id']]);
        }
        Database::delete('device_power_supplies', 'power_supply_id = ?', [$id]);
        App::json(['ok' => true]);
    }

    App::json(['error' => 'Method not allowed'], 405);
} catch (Throwable $e) {
    App::log('API device_power: ' . $e->getMessage(), 'error');
    App::json(['error' => $e->getMessage()], 500);
}
