<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$method = api_method();
$user = AuthManager::user();

function device_u_conflict(int $cabinetId, int $positionU, int $uHeight, ?int $excludeId = null): ?string
{
    $devices = Database::fetchAll(
        'SELECT device_id, label, position_u, u_height FROM devices
         WHERE cabinet_id = ? AND is_active = 1 AND position_u IS NOT NULL' .
        ($excludeId ? ' AND device_id <> ' . (int)$excludeId : ''),
        [$cabinetId]
    );
    $end = $positionU + $uHeight - 1;
    foreach ($devices as $d) {
        $dStart = (int)$d['position_u'];
        $dEnd = $dStart + (int)$d['u_height'] - 1;
        if ($positionU <= $dEnd && $end >= $dStart) {
            return "U-space conflict with {$d['label']} (U{$dStart}-U{$dEnd})";
        }
    }
    $cabU = (int) Database::fetchValue('SELECT u_height FROM cabinets WHERE cabinet_id = ?', [$cabinetId]);
    if ($positionU < 1 || $end > $cabU) {
        return "Position exceeds cabinet height ({$cabU}U)";
    }
    return null;
}

try {
    if ($method === 'GET') {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id) {
            $dev = Database::fetchOne(
                'SELECT d.*, c.name AS cabinet_name FROM devices d
                 LEFT JOIN cabinets c ON c.cabinet_id = d.cabinet_id
                 WHERE d.device_id = ?',
                [$id]
            );
            if (!$dev) {
                App::json(['error' => 'Not found'], 404);
            }
            $ports = Database::fetchAll(
                'SELECT * FROM device_ports WHERE device_id = ? ORDER BY port_type, port_number',
                [$id]
            );
            App::json(['device' => $dev, 'ports' => $ports]);
        }
        $cabinetId = isset($_GET['cabinet_id']) ? (int)$_GET['cabinet_id'] : 0;
        $sql = 'SELECT d.*, c.name AS cabinet_name FROM devices d
                LEFT JOIN cabinets c ON c.cabinet_id = d.cabinet_id WHERE d.is_active = 1';
        $params = [];
        if ($cabinetId) {
            $sql .= ' AND d.cabinet_id = ?';
            $params[] = $cabinetId;
        }
        $sql .= ' ORDER BY d.label';
        App::json(['devices' => Database::fetchAll($sql, $params)]);
    }

    if ($method === 'POST') {
        api_require_csrf();
        $data = api_read_json();
        $label = trim((string)($data['label'] ?? ''));
        if ($label === '') {
            App::json(['error' => 'label required'], 400);
        }
        $cabinetId = !empty($data['cabinet_id']) ? (int)$data['cabinet_id'] : null;
        $positionU = isset($data['position_u']) && $data['position_u'] !== '' ? (int)$data['position_u'] : null;
        $uHeight = max(1, (int)($data['u_height'] ?? 1));

        if ($cabinetId && $positionU !== null) {
            $conflict = device_u_conflict($cabinetId, $positionU, $uHeight);
            if ($conflict) {
                App::json(['error' => $conflict], 409);
            }
        }

        $id = Database::insert('devices', [
            'cabinet_id' => $cabinetId,
            'template_id' => $data['template_id'] ?? null,
            'department_id' => $data['department_id'] ?? null,
            'label' => $label,
            'serial_no' => $data['serial_no'] ?? null,
            'asset_tag' => $data['asset_tag'] ?? null,
            'device_type' => $data['device_type'] ?? 'server',
            'manufacturer' => $data['manufacturer'] ?? null,
            'model' => $data['model'] ?? null,
            'position_u' => $positionU,
            'u_height' => $uHeight,
            'half_depth' => !empty($data['half_depth']) ? 1 : 0,
            'back_side' => !empty($data['back_side']) ? 1 : 0,
            'primary_ip' => $data['primary_ip'] ?? null,
            'mgmt_ip' => $data['mgmt_ip'] ?? null,
            'hostname' => $data['hostname'] ?? null,
            'nominal_watts' => $data['nominal_watts'] ?? null,
            'status' => $data['status'] ?? 'production',
            'install_date' => $data['install_date'] ?? null,
            'warranty_end' => $data['warranty_end'] ?? null,
            'notes' => $data['notes'] ?? null,
            'is_active' => 1,
        ]);

        // Auto-create ports if counts provided
        $dataPorts = (int)($data['num_data_ports'] ?? 0);
        $powerPorts = (int)($data['num_power_ports'] ?? 0);
        for ($i = 1; $i <= $dataPorts; $i++) {
            Database::insert('device_ports', [
                'device_id' => $id,
                'port_type' => 'data',
                'port_number' => $i,
                'label' => 'Eth' . $i,
                'media_type' => $data['data_media'] ?? 'RJ45',
            ]);
        }
        for ($i = 1; $i <= $powerPorts; $i++) {
            Database::insert('device_ports', [
                'device_id' => $id,
                'port_type' => 'power',
                'port_number' => $i,
                'label' => 'PSU' . $i,
                'media_type' => $data['power_media'] ?? 'C14',
            ]);
        }

        $dev = Database::fetchOne('SELECT * FROM devices WHERE device_id = ?', [$id]);
        AuditService::log((int)$user['user_id'], $user['username'], 'create', 'device', $id, ['label' => $label]);
        App::json(['device' => $dev], 201);
    }

    if ($method === 'PUT' || $method === 'PATCH') {
        api_require_csrf();
        $data = api_read_json();
        $id = (int)($data['device_id'] ?? $_GET['id'] ?? 0);
        if (!$id) {
            App::json(['error' => 'device_id required'], 400);
        }
        $fields = [];
        foreach ([
            'cabinet_id', 'template_id', 'department_id', 'label', 'serial_no', 'asset_tag',
            'device_type', 'manufacturer', 'model', 'position_u', 'u_height', 'half_depth',
            'back_side', 'primary_ip', 'mgmt_ip', 'hostname', 'nominal_watts', 'status',
            'install_date', 'warranty_end', 'notes',
        ] as $k) {
            if (array_key_exists($k, $data)) {
                $fields[$k] = $data[$k] === '' ? null : $data[$k];
            }
        }
        if (isset($fields['half_depth'])) {
            $fields['half_depth'] = $fields['half_depth'] ? 1 : 0;
        }
        if (isset($fields['back_side'])) {
            $fields['back_side'] = $fields['back_side'] ? 1 : 0;
        }

        $cabinetId = isset($fields['cabinet_id']) ? (int)$fields['cabinet_id'] : null;
        $positionU = array_key_exists('position_u', $fields) ? ($fields['position_u'] !== null ? (int)$fields['position_u'] : null) : null;
        $uHeight = isset($fields['u_height']) ? (int)$fields['u_height'] : null;

        // Load existing for conflict check
        $existing = Database::fetchOne('SELECT * FROM devices WHERE device_id = ?', [$id]);
        $checkCab = $cabinetId ?? ($existing['cabinet_id'] ? (int)$existing['cabinet_id'] : null);
        $checkPos = $positionU !== null ? $positionU : ($existing['position_u'] !== null ? (int)$existing['position_u'] : null);
        $checkU = $uHeight ?? (int)$existing['u_height'];

        if ($checkCab && $checkPos !== null) {
            $conflict = device_u_conflict($checkCab, $checkPos, $checkU, $id);
            if ($conflict) {
                App::json(['error' => $conflict], 409);
            }
        }

        $fields['updated_at'] = date('Y-m-d H:i:s');
        Database::update('devices', $fields, 'device_id = :id', [':id' => $id]);
        $dev = Database::fetchOne('SELECT * FROM devices WHERE device_id = ?', [$id]);
        AuditService::log((int)$user['user_id'], $user['username'], 'update', 'device', $id, $fields);
        App::json(['device' => $dev]);
    }

    if ($method === 'DELETE') {
        api_require_csrf();
        $id = (int)($_GET['id'] ?? 0);
        Database::update('devices', ['is_active' => 0, 'status' => 'decommissioned', 'updated_at' => date('Y-m-d H:i:s')], 'device_id = :id', [':id' => $id]);
        AuditService::log((int)$user['user_id'], $user['username'], 'delete', 'device', $id);
        App::json(['ok' => true]);
    }

    App::json(['error' => 'Method not allowed'], 405);
} catch (Throwable $e) {
    App::log('API devices: ' . $e->getMessage(), 'error');
    App::json(['error' => $e->getMessage()], 500);
}
