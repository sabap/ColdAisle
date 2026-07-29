<?php
/**
 * ColdAisle - Device power supply line items API
 *
 * Bidirectional map: device_power_supplies ↔ pdu_outlets
 * (pdu_id / pdu_outlet_id on PSU; connected_device_id / device_power_supply_id on outlet).
 */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$method = api_method();
$user = AuthManager::user();

/**
 * Normalize empty → null; otherwise int.
 */
function dps_nullable_int(mixed $v): ?int
{
    if ($v === null || $v === '' || $v === false) {
        return null;
    }
    return (int)$v;
}

/**
 * Validate PDU + outlet for mapping onto a device PSU.
 * Ensures outlet is free (or already owned by this PSU) and belongs to the PDU / cabinet.
 *
 * @return array{0:?int,1:?int} [pdu_id, outlet_id]
 */
function dps_resolve_outlet_map(int $deviceId, ?int $pduId, ?int $outletId, ?int $ownPsuId = null): array
{
    if ($outletId === null || $outletId <= 0) {
        return [$pduId && $pduId > 0 ? $pduId : null, null];
    }

    $outlet = Database::fetchOne(
        'SELECT o.outlet_id, o.pdu_id, o.outlet_number, o.connected_device_id, o.device_power_supply_id,
                p.cabinet_id AS pdu_cabinet_id, p.name AS pdu_name, p.is_active
         FROM pdu_outlets o
         INNER JOIN pdus p ON p.pdu_id = o.pdu_id
         WHERE o.outlet_id = ?',
        [$outletId]
    );
    if (!$outlet) {
        throw new InvalidArgumentException('PDU outlet not found.');
    }
    if ((int)($outlet['is_active'] ?? 0) !== 1) {
        throw new InvalidArgumentException('PDU is inactive.');
    }

    $outletPduId = (int)$outlet['pdu_id'];
    if ($pduId !== null && $pduId > 0 && $pduId !== $outletPduId) {
        throw new InvalidArgumentException('Selected outlet does not belong to the selected PDU.');
    }
    $pduId = $outletPduId;

    // Prefer same-cabinet PDUs (warn soft only if device has no cabinet yet)
    $dev = Database::fetchOne('SELECT cabinet_id FROM devices WHERE device_id = ?', [$deviceId]);
    $devCab = (int)($dev['cabinet_id'] ?? 0);
    $pduCab = (int)($outlet['pdu_cabinet_id'] ?? 0);
    if ($devCab > 0 && $pduCab > 0 && $devCab !== $pduCab) {
        throw new InvalidArgumentException('PDU is not assigned to the same cabinet as this device.');
    }

    $takenByDevice = $outlet['connected_device_id'] !== null ? (int)$outlet['connected_device_id'] : null;
    $takenByPsu = $outlet['device_power_supply_id'] !== null ? (int)$outlet['device_power_supply_id'] : null;
    $own = $ownPsuId !== null && $ownPsuId > 0;
    $isOwn = $own && $takenByPsu === $ownPsuId;
    if (($takenByDevice !== null || $takenByPsu !== null) && !$isOwn) {
        // Allow reclaim if both point at this device without a PSU id (legacy half-map)
        if (!($takenByDevice === $deviceId && $takenByPsu === null && $own)) {
            throw new InvalidArgumentException(
                'Outlet #' . (int)$outlet['outlet_number'] . ' on '
                . (string)($outlet['pdu_name'] ?? 'PDU')
                . ' is already mapped to another device or power supply.'
            );
        }
    }

    return [$pduId, $outletId];
}

/**
 * Fetch PSU row with joined PDU/outlet labels (for UI refresh).
 */
function dps_fetch_row(int $id): ?array
{
    return Database::fetchOne(
        'SELECT ps.*,
                p.name AS pdu_name,
                o.outlet_number,
                o.outlet_type AS pdu_outlet_type
         FROM device_power_supplies ps
         LEFT JOIN pdus p ON p.pdu_id = ps.pdu_id
         LEFT JOIN pdu_outlets o ON o.outlet_id = ps.pdu_outlet_id
         WHERE ps.power_supply_id = ?',
        [$id]
    );
}

try {
    if ($method === 'GET') {
        $deviceId = (int)($_GET['device_id'] ?? 0);
        $cabinetId = (int)($_GET['cabinet_id'] ?? 0);

        // Cabinet PDUs + free (unmapped) outlets for device mapping UI
        if (!empty($_GET['cabinet_pdus'])) {
            $cab = $cabinetId;
            if (!$cab && $deviceId) {
                $devRow = Database::fetchOne('SELECT cabinet_id FROM devices WHERE device_id = ?', [$deviceId]);
                $cab = (int)($devRow['cabinet_id'] ?? 0);
            }
            if (!$cab) {
                App::json(['pdus' => [], 'cabinet_id' => null]);
            }
            $keepOutletId = dps_nullable_int($_GET['keep_outlet_id'] ?? null);
            $pdus = Database::fetchAll(
                'SELECT pdu_id, name, num_outlets, cabinet_id FROM pdus
                 WHERE cabinet_id = ? AND is_active = 1 ORDER BY name',
                [$cab]
            );
            foreach ($pdus as &$cp) {
                $outlets = Database::fetchAll(
                    'SELECT o.outlet_id, o.outlet_number, o.outlet_type, o.rated_amps,
                            o.connected_device_id, o.device_power_supply_id,
                            d.label AS connected_device_label
                     FROM pdu_outlets o
                     LEFT JOIN devices d ON d.device_id = o.connected_device_id
                     WHERE o.pdu_id = ?
                     ORDER BY o.outlet_number',
                    [(int)$cp['pdu_id']]
                );
                $free = [];
                foreach ($outlets as $o) {
                    $oid = (int)$o['outlet_id'];
                    $taken = $o['connected_device_id'] !== null || $o['device_power_supply_id'] !== null;
                    $keep = $keepOutletId !== null && $oid === $keepOutletId;
                    if (!$taken || $keep) {
                        $free[] = [
                            'outlet_id' => $oid,
                            'outlet_number' => (int)$o['outlet_number'],
                            'outlet_type' => $o['outlet_type'],
                            'rated_amps' => $o['rated_amps'],
                            'connected_device_id' => $o['connected_device_id'] !== null
                                ? (int)$o['connected_device_id'] : null,
                            'device_power_supply_id' => $o['device_power_supply_id'] !== null
                                ? (int)$o['device_power_supply_id'] : null,
                            'connected_device_label' => $o['connected_device_label'] ?? null,
                            'available' => !$taken || $keep,
                        ];
                    }
                }
                $cp['pdu_id'] = (int)$cp['pdu_id'];
                $cp['num_outlets'] = (int)($cp['num_outlets'] ?? 0);
                $cp['outlets'] = $free;
            }
            unset($cp);
            App::json(['cabinet_id' => $cab, 'pdus' => $pdus]);
        }

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
        $dev = Database::fetchOne(
            'SELECT device_id, department_id, cabinet_id FROM devices WHERE device_id = ?',
            [$deviceId]
        );
        if (!$dev || !AuthManager::canEditDevice($user, $dev)) {
            App::json(['error' => 'Forbidden — department ownership'], 403);
        }

        $pduId = dps_nullable_int($d['pdu_id'] ?? null);
        $outletId = dps_nullable_int($d['pdu_outlet_id'] ?? null);
        [$pduId, $outletId] = dps_resolve_outlet_map($deviceId, $pduId, $outletId, null);

        $id = Database::insert('device_power_supplies', [
            'device_id' => $deviceId,
            'name' => trim((string)($d['name'] ?? 'PSU')) ?: 'PSU',
            'watts' => isset($d['watts']) && $d['watts'] !== '' ? (float)$d['watts'] : null,
            'connector_type' => ($d['connector_type'] ?? '') !== '' ? (string)$d['connector_type'] : null,
            'pdu_id' => $pduId,
            'pdu_outlet_id' => $outletId,
            'sort_order' => (int)($d['sort_order'] ?? 0),
            'notes' => ($d['notes'] ?? '') !== '' ? (string)$d['notes'] : null,
        ]);
        // Mirror link on outlet if set
        if ($outletId) {
            Database::update('pdu_outlets', [
                'connected_device_id' => $deviceId,
                'device_power_supply_id' => $id,
            ], 'outlet_id = :id', [':id' => $outletId]);
        }
        App::json([
            'power_supply' => dps_fetch_row($id),
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
            'SELECT device_id, department_id, cabinet_id FROM devices WHERE device_id = ?',
            [(int)$existing['device_id']]
        );
        if (!$dev || !AuthManager::canEditDevice($user, $dev)) {
            App::json(['error' => 'Forbidden — department ownership'], 403);
        }

        $fields = [];
        foreach (['name', 'watts', 'connector_type', 'sort_order', 'notes'] as $k) {
            if (array_key_exists($k, $d)) {
                if ($k === 'watts') {
                    $fields[$k] = ($d[$k] === '' || $d[$k] === null) ? null : (float)$d[$k];
                } elseif ($k === 'sort_order') {
                    $fields[$k] = (int)$d[$k];
                } else {
                    $fields[$k] = $d[$k] === '' ? null : $d[$k];
                }
            }
        }

        $mapTouched = array_key_exists('pdu_id', $d) || array_key_exists('pdu_outlet_id', $d);
        $newPdu = array_key_exists('pdu_id', $d)
            ? dps_nullable_int($d['pdu_id'])
            : dps_nullable_int($existing['pdu_id'] ?? null);
        $newOutlet = array_key_exists('pdu_outlet_id', $d)
            ? dps_nullable_int($d['pdu_outlet_id'])
            : dps_nullable_int($existing['pdu_outlet_id'] ?? null);

        if ($mapTouched) {
            // Clearing PDU also clears outlet
            if ($newPdu === null) {
                $newOutlet = null;
            }
            [$newPdu, $newOutlet] = dps_resolve_outlet_map(
                (int)$existing['device_id'],
                $newPdu,
                $newOutlet,
                $id
            );
            $fields['pdu_id'] = $newPdu;
            $fields['pdu_outlet_id'] = $newOutlet;
        }

        if ($fields) {
            Database::update('device_power_supplies', $fields, 'power_supply_id = :id', [':id' => $id]);
        }

        $oldOutlet = dps_nullable_int($existing['pdu_outlet_id'] ?? null);
        $finalOutlet = array_key_exists('pdu_outlet_id', $fields)
            ? dps_nullable_int($fields['pdu_outlet_id'])
            : $oldOutlet;

        // Clear old outlet link if outlet changed or unmapped
        if ($oldOutlet !== null && $oldOutlet !== $finalOutlet) {
            Database::update('pdu_outlets', [
                'connected_device_id' => null,
                'device_power_supply_id' => null,
            ], 'outlet_id = :id', [':id' => $oldOutlet]);
        }
        if ($finalOutlet !== null) {
            Database::update('pdu_outlets', [
                'connected_device_id' => (int)$existing['device_id'],
                'device_power_supply_id' => $id,
            ], 'outlet_id = :id', [':id' => $finalOutlet]);
        }

        App::json([
            'power_supply' => dps_fetch_row($id),
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
} catch (InvalidArgumentException $e) {
    App::json(['error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    App::log('API device_power: ' . $e->getMessage(), 'error');
    App::json(['error' => $e->getMessage()], 500);
}
