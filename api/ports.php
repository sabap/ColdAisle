<?php
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
        $ports = Database::fetchAll(
            'SELECT p.*,
                ca.cable_id, ca.cable_label,
                CASE WHEN ca.a_port_id = p.port_id THEN ca.b_port_id ELSE ca.a_port_id END AS peer_port_id
             FROM device_ports p
             LEFT JOIN cables ca ON (ca.a_port_id = p.port_id OR ca.b_port_id = p.port_id) AND ca.status = \'active\'
             WHERE p.device_id = ?
             ORDER BY p.port_type, p.port_number',
            [$deviceId]
        );
        App::json(['ports' => $ports]);
    }

    if ($method === 'POST') {
        api_require_csrf();
        $data = api_read_json();
        $deviceId = (int)($data['device_id'] ?? 0);
        $portType = $data['port_type'] ?? 'data';
        if (!$deviceId || !in_array($portType, ['data', 'power'], true)) {
            App::json(['error' => 'device_id and port_type (data|power) required'], 400);
        }
        $max = (int) Database::fetchValue(
            'SELECT ISNULL(MAX(port_number),0) FROM device_ports WHERE device_id = ? AND port_type = ?',
            [$deviceId, $portType]
        );
        $num = (int)($data['port_number'] ?? ($max + 1));
        $id = Database::insert('device_ports', [
            'device_id' => $deviceId,
            'port_type' => $portType,
            'port_number' => $num,
            'label' => $data['label'] ?? (($portType === 'power' ? 'PSU' : 'Port') . $num),
            'media_type' => $data['media_type'] ?? null,
            'speed' => $data['speed'] ?? null,
            'mac_address' => $data['mac_address'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);
        $port = Database::fetchOne('SELECT * FROM device_ports WHERE port_id = ?', [$id]);
        AuditService::log((int)$user['user_id'], $user['username'], 'create', 'port', $id);
        App::json(['port' => $port], 201);
    }

    if ($method === 'PUT') {
        api_require_csrf();
        $data = api_read_json();
        $id = (int)($data['port_id'] ?? 0);
        if (!$id) {
            App::json(['error' => 'port_id required'], 400);
        }
        $fields = [];
        foreach (['label', 'media_type', 'speed', 'mac_address', 'notes', 'port_number'] as $k) {
            if (array_key_exists($k, $data)) {
                $fields[$k] = $data[$k];
            }
        }
        if ($fields) {
            Database::update('device_ports', $fields, 'port_id = :id', [':id' => $id]);
        }
        App::json(['port' => Database::fetchOne('SELECT * FROM device_ports WHERE port_id = ?', [$id])]);
    }

    if ($method === 'DELETE') {
        api_require_csrf();
        $id = (int)($_GET['id'] ?? 0);
        Database::delete('device_ports', 'port_id = ?', [$id]);
        App::json(['ok' => true]);
    }

    App::json(['error' => 'Method not allowed'], 405);
} catch (Throwable $e) {
    App::json(['error' => $e->getMessage()], 500);
}
