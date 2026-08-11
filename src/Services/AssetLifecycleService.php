<?php
/**
 * ColdAisle — asset lifecycle (G-B3).
 *
 * Chain-of-custody style event log, PO/RMA helpers, warranty digest queries.
 */
declare(strict_types=1);

class AssetLifecycleService
{
    public const EVENT_CREATED = 'created';
    public const EVENT_STATUS = 'status_change';
    public const EVENT_LOCATION = 'location_change';
    public const EVENT_OWNERSHIP = 'ownership_change';
    public const EVENT_WARRANTY = 'warranty_change';
    public const EVENT_RMA = 'rma';
    public const EVENT_PURCHASE = 'purchase';
    public const EVENT_NOTE = 'note';
    public const EVENT_CUSTODY = 'custody';
    public const EVENT_DECOMMISSION = 'decommission';

    public const SETTING_WARRANTY_MAIL = 'warranty_mail_enabled';
    public const SETTING_WARRANTY_EMAIL = 'warranty_notify_email';
    public const SETTING_WARRANTY_DAYS = 'warranty_notify_days';

    /** @return array<string,string> */
    public static function eventLabels(): array
    {
        return [
            self::EVENT_CREATED => 'Created',
            self::EVENT_STATUS => 'Status change',
            self::EVENT_LOCATION => 'Location change',
            self::EVENT_OWNERSHIP => 'Ownership change',
            self::EVENT_WARRANTY => 'Warranty change',
            self::EVENT_RMA => 'RMA',
            self::EVENT_PURCHASE => 'Purchase / PO',
            self::EVENT_NOTE => 'Note',
            self::EVENT_CUSTODY => 'Chain of custody',
            self::EVENT_DECOMMISSION => 'Decommission',
        ];
    }

    /** @return array<string,string> */
    public static function rmaStatuses(): array
    {
        return [
            '' => '— None —',
            'none' => 'None',
            'open' => 'Open',
            'shipped' => 'Shipped to vendor',
            'received' => 'Received back',
            'closed' => 'Closed',
        ];
    }

    /**
     * @param array{user_id?:int|null,username?:string|null}|null $actor
     * @return int event_id or 0
     */
    public static function logEvent(
        int $deviceId,
        string $eventType,
        string $summary,
        ?array $actor = null,
        ?string $fromValue = null,
        ?string $toValue = null,
        ?string $notes = null,
        ?array $meta = null
    ): int {
        if ($deviceId < 1) {
            return 0;
        }
        $labels = self::eventLabels();
        if (!isset($labels[$eventType])) {
            $eventType = self::EVENT_NOTE;
        }
        $summary = trim($summary);
        if ($summary === '') {
            $summary = $labels[$eventType] ?? $eventType;
        }
        try {
            $id = Database::insert('asset_events', [
                'device_id' => $deviceId,
                'event_type' => $eventType,
                'summary' => mb_substr($summary, 0, 500),
                'from_value' => $fromValue !== null ? mb_substr($fromValue, 0, 255) : null,
                'to_value' => $toValue !== null ? mb_substr($toValue, 0, 255) : null,
                'notes' => $notes !== null && $notes !== '' ? $notes : null,
                'meta_json' => $meta ? json_encode($meta, JSON_UNESCAPED_SLASHES) : null,
                'performed_by' => isset($actor['user_id']) ? (int)$actor['user_id'] : null,
                'performed_by_name' => isset($actor['username'])
                    ? mb_substr((string)$actor['username'], 0, 150)
                    : null,
                'occurred_at' => date('Y-m-d H:i:s'),
            ]);
            return (int)$id;
        } catch (Throwable $e) {
            App::log('AssetLifecycleService::logEvent: ' . $e->getMessage(), 'error');
            return 0;
        }
    }

    /**
     * Compare before/after device rows and write lifecycle events.
     *
     * @param array<string,mixed>|null $before
     * @param array<string,mixed> $after
     * @param array{user_id?:int|null,username?:string|null}|null $actor
     */
    public static function logDeviceChanges(?array $before, array $after, int $deviceId, ?array $actor = null): void
    {
        if ($before === null) {
            self::logEvent(
                $deviceId,
                self::EVENT_CREATED,
                'Device created: ' . (string)($after['label'] ?? ('#' . $deviceId)),
                $actor,
                null,
                (string)($after['status'] ?? 'production')
            );
            return;
        }

        $statusFrom = (string)($before['status'] ?? '');
        $statusTo = (string)($after['status'] ?? '');
        if ($statusFrom !== $statusTo) {
            $type = $statusTo === 'disposed' || $statusTo === 'decommissioning'
                ? self::EVENT_DECOMMISSION
                : self::EVENT_STATUS;
            self::logEvent(
                $deviceId,
                $type,
                "Status: {$statusFrom} → {$statusTo}",
                $actor,
                $statusFrom,
                $statusTo
            );
        }

        $locFrom = self::formatLocation($before);
        $locTo = self::formatLocation($after);
        if ($locFrom !== $locTo) {
            self::logEvent(
                $deviceId,
                self::EVENT_LOCATION,
                "Location: {$locFrom} → {$locTo}",
                $actor,
                $locFrom,
                $locTo
            );
        }

        $deptFrom = (string)($before['department_id'] ?? '');
        $deptTo = (string)($after['department_id'] ?? '');
        $contactFrom = (string)($before['owner_contact_id'] ?? '');
        $contactTo = (string)($after['owner_contact_id'] ?? '');
        if ($deptFrom !== $deptTo || $contactFrom !== $contactTo) {
            self::logEvent(
                $deviceId,
                self::EVENT_OWNERSHIP,
                'Department/contact updated',
                $actor,
                'dept=' . ($deptFrom !== '' ? $deptFrom : 'none') . ' contact=' . ($contactFrom !== '' ? $contactFrom : 'none'),
                'dept=' . ($deptTo !== '' ? $deptTo : 'none') . ' contact=' . ($contactTo !== '' ? $contactTo : 'none')
            );
        }

        $wFrom = trim((string)($before['warranty_end'] ?? '') . '|' . (string)($before['warranty_provider'] ?? ''));
        $wTo = trim((string)($after['warranty_end'] ?? '') . '|' . (string)($after['warranty_provider'] ?? ''));
        if ($wFrom !== $wTo) {
            // Reset warranty digest tracking when end date changes
            if ((string)($before['warranty_end'] ?? '') !== (string)($after['warranty_end'] ?? '')) {
                try {
                    Database::update('devices', [
                        'warranty_notify_for_end' => null,
                    ], 'device_id = :id', [':id' => $deviceId]);
                } catch (Throwable $e) {
                    // column may not exist yet mid-upgrade
                }
            }
            self::logEvent(
                $deviceId,
                self::EVENT_WARRANTY,
                'Warranty: '
                    . ((string)($before['warranty_end'] ?? '—') ?: '—')
                    . ' → '
                    . ((string)($after['warranty_end'] ?? '—') ?: '—'),
                $actor,
                (string)($before['warranty_end'] ?? ''),
                (string)($after['warranty_end'] ?? ''),
                (string)($after['warranty_provider'] ?? '') !== ''
                    ? 'Provider: ' . (string)$after['warranty_provider']
                    : null
            );
        }

        $poFrom = trim((string)($before['po_number'] ?? '') . '|' . (string)($before['purchase_date'] ?? ''));
        $poTo = trim((string)($after['po_number'] ?? '') . '|' . (string)($after['purchase_date'] ?? ''));
        if ($poFrom !== $poTo) {
            self::logEvent(
                $deviceId,
                self::EVENT_PURCHASE,
                'PO/purchase updated'
                    . (!empty($after['po_number']) ? ': ' . $after['po_number'] : ''),
                $actor,
                (string)($before['po_number'] ?? ''),
                (string)($after['po_number'] ?? '')
            );
        }

        $rmaFrom = trim((string)($before['rma_number'] ?? '') . '|' . (string)($before['rma_status'] ?? ''));
        $rmaTo = trim((string)($after['rma_number'] ?? '') . '|' . (string)($after['rma_status'] ?? ''));
        if ($rmaFrom !== $rmaTo) {
            $rs = (string)($after['rma_status'] ?? '');
            $rn = (string)($after['rma_number'] ?? '');
            self::logEvent(
                $deviceId,
                self::EVENT_RMA,
                'RMA ' . ($rn !== '' ? $rn . ' ' : '') . ($rs !== '' && $rs !== 'none' ? "({$rs})" : 'cleared'),
                $actor,
                $rmaFrom,
                $rmaTo,
                !empty($after['rma_notes']) ? (string)$after['rma_notes'] : null
            );
        }
    }

    /** @param array<string,mixed> $row */
    public static function formatLocation(array $row): string
    {
        $cab = $row['cabinet_id'] ?? null;
        $u = $row['position_u'] ?? null;
        if ($cab === null || $cab === '') {
            return 'unracked';
        }
        $uPart = ($u !== null && $u !== '') ? ' U' . $u : '';
        return 'cab#' . $cab . $uPart;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function listEvents(int $deviceId, int $limit = 100): array
    {
        if ($deviceId < 1) {
            return [];
        }
        $limit = max(1, min(500, $limit));
        try {
            return Database::fetchAll(
                "SELECT TOP ({$limit}) *
                 FROM asset_events
                 WHERE device_id = ?
                 ORDER BY occurred_at DESC, event_id DESC",
                [$deviceId]
            );
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Devices (and optional infrastructure) with warranty ending within window or already expired.
     *
     * @return list<array<string,mixed>>
     */
    public static function warrantyDueRows(int $days, bool $pendingNotifyOnly = false): array
    {
        $days = max(0, min(730, $days));
        $sql = "SELECT d.device_id, d.label, d.serial_no, d.asset_tag, d.manufacturer, d.model,
                       d.warranty_end, d.warranty_provider, d.status, d.department_id,
                       d.warranty_notify_for_end,
                       dep.name AS department_name,
                       'device' AS entity_kind
                FROM devices d
                LEFT JOIN departments dep ON dep.department_id = d.department_id
                WHERE d.is_active = 1
                  AND d.status NOT IN ('disposed')
                  AND d.warranty_end IS NOT NULL
                  AND d.warranty_end <= DATEADD(day, ?, CAST(GETUTCDATE() AS date))";
        if ($pendingNotifyOnly) {
            // Not yet emailed for this warranty_end value
            $sql .= " AND (d.warranty_notify_for_end IS NULL
                           OR CAST(d.warranty_notify_for_end AS date) <> CAST(d.warranty_end AS date))";
        }
        $sql .= ' ORDER BY d.warranty_end, d.label';
        try {
            return Database::fetchAll($sql, [$days]);
        } catch (Throwable $e) {
            App::log('warrantyDueRows: ' . $e->getMessage(), 'error');
            return [];
        }
    }

    /**
     * Days until warranty end (negative = expired).
     */
    public static function daysUntil(?string $warrantyEnd): ?int
    {
        if ($warrantyEnd === null || trim($warrantyEnd) === '') {
            return null;
        }
        try {
            $end = new DateTimeImmutable(substr($warrantyEnd, 0, 10));
            $today = new DateTimeImmutable('today');
            return (int)$today->diff($end)->format('%r%a');
        } catch (Throwable $e) {
            return null;
        }
    }

    /** @return array{label:string,class:string} */
    public static function warrantyBadge(?int $days): array
    {
        if ($days === null) {
            return ['label' => '—', 'class' => ''];
        }
        if ($days < 0) {
            return ['label' => abs($days) . 'd overdue', 'class' => 'badge-danger'];
        }
        if ($days === 0) {
            return ['label' => 'expires today', 'class' => 'badge-danger'];
        }
        if ($days <= 30) {
            return ['label' => $days . 'd left', 'class' => 'badge-warning'];
        }
        if ($days <= 90) {
            return ['label' => $days . 'd left', 'class' => 'badge-info'];
        }
        return ['label' => $days . 'd left', 'class' => 'badge'];
    }

    /**
     * Mark devices as notified for their current warranty_end.
     * @param list<int> $deviceIds
     */
    public static function markWarrantyNotified(array $deviceIds): void
    {
        foreach ($deviceIds as $id) {
            $id = (int)$id;
            if ($id < 1) {
                continue;
            }
            try {
                Database::query(
                    'UPDATE devices
                     SET warranty_notify_for_end = warranty_end
                     WHERE device_id = ? AND warranty_end IS NOT NULL',
                    [$id]
                );
            } catch (Throwable $e) {
                App::log('markWarrantyNotified: ' . $e->getMessage(), 'error');
            }
        }
    }
}
