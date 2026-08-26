<?php
/**
 * ColdAisle IPAM — address plan (prefixes + host records).
 *
 * Statics/reserved are stored. Available space is computed, not 254 empty rows.
 * DHCP is a fence on the prefix, not a server.
 */
declare(strict_types=1);

class IpamService
{
    public const MAX_IMPORT_ROWS = 8000;

    /** @return array<string,string> */
    public static function statuses(): array
    {
        return [
            'assigned' => 'Assigned (static)',
            'reserved' => 'Reserved',
            'dhcp' => 'DHCP / dynamic',
            'deprecated' => 'Deprecated',
        ];
    }

    /** @return array<string,string> */
    public static function roles(): array
    {
        return [
            'management' => 'Management / OOB',
            'ipmi' => 'iDRAC / ILO / BMC',
            'production' => 'Production / servers',
            'storage' => 'Storage / SAN',
            'kvm' => 'KVM / console',
            'network' => 'Network infrastructure',
            'power' => 'PDU / UPS / meters',
            'public' => 'Public / NAT',
            'interconnect' => 'P2P / interconnect',
            'other' => 'Other',
        ];
    }

    public static function ensure(): void
    {
        if (class_exists('Schema')) {
            Schema::ensureIpam();
        }
    }

    /**
     * @return array{
     *   cidr:string,ip_version:int,prefix_len:int,network:string,broadcast:string,
     *   network_int:int,broadcast_int:int,first_usable:?string,last_usable:?string,usable:int
     * }|null
     */
    public static function parseCidr(string $raw): ?array
    {
        $raw = trim($raw);
        $raw = str_replace('\\', '/', $raw);
        $raw = preg_replace('/\s+/', '', $raw) ?? $raw;
        if (!preg_match('#^(\d{1,3}(?:\.\d{1,3}){3})/(\d{1,2})$#', $raw, $m)) {
            return null;
        }
        $net = self::canonicalIp($m[1]);
        $len = (int)$m[2];
        if ($net === null || $len < 8 || $len > 32) {
            return null;
        }
        $nInt = self::ipToInt($net);
        if ($nInt === null) {
            return null;
        }
        $mask = $len === 0 ? 0 : ((-1 << (32 - $len)) & 0xFFFFFFFF);
        $networkInt = $nInt & $mask;
        $broadcastInt = $networkInt | ((~$mask) & 0xFFFFFFFF);
        $network = self::intToIp($networkInt);
        $broadcast = self::intToIp($broadcastInt);
        $usable = 0;
        $first = null;
        $last = null;
        if ($len <= 30) {
            $usable = max(0, $broadcastInt - $networkInt - 1);
            $first = self::intToIp($networkInt + 1);
            $last = self::intToIp($broadcastInt - 1);
        } elseif ($len === 31) {
            $usable = 2;
            $first = $network;
            $last = $broadcast;
        } else {
            $usable = 1;
            $first = $network;
            $last = $network;
        }
        return [
            'cidr' => $network . '/' . $len,
            'ip_version' => 4,
            'prefix_len' => $len,
            'network' => $network,
            'broadcast' => $broadcast,
            'network_int' => $networkInt,
            'broadcast_int' => $broadcastInt,
            'first_usable' => $first,
            'last_usable' => $last,
            'usable' => $usable,
        ];
    }

    public static function canonicalIp(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '' || !filter_var($raw, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return null;
        }
        $int = self::ipToInt($raw);
        return $int === null ? null : self::intToIp($int);
    }

    public static function ipToInt(string $ip): ?int
    {
        $l = ip2long($ip);
        if ($l === false) {
            return null;
        }
        return (int)sprintf('%u', $l);
    }

    public static function intToIp(int $n): string
    {
        return long2ip($n & 0xFFFFFFFF) ?: '0.0.0.0';
    }

    public static function cidrContains(array $parsed, string $ip): bool
    {
        $i = self::ipToInt($ip);
        if ($i === null) {
            return false;
        }
        return $i >= (int)$parsed['network_int'] && $i <= (int)$parsed['broadcast_int'];
    }

    /** @param array<string,mixed> $post */
    public static function prefixFromInput(array $post): array
    {
        $parsed = self::parseCidr((string)($post['cidr'] ?? ''));
        if (!$parsed) {
            throw new InvalidArgumentException('CIDR is required (e.g. 10.12.40.0/24).');
        }
        $roles = self::roles();
        $role = strtolower(trim((string)($post['role'] ?? '')));
        if ($role !== '' && !isset($roles[$role])) {
            $role = 'other';
        }
        $vrf = trim((string)($post['vrf'] ?? 'default'));
        if ($vrf === '') {
            $vrf = 'default';
        }
        $gw = self::canonicalIp((string)($post['gateway'] ?? ''));
        $dhcpS = self::canonicalIp((string)($post['dhcp_start'] ?? ''));
        $dhcpE = self::canonicalIp((string)($post['dhcp_end'] ?? ''));
        $vlan = trim((string)($post['vlan_id'] ?? ''));
        $name = trim((string)($post['name'] ?? ''));
        return [
            'cidr' => $parsed['cidr'],
            'name' => $name !== '' ? $name : $parsed['cidr'],
            'vlan_id' => $vlan !== '' ? max(1, min(4094, (int)$vlan)) : null,
            'vrf' => $vrf,
            'gateway' => $gw,
            'role' => $role !== '' ? $role : null,
            'description' => ($d = trim((string)($post['description'] ?? ''))) !== '' ? $d : null,
            'notes' => ($n = trim((string)($post['notes'] ?? ''))) !== '' ? $n : null,
            'prefix_len' => $parsed['prefix_len'],
            'ip_version' => 4,
            'network_int' => $parsed['network_int'],
            'dhcp_start' => $dhcpS,
            'dhcp_end' => $dhcpE,
            'room_id' => !empty($post['room_id']) ? (int)$post['room_id'] : null,
            'is_active' => 1,
            'updated_at' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * @param array<string,mixed> $post
     * @return array{ok:bool,prefix_id:int,message:string}
     */
    public static function savePrefix(array $post, ?int $prefixId = null): array
    {
        $fields = self::prefixFromInput($post);
        $dup = Database::fetchOne(
            'SELECT prefix_id FROM ipam_prefixes WHERE cidr = ? AND vrf = ? AND is_active = 1'
            . ($prefixId ? ' AND prefix_id <> ' . (int)$prefixId : ''),
            [$fields['cidr'], $fields['vrf']]
        );
        if ($dup) {
            throw new RuntimeException('That CIDR already exists in VRF ' . $fields['vrf'] . '.');
        }
        if ($prefixId && $prefixId > 0) {
            Database::update('ipam_prefixes', $fields, 'prefix_id = :id', [':id' => $prefixId]);
            Database::query(
                'UPDATE ipam_addresses SET vrf = ? WHERE prefix_id = ?',
                [$fields['vrf'], $prefixId]
            );
            return ['ok' => true, 'prefix_id' => $prefixId, 'message' => 'Prefix saved.'];
        }
        $id = Database::insert('ipam_prefixes', $fields);
        return ['ok' => true, 'prefix_id' => (int)$id, 'message' => 'Prefix created.'];
    }

    public static function deletePrefix(int $prefixId): array
    {
        $n = (int)Database::fetchValue('SELECT COUNT(*) FROM ipam_addresses WHERE prefix_id = ?', [$prefixId]);
        Database::delete('ipam_addresses', 'prefix_id = ?', [$prefixId]);
        Database::delete('ipam_prefixes', 'prefix_id = ?', [$prefixId]);
        return ['ok' => true, 'message' => 'Prefix deleted' . ($n > 0 ? " ({$n} address record(s) removed)." : '.')];
    }

    /**
     * @param array<string,mixed> $post
     */
    public static function saveAddress(array $post, ?int $addressId = null): array
    {
        $ip = self::canonicalIp((string)($post['ip'] ?? ''));
        if ($ip === null) {
            throw new InvalidArgumentException('A valid IPv4 address is required.');
        }
        $prefixId = (int)($post['prefix_id'] ?? 0);
        $prefix = $prefixId > 0
            ? Database::fetchOne('SELECT * FROM ipam_prefixes WHERE prefix_id = ?', [$prefixId])
            : self::findPrefixForIp($ip, (string)($post['vrf'] ?? 'default'));
        if (!$prefix) {
            throw new RuntimeException('That IP does not fall in any prefix. Create the subnet first.');
        }
        $parsed = self::parseCidr((string)$prefix['cidr']);
        if (!$parsed || !self::cidrContains($parsed, $ip)) {
            throw new RuntimeException($ip . ' is not inside ' . $prefix['cidr'] . '.');
        }
        $status = strtolower(trim((string)($post['status'] ?? 'assigned')));
        if (!isset(self::statuses()[$status])) {
            $status = 'assigned';
        }
        $vrf = (string)($prefix['vrf'] ?? 'default');
        $existing = Database::fetchOne(
            'SELECT address_id, prefix_id FROM ipam_addresses WHERE ip = ? AND vrf = ?'
            . ($addressId ? ' AND address_id <> ' . (int)$addressId : ''),
            [$ip, $vrf]
        );
        if ($existing) {
            throw new RuntimeException($ip . ' is already documented in this VRF.');
        }
        $fields = [
            'prefix_id' => (int)$prefix['prefix_id'],
            'ip' => $ip,
            'ip_int' => self::ipToInt($ip),
            'vrf' => $vrf,
            'status' => $status,
            'hostname' => ($h = trim((string)($post['hostname'] ?? ''))) !== '' ? $h : null,
            'mac_address' => ($m = trim((string)($post['mac_address'] ?? ''))) !== '' ? $m : null,
            'description' => ($d = trim((string)($post['description'] ?? ''))) !== '' ? $d : null,
            'notes' => ($n = trim((string)($post['notes'] ?? ''))) !== '' ? $n : null,
            'device_id' => !empty($post['device_id']) ? (int)$post['device_id'] : null,
            'pdu_id' => !empty($post['pdu_id']) ? (int)$post['pdu_id'] : null,
            'ups_id' => !empty($post['ups_id']) ? (int)$post['ups_id'] : null,
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        if ($addressId && $addressId > 0) {
            Database::update('ipam_addresses', $fields, 'address_id = :id', [':id' => $addressId]);
            return ['ok' => true, 'address_id' => $addressId, 'message' => 'Address saved.'];
        }
        $id = Database::insert('ipam_addresses', $fields);
        return ['ok' => true, 'address_id' => (int)$id, 'message' => 'Address recorded.'];
    }

    public static function deleteAddress(int $addressId): void
    {
        Database::delete('ipam_addresses', 'address_id = ?', [$addressId]);
    }

    /** @return array<string,mixed>|null */
    public static function findPrefixForIp(string $ip, string $vrf = 'default'): ?array
    {
        $ip = self::canonicalIp($ip);
        if ($ip === null) {
            return null;
        }
        $int = self::ipToInt($ip);
        $rows = Database::fetchAll(
            'SELECT * FROM ipam_prefixes WHERE is_active = 1 AND vrf = ? AND ip_version = 4
             ORDER BY prefix_len DESC',
            [$vrf !== '' ? $vrf : 'default']
        );
        foreach ($rows as $row) {
            $p = self::parseCidr((string)$row['cidr']);
            if ($p && $int >= (int)$p['network_int'] && $int <= (int)$p['broadcast_int']) {
                return $row;
            }
        }
        if ($vrf !== 'default') {
            return self::findPrefixForIp($ip, 'default');
        }
        return null;
    }

    /**
     * @param array<string,mixed> $prefix
     * @return array{used:int,reserved:int,dhcp:int,usable:int,free:int,pct:float}
     */
    public static function utilization(array $prefix): array
    {
        $parsed = self::parseCidr((string)($prefix['cidr'] ?? ''));
        $usable = $parsed['usable'] ?? 0;
        $pid = (int)($prefix['prefix_id'] ?? 0);
        $counts = ['assigned' => 0, 'reserved' => 0, 'dhcp' => 0, 'deprecated' => 0];
        if ($pid > 0) {
            $rows = Database::fetchAll(
                'SELECT status, COUNT(*) AS n FROM ipam_addresses WHERE prefix_id = ? GROUP BY status',
                [$pid]
            );
            foreach ($rows as $r) {
                $st = (string)($r['status'] ?? '');
                $counts[$st] = (int)$r['n'];
            }
        }
        $used = $counts['assigned'] + $counts['reserved'] + $counts['dhcp'];
        $dhcpFence = 0;
        $ds = self::ipToInt((string)($prefix['dhcp_start'] ?? ''));
        $de = self::ipToInt((string)($prefix['dhcp_end'] ?? ''));
        if ($ds !== null && $de !== null && $de >= $ds) {
            $dhcpFence = $de - $ds + 1;
        }
        $blocked = min($usable, $used + max(0, $dhcpFence - $counts['dhcp']));
        $free = max(0, $usable - $blocked);
        $pct = $usable > 0 ? round(100 * $blocked / $usable, 1) : 0.0;
        return [
            'used' => $counts['assigned'],
            'reserved' => $counts['reserved'],
            'dhcp' => max($counts['dhcp'], $dhcpFence),
            'usable' => $usable,
            'free' => $free,
            'pct' => $pct,
        ];
    }

    /** @param array<string,mixed> $prefix */
    public static function nextFree(array $prefix): ?string
    {
        $parsed = self::parseCidr((string)($prefix['cidr'] ?? ''));
        if (!$parsed || $parsed['first_usable'] === null) {
            return null;
        }
        $start = (int)self::ipToInt($parsed['first_usable']);
        $end = (int)self::ipToInt($parsed['last_usable'] ?? $parsed['first_usable']);
        $skip = [];
        $gw = self::ipToInt((string)($prefix['gateway'] ?? ''));
        if ($gw !== null) {
            $skip[$gw] = true;
        }
        $ds = self::ipToInt((string)($prefix['dhcp_start'] ?? ''));
        $de = self::ipToInt((string)($prefix['dhcp_end'] ?? ''));
        if ($ds !== null && $de !== null && $de >= $ds) {
            for ($i = $ds; $i <= $de; $i++) {
                $skip[$i] = true;
            }
        }
        $taken = Database::fetchAll(
            'SELECT ip_int FROM ipam_addresses WHERE prefix_id = ? AND ip_int IS NOT NULL',
            [(int)$prefix['prefix_id']]
        );
        foreach ($taken as $t) {
            $skip[(int)$t['ip_int']] = true;
        }
        for ($i = $start; $i <= $end; $i++) {
            if (empty($skip[$i])) {
                return self::intToIp($i);
            }
        }
        return null;
    }

    /**
     * IPs already on devices / PDUs / UPS.
     *
     * @return list<array{ip:string,kind:string,id:int,label:string}>
     */
    public static function inventoryIps(): array
    {
        $out = [];
        $add = static function (string $ip, string $kind, int $id, string $label) use (&$out): void {
            $c = self::canonicalIp($ip);
            if ($c === null) {
                return;
            }
            $out[] = ['ip' => $c, 'kind' => $kind, 'id' => $id, 'label' => $label];
        };
        try {
            foreach (Database::fetchAll(
                "SELECT device_id, label, primary_ip, mgmt_ip, idrac_host FROM devices WHERE is_active = 1"
            ) as $d) {
                $id = (int)$d['device_id'];
                $lab = (string)$d['label'];
                $add((string)($d['primary_ip'] ?? ''), 'device', $id, $lab);
                $add((string)($d['mgmt_ip'] ?? ''), 'device', $id, $lab . ' (mgmt)');
                $host = trim((string)($d['idrac_host'] ?? ''));
                if ($host !== '' && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $add($host, 'device', $id, $lab . ' (iDRAC)');
                }
            }
        } catch (Throwable $e) {
        }
        try {
            foreach (Database::fetchAll(
                'SELECT pdu_id, name, ip_address FROM pdus WHERE is_active = 1'
            ) as $p) {
                $add((string)($p['ip_address'] ?? ''), 'pdu', (int)$p['pdu_id'], (string)$p['name']);
            }
        } catch (Throwable $e) {
        }
        try {
            foreach (Database::fetchAll(
                'SELECT ups_id, name, primary_ip FROM ups_units WHERE is_active = 1'
            ) as $u) {
                $add((string)($u['primary_ip'] ?? ''), 'ups', (int)$u['ups_id'], (string)$u['name']);
            }
        } catch (Throwable $e) {
        }
        return $out;
    }

    /**
     * Create/update IPAM rows from inventory IPs that fall in a prefix.
     *
     * @return array{linked:int,created:int,skipped:int,orphans:int}
     */
    public static function reconcileInventory(): array
    {
        $res = ['linked' => 0, 'created' => 0, 'skipped' => 0, 'orphans' => 0];
        foreach (self::inventoryIps() as $row) {
            $prefix = self::findPrefixForIp($row['ip']);
            if (!$prefix) {
                $res['orphans']++;
                continue;
            }
            $vrf = (string)($prefix['vrf'] ?? 'default');
            $existing = Database::fetchOne(
                'SELECT * FROM ipam_addresses WHERE ip = ? AND vrf = ?',
                [$row['ip'], $vrf]
            );
            $link = [
                'device_id' => $row['kind'] === 'device' ? $row['id'] : null,
                'pdu_id' => $row['kind'] === 'pdu' ? $row['id'] : null,
                'ups_id' => $row['kind'] === 'ups' ? $row['id'] : null,
                'status' => 'assigned',
                'updated_at' => date('Y-m-d H:i:s'),
            ];
            if ($existing) {
                $patch = $link;
                if (trim((string)($existing['hostname'] ?? '')) === '') {
                    $patch['hostname'] = $row['label'];
                }
                Database::update('ipam_addresses', $patch, 'address_id = :id', [':id' => (int)$existing['address_id']]);
                $res['linked']++;
                continue;
            }
            Database::insert('ipam_addresses', array_merge($link, [
                'prefix_id' => (int)$prefix['prefix_id'],
                'ip' => $row['ip'],
                'ip_int' => self::ipToInt($row['ip']),
                'vrf' => $vrf,
                'hostname' => $row['label'],
            ]));
            $res['created']++;
        }
        return $res;
    }

    /**
     * @return array{duplicates:list<array<string,mixed>>,orphans:list<array<string,mixed>>}
     */
    public static function conflicts(): array
    {
        $dups = [];
        try {
            $dups = Database::fetchAll(
                "SELECT ip, vrf, COUNT(*) AS n FROM ipam_addresses GROUP BY ip, vrf HAVING COUNT(*) > 1"
            );
        } catch (Throwable $e) {
        }
        $seen = [];
        $orphans = [];
        foreach (self::inventoryIps() as $row) {
            $key = $row['ip'];
            if (isset($seen[$key]) && $seen[$key]['id'] !== $row['id']) {
                $dups[] = [
                    'ip' => $key,
                    'vrf' => 'inventory',
                    'n' => 2,
                    'detail' => $seen[$key]['label'] . ' and ' . $row['label'],
                ];
            }
            $seen[$key] = $row;
            if (!self::findPrefixForIp($row['ip'])) {
                $orphans[] = $row;
            }
        }
        return ['duplicates' => $dups, 'orphans' => $orphans];
    }

    /** @return list<string> */
    public static function csvTemplateHeaders(): array
    {
        return ['ip', 'hostname', 'status', 'mac', 'description', 'notes', 'vlan', 'gateway', 'cidr'];
    }

    /**
     * Import CSV (one subnet) or XLSX (each worksheet = a subnet).
     *
     * @param array{prefix_id?:int,skip_empty?:bool} $options
     * @return array{ok:bool,created:int,updated:int,skipped:int,prefixes:int,errors:list<string>,message:string}
     */
    public static function importFile(string $path, string $originalName, array $options = []): array
    {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if ($ext === 'xlsx' || $ext === 'xlsm') {
            return self::importXlsx($path, $options);
        }
        $text = (string)file_get_contents($path);
        $prefixId = (int)($options['prefix_id'] ?? 0);
        return self::importCsvText($text, $prefixId > 0 ? $prefixId : null, $originalName, $options);
    }

    /**
     * @param array{prefix_id?:int,skip_empty?:bool} $options
     * @return array{ok:bool,created:int,updated:int,skipped:int,prefixes:int,errors:list<string>,message:string}
     */
    public static function importCsvText(string $text, ?int $prefixId, string $label, array $options = []): array
    {
        $grid = self::parseCsvGrid($text);
        return self::importGrid($grid, $prefixId, $label, $options);
    }

    /**
     * @param list<list<string>> $grid
     * @param array{skip_empty?:bool} $options
     * @return array{ok:bool,created:int,updated:int,skipped:int,prefixes:int,errors:list<string>,message:string}
     */
    public static function importGrid(array $grid, ?int $prefixId, string $sheetName, array $options = []): array
    {
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];
        $prefixesTouched = 0;

        $meta = self::extractSheetMeta($grid, $sheetName);
        $prefix = null;
        if ($prefixId && $prefixId > 0) {
            $prefix = Database::fetchOne('SELECT * FROM ipam_prefixes WHERE prefix_id = ?', [$prefixId]);
        }
        if (!$prefix && !empty($meta['cidr'])) {
            $parsed = self::parseCidr((string)$meta['cidr']);
            if ($parsed) {
                $existing = Database::fetchOne(
                    'SELECT * FROM ipam_prefixes WHERE cidr = ? AND vrf = ? AND is_active = 1',
                    [$parsed['cidr'], $meta['vrf'] ?? 'default']
                );
                if ($existing) {
                    $prefix = $existing;
                    if (!empty($meta['gateway']) && empty($prefix['gateway'])) {
                        Database::update('ipam_prefixes', [
                            'gateway' => $meta['gateway'],
                            'updated_at' => date('Y-m-d H:i:s'),
                        ], 'prefix_id = :id', [':id' => (int)$prefix['prefix_id']]);
                        $prefix['gateway'] = $meta['gateway'];
                    }
                    if (!empty($meta['vlan_id']) && empty($prefix['vlan_id'])) {
                        Database::update('ipam_prefixes', [
                            'vlan_id' => $meta['vlan_id'],
                            'updated_at' => date('Y-m-d H:i:s'),
                        ], 'prefix_id = :id', [':id' => (int)$prefix['prefix_id']]);
                    }
                } else {
                    $res = self::savePrefix([
                        'cidr' => $parsed['cidr'],
                        'name' => $meta['name'] ?: $sheetName,
                        'vlan_id' => $meta['vlan_id'] ?? '',
                        'gateway' => $meta['gateway'] ?? '',
                        'vrf' => $meta['vrf'] ?? 'default',
                        'role' => $meta['role'] ?? '',
                        'description' => 'Imported from ' . $sheetName,
                    ]);
                    $prefix = Database::fetchOne('SELECT * FROM ipam_prefixes WHERE prefix_id = ?', [$res['prefix_id']]);
                    $prefixesTouched++;
                }
            }
        }
        if (!$prefix) {
            return [
                'ok' => false, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'prefixes' => 0,
                'errors' => ['Sheet “' . $sheetName . '”: no CIDR (put 10.x.x.0/24 in the sheet name, a Subnet column, or import into an existing prefix).'],
                'message' => 'No prefix for ' . $sheetName,
            ];
        }

        $headerAt = $meta['header_row'];
        $map = $meta['columns'];
        $dataRows = 0;
        for ($r = $headerAt + 1, $n = count($grid); $r < $n; $r++) {
            $row = $grid[$r];
            if (self::rowIsEmpty($row)) {
                continue;
            }
            $dataRows++;
            if ($dataRows > self::MAX_IMPORT_ROWS) {
                $errors[] = $sheetName . ': stopped after ' . self::MAX_IMPORT_ROWS . ' rows.';
                break;
            }
            try {
                $cell = static function (string $key) use ($map, $row): string {
                    if (!isset($map[$key])) {
                        return '';
                    }
                    return trim((string)($row[$map[$key]] ?? ''));
                };
                $ip = self::canonicalIp($cell('ip'));
                if ($ip === null) {
                    $skipped++;
                    continue;
                }
                $hostname = $cell('hostname');
                $desc = $cell('description');
                $notes = $cell('notes');
                $statusRaw = strtolower($cell('status'));
                $status = self::inferStatus($statusRaw, $hostname, $desc . ' ' . $notes);
                if ($status === null) {
                    $skipped++;
                    continue;
                }
                $parsedP = self::parseCidr((string)$prefix['cidr']);
                if ($parsedP && !self::cidrContains($parsedP, $ip)) {
                    throw new RuntimeException($ip . ' is not in ' . $prefix['cidr']);
                }
                $vrf = (string)($prefix['vrf'] ?? 'default');
                $existing = Database::fetchOne(
                    'SELECT address_id FROM ipam_addresses WHERE ip = ? AND vrf = ?',
                    [$ip, $vrf]
                );
                $link = self::matchInventoryLabel($hostname !== '' ? $hostname : $desc);
                $fields = [
                    'prefix_id' => (int)$prefix['prefix_id'],
                    'ip' => $ip,
                    'ip_int' => self::ipToInt($ip),
                    'vrf' => $vrf,
                    'status' => $status,
                    'hostname' => $hostname !== '' ? $hostname : null,
                    'mac_address' => ($m = $cell('mac')) !== '' ? $m : null,
                    'description' => $desc !== '' ? $desc : null,
                    'notes' => $notes !== '' ? $notes : null,
                    'device_id' => $link['device_id'],
                    'pdu_id' => $link['pdu_id'],
                    'ups_id' => $link['ups_id'],
                    'updated_at' => date('Y-m-d H:i:s'),
                ];
                if ($existing) {
                    Database::update('ipam_addresses', $fields, 'address_id = :id', [':id' => (int)$existing['address_id']]);
                    $updated++;
                } else {
                    Database::insert('ipam_addresses', $fields);
                    $created++;
                }
            } catch (Throwable $e) {
                $errors[] = $sheetName . ' row ' . ($r + 1) . ': ' . $e->getMessage();
            }
        }

        $ok = $created + $updated > 0 || ($dataRows === 0 && $prefixesTouched > 0);
        return [
            'ok' => $ok || ($created + $updated + $skipped) > 0,
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'prefixes' => $prefixesTouched,
            'errors' => $errors,
            'message' => $sheetName . ': ' . $created . ' new, ' . $updated . ' updated, ' . $skipped . ' skipped.',
        ];
    }

    /**
     * @param array{skip_empty?:bool} $options
     * @return array{ok:bool,created:int,updated:int,skipped:int,prefixes:int,errors:list<string>,message:string}
     */
    private static function importXlsx(string $path, array $options): array
    {
        $sheets = self::xlsxSheets($path);
        if ($sheets === []) {
            return [
                'ok' => false, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'prefixes' => 0,
                'errors' => ['Could not read worksheets. Save as .xlsx (not legacy .xls) or export CSV.'],
                'message' => 'No worksheets found.',
            ];
        }
        $tot = ['ok' => true, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'prefixes' => 0, 'errors' => [], 'message' => ''];
        $forcePrefix = (int)($options['prefix_id'] ?? 0);
        foreach ($sheets as $sheet) {
            $pid = $forcePrefix > 0 ? $forcePrefix : null;
            $one = self::importGrid($sheet['rows'], $pid, $sheet['name'], $options);
            $tot['created'] += $one['created'];
            $tot['updated'] += $one['updated'];
            $tot['skipped'] += $one['skipped'];
            $tot['prefixes'] += $one['prefixes'];
            foreach ($one['errors'] as $err) {
                $tot['errors'][] = $err;
            }
            if (empty($one['ok']) && $one['created'] + $one['updated'] < 1) {
                $tot['ok'] = $tot['created'] + $tot['updated'] > 0;
            }
        }
        $tot['message'] = 'Workbook: ' . $tot['created'] . ' new, ' . $tot['updated'] . ' updated, '
            . $tot['skipped'] . ' skipped, ' . count($sheets) . ' sheet(s).';
        if ($tot['errors'] !== [] && $tot['created'] + $tot['updated'] < 1) {
            $tot['ok'] = false;
        }
        return $tot;
    }

    /** @return list<array{name:string,rows:list<list<string>>}> */
    public static function xlsxSheets(string $path): array
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('PHP zip extension is required to read .xlsx files.');
        }
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Could not open the Excel file.');
        }
        $shared = [];
        $ss = $zip->getFromName('xl/sharedStrings.xml');
        if (is_string($ss) && $ss !== '') {
            $shared = self::xlsxSharedStrings($ss);
        }
        $wb = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if (!is_string($wb) || $wb === '') {
            $zip->close();
            return [];
        }
        $relMap = [];
        if (is_string($rels) && $rels !== '') {
            $relXml = @simplexml_load_string($rels);
            if ($relXml) {
                foreach ($relXml->Relationship as $rel) {
                    $id = (string)$rel['Id'];
                    $tgt = (string)$rel['Target'];
                    $relMap[$id] = 'xl/' . ltrim(str_replace('\\', '/', $tgt), '/');
                    $relMap[$id] = preg_replace('#^xl/xl/#', 'xl/', $relMap[$id]) ?? $relMap[$id];
                }
            }
        }
        $wbXml = @simplexml_load_string($wb);
        $out = [];
        if ($wbXml && isset($wbXml->sheets->sheet)) {
            $i = 0;
            foreach ($wbXml->sheets->sheet as $sh) {
                $i++;
                $name = (string)$sh['name'];
                $rid = '';
                foreach ($sh->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships') as $a) {
                    $rid = (string)$a;
                }
                $target = $relMap[$rid] ?? ('xl/worksheets/sheet' . $i . '.xml');
                $xml = $zip->getFromName($target);
                if (!is_string($xml) || $xml === '') {
                    continue;
                }
                $rows = self::xlsxSheetRows($xml, $shared);
                if ($rows === []) {
                    continue;
                }
                $out[] = ['name' => $name !== '' ? $name : ('Sheet' . $i), 'rows' => $rows];
            }
        }
        $zip->close();
        return $out;
    }

    /** @return list<string> */
    private static function xlsxSharedStrings(string $xml): array
    {
        $out = [];
        $sx = @simplexml_load_string($xml);
        if (!$sx) {
            return $out;
        }
        foreach ($sx->si as $si) {
            $t = '';
            if (isset($si->t)) {
                $t = (string)$si->t;
            } else {
                foreach ($si->r as $r) {
                    $t .= (string)$r->t;
                }
            }
            $out[] = $t;
        }
        return $out;
    }

    /**
     * @param list<string> $shared
     * @return list<list<string>>
     */
    private static function xlsxSheetRows(string $xml, array $shared): array
    {
        $sx = @simplexml_load_string($xml);
        if (!$sx || !isset($sx->sheetData->row)) {
            return [];
        }
        $grid = [];
        foreach ($sx->sheetData->row as $row) {
            $rIdx = (int)$row['r'] - 1;
            if ($rIdx < 0) {
                $rIdx = count($grid);
            }
            $line = [];
            foreach ($row->c as $c) {
                $ref = (string)$c['r'];
                $col = 0;
                if (preg_match('/^([A-Z]+)/', $ref, $m)) {
                    $col = self::xlsxColIndex($m[1]);
                }
                $type = (string)$c['t'];
                $val = '';
                if ($type === 's') {
                    $si = (int)(string)$c->v;
                    $val = $shared[$si] ?? '';
                } elseif ($type === 'inlineStr') {
                    $val = isset($c->is->t) ? (string)$c->is->t : '';
                } else {
                    $val = isset($c->v) ? (string)$c->v : '';
                }
                $line[$col] = $val;
            }
            if ($line === []) {
                continue;
            }
            $max = max(array_keys($line));
            $cells = array_fill(0, $max + 1, '');
            foreach ($line as $i => $v) {
                $cells[$i] = $v;
            }
            $grid[$rIdx] = $cells;
        }
        if ($grid === []) {
            return [];
        }
        ksort($grid);
        $maxR = max(array_keys($grid));
        $out = [];
        for ($i = 0; $i <= $maxR; $i++) {
            $out[] = $grid[$i] ?? [];
        }
        return $out;
    }

    private static function xlsxColIndex(string $letters): int
    {
        $n = 0;
        $len = strlen($letters);
        for ($i = 0; $i < $len; $i++) {
            $n = $n * 26 + (ord($letters[$i]) - 64);
        }
        return max(0, $n - 1);
    }

    /** @return list<list<string>> */
    private static function parseCsvGrid(string $text): array
    {
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text) ?? $text;
        $fh = fopen('php://temp', 'r+');
        if ($fh === false) {
            return [];
        }
        fwrite($fh, $text);
        rewind($fh);
        $grid = [];
        while (($row = fgetcsv($fh)) !== false) {
            if (!is_array($row)) {
                continue;
            }
            $grid[] = array_map(static fn ($c) => (string)$c, $row);
        }
        fclose($fh);
        return $grid;
    }

    /**
     * @param list<list<string>> $grid
     * @return array{cidr:?string,name:string,vlan_id:string,gateway:?string,vrf:string,role:string,header_row:int,columns:array<string,int>}
     */
    private static function extractSheetMeta(array $grid, string $sheetName): array
    {
        $meta = [
            'cidr' => self::findCidrInText($sheetName),
            'name' => $sheetName,
            'vlan_id' => '',
            'gateway' => null,
            'vrf' => 'default',
            'role' => '',
            'header_row' => 0,
            'columns' => [],
        ];
        $scan = min(12, count($grid));
        for ($i = 0; $i < $scan; $i++) {
            $row = $grid[$i];
            $joined = strtolower(implode(' ', $row));
            if ($meta['cidr'] === null) {
                foreach ($row as $cell) {
                    $c = self::findCidrInText((string)$cell);
                    if ($c) {
                        $meta['cidr'] = $c;
                    }
                }
            }
            if (preg_match('/\bvlan\b[^0-9]{0,8}(\d{1,4})/i', $joined, $vm)) {
                $meta['vlan_id'] = $vm[1];
            }
            foreach ($row as $j => $cell) {
                $k = self::headerKey((string)$cell);
                $next = trim((string)($row[$j + 1] ?? ''));
                if ($k === 'gateway' && self::canonicalIp($next)) {
                    $meta['gateway'] = self::canonicalIp($next);
                }
                if ($k === 'cidr' && self::findCidrInText($next)) {
                    $meta['cidr'] = self::findCidrInText($next);
                }
                if ($k === 'vlan' && ctype_digit($next)) {
                    $meta['vlan_id'] = $next;
                }
            }
            $cols = self::detectColumns($row);
            if (isset($cols['ip'])) {
                $meta['header_row'] = $i;
                $meta['columns'] = $cols;
                break;
            }
        }
        if ($meta['columns'] === [] && isset($grid[0][0]) && self::canonicalIp((string)$grid[0][0])) {
            $meta['header_row'] = -1;
            $meta['columns'] = ['ip' => 0, 'hostname' => 1, 'notes' => 2];
        }
        return $meta;
    }

    /** @param list<string> $row @return array<string,int> */
    private static function detectColumns(array $row): array
    {
        $map = [];
        foreach ($row as $i => $raw) {
            $k = self::headerKey((string)$raw);
            if ($k !== '' && !isset($map[$k])) {
                $map[$k] = (int)$i;
            }
        }
        return $map;
    }

    private static function headerKey(string $raw): string
    {
        $h = strtolower(trim($raw));
        $h = preg_replace('/[^a-z0-9]+/', '_', $h) ?? $h;
        $h = trim($h, '_');
        $aliases = [
            'ip' => 'ip', 'ip_address' => 'ip', 'address' => 'ip', 'host_ip' => 'ip',
            'ipv4' => 'ip', 'host_address' => 'ip',
            'hostname' => 'hostname', 'host' => 'hostname', 'name' => 'hostname',
            'dns' => 'hostname', 'fqdn' => 'hostname', 'device' => 'hostname',
            'device_name' => 'hostname', 'label' => 'hostname', 'asset' => 'hostname',
            'status' => 'status', 'state' => 'status', 'type' => 'status',
            'mac' => 'mac', 'mac_address' => 'mac',
            'description' => 'description', 'desc' => 'description', 'comment' => 'description',
            'notes' => 'notes', 'note' => 'notes',
            'vlan' => 'vlan', 'vlan_id' => 'vlan',
            'gateway' => 'gateway', 'gw' => 'gateway', 'default_gateway' => 'gateway',
            'cidr' => 'cidr', 'subnet' => 'cidr', 'network' => 'cidr', 'prefix' => 'cidr',
            'mask' => 'mask', 'netmask' => 'mask',
        ];
        return $aliases[$h] ?? $h;
    }

    public static function findCidrInText(string $s): ?string
    {
        $s = trim($s);
        if (preg_match('#(\d{1,3}(?:\.\d{1,3}){3})\s*/\s*(\d{1,2})#', $s, $m)) {
            $p = self::parseCidr($m[1] . '/' . $m[2]);
            return $p['cidr'] ?? null;
        }
        if (preg_match('#(\d{1,3}(?:\.\d{1,3}){3})\s*_\s*(\d{1,2})#', $s, $m)) {
            $p = self::parseCidr($m[1] . '/' . $m[2]);
            return $p['cidr'] ?? null;
        }
        return null;
    }

    private static function inferStatus(string $statusRaw, string $hostname, string $blob): ?string
    {
        $s = $statusRaw . ' ' . strtolower($hostname) . ' ' . strtolower($blob);
        if (preg_match('/\b(dhcp|dynamic|pool|leased)\b/', $s)) {
            return 'dhcp';
        }
        if (preg_match('/\b(reserved|hold|vip|hsrp|vrrp|anycast|gateway|gw|unused keep)\b/', $s)) {
            return 'reserved';
        }
        if (preg_match('/\b(deprecated|old|do not use)\b/', $s)) {
            return 'deprecated';
        }
        if (preg_match('/\b(available|free|spare)\b/', $statusRaw)) {
            return null;
        }
        if ($hostname === '' && trim($blob) === '' && $statusRaw === '') {
            return null;
        }
        return 'assigned';
    }

    /** @param list<string> $row */
    private static function rowIsEmpty(array $row): bool
    {
        foreach ($row as $c) {
            if (trim((string)$c) !== '') {
                return false;
            }
        }
        return true;
    }

    /**
     * @return array{device_id:?int,pdu_id:?int,ups_id:?int}
     */
    private static function matchInventoryLabel(string $label): array
    {
        $out = ['device_id' => null, 'pdu_id' => null, 'ups_id' => null];
        $label = trim($label);
        if ($label === '') {
            return $out;
        }
        try {
            $d = Database::fetchAll(
                'SELECT device_id FROM devices WHERE is_active = 1 AND (LOWER(label) = LOWER(?) OR LOWER(ISNULL(hostname, \'\')) = LOWER(?))',
                [$label, $label]
            );
            if (count($d) === 1) {
                $out['device_id'] = (int)$d[0]['device_id'];
            }
        } catch (Throwable $e) {
        }
        try {
            $p = Database::fetchAll(
                'SELECT pdu_id FROM pdus WHERE is_active = 1 AND LOWER(name) = LOWER(?)',
                [$label]
            );
            if (count($p) === 1) {
                $out['pdu_id'] = (int)$p[0]['pdu_id'];
            }
        } catch (Throwable $e) {
        }
        try {
            $u = Database::fetchAll(
                'SELECT ups_id FROM ups_units WHERE is_active = 1 AND LOWER(name) = LOWER(?)',
                [$label]
            );
            if (count($u) === 1) {
                $out['ups_id'] = (int)$u[0]['ups_id'];
            }
        } catch (Throwable $e) {
        }
        return $out;
    }
}
