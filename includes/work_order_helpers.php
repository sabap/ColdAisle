<?php
/**
 * Change / move work order helpers (G-B2).
 */
declare(strict_types=1);

/** @return array<string,string> */
function work_order_types(): array
{
    return [
        'move' => 'Rack move',
        'install' => 'Install / rack-in',
        'relocate_pdu' => 'PDU relocate',
        'other' => 'Other change',
    ];
}

/** @return array<string,string> */
function work_order_statuses(): array
{
    return [
        'draft' => 'Draft',
        'planned' => 'Planned',
        'in_progress' => 'In progress',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
    ];
}

/** @return array<string,string> */
function work_order_item_statuses(): array
{
    return [
        'pending' => 'Pending',
        'done' => 'Done',
        'skipped' => 'Skipped',
    ];
}

/**
 * Default checklist for move-type work orders.
 *
 * @return list<array{id:string,label:string,done:bool,done_at:?string,done_by:?string}>
 */
function work_order_default_checklist(string $workType = 'move'): array
{
    $labels = match ($workType) {
        'install' => [
            'Confirm change ticket / window',
            'Verify destination rack space',
            'Label / asset check',
            'Rack and cable',
            'Power / network verify',
            'Update DCIM inventory',
        ],
        default => [
            'Confirm change ticket / window',
            'Verify source location in rack',
            'Label / asset check',
            'Power down / disconnect',
            'Physical move',
            'Install at destination',
            'Power / network verify',
            'Update DCIM inventory',
        ],
    };
    $out = [];
    $i = 0;
    foreach ($labels as $lab) {
        $i++;
        $out[] = [
            'id' => 'c' . $i,
            'label' => $lab,
            'done' => false,
            'done_at' => null,
            'done_by' => null,
        ];
    }
    return $out;
}

/**
 * @param mixed $json
 * @return list<array{id:string,label:string,done:bool,done_at:?string,done_by:?string}>
 */
function work_order_checklist_decode($json): array
{
    if ($json === null || $json === '') {
        return [];
    }
    if (is_array($json)) {
        $data = $json;
    } else {
        $data = json_decode((string)$json, true);
    }
    if (!is_array($data)) {
        return [];
    }
    $out = [];
    foreach ($data as $row) {
        if (!is_array($row) || empty($row['id'])) {
            continue;
        }
        $out[] = [
            'id' => (string)$row['id'],
            'label' => (string)($row['label'] ?? $row['id']),
            'done' => !empty($row['done']),
            'done_at' => isset($row['done_at']) && $row['done_at'] !== '' ? (string)$row['done_at'] : null,
            'done_by' => isset($row['done_by']) && $row['done_by'] !== '' ? (string)$row['done_by'] : null,
        ];
    }
    return $out;
}

/** @param list<array{id:string,label:string,done:bool,done_at:?string,done_by:?string}> $list */
function work_order_checklist_encode(array $list): string
{
    return json_encode(array_values($list), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
}

function work_order_null($v)
{
    if ($v === null || (is_string($v) && trim($v) === '')) {
        return null;
    }
    return is_string($v) ? trim($v) : $v;
}

function work_order_status_badge_class(string $status): string
{
    return match ($status) {
        'completed' => 'badge-success',
        'cancelled' => 'badge-muted',
        'in_progress' => 'badge-warning',
        'planned' => 'badge-info',
        default => 'badge',
    };
}

/**
 * Apply done items' destinations to devices. Soft-skips U conflicts.
 *
 * @return array{applied:int,skipped:int,errors:list<string>}
 */
/**
 * @param array{user_id?:int,username?:string,display_name?:string}|null $actor
 */
function work_order_apply_destinations(int $workOrderId, ?array $actor = null): array
{
    $result = ['applied' => 0, 'skipped' => 0, 'errors' => []];
    try {
        $items = Database::fetchAll(
            "SELECT i.*, d.label AS device_label, d.cabinet_id AS cur_cabinet_id, d.position_u AS cur_position_u
             FROM work_order_items i
             INNER JOIN devices d ON d.device_id = i.device_id
             WHERE i.work_order_id = ? AND i.item_status = 'done' AND i.to_cabinet_id IS NOT NULL",
            [$workOrderId]
        );
    } catch (Throwable $e) {
        $result['errors'][] = $e->getMessage();
        return $result;
    }

    $uid = (int)($actor['user_id'] ?? 0);
    $uname = (string)($actor['username'] ?? $actor['display_name'] ?? 'system');

    foreach ($items as $it) {
        $deviceId = (int)$it['device_id'];
        $toCab = (int)$it['to_cabinet_id'];
        $toU = $it['to_position_u'] !== null && $it['to_position_u'] !== ''
            ? (int)$it['to_position_u'] : null;
        $label = (string)($it['device_label'] ?? ('#' . $deviceId));

        if ($toU !== null && $toU > 0) {
            try {
                $conflict = Database::fetchOne(
                    'SELECT device_id, label FROM devices
                     WHERE is_active = 1 AND cabinet_id = ? AND position_u = ?
                       AND device_id <> ? AND parent_device_id IS NULL',
                    [$toCab, $toU, $deviceId]
                );
                if ($conflict) {
                    $result['skipped']++;
                    $result['errors'][] = sprintf(
                        '%s: U%d occupied by %s — skipped',
                        $label,
                        $toU,
                        (string)($conflict['label'] ?? ('#' . $conflict['device_id']))
                    );
                    continue;
                }
            } catch (Throwable $e) {
                // If conflict query fails, still try apply
            }
        }

        try {
            Database::update('devices', [
                'cabinet_id' => $toCab,
                'position_u' => $toU,
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'device_id = :id', [':id' => $deviceId]);
            $result['applied']++;
            if (class_exists('AuditService')) {
                AuditService::log(
                    $uid,
                    $uname,
                    'work_order_apply_device',
                    'device',
                    $deviceId,
                    [
                        'work_order_id' => $workOrderId,
                        'to_cabinet_id' => $toCab,
                        'to_position_u' => $toU,
                        'from_cabinet_id' => $it['from_cabinet_id'] ?? null,
                        'from_position_u' => $it['from_position_u'] ?? null,
                    ]
                );
            }
        } catch (Throwable $e) {
            $result['skipped']++;
            $result['errors'][] = $label . ': ' . $e->getMessage();
        }
    }

    return $result;
}
