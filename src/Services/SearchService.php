<?php
/**
 * Site-wide inventory lookup (desktop chrome + list ?q= filters).
 */
declare(strict_types=1);

class SearchService
{
    public const MIN_CHARS = 1;
    public const PER_TYPE = 8;

    public static function queryFromRequest(): string
    {
        return trim((string)($_GET['q'] ?? ''));
    }

    public static function like(string $q): string
    {
        return '%' . $q . '%';
    }

    /**
     * Permission-aware grouped hits for the top-bar typeahead.
     *
     * @param array<string,mixed> $user
     * @return array{q:string,total:int,groups:list<array<string,mixed>>}
     */
    public static function lookup(array $user, string $q, int $perType = self::PER_TYPE): array
    {
        $q = trim($q);
        $groups = [];
        if ($q === '' || (mb_strlen($q) < self::MIN_CHARS && !ctype_digit($q))) {
            return ['q' => $q, 'total' => 0, 'groups' => []];
        }

        $specs = [
            ['cabinets', 'Cabinets', 'view_cabinets', 'pages/cabinets.php', fn () => self::searchCabinets($q, $perType)],
            ['devices', 'Devices', 'view_devices', 'pages/devices.php', fn () => self::searchDevices($q, $perType)],
            ['pdus', 'PDUs', 'view_power', 'pages/power_pdus.php', fn () => self::searchPdus($q, $perType)],
            ['ups', 'UPS', 'view_power', 'pages/power_ups.php', fn () => self::searchUps($q, $perType)],
            ['cables', 'Cables', 'view_cables', 'pages/cables.php', fn () => self::searchCables($q, $perType)],
            ['ipam', 'IPAM', 'view_ipam', 'pages/ipam.php', fn () => self::searchIpam($q, $perType)],
            ['work_orders', 'Work orders', 'view_work_orders', 'pages/work_orders.php', fn () => self::searchWorkOrders($q, $perType)],
            ['users', 'Users', 'manage_users', 'pages/users.php', fn () => self::searchUsers($q, $perType)],
        ];

        $total = 0;
        foreach ($specs as [$type, $label, $perm, $listPath, $fn]) {
            if (!AuthManager::can($user, $perm)) {
                continue;
            }
            $hits = $fn();
            if ($hits === []) {
                continue;
            }
            $total += count($hits);
            $groups[] = [
                'type' => $type,
                'label' => $label,
                'href' => App::url($listPath . '?q=' . rawurlencode($q)),
                'hits' => $hits,
            ];
        }

        return ['q' => $q, 'total' => $total, 'groups' => $groups];
    }

    /**
     * @return list<array{id:int,title:string,subtitle:string,href:string}>
     */
    private static function searchCabinets(string $q, int $limit): array
    {
        $like = self::like($q);
        $sql = 'SELECT TOP ' . (int)$limit . '
                    c.cabinet_id, c.name, c.location_tag, r.name AS room_name, dc.name AS dc_name, cr.name AS row_name
                FROM cabinets c
                INNER JOIN rooms r ON r.room_id = c.room_id
                INNER JOIN datacenters dc ON dc.datacenter_id = r.datacenter_id
                LEFT JOIN cabinet_rows cr ON cr.row_id = c.row_id
                WHERE c.is_active = 1
                  AND (c.name LIKE ? OR ISNULL(c.location_tag, \'\') LIKE ?
                       OR r.name LIKE ? OR dc.name LIKE ? OR ISNULL(cr.name, \'\') LIKE ?
                       OR CAST(c.cabinet_id AS NVARCHAR(20)) = ?)
                ORDER BY CASE WHEN c.name = ? OR CAST(c.cabinet_id AS NVARCHAR(20)) = ? THEN 0 ELSE 1 END, c.name';
        return self::mapRows($sql, [$like, $like, $like, $like, $like, $q, $q, $q], static function (array $row): array {
            $bits = array_filter([
                (string)($row['dc_name'] ?? ''),
                (string)($row['room_name'] ?? ''),
                (string)($row['row_name'] ?? ''),
                (string)($row['location_tag'] ?? ''),
            ]);
            $id = (int)$row['cabinet_id'];
            return [
                'id' => $id,
                'title' => (string)$row['name'],
                'subtitle' => $bits !== [] ? implode(' · ', $bits) : 'Cabinet',
                'href' => App::url('pages/cabinets.php?id=' . $id),
            ];
        });
    }

    /**
     * @return list<array{id:int,title:string,subtitle:string,href:string}>
     */
    private static function searchDevices(string $q, int $limit): array
    {
        $like = self::like($q);
        $sql = 'SELECT TOP ' . (int)$limit . '
                    d.device_id, d.label, d.serial_no, d.asset_tag, d.primary_ip, d.hostname,
                    c.name AS cabinet_name
                FROM devices d
                LEFT JOIN cabinets c ON c.cabinet_id = d.cabinet_id
                WHERE d.is_active = 1
                  AND (d.label LIKE ? OR ISNULL(d.hostname, \'\') LIKE ? OR ISNULL(d.serial_no, \'\') LIKE ?
                       OR ISNULL(d.asset_tag, \'\') LIKE ? OR ISNULL(d.primary_ip, \'\') LIKE ?
                       OR ISNULL(d.tags, \'\') LIKE ? OR CAST(d.device_id AS NVARCHAR(20)) = ?)
                ORDER BY CASE WHEN d.label = ? OR CAST(d.device_id AS NVARCHAR(20)) = ? THEN 0 ELSE 1 END, d.label';
        return self::mapRows($sql, [$like, $like, $like, $like, $like, $like, $q, $q, $q], static function (array $row): array {
            $bits = array_filter([
                (string)($row['cabinet_name'] ?? ''),
                (string)($row['primary_ip'] ?? ''),
                (string)($row['serial_no'] ?? ''),
                (string)($row['asset_tag'] ?? ''),
            ]);
            $id = (int)$row['device_id'];
            return [
                'id' => $id,
                'title' => (string)$row['label'],
                'subtitle' => $bits !== [] ? implode(' · ', $bits) : 'Device',
                'href' => App::url('pages/devices.php?id=' . $id),
            ];
        });
    }

    /**
     * @return list<array{id:int,title:string,subtitle:string,href:string}>
     */
    private static function searchPdus(string $q, int $limit): array
    {
        $like = self::like($q);
        $sql = 'SELECT TOP ' . (int)$limit . '
                    p.pdu_id, p.name, p.ip_address, p.pdu_scope, c.name AS cabinet_name,
                    r.name AS row_name, z.name AS zone_name
                FROM pdus p
                LEFT JOIN cabinets c ON c.cabinet_id = p.cabinet_id
                LEFT JOIN cabinet_rows r ON r.row_id = p.row_id
                LEFT JOIN power_zones z ON z.zone_id = p.zone_id
                WHERE p.is_active = 1
                  AND (p.name LIKE ? OR ISNULL(p.ip_address, \'\') LIKE ?
                       OR ISNULL(p.serial_no, \'\') LIKE ? OR ISNULL(p.manufacturer, \'\') LIKE ?
                       OR ISNULL(p.model, \'\') LIKE ? OR ISNULL(c.name, \'\') LIKE ?
                       OR ISNULL(r.name, \'\') LIKE ? OR ISNULL(z.name, \'\') LIKE ?
                       OR CAST(p.pdu_id AS NVARCHAR(20)) = ?)
                ORDER BY CASE WHEN p.name = ? OR CAST(p.pdu_id AS NVARCHAR(20)) = ? THEN 0 ELSE 1 END, p.name';
        return self::mapRows($sql, [$like, $like, $like, $like, $like, $like, $like, $like, $q, $q, $q], static function (array $row): array {
            $bits = array_filter([
                (string)($row['ip_address'] ?? ''),
                (string)($row['cabinet_name'] ?? $row['row_name'] ?? ''),
                (string)($row['zone_name'] ?? ''),
                (string)($row['pdu_scope'] ?? ''),
            ]);
            $id = (int)$row['pdu_id'];
            return [
                'id' => $id,
                'title' => (string)$row['name'],
                'subtitle' => $bits !== [] ? implode(' · ', $bits) : 'PDU',
                'href' => App::url('pages/power_pdus.php?id=' . $id),
            ];
        });
    }

    /**
     * @return list<array{id:int,title:string,subtitle:string,href:string}>
     */
    private static function searchUps(string $q, int $limit): array
    {
        $like = self::like($q);
        $sql = 'SELECT TOP ' . (int)$limit . '
                    u.ups_id, u.name, u.primary_ip, u.manufacturer, u.model, u.serial_no, u.asset_tag,
                    rm.name AS room_name, z.name AS zone_name
                FROM ups_units u
                LEFT JOIN rooms rm ON rm.room_id = u.room_id
                LEFT JOIN power_zones z ON z.zone_id = u.zone_id
                WHERE u.is_active = 1
                  AND (u.name LIKE ? OR ISNULL(u.primary_ip, \'\') LIKE ?
                       OR ISNULL(u.manufacturer, \'\') LIKE ? OR ISNULL(u.model, \'\') LIKE ?
                       OR ISNULL(u.serial_no, \'\') LIKE ? OR ISNULL(u.asset_tag, \'\') LIKE ?
                       OR ISNULL(rm.name, \'\') LIKE ? OR CAST(u.ups_id AS NVARCHAR(20)) = ?)
                ORDER BY CASE WHEN u.name = ? OR CAST(u.ups_id AS NVARCHAR(20)) = ? THEN 0 ELSE 1 END, u.name';
        return self::mapRows($sql, [$like, $like, $like, $like, $like, $like, $like, $q, $q, $q], static function (array $row): array {
            $model = trim((string)($row['manufacturer'] ?? '') . ' ' . (string)($row['model'] ?? ''));
            $bits = array_filter([
                (string)($row['primary_ip'] ?? ''),
                $model,
                (string)($row['room_name'] ?? ''),
                (string)($row['serial_no'] ?? ''),
            ]);
            $id = (int)$row['ups_id'];
            return [
                'id' => $id,
                'title' => (string)$row['name'],
                'subtitle' => $bits !== [] ? implode(' · ', $bits) : 'UPS',
                'href' => App::url('pages/power_ups.php?id=' . $id),
            ];
        });
    }

    /**
     * @return list<array{id:int,title:string,subtitle:string,href:string}>
     */
    private static function searchCables(string $q, int $limit): array
    {
        $like = self::like($q);
        $sql = 'SELECT TOP ' . (int)$limit . '
                    c.cable_id, c.cable_label, c.circuit_id, c.speed,
                    da.label AS a_device, db.label AS b_device,
                    ca.name AS a_cabinet, cb.name AS b_cabinet,
                    cp.path_code, cp.name AS path_name
                FROM cables c
                LEFT JOIN device_ports pa ON pa.port_id = c.a_port_id
                LEFT JOIN devices da ON da.device_id = pa.device_id
                LEFT JOIN cabinets ca ON ca.cabinet_id = da.cabinet_id
                LEFT JOIN device_ports pb ON pb.port_id = c.b_port_id
                LEFT JOIN devices db ON db.device_id = pb.device_id
                LEFT JOIN cabinets cb ON cb.cabinet_id = db.cabinet_id
                LEFT JOIN cable_paths cp ON cp.path_id = c.path_id
                WHERE ISNULL(c.cable_label, \'\') LIKE ? OR ISNULL(c.circuit_id, \'\') LIKE ?
                   OR ISNULL(da.label, \'\') LIKE ? OR ISNULL(db.label, \'\') LIKE ?
                   OR ISNULL(ca.name, \'\') LIKE ? OR ISNULL(cb.name, \'\') LIKE ?
                   OR ISNULL(cp.path_code, \'\') LIKE ? OR ISNULL(cp.name, \'\') LIKE ?
                   OR CAST(c.cable_id AS NVARCHAR(20)) = ?
                ORDER BY c.cable_id DESC';
        return self::mapRows($sql, [$like, $like, $like, $like, $like, $like, $like, $like, $q], static function (array $row): array {
            $label = trim((string)($row['cable_label'] ?? ''));
            if ($label === '') {
                $label = '#' . (int)$row['cable_id'];
            }
            $a = (string)($row['a_device'] ?? '');
            $b = (string)($row['b_device'] ?? '');
            $ends = trim($a . ($a !== '' && $b !== '' ? ' → ' : '') . $b);
            $bits = array_filter([
                $ends,
                (string)($row['circuit_id'] ?? ''),
                (string)($row['path_code'] ?: $row['path_name'] ?? ''),
            ]);
            $id = (int)$row['cable_id'];
            return [
                'id' => $id,
                'title' => $label,
                'subtitle' => $bits !== [] ? implode(' · ', $bits) : 'Cable',
                'href' => App::url('pages/cables.php?q=' . rawurlencode($label !== ('#' . $id) ? $label : (string)$id)),
            ];
        });
    }

    /**
     * @return list<array{id:int,title:string,subtitle:string,href:string}>
     */
    private static function searchIpam(string $q, int $limit): array
    {
        if (!class_exists('IpamService')) {
            return [];
        }
        try {
            IpamService::ensure();
        } catch (Throwable $e) {
            return [];
        }
        $like = self::like($q);
        $hits = [];
        try {
            $sql = 'SELECT TOP ' . (int)$limit . '
                        prefix_id, cidr, name, vlan_id, role
                    FROM ipam_prefixes
                    WHERE is_active = 1 AND (cidr LIKE ? OR ISNULL(name, \'\') LIKE ?
                       OR CAST(vlan_id AS NVARCHAR(20)) = ? OR CAST(prefix_id AS NVARCHAR(20)) = ?)
                    ORDER BY network_int';
            $hits = self::mapRows($sql, [$like, $like, $q, $q], static function (array $row): array {
                $id = (int)$row['prefix_id'];
                $bits = array_filter([
                    (string)($row['name'] ?? ''),
                    !empty($row['vlan_id']) ? ('VLAN ' . $row['vlan_id']) : '',
                    (string)($row['role'] ?? ''),
                ]);
                return [
                    'id' => $id,
                    'title' => (string)$row['cidr'],
                    'subtitle' => $bits !== [] ? implode(' · ', $bits) : 'Prefix',
                    'href' => App::url('pages/ipam.php?prefix_id=' . $id),
                ];
            });
        } catch (Throwable $e) {
            $hits = [];
        }
        if (count($hits) >= $limit) {
            return $hits;
        }
        $rest = $limit - count($hits);
        try {
            $sql = 'SELECT TOP ' . (int)$rest . '
                        a.address_id, a.ip, a.hostname, a.status, p.cidr, p.prefix_id
                    FROM ipam_addresses a
                    INNER JOIN ipam_prefixes p ON p.prefix_id = a.prefix_id
                    WHERE a.ip LIKE ? OR ISNULL(a.hostname, \'\') LIKE ? OR ISNULL(a.description, \'\') LIKE ?
                    ORDER BY a.ip_int';
            $more = self::mapRows($sql, [$like, $like, $like], static function (array $row): array {
                $id = (int)$row['address_id'];
                return [
                    'id' => $id,
                    'title' => (string)$row['ip'],
                    'subtitle' => trim((string)($row['hostname'] ?? '') . ' · ' . (string)($row['cidr'] ?? ''), ' ·'),
                    'href' => App::url('pages/ipam.php?prefix_id=' . (int)$row['prefix_id'] . '&address_id=' . $id),
                ];
            });
            $hits = array_merge($hits, $more);
        } catch (Throwable $e) {
        }
        return $hits;
    }

    /**
     * @return list<array{id:int,title:string,subtitle:string,href:string}>
     */
    private static function searchWorkOrders(string $q, int $limit): array
    {
        $like = self::like($q);
        $sql = 'SELECT TOP ' . (int)$limit . '
                    w.work_order_id, w.title, w.status, w.change_ticket, w.itsm_display_id
                FROM work_orders w
                WHERE w.title LIKE ? OR ISNULL(w.change_ticket, \'\') LIKE ?
                   OR ISNULL(w.itsm_display_id, \'\') LIKE ? OR ISNULL(w.notes, \'\') LIKE ?
                   OR CAST(w.work_order_id AS NVARCHAR(20)) = ?
                ORDER BY w.updated_at DESC';
        $rows = self::mapRows($sql, [$like, $like, $like, $like, $q], static function (array $row): array {
            $ticket = (string)($row['itsm_display_id'] ?: $row['change_ticket'] ?: '');
            $bits = array_filter([
                (string)($row['status'] ?? ''),
                $ticket !== '' ? ('#' . $ticket) : '',
            ]);
            $id = (int)$row['work_order_id'];
            return [
                'id' => $id,
                'title' => (string)$row['title'],
                'subtitle' => $bits !== [] ? implode(' · ', $bits) : 'Work order',
                'href' => App::url('pages/work_orders.php?id=' . $id),
            ];
        });
        if ($rows !== []) {
            return $rows;
        }
        // Older DBs without ITSM columns
        $sql = 'SELECT TOP ' . (int)$limit . '
                    w.work_order_id, w.title, w.status, w.change_ticket
                FROM work_orders w
                WHERE w.title LIKE ? OR ISNULL(w.change_ticket, \'\') LIKE ?
                   OR ISNULL(w.notes, \'\') LIKE ? OR CAST(w.work_order_id AS NVARCHAR(20)) = ?
                ORDER BY w.updated_at DESC';
        return self::mapRows($sql, [$like, $like, $like, $q], static function (array $row): array {
            $ticket = (string)($row['change_ticket'] ?? '');
            $bits = array_filter([
                (string)($row['status'] ?? ''),
                $ticket !== '' ? ('#' . $ticket) : '',
            ]);
            $id = (int)$row['work_order_id'];
            return [
                'id' => $id,
                'title' => (string)$row['title'],
                'subtitle' => $bits !== [] ? implode(' · ', $bits) : 'Work order',
                'href' => App::url('pages/work_orders.php?id=' . $id),
            ];
        });
    }

    /**
     * @return list<array{id:int,title:string,subtitle:string,href:string}>
     */
    private static function searchUsers(string $q, int $limit): array
    {
        $like = self::like($q);
        $sql = 'SELECT TOP ' . (int)$limit . '
                    u.user_id, u.username, u.display_name, u.email, r.name AS role_name, d.name AS department_name
                FROM users u
                INNER JOIN roles r ON r.role_id = u.role_id
                LEFT JOIN departments d ON d.department_id = u.department_id
                WHERE u.username LIKE ? OR ISNULL(u.display_name, \'\') LIKE ?
                   OR u.email LIKE ? OR ISNULL(d.name, \'\') LIKE ?
                   OR r.name LIKE ?
                ORDER BY u.username';
        return self::mapRows($sql, [$like, $like, $like, $like, $like], static function (array $row): array {
            $title = (string)$row['username'];
            $dn = trim((string)($row['display_name'] ?? ''));
            if ($dn !== '' && strcasecmp($dn, $title) !== 0) {
                $title .= ' · ' . $dn;
            }
            $bits = array_filter([
                (string)($row['role_name'] ?? ''),
                (string)($row['department_name'] ?? ''),
                (string)($row['email'] ?? ''),
            ]);
            $id = (int)$row['user_id'];
            return [
                'id' => $id,
                'title' => $title,
                'subtitle' => $bits !== [] ? implode(' · ', $bits) : 'User',
                'href' => App::url('pages/users.php?edit_user=' . $id),
            ];
        });
    }

    /**
     * @param list<mixed> $params
     * @param callable(array<string,mixed>):array{id:int,title:string,subtitle:string,href:string} $map
     * @return list<array{id:int,title:string,subtitle:string,href:string}>
     */
    private static function mapRows(string $sql, array $params, callable $map): array
    {
        try {
            $rows = Database::fetchAll($sql, $params);
        } catch (Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            try {
                $out[] = $map($row);
            } catch (Throwable $e) {
                // skip bad row
            }
        }
        return $out;
    }
}
