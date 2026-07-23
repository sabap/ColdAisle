<?php
/**
 * Cabinet physical audit helpers — schedules, compliance, queries.
 */
declare(strict_types=1);

/** Default audit cadence in days when not configured (quarterly). */
function audit_default_interval_days(): int
{
    $v = (int) SettingsService::get('audit_interval_days', '90');
    return $v >= 1 && $v <= 3650 ? $v : 90;
}

/** @return list<array{days:int,label:string}> */
function audit_interval_presets(): array
{
    return [
        ['days' => 30, 'label' => 'Monthly (30 days)'],
        ['days' => 90, 'label' => 'Quarterly (90 days)'],
        ['days' => 180, 'label' => 'Semi-annual (180 days)'],
        ['days' => 365, 'label' => 'Annual (365 days)'],
    ];
}

function audit_interval_label(int $days): string
{
    foreach (audit_interval_presets() as $p) {
        if ($p['days'] === $days) {
            return $p['label'];
        }
    }
    return $days . ' days';
}

/**
 * Effective interval for a cabinet row (uses cabinet override or global).
 * @param array<string,mixed> $cab
 */
function audit_cabinet_interval_days(array $cab): int
{
    $own = isset($cab['audit_interval_days']) && $cab['audit_interval_days'] !== null && $cab['audit_interval_days'] !== ''
        ? (int)$cab['audit_interval_days'] : 0;
    if ($own >= 1) {
        return min(3650, $own);
    }
    return audit_default_interval_days();
}

/**
 * @return array{next_due:?string,status:string,days_until:?int,last_audit:?string}
 */
function audit_cabinet_schedule(?string $lastAuditAt, int $intervalDays, ?DateTimeInterface $now = null): array
{
    $now = $now ?? new DateTimeImmutable('now');
    if ($lastAuditAt === null || trim($lastAuditAt) === '') {
        return [
            'next_due' => null,
            'status' => 'never',
            'days_until' => null,
            'last_audit' => null,
        ];
    }
    try {
        $last = new DateTimeImmutable($lastAuditAt);
    } catch (Throwable $e) {
        return [
            'next_due' => null,
            'status' => 'never',
            'days_until' => null,
            'last_audit' => null,
        ];
    }
    $due = $last->modify('+' . max(1, $intervalDays) . ' days')->setTime(0, 0, 0);
    $today = $now->setTime(0, 0, 0);
    $diff = (int)$today->diff($due)->format('%r%a');
    // %r%a: negative if due is in the past
    $status = 'ok';
    if ($diff < 0) {
        $status = 'overdue';
    } elseif ($diff <= 14) {
        $status = 'due_soon';
    }
    return [
        'next_due' => $due->format('Y-m-d'),
        'status' => $status,
        'days_until' => $diff,
        'last_audit' => $last->format('Y-m-d H:i'),
    ];
}

/**
 * Compliance summary across active cabinets.
 * @return array{
 *   total:int,compliant:int,overdue:int,due_soon:int,never:int,
 *   compliance_pct:float,interval_days:int
 * }
 */
function audit_compliance_summary(): array
{
    $intervalDefault = audit_default_interval_days();
    $total = 0;
    $compliant = 0;
    $overdue = 0;
    $dueSoon = 0;
    $never = 0;
    try {
        $rows = Database::fetchAll(
            'SELECT c.cabinet_id, c.audit_interval_days,
                    (SELECT MAX(a.audited_at) FROM cabinet_audits a WHERE a.cabinet_id = c.cabinet_id) AS last_audit_at
             FROM cabinets c
             WHERE c.is_active = 1'
        );
        foreach ($rows as $r) {
            $total++;
            $iv = audit_cabinet_interval_days($r);
            $sch = audit_cabinet_schedule(
                isset($r['last_audit_at']) ? (string)$r['last_audit_at'] : null,
                $iv
            );
            switch ($sch['status']) {
                case 'ok':
                    $compliant++;
                    break;
                case 'due_soon':
                    $dueSoon++;
                    // still in compliance window
                    $compliant++;
                    break;
                case 'overdue':
                    $overdue++;
                    break;
                default:
                    $never++;
                    $overdue++; // never audited counts as non-compliant
                    break;
            }
        }
    } catch (Throwable $e) {
        // table missing etc.
    }
    $pct = $total > 0 ? round(100 * $compliant / $total, 1) : 100.0;
    return [
        'total' => $total,
        'compliant' => $compliant,
        'overdue' => $overdue,
        'due_soon' => $dueSoon,
        'never' => $never,
        'compliance_pct' => $pct,
        'interval_days' => $intervalDefault,
    ];
}

/**
 * Cabinets that are overdue or never audited (for dashboards).
 * @return list<array<string,mixed>>
 */
function audit_overdue_cabinets(int $limit = 50): array
{
    $out = [];
    try {
        $rows = Database::fetchAll(
            'SELECT c.cabinet_id, c.name, c.audit_interval_days,
                    cr.row_id, cr.name AS row_name,
                    rm.name AS room_name, dc.name AS dc_name,
                    (SELECT MAX(a.audited_at) FROM cabinet_audits a WHERE a.cabinet_id = c.cabinet_id) AS last_audit_at,
                    (SELECT TOP 1 a.audited_by_name FROM cabinet_audits a
                     WHERE a.cabinet_id = c.cabinet_id ORDER BY a.audited_at DESC) AS last_auditor
             FROM cabinets c
             LEFT JOIN cabinet_rows cr ON cr.row_id = c.row_id
             LEFT JOIN rooms rm ON rm.room_id = c.room_id
             LEFT JOIN datacenters dc ON dc.datacenter_id = rm.datacenter_id
             WHERE c.is_active = 1
             ORDER BY c.name'
        );
        foreach ($rows as $r) {
            $iv = audit_cabinet_interval_days($r);
            $sch = audit_cabinet_schedule(
                isset($r['last_audit_at']) ? (string)$r['last_audit_at'] : null,
                $iv
            );
            if (!in_array($sch['status'], ['overdue', 'never'], true)) {
                continue;
            }
            $r['interval_days'] = $iv;
            $r['next_due'] = $sch['next_due'];
            $r['audit_status'] = $sch['status'];
            $r['days_until'] = $sch['days_until'];
            $out[] = $r;
            if (count($out) >= $limit) {
                break;
            }
        }
        // Sort: never first, then most overdue
        usort($out, static function ($a, $b) {
            $sa = $a['audit_status'] === 'never' ? -9999 : (int)($a['days_until'] ?? 0);
            $sb = $b['audit_status'] === 'never' ? -9999 : (int)($b['days_until'] ?? 0);
            return $sa <=> $sb;
        });
    } catch (Throwable $e) {
        return [];
    }
    return $out;
}

/**
 * @param array{row_id?:int,cabinet_id?:int,user_id?:int,date_from?:string,date_to?:string,limit?:int,offset?:int} $filters
 * @return array{rows:list<array>,total:int}
 */
function audit_cabinet_audit_list(array $filters = []): array
{
    $limit = max(1, min(200, (int)($filters['limit'] ?? 25)));
    $offset = max(0, (int)($filters['offset'] ?? 0));
    $where = ['1=1'];
    $params = [];

    if (!empty($filters['cabinet_id'])) {
        $where[] = 'a.cabinet_id = ?';
        $params[] = (int)$filters['cabinet_id'];
    }
    if (!empty($filters['row_id'])) {
        $where[] = 'c.row_id = ?';
        $params[] = (int)$filters['row_id'];
    }
    if (!empty($filters['user_id'])) {
        $where[] = 'a.audited_by = ?';
        $params[] = (int)$filters['user_id'];
    }
    if (!empty($filters['date_from'])) {
        $where[] = 'a.audited_at >= ?';
        $params[] = $filters['date_from'] . ' 00:00:00';
    }
    if (!empty($filters['date_to'])) {
        $where[] = 'a.audited_at < ?';
        // inclusive end date → next day
        try {
            $end = (new DateTimeImmutable($filters['date_to']))->modify('+1 day')->format('Y-m-d');
        } catch (Throwable $e) {
            $end = $filters['date_to'];
        }
        $params[] = $end . ' 00:00:00';
    }

    $sqlWhere = implode(' AND ', $where);
    try {
        $total = (int) Database::fetchValue(
            "SELECT COUNT(*) FROM cabinet_audits a
             INNER JOIN cabinets c ON c.cabinet_id = a.cabinet_id
             WHERE {$sqlWhere}",
            $params
        );
        $rows = Database::fetchAll(
            "SELECT a.cabinet_audit_id, a.cabinet_id, a.audited_by, a.audited_by_name,
                    a.certified, a.comments, a.audited_at,
                    c.name AS cabinet_name, c.row_id,
                    cr.name AS row_name, rm.name AS room_name, dc.name AS dc_name
             FROM cabinet_audits a
             INNER JOIN cabinets c ON c.cabinet_id = a.cabinet_id
             LEFT JOIN cabinet_rows cr ON cr.row_id = c.row_id
             LEFT JOIN rooms rm ON rm.room_id = c.room_id
             LEFT JOIN datacenters dc ON dc.datacenter_id = rm.datacenter_id
             WHERE {$sqlWhere}
             ORDER BY a.audited_at DESC
             OFFSET {$offset} ROWS FETCH NEXT {$limit} ROWS ONLY",
            $params
        );
        return ['rows' => $rows, 'total' => $total];
    } catch (Throwable $e) {
        return ['rows' => [], 'total' => 0];
    }
}

/**
 * System audit_log page slice.
 * @return array{rows:list<array>,total:int}
 */
function audit_system_log_page(int $limit = 50, int $offset = 0): array
{
    $limit = max(1, min(200, $limit));
    $offset = max(0, $offset);
    try {
        $total = (int) Database::fetchValue('SELECT COUNT(*) FROM audit_log');
        $rows = Database::fetchAll(
            "SELECT audit_id, user_id, username, action, entity_type, entity_id, details, ip_address, created_at
             FROM audit_log
             ORDER BY created_at DESC
             OFFSET {$offset} ROWS FETCH NEXT {$limit} ROWS ONLY"
        );
        return ['rows' => $rows, 'total' => $total];
    } catch (Throwable $e) {
        return ['rows' => [], 'total' => 0];
    }
}

function audit_status_badge_class(string $status): string
{
    return match ($status) {
        'ok' => 'badge-success',
        'due_soon' => 'badge-warning',
        'overdue', 'never' => 'badge-danger',
        default => 'badge',
    };
}

function audit_status_label(string $status): string
{
    return match ($status) {
        'ok' => 'In compliance',
        'due_soon' => 'Due soon',
        'overdue' => 'Overdue',
        'never' => 'Never audited',
        default => $status,
    };
}
