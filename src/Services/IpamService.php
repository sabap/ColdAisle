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
    public static function tracks(): array
    {
        return [
            'hosts' => 'Address plan — track individual IPs',
            'subnets' => 'Subnet plan — track prefixes only (no host list)',
        ];
    }

    public static function isSubnetTrack(array $prefix): bool
    {
        return strtolower(trim((string)($prefix['track'] ?? 'hosts'))) === 'subnets';
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
        $track = strtolower(trim((string)($post['track'] ?? 'hosts')));
        if (!isset(self::tracks()[$track])) {
            $track = 'hosts';
        }
        $parentId = (int)($post['parent_id'] ?? 0);
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
            'track' => $track,
            'parent_id' => $parentId > 0 ? $parentId : null,
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
        Database::query('UPDATE ipam_prefixes SET parent_id = NULL WHERE parent_id = ?', [$prefixId]);
        Database::delete('ipam_addresses', 'prefix_id = ?', [$prefixId]);
        Database::delete('ipam_prefixes', 'prefix_id = ?', [$prefixId]);
        return ['ok' => true, 'message' => 'Prefix deleted' . ($n > 0 ? " ({$n} address record(s) removed)." : '.')];
    }

    /**
     * Remove every prefix and host record. Inventory devices/PDUs/UPS are not touched.
     *
     * @return array{ok:bool,prefixes:int,addresses:int,message:string}
     */
    public static function purgeAll(): array
    {
        self::ensure();
        $addresses = (int)Database::fetchValue('SELECT COUNT(*) FROM ipam_addresses');
        $prefixes = (int)Database::fetchValue('SELECT COUNT(*) FROM ipam_prefixes');
        Database::query('DELETE FROM ipam_addresses');
        Database::query('DELETE FROM ipam_prefixes');
        return [
            'ok' => true,
            'prefixes' => $prefixes,
            'addresses' => $addresses,
            'message' => 'Cleared IPAM: ' . $prefixes . ' prefix(es), ' . $addresses . ' address record(s). Devices and PDUs were not changed.',
        ];
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
        if (self::isSubnetTrack($prefix) && $parsed) {
            $kids = self::childPrefixes($pid);
            $span = max(1, (int)$parsed['broadcast_int'] - (int)$parsed['network_int'] + 1);
            $alloc = 0;
            foreach ($kids as $kid) {
                $kp = self::parseCidr((string)($kid['cidr'] ?? ''));
                if ($kp) {
                    $alloc += (int)$kp['broadcast_int'] - (int)$kp['network_int'] + 1;
                }
            }
            $pct = round(100 * min($span, $alloc) / $span, 1);
            return [
                'used' => count($kids),
                'reserved' => 0,
                'dhcp' => 0,
                'usable' => $span,
                'free' => max(0, $span - $alloc),
                'pct' => $pct,
                'mode' => 'subnets',
            ];
        }
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
            'mode' => 'hosts',
        ];
    }

    /** @return list<array<string,mixed>> */
    public static function childPrefixes(int $parentId): array
    {
        if ($parentId < 1) {
            return [];
        }
        return Database::fetchAll(
            'SELECT * FROM ipam_prefixes WHERE is_active = 1 AND parent_id = ? ORDER BY network_int, prefix_len',
            [$parentId]
        ) ?: [];
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
     * Classify each worksheet so the operator can pick address vs subnet plan.
     *
     * @return list<array{index:int,name:string,rows:int,guess:string,hint:string}>
     */
    public static function previewWorkbook(string $path): array
    {
        $sheets = self::xlsxSheets($path);
        $out = [];
        foreach ($sheets as $i => $sheet) {
            $meta = self::extractSheetMeta($sheet['rows'], $sheet['name']);
            $guess = 'hosts';
            $hint = 'Looks like host IPs.';
            if (!empty($meta['skip'])) {
                $guess = 'skip';
                $hint = (string)($meta['skip_reason'] ?? 'Not a host or prefix list.');
            } elseif (!empty($meta['catalog'])) {
                $guess = 'subnets';
                $hint = 'Looks like a prefix list (network + CIDR or mask).';
            } elseif (empty($meta['cidr'])) {
                $hint = 'Host IPs; no CIDR found on the tab yet.';
            }
            $out[] = [
                'index' => $i,
                'name' => $sheet['name'],
                'rows' => count($sheet['rows']),
                'guess' => $guess,
                'hint' => $hint,
            ];
        }
        return $out;
    }

    /** @return array{id:string,path:string,name:string} */
    public static function stageImportFile(string $src, string $originalName): array
    {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($ext, ['xlsx', 'xlsm', 'csv'], true)) {
            throw new RuntimeException('Use .xlsx, .xlsm, or .csv.');
        }
        $dir = (class_exists('App') ? App::ROOT : dirname(__DIR__, 2)) . '/storage/tmp';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot write storage/tmp for import.');
        }
        $id = bin2hex(random_bytes(8));
        $dest = $dir . DIRECTORY_SEPARATOR . 'ipam-imp-' . $id . '.' . $ext;
        if (!@copy($src, $dest)) {
            throw new RuntimeException('Could not stage the upload.');
        }
        return ['id' => $id, 'path' => $dest, 'name' => $originalName];
    }

    public static function stagedImportPath(string $id): ?string
    {
        if (!preg_match('/^[a-f0-9]{16}$/', $id)) {
            return null;
        }
        $dir = (class_exists('App') ? App::ROOT : dirname(__DIR__, 2)) . '/storage/tmp';
        foreach (['xlsx', 'xlsm', 'csv'] as $ext) {
            $p = $dir . DIRECTORY_SEPARATOR . 'ipam-imp-' . $id . '.' . $ext;
            if (is_file($p)) {
                return $p;
            }
        }
        return null;
    }

    public static function clearStagedImport(string $id): void
    {
        $p = self::stagedImportPath($id);
        if ($p) {
            @unlink($p);
        }
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
        $mode = strtolower(trim((string)($options['track'] ?? 'auto')));
        if ($mode === 'skip') {
            return [
                'ok' => true, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'prefixes' => 0,
                'errors' => [],
                'message' => $sheetName . ': skipped.',
            ];
        }
        if ($mode === 'auto' && !empty($meta['skip'])) {
            return [
                'ok' => true, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'prefixes' => 0,
                'errors' => [],
                'message' => $sheetName . ': ' . (string)($meta['skip_reason'] ?? 'skipped (not a host list).'),
            ];
        }
        if ($mode === 'subnets' || ($mode !== 'hosts' && !empty($meta['catalog']))) {
            return self::importPrefixCatalog($grid, $meta, $sheetName, $options);
        }
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
                        'track' => 'hosts',
                        'dhcp_start' => $meta['dhcp_start'] ?? '',
                        'dhcp_end' => $meta['dhcp_end'] ?? '',
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
                if ($hostname === '') {
                    $hostname = $cell('equipment');
                }
                $desc = $cell('description');
                if ($desc === '') {
                    $desc = $cell('use');
                }
                if ($desc === '') {
                    $desc = $cell('device_function');
                }
                $notes = $cell('notes');
                if (preg_match('/^(n\/a|network addy|network address|broadcast addy|broadcast address)$/i', $hostname)) {
                    $hostname = '';
                }
                $statusRaw = strtolower($cell('status'));
                $status = self::inferStatus($statusRaw, $hostname, $desc . ' ' . $notes);
                if ($status === null) {
                    $skipped++;
                    continue;
                }
                $parsedP = self::parseCidr((string)$prefix['cidr']);
                if ($parsedP && ($ip === $parsedP['network'] || $ip === $parsedP['broadcast'])) {
                    $skipped++;
                    continue;
                }
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
     * Subnet plan: each data row is a prefix (not a host IP).
     * Optional parent/supernet/aggregate column nests smaller prefixes under a larger one.
     *
     * @param list<list<string>> $grid
     * @param array<string,mixed> $meta
     * @param array{skip_empty?:bool} $options
     * @return array{ok:bool,created:int,updated:int,skipped:int,prefixes:int,errors:list<string>,message:string}
     */
    private static function importPrefixCatalog(array $grid, array $meta, string $sheetName, array $options): array
    {
        $map = $meta['columns'];
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];
        $defaultLen = isset($meta['mask']) && ctype_digit((string)$meta['mask']) ? (int)$meta['mask'] : null;
        $parentLen = isset($meta['supernet_len']) ? (int)$meta['supernet_len'] : 0;
        $headerAt = (int)$meta['header_row'];
        $forceParent = (int)($options['prefix_id'] ?? 0);
        $rowsOut = [];
        $lastParent = '';
        for ($r = $headerAt + 1, $n = count($grid); $r < $n; $r++) {
            $row = $grid[$r];
            if (self::rowIsEmpty($row)) {
                continue;
            }
            $cell = static function (string $key) use ($map, $row): string {
                if (!isset($map[$key])) {
                    return '';
                }
                return trim((string)($row[$map[$key]] ?? ''));
            };
            $netRaw = $cell('network');
            if ($netRaw === '') {
                $netRaw = $cell('cidr');
            }
            if ($netRaw === '') {
                $netRaw = $cell('ip');
            }
            if ($netRaw === '' || $netRaw === '*') {
                $skipped++;
                continue;
            }
            $cidr = self::findCidrInText($netRaw);
            if ($cidr === null) {
                $len = self::maskToPrefixLen($cell('cidr'))
                    ?? self::maskToPrefixLen($cell('mask'))
                    ?? $defaultLen;
                $net = self::canonicalIp($netRaw) ?? self::findNetworkInText($netRaw);
                if ($net !== null && $len !== null) {
                    $parsed = self::parseCidr($net . '/' . $len);
                    $cidr = $parsed['cidr'] ?? null;
                }
            }
            if ($cidr === null) {
                $skipped++;
                continue;
            }
            $name = $cell('hostname');
            if ($name === '' || $name === '--') {
                $name = $cell('description');
            }
            if ($name === '--') {
                $name = '';
            }
            $vlan = $cell('vlan');
            if (preg_match('/(\d{1,4})/', $vlan, $vm)) {
                $vlan = $vm[1];
            } else {
                $vlan = '';
            }
            $parentRaw = $cell('parent');
            if ($parentRaw === '') {
                $parentRaw = $cell('supernet');
            }
            if ($parentRaw !== '') {
                $lastParent = $parentRaw;
            } elseif ($lastParent !== '') {
                $parentRaw = $lastParent;
            }
            $parentCidr = null;
            if ($parentRaw !== '') {
                $parentCidr = self::findCidrInText($parentRaw);
                if ($parentCidr === null && $parentLen > 0) {
                    $pn = self::canonicalIp($parentRaw) ?? self::findNetworkInText($parentRaw);
                    if ($pn !== null) {
                        $pp = self::parseCidr($pn . '/' . $parentLen);
                        $parentCidr = $pp['cidr'] ?? null;
                    }
                }
            }
            $rowsOut[] = [
                'cidr' => $cidr,
                'name' => $name,
                'vlan' => $vlan,
                'parent' => $parentCidr,
                'notes' => $cell('notes'),
            ];
        }

        $parentIds = [];
        if ($forceParent > 0) {
            $parentIds[''] = $forceParent;
        }
        foreach ($rowsOut as $one) {
            $pc = $one['parent'];
            if ($pc === null || $pc === $one['cidr'] || isset($parentIds[$pc])) {
                continue;
            }
            try {
                $res = self::upsertSubnetPrefix([
                    'cidr' => $pc,
                    'name' => $pc,
                    'vrf' => 'default',
                    'track' => 'subnets',
                    'parent_id' => $forceParent,
                    'description' => 'Parent prefix from ' . $sheetName,
                ], $created, $updated);
                $parentIds[$pc] = (int)$res['prefix_id'];
            } catch (Throwable $e) {
                $errors[] = $sheetName . ': ' . $e->getMessage();
            }
        }

        foreach ($rowsOut as $one) {
            try {
                $parentId = $forceParent;
                if (!empty($one['parent']) && isset($parentIds[$one['parent']]) && $one['cidr'] !== $one['parent']) {
                    $parentId = $parentIds[$one['parent']];
                }
                self::upsertSubnetPrefix([
                    'cidr' => $one['cidr'],
                    'name' => $one['name'] !== '' ? $one['name'] : $one['cidr'],
                    'vlan_id' => $one['vlan'],
                    'vrf' => 'default',
                    'track' => 'subnets',
                    'parent_id' => $parentId,
                    'notes' => $one['notes'],
                    'description' => 'Imported from ' . $sheetName,
                ], $created, $updated);
            } catch (Throwable $e) {
                $errors[] = $sheetName . ': ' . $e->getMessage();
            }
        }

        $nPref = $created + $updated;
        return [
            'ok' => $nPref > 0 || $errors === [],
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'prefixes' => $created,
            'errors' => $errors,
            'message' => $sheetName . ': ' . $created . ' prefix(es) new, ' . $updated . ' updated, '
                . $skipped . ' skipped (subnet plan).',
        ];
    }

    /**
     * @param array<string,mixed> $post
     * @return array{prefix_id:int,created:bool}
     */
    private static function upsertSubnetPrefix(array $post, int &$created, int &$updated): array
    {
        $post['track'] = 'subnets';
        $vrf = (string)($post['vrf'] ?? 'default');
        $parsed = self::parseCidr((string)($post['cidr'] ?? ''));
        if (!$parsed) {
            throw new RuntimeException('Invalid CIDR in subnet catalog.');
        }
        $existing = Database::fetchOne(
            'SELECT * FROM ipam_prefixes WHERE cidr = ? AND vrf = ? AND is_active = 1',
            [$parsed['cidr'], $vrf]
        );
        $parentId = (int)($post['parent_id'] ?? 0);
        if ($existing) {
            $patch = [
                'track' => 'subnets',
                'updated_at' => date('Y-m-d H:i:s'),
            ];
            if ($parentId > 0 && (int)($existing['parent_id'] ?? 0) !== $parentId
                && (int)$existing['prefix_id'] !== $parentId) {
                $patch['parent_id'] = $parentId;
            }
            if (empty($existing['name']) && !empty($post['name'])) {
                $patch['name'] = $post['name'];
            }
            if (empty($existing['vlan_id']) && !empty($post['vlan_id'])) {
                $patch['vlan_id'] = max(1, min(4094, (int)$post['vlan_id']));
            }
            Database::update('ipam_prefixes', $patch, 'prefix_id = :id', [':id' => (int)$existing['prefix_id']]);
            $updated++;
            return ['prefix_id' => (int)$existing['prefix_id'], 'created' => false];
        }
        $res = self::savePrefix($post);
        $created++;
        return ['prefix_id' => (int)$res['prefix_id'], 'created' => true];
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
        $perSheet = is_array($options['sheet_tracks'] ?? null) ? $options['sheet_tracks'] : null;
        foreach ($sheets as $i => $sheet) {
            $pid = $forcePrefix > 0 ? $forcePrefix : null;
            $opts = $options;
            if ($perSheet !== null) {
                $opts['track'] = (string)($perSheet[$i] ?? $perSheet[(string)$sheet['name']] ?? 'skip');
            }
            $one = self::importGrid($sheet['rows'], $pid, $sheet['name'], $opts);
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
        $files = self::xlsxUnzipFiles($path);
        $shared = [];
        if (!empty($files['xl/sharedStrings.xml'])) {
            $shared = self::xlsxSharedStrings($files['xl/sharedStrings.xml']);
        }
        $wb = $files['xl/workbook.xml'] ?? '';
        $rels = $files['xl/_rels/workbook.xml.rels'] ?? '';
        if ($wb === '') {
            return [];
        }
        $relMap = [];
        if ($rels !== '') {
            $relXml = self::xlsxSimpleXml($rels);
            if ($relXml) {
                foreach ($relXml->Relationship as $rel) {
                    $id = (string)$rel['Id'];
                    $tgt = str_replace('\\', '/', (string)$rel['Target']);
                    $relMap[$id] = 'xl/' . ltrim($tgt, '/');
                    $relMap[$id] = preg_replace('#^xl/xl/#', 'xl/', $relMap[$id]) ?? $relMap[$id];
                }
            }
        }
        $wbXml = self::xlsxSimpleXml($wb);
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
                $xml = $files[$target] ?? $files[ltrim($target, '/')] ?? '';
                if ($xml === '') {
                    continue;
                }
                $rows = self::xlsxSheetRows($xml, $shared);
                if ($rows === []) {
                    continue;
                }
                $out[] = ['name' => $name !== '' ? $name : ('Sheet' . $i), 'rows' => $rows];
            }
        }
        return $out;
    }

    /**
     * Read OOXML parts from an .xlsx (zip). ZipArchive if loaded; otherwise a
     * built-in ZIP reader (no php_zip, no exec) so production IIS can import.
     * Windows PowerShell unzip is a last resort.
     *
     * @return array<string,string> path => contents
     */
    private static function xlsxUnzipFiles(string $path): array
    {
        $path = (string)realpath($path) ?: $path;
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('Could not read the uploaded spreadsheet.');
        }
        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($path) === true) {
                $files = [];
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $name = str_replace('\\', '/', (string)$zip->getNameIndex($i));
                    if ($name === '' || str_ends_with($name, '/')) {
                        continue;
                    }
                    $data = $zip->getFromIndex($i);
                    if (is_string($data)) {
                        $files[$name] = $data;
                    }
                }
                $zip->close();
                if ($files !== []) {
                    return $files;
                }
            }
        }

        $files = self::xlsxUnzipViaPhp($path);
        if ($files !== []) {
            return $files;
        }

        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $files = self::xlsxUnzipViaPowerShell($path);
            if ($files !== []) {
                return $files;
            }
        }

        throw new RuntimeException(
            'Could not read the Excel workbook. Enable extension=zip in php.ini and recycle the IIS app pool, '
            . 'or save each worksheet as CSV.'
        );
    }

    /**
     * Store (0) + deflate (8) ZIP reader. Uses zlib gzinflate — no ZipArchive.
     *
     * @return array<string,string>
     */
    private static function xlsxUnzipViaPhp(string $path): array
    {
        $bin = @file_get_contents($path);
        if (!is_string($bin) || strlen($bin) < 30 || !str_starts_with($bin, 'PK')) {
            return [];
        }
        $files = self::zipExtractFromCentralDirectory($bin);
        if ($files !== []) {
            return $files;
        }
        return self::zipExtractFromLocalHeaders($bin);
    }

    /** @return array<string,string> */
    private static function zipExtractFromCentralDirectory(string $bin): array
    {
        $eocd = self::zipFindEocd($bin);
        if ($eocd === null) {
            return [];
        }
        $len = strlen($bin);
        $pos = $eocd['cdOff'];
        $files = [];
        for ($i = 0; $i < $eocd['entries']; $i++) {
            if ($pos + 46 > $len || substr($bin, $pos, 4) !== "PK\x01\x02") {
                return $files;
            }
            $flags = self::zipU16($bin, $pos + 8);
            $method = self::zipU16($bin, $pos + 10);
            $comp = self::zipU32($bin, $pos + 20);
            $uncomp = self::zipU32($bin, $pos + 24);
            $nameLen = self::zipU16($bin, $pos + 28);
            $extraLen = self::zipU16($bin, $pos + 30);
            $commentLen = self::zipU16($bin, $pos + 32);
            $localOff = self::zipU32($bin, $pos + 42);
            $name = str_replace('\\', '/', substr($bin, $pos + 46, $nameLen));
            $pos += 46 + $nameLen + $extraLen + $commentLen;
            if ($name === '' || str_ends_with($name, '/') || ($flags & 1) !== 0) {
                continue;
            }
            $data = self::zipReadLocalData($bin, $localOff, $method, $comp, $uncomp);
            if (is_string($data)) {
                $files[$name] = $data;
            }
        }
        return $files;
    }

    /** @return array{entries:int,cdOff:int}|null */
    private static function zipFindEocd(string $bin): ?array
    {
        $len = strlen($bin);
        $maxScan = min($len - 22, 65535);
        $sig = "PK\x05\x06";
        for ($i = 0; $i <= $maxScan; $i++) {
            $off = $len - 22 - $i;
            if (substr($bin, $off, 4) !== $sig) {
                continue;
            }
            $commentLen = self::zipU16($bin, $off + 20);
            if ($off + 22 + $commentLen !== $len) {
                continue;
            }
            $cdOff = self::zipU32($bin, $off + 16);
            $entries = self::zipU16($bin, $off + 10);
            if ($cdOff === 0xFFFFFFFF || $entries < 1 || $cdOff + 46 > $len) {
                return null;
            }
            return ['entries' => $entries, 'cdOff' => $cdOff];
        }
        return null;
    }

    private static function zipReadLocalData(
        string $bin,
        int $localOff,
        int $method,
        int $comp,
        int $uncomp
    ): ?string {
        $len = strlen($bin);
        if ($localOff + 30 > $len || substr($bin, $localOff, 4) !== "PK\x03\x04") {
            return null;
        }
        $nameLen = self::zipU16($bin, $localOff + 26);
        $extraLen = self::zipU16($bin, $localOff + 28);
        $dataOff = $localOff + 30 + $nameLen + $extraLen;
        if ($comp < 0 || $dataOff + $comp > $len) {
            return null;
        }
        return self::zipInflate(substr($bin, $dataOff, $comp), $method, $uncomp);
    }

    /** @return array<string,string> */
    private static function zipExtractFromLocalHeaders(string $bin): array
    {
        $len = strlen($bin);
        $pos = 0;
        $files = [];
        $sig = "PK\x03\x04";
        while ($pos + 30 <= $len) {
            $found = strpos($bin, $sig, $pos);
            if ($found === false) {
                break;
            }
            $pos = $found;
            $flags = self::zipU16($bin, $pos + 6);
            $method = self::zipU16($bin, $pos + 8);
            $comp = self::zipU32($bin, $pos + 18);
            $uncomp = self::zipU32($bin, $pos + 22);
            $nameLen = self::zipU16($bin, $pos + 26);
            $extraLen = self::zipU16($bin, $pos + 28);
            $name = str_replace('\\', '/', substr($bin, $pos + 30, $nameLen));
            $dataOff = $pos + 30 + $nameLen + $extraLen;
            if (($flags & 8) !== 0 && $comp === 0) {
                $pos = $dataOff + 1;
                continue;
            }
            if ($dataOff + $comp > $len) {
                break;
            }
            if ($name !== '' && !str_ends_with($name, '/') && ($flags & 1) === 0) {
                $data = self::zipInflate(substr($bin, $dataOff, $comp), $method, $uncomp);
                if (is_string($data)) {
                    $files[$name] = $data;
                }
            }
            $pos = $dataOff + $comp;
        }
        return $files;
    }

    private static function zipInflate(string $raw, int $method, int $uncomp): ?string
    {
        if ($method === 0) {
            return $raw;
        }
        if ($method !== 8) {
            return null;
        }
        if ($raw === '' && $uncomp === 0) {
            return '';
        }
        $out = function_exists('gzinflate') ? @gzinflate($raw) : false;
        if (!is_string($out) && function_exists('inflate_init') && defined('ZLIB_ENCODING_RAW')) {
            $ctx = @inflate_init(ZLIB_ENCODING_RAW);
            if ($ctx !== false) {
                $out = @inflate_add($ctx, $raw, ZLIB_FINISH);
            }
        }
        return is_string($out) ? $out : null;
    }

    private static function zipU16(string $bin, int $off): int
    {
        if ($off + 2 > strlen($bin)) {
            return 0;
        }
        $a = unpack('v', substr($bin, $off, 2));
        return (int)($a[1] ?? 0);
    }

    private static function zipU32(string $bin, int $off): int
    {
        if ($off + 4 > strlen($bin)) {
            return 0;
        }
        $a = unpack('V', substr($bin, $off, 4));
        return (int)($a[1] ?? 0);
    }

    /** @return array<string,string> */
    private static function xlsxUnzipViaPowerShell(string $path): array
    {
        $work = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ca-xlsx-' . bin2hex(random_bytes(4));
        if (class_exists('App')) {
            $base = App::ROOT . '/storage/tmp';
            if (!is_dir($base)) {
                @mkdir($base, 0775, true);
            }
            if (is_dir($base) && is_writable($base)) {
                $work = $base . DIRECTORY_SEPARATOR . 'ca-xlsx-' . bin2hex(random_bytes(4));
            }
        }
        @mkdir($work, 0700, true);
        $zipCopy = $work . '.zip';
        if (!@copy($path, $zipCopy)) {
            self::xlsxCleanupDir($work);
            return [];
        }
        $dest = $work . DIRECTORY_SEPARATOR . 'x';
        @mkdir($dest, 0700, true);
        $root = getenv('SystemRoot') ?: 'C:\\Windows';
        $psExe = $root . '\\System32\\WindowsPowerShell\\v1.0\\powershell.exe';
        if (!is_file($psExe)) {
            $psExe = 'powershell.exe';
        }
        $srcLit = str_replace("'", "''", $zipCopy);
        $dstLit = str_replace("'", "''", $dest);
        $script = 'Add-Type -AssemblyName System.IO.Compression.FileSystem; '
            . "[System.IO.Compression.ZipFile]::ExtractToDirectory('$srcLit', '$dstLit')";
        $cmd = escapeshellarg($psExe)
            . ' -NoProfile -NonInteractive -ExecutionPolicy Bypass -Command '
            . escapeshellarg($script);
        $out = [];
        $code = 1;
        @exec($cmd, $out, $code);
        if ($code !== 0) {
            $script2 = "Expand-Archive -LiteralPath '$srcLit' -DestinationPath '$dstLit' -Force";
            $cmd2 = escapeshellarg($psExe)
                . ' -NoProfile -NonInteractive -ExecutionPolicy Bypass -Command '
                . escapeshellarg($script2);
            $out = [];
            $code = 1;
            @exec($cmd2, $out, $code);
        }
        $files = $code === 0 ? self::xlsxReadExtractedDir($dest) : [];
        self::xlsxCleanupDir($work);
        @unlink($zipCopy);
        return $files;
    }

    /** @return array<string,string> */
    private static function xlsxReadExtractedDir(string $dir): array
    {
        $files = [];
        $dir = rtrim(str_replace('\\', '/', $dir), '/');
        $it = @scandir($dir);
        if (!is_array($it)) {
            return [];
        }
        $stack = [$dir];
        while ($stack) {
            $cur = array_pop($stack);
            $entries = @scandir($cur);
            if (!is_array($entries)) {
                continue;
            }
            foreach ($entries as $e) {
                if ($e === '.' || $e === '..') {
                    continue;
                }
                $full = $cur . DIRECTORY_SEPARATOR . $e;
                if (is_dir($full)) {
                    $stack[] = $full;
                    continue;
                }
                $rel = str_replace('\\', '/', substr($full, strlen($dir) + 1));
                $data = @file_get_contents($full);
                if (is_string($data)) {
                    $files[$rel] = $data;
                }
            }
        }
        return $files;
    }

    private static function xlsxCleanupDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            if ($f->isDir()) {
                @rmdir($f->getPathname());
            } else {
                @unlink($f->getPathname());
            }
        }
        @rmdir($dir);
    }

    /** Default xmlns hides children from SimpleXML property access. */
    private static function xlsxSimpleXml(string $xml): ?SimpleXMLElement
    {
        $xml = preg_replace('/\sxmlns="[^"]*"/', '', $xml) ?? $xml;
        $sx = @simplexml_load_string($xml);
        return $sx instanceof SimpleXMLElement ? $sx : null;
    }

    /** @return list<string> */
    private static function xlsxSharedStrings(string $xml): array
    {
        $out = [];
        $sx = self::xlsxSimpleXml($xml);
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
        $sx = self::xlsxSimpleXml($xml);
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
     * @return array{
     *   cidr:?string,name:string,vlan_id:string,gateway:?string,vrf:string,role:string,
     *   header_row:int,columns:array<string,int>,catalog?:bool,skip?:bool,skip_reason?:string,
     *   dhcp_start:?string,dhcp_end:?string,mask:?string,network:?string
     * }
     */
    private static function extractSheetMeta(array $grid, string $sheetName): array
    {
        $meta = [
            'cidr' => self::findCidrInText($sheetName),
            'name' => trim($sheetName),
            'vlan_id' => '',
            'gateway' => null,
            'vrf' => 'default',
            'role' => '',
            'header_row' => 0,
            'columns' => [],
            'dhcp_start' => null,
            'dhcp_end' => null,
            'mask' => null,
            'network' => self::findNetworkInText($sheetName),
            'catalog' => false,
            'skip' => false,
            'skip_reason' => '',
            'supernet_len' => null,
            'title' => trim($sheetName),
        ];

        $scan = min(40, count($grid));
        for ($i = 0; $i < $scan; $i++) {
            $row = $grid[$i] ?? [];
            $joined = strtolower(implode(' ', $row));
            self::absorbMetaFromText($meta, $joined);
            foreach ($row as $cell) {
                self::absorbMetaFromText($meta, (string)$cell);
            }

            foreach ($row as $j => $cell) {
                $k = self::headerKey((string)$cell);
                $next = trim((string)($row[$j + 1] ?? ''));
                if ($k === 'gateway' && ($gw = self::canonicalIp($next)) && !str_starts_with($gw, '255.')) {
                    $meta['gateway'] = $gw;
                }
                if (in_array($k, ['cidr', 'network'], true) && ($c = self::findCidrInText($next))) {
                    $meta['cidr'] = $c;
                }
                if (in_array($k, ['network', 'cidr'], true) && ($n = self::findNetworkInText($next))) {
                    $meta['network'] = $n;
                }
                if ($k === 'mask' && ($len = self::maskToPrefixLen($next))) {
                    $meta['mask'] = (string)$len;
                }
                if ($k === 'vlan' && ctype_digit($next)) {
                    $meta['vlan_id'] = $next;
                }
            }

            foreach ($row as $cell) {
                if (preg_match('/\/(\d{1,2})\s*supernet/i', (string)$cell, $sm)) {
                    $meta['supernet_len'] = (int)$sm[1];
                }
            }
            if ($i === 0) {
                $titleBits = [];
                foreach ($row as $cell) {
                    $t = trim((string)$cell);
                    if ($t !== '' && !self::canonicalIp($t) && self::findCidrInText($t) === null) {
                        $titleBits[] = $t;
                    }
                }
                if ($titleBits !== []) {
                    $meta['title'] = implode(' ', $titleBits);
                }
            }
            $cols = self::detectColumns($row);
            $isMetaHeader = isset($cols['mask']) || isset($cols['gateway']) || isset($cols['network'])
                || isset($cols['range']);
            if ($isMetaHeader && !isset($cols['ip'])) {
                $nextRow = $grid[$i + 1] ?? [];
                self::absorbMetaFromValueRow($meta, $cols, $nextRow);
            }
            if ($cols !== []) {
                $isHost = isset($cols['ip']);
                $isCat = isset($cols['network']) && (isset($cols['cidr']) || isset($cols['mask']) || isset($cols['broadcast']));
                if ($isHost) {
                    $meta['header_row'] = $i;
                    $meta['columns'] = $cols;
                } elseif ($meta['columns'] === [] && $isCat) {
                    $meta['header_row'] = $i;
                    $meta['columns'] = $cols;
                }
            }
            if (self::looksLikeLegendHeader($cols, $joined)) {
                $meta['skip'] = true;
                $meta['skip_reason'] = 'closet / VLAN legend (not a host list)';
            }
        }

        if (preg_match('/current ip scheme/i', $sheetName) || preg_match('/rfc1918\s+nat/i', $sheetName)) {
            $meta['skip'] = true;
            $meta['skip_reason'] = $meta['skip_reason'] !== '' ? $meta['skip_reason'] : 'summary / NAT table (not a single subnet)';
        }

        if ($meta['columns'] === [] && isset($grid[0][0]) && self::canonicalIp((string)$grid[0][0])) {
            $meta['header_row'] = -1;
            $meta['columns'] = ['ip' => 0, 'hostname' => 1, 'notes' => 2];
        }

        if (self::looksLikePrefixCatalog($meta, $grid)) {
            $meta['catalog'] = true;
            $meta['skip'] = false;
        }

        if ($meta['cidr'] === null) {
            $meta['cidr'] = self::resolveSheetCidr($meta, $grid);
        }
        $meta['cidr'] = self::preferCidrMatchingHosts($meta, $grid);
        if ($meta['cidr'] === null && empty($meta['catalog']) && empty($meta['skip'])) {
            $meta['skip'] = true;
            $meta['skip_reason'] = 'no CIDR found (name the tab 10.x.x.0/24, or add a mask / gateway block)';
        }
        if ($meta['gateway'] === null && !empty($meta['columns'])) {
            $meta['gateway'] = self::gatewayFromHostRows($grid, $meta);
        }
        if ($meta['gateway'] === null && is_string($meta['cidr']) && $meta['cidr'] !== '') {
            $parsed = self::parseCidr($meta['cidr']);
            if ($parsed && !empty($parsed['first_usable']) && (int)$parsed['prefix_len'] <= 30) {
                $top = strtolower(implode("\n", array_map(
                    static fn ($r) => implode(' ', $r),
                    array_slice($grid, 0, 8)
                )));
                if (!preg_match('/not\s+routed|unrouted|no\s+gateway/i', $top)) {
                    $meta['gateway'] = $parsed['first_usable'];
                }
            }
        }

        return $meta;
    }

    /** @param array<string,mixed> $meta */
    private static function absorbMetaFromText(array &$meta, string $text): void
    {
        $text = trim($text);
        if ($text === '') {
            return;
        }
        if ($c = self::findCidrInText($text)) {
            $meta['cidr'] = $c;
        }
        if (preg_match('/\bvlan\s*#?\s*(\d{1,4})\b/i', $text, $vm)) {
            $meta['vlan_id'] = $vm[1];
        }
        if (preg_match('/\b(?:default\s+)?(?:gateway|gw|router)\s*:?\s*(\d{1,3}(?:\.\d{1,3}){3})\b/i', $text, $gm)) {
            $gw = self::canonicalIp($gm[1]);
            if ($gw && !str_starts_with($gw, '255.')) {
                $meta['gateway'] = $gw;
            }
        }
        if (preg_match('/\b(?:subnet\s*)?mask\s*:?\s*(255\.\d{1,3}\.\d{1,3}\.\d{1,3})\b/i', $text, $mm)) {
            if ($len = self::maskToPrefixLen($mm[1])) {
                $meta['mask'] = (string)$len;
            }
        }
        if (preg_match('/^255\.\d{1,3}\.\d{1,3}\.\d{1,3}$/', $text) && ($len = self::maskToPrefixLen($text))) {
            $meta['mask'] = (string)$len;
        }
        if (preg_match('/^\/(\d{1,2})$/', $text, $pl2)) {
            $len = (int)$pl2[1];
            if ($len >= 8 && $len <= 32) {
                $meta['mask'] = (string)$len;
            }
        }
        if (preg_match('/\b(?:network(?:\s+address)?|ip\s+range)\s*:?\s*(\d{1,3}(?:\.\d{1,3}){3})\b/i', $text, $nm)) {
            if ($n = self::canonicalIp($nm[1])) {
                $meta['network'] = $n;
            }
        }
        if (preg_match('/dhcp[^\d]{0,24}(\d{1,3}(?:\.\d{1,3}){3})\s*[-–]\s*(\d{1,3}(?:\.\d{1,3}){3})/i', $text, $dh)) {
            $meta['dhcp_start'] = self::canonicalIp($dh[1]);
            $meta['dhcp_end'] = self::canonicalIp($dh[2]);
        }
        if (preg_match('/(\d{1,3}(?:\.\d{1,3}){3})\s*[-–]\s*(\d{1,3}(?:\.\d{1,3}){3})/', $text, $rg)) {
            $fromRange = self::cidrFromInclusiveRange($rg[1], $rg[2]);
            if ($fromRange && $meta['cidr'] === null) {
                $meta['cidr'] = $fromRange;
            }
        }
        if (preg_match('/(?:^|\s)\/(\d{1,2})(?:\s|$)/', $text, $pl) && $meta['mask'] === null) {
            $len = (int)$pl[1];
            if ($len >= 8 && $len <= 32) {
                $meta['mask'] = (string)$len;
            }
        }
    }

    /**
     * @param array<string,mixed> $meta
     * @param array<string,int> $cols
     * @param list<mixed> $row
     */
    private static function absorbMetaFromValueRow(array &$meta, array $cols, array $row): void
    {
        $val = static function (string $key) use ($cols, $row): string {
            if (!isset($cols[$key])) {
                return '';
            }
            return trim((string)($row[$cols[$key]] ?? ''));
        };
        if ($c = self::findCidrInText($val('cidr') . ' ' . $val('network'))) {
            $meta['cidr'] = $c;
        }
        if ($n = self::findNetworkInText($val('network'))) {
            $meta['network'] = $n;
        }
        if ($n = self::canonicalIp($val('network'))) {
            $meta['network'] = $n;
        }
        if ($len = self::maskToPrefixLen($val('mask'))) {
            $meta['mask'] = (string)$len;
        }
        $gwRaw = $val('gateway');
        if (preg_match('/not\s+routed/i', $gwRaw)) {
            $meta['gateway'] = null;
            $meta['role'] = $meta['role'] !== '' ? $meta['role'] : 'interconnect';
        } elseif (($gw = self::canonicalIp($gwRaw)) && !str_starts_with($gw, '255.')) {
            $meta['gateway'] = $gw;
        }
        self::absorbMetaFromText($meta, implode(' ', $row));
    }

    /** @param array<string,int> $cols */
    private static function looksLikeLegendHeader(array $cols, string $joined): bool
    {
        if (isset($cols['closet_names']) || isset($cols['address_ranges']) || isset($cols['address_ranges_per_closet_network'])) {
            return true;
        }
        return (bool)preg_match('/closet names|address ranges per closet/i', $joined);
    }

    /**
     * @param array<string,mixed> $meta
     * @param list<list<string>> $grid
     */
    private static function looksLikePrefixCatalog(array $meta, array $grid): bool
    {
        $cols = $meta['columns'];
        if (isset($cols['network']) && (isset($cols['cidr']) || isset($cols['mask']) || isset($cols['broadcast']))) {
            $idx = $cols['cidr'] ?? $cols['mask'] ?? null;
            if ($idx !== null) {
                $slash = 0;
                $n = 0;
                $start = (int)$meta['header_row'] + 1;
                $end = min(count($grid), $start + 20);
                for ($r = $start; $r < $end; $r++) {
                    $v = trim((string)($grid[$r][$idx] ?? ''));
                    if ($v === '' || $v === '*') {
                        continue;
                    }
                    $n++;
                    if (preg_match('#^/?\d{1,2}$#', $v) || self::findCidrInText($v) !== null) {
                        $slash++;
                    }
                }
                if ($n >= 3 && $slash * 2 >= $n) {
                    return true;
                }
            }
            $netIdx = $cols['network'];
            $cidrCells = 0;
            $n = 0;
            $start = (int)$meta['header_row'] + 1;
            $end = min(count($grid), $start + 12);
            for ($r = $start; $r < $end; $r++) {
                $v = trim((string)($grid[$r][$netIdx] ?? ''));
                if ($v === '') {
                    continue;
                }
                $n++;
                if (self::findCidrInText($v) !== null) {
                    $cidrCells++;
                }
            }
            if ($n >= 3 && $cidrCells * 2 >= $n) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string,mixed> $meta
     * @param list<list<string>> $grid
     */
    private static function resolveSheetCidr(array $meta, array $grid): ?string
    {
        $network = is_string($meta['network'] ?? null) ? self::canonicalIp((string)$meta['network']) : null;
        $len = isset($meta['mask']) && ctype_digit((string)$meta['mask']) ? (int)$meta['mask'] : null;
        if ($network && $len !== null) {
            $p = self::parseCidr($network . '/' . $len);
            return $p['cidr'] ?? null;
        }
        if ($network && $len === null) {
            $p = self::parseCidr($network . '/24');
            if ($p && self::sheetIpsFit($grid, $p, $meta, true)) {
                return $p['cidr'];
            }
        }
        return null;
    }

    /**
     * @param list<list<string>> $grid
     * @param array<string,mixed> $parsed
     * @param array<string,mixed> $meta
     */
    private static function sheetIpsFit(array $grid, array $parsed, array $meta, bool $requireIps = false): bool
    {
        $ipCol = $meta['columns']['ip'] ?? 0;
        $checked = 0;
        $fit = 0;
        $start = (int)($meta['header_row'] ?? 0) + 1;
        $scan = static function (int $from, int $limit) use ($grid, $ipCol, $parsed, &$checked, &$fit): void {
            for ($r = $from, $n = count($grid); $r < $n && $checked < $limit; $r++) {
                $ip = self::canonicalIp((string)($grid[$r][$ipCol] ?? ''));
                if ($ip === null) {
                    continue;
                }
                $checked++;
                if (self::cidrContains($parsed, $ip)) {
                    $fit++;
                }
            }
        };
        $scan($start, 40);
        if ($checked < 3) {
            $checked = 0;
            $fit = 0;
            $scan(0, 60);
        }
        if ($checked === 0) {
            return !$requireIps;
        }
        return $fit * 2 >= $checked;
    }

    /**
     * @param list<list<string>> $grid
     * @param array<string,mixed> $meta
     */
    private static function gatewayFromHostRows(array $grid, array $meta): ?string
    {
        $cols = $meta['columns'];
        if (!isset($cols['ip'])) {
            return null;
        }
        $hostCol = $cols['hostname'] ?? $cols['description'] ?? null;
        $start = (int)$meta['header_row'] + 1;
        $end = min(count($grid), $start + 25);
        for ($r = $start; $r < $end; $r++) {
            $ip = self::canonicalIp((string)($grid[$r][$cols['ip']] ?? ''));
            if ($ip === null) {
                continue;
            }
            $label = '';
            if ($hostCol !== null) {
                $label = strtolower(trim((string)($grid[$r][$hostCol] ?? '')));
            }
            foreach ($grid[$r] as $c) {
                $label .= ' ' . strtolower((string)$c);
            }
            $host = $hostCol !== null ? trim((string)($grid[$r][$hostCol] ?? '')) : '';
            if (preg_match('/^(default\s+)?(gw|gateway)(\b|\/|\s|$)/i', $host)
                || preg_match('/\bdefault\s+(gw|gateway|router)\b/i', $label)) {
                return $ip;
            }
        }
        return null;
    }

    public static function findNetworkInText(string $s): ?string
    {
        $s = trim($s);
        $s = preg_replace('/\bX\b/i', '0', $s) ?? $s;
        if (preg_match('/(\d{1,3}(?:\.\d{1,3}){3})/', $s, $m)) {
            return self::canonicalIp($m[1]);
        }
        return null;
    }

    /**
     * @param array<string,mixed> $meta
     * @param list<list<string>> $grid
     */
    private static function preferCidrMatchingHosts(array $meta, array $grid): ?string
    {
        $cidr = is_string($meta['cidr'] ?? null) ? $meta['cidr'] : null;
        $network = is_string($meta['network'] ?? null) ? self::canonicalIp((string)$meta['network']) : null;
        $len = isset($meta['mask']) && ctype_digit((string)$meta['mask']) ? (int)$meta['mask'] : null;
        $fromCidr = $cidr ? self::parseCidr($cidr) : null;
        if ($network && $len !== null) {
            $fromMask = self::parseCidr($network . '/' . $len);
            if ($fromMask && (!$fromCidr || $fromMask['cidr'] !== $fromCidr['cidr'])) {
                $maskFits = self::sheetIpsFit($grid, $fromMask, $meta, true);
                $cidrFits = $fromCidr ? self::sheetIpsFit($grid, $fromCidr, $meta, true) : false;
                if ($maskFits && !$cidrFits) {
                    return $fromMask['cidr'];
                }
            }
        }
        if ($fromCidr && $network && !self::sheetIpsFit($grid, $fromCidr, $meta, true)) {
            $wide = self::parseCidr($network . '/24');
            if ($wide && (int)$fromCidr['prefix_len'] > 24 && self::sheetIpsFit($grid, $wide, $meta, true)) {
                return $wide['cidr'];
            }
        }
        return $cidr;
    }

    public static function maskToPrefixLen(string $mask): ?int
    {
        $mask = trim($mask);
        if (preg_match('/^\/(\d{1,2})$/', $mask, $m) || preg_match('/^(\d{1,2})$/', $mask, $m)) {
            $len = (int)$m[1];
            // Bare "8" is usually a VLAN id; require dotted mask or /8 in text for /8-/9.
            if (preg_match('/^\d{1,2}$/', $mask) && $len < 16) {
                return null;
            }
            return ($len >= 8 && $len <= 32) ? $len : null;
        }
        $ip = self::canonicalIp($mask);
        if ($ip === null) {
            return null;
        }
        $n = self::ipToInt($ip);
        if ($n === null) {
            return null;
        }
        $bin = sprintf('%032b', $n);
        if (!preg_match('/^1*0*$/', $bin)) {
            return null;
        }
        $len = substr_count($bin, '1');
        return ($len >= 8 && $len <= 32) ? $len : null;
    }

    public static function cidrFromInclusiveRange(string $a, string $b): ?string
    {
        $ia = self::ipToInt((string)self::canonicalIp($a));
        $ib = self::ipToInt((string)self::canonicalIp($b));
        if ($ia === null || $ib === null) {
            return null;
        }
        if ($ia > $ib) {
            [$ia, $ib] = [$ib, $ia];
        }
        $xor = $ia ^ $ib;
        $len = 32;
        while ($xor > 0) {
            $xor >>= 1;
            $len--;
        }
        if ($len < 8) {
            return null;
        }
        $p = self::parseCidr(self::intToIp($ia) . '/' . $len);
        if (!$p) {
            return null;
        }
        $net = (int)$p['network_int'];
        $bcast = (int)$p['broadcast_int'];
        $isStart = $ia === $net || $ia === $net + 1;
        $isEnd = $ib === $bcast || $ib === $bcast - 1;
        if (!$isStart || !$isEnd) {
            return null;
        }
        return $p['cidr'];
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
        if ($h === 'parent' || $h === 'parent_cidr' || $h === 'aggregate'
            || $h === 'supernet' || str_ends_with($h, '_supernet') || str_ends_with($h, 'supernet')) {
            return 'parent';
        }
        $aliases = [
            'ip' => 'ip', 'ip_address' => 'ip', 'address' => 'ip', 'host_ip' => 'ip',
            'ipv4' => 'ip', 'host_address' => 'ip', 'dmz_ip_address' => 'ip', 'dmz_ip' => 'ip',
            'ethernet_address' => 'ip', 'server_ip' => 'ip',
            'hostname' => 'hostname', 'host' => 'hostname', 'host_name' => 'hostname',
            'name' => 'hostname', 'dns' => 'hostname', 'dns_name' => 'hostname',
            'fqdn' => 'hostname', 'device' => 'hostname',
            'device_name' => 'hostname', 'label' => 'hostname', 'asset' => 'hostname',
            'equipment' => 'hostname', 'ge_system_id' => 'hostname',
            'server_name' => 'hostname', 'fatpipe_usage' => 'hostname',
            'campus_clinic' => 'hostname', 'vendor_name' => 'hostname',
            'status' => 'status', 'state' => 'status', 'type' => 'status',
            'mac' => 'mac', 'mac_address' => 'mac',
            'description' => 'description', 'desc' => 'description', 'comment' => 'description',
            'notes' => 'notes', 'note' => 'notes', 'use' => 'description',
            'device_function' => 'description',
            'vlan' => 'vlan', 'vlan_id' => 'vlan', 'local_vlan' => 'vlan',
            'gateway' => 'gateway', 'gw' => 'gateway', 'default_gateway' => 'gateway',
            'default_router' => 'gateway',
            'cidr' => 'cidr', 'prefix' => 'cidr',
            'network' => 'network', 'network_address' => 'network',
            'ip_range' => 'network', 'network_address' => 'network',
            'subnet' => 'mask', 'mask' => 'mask', 'netmask' => 'mask', 'subnet_mask' => 'mask',
            'broadcast' => 'broadcast', 'broadcast_address' => 'broadcast',
            'usable_host_range' => 'range',
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
