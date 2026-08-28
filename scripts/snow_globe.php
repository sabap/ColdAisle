<?php
/**
 * ColdAisle "Snow Globe" — anonymize a restored production site for demos/screenshots.
 *
 * Shakes identifying data (names, IPs, serials, contacts, secrets) while keeping
 * topology, U-heights, power layout, and cabling structure intact.
 *
 * Usage (from site root or any cwd):
 *   php scripts/snow_globe.php
 *   php scripts/snow_globe.php --root=C:\inetpub\wwwroot\WinDCIM
 *   php scripts/snow_globe.php --dry-run
 *   php scripts/snow_globe.php --seed=42 --admin-pass='Demo-ChangeMe!'
 *   php scripts/snow_globe.php --freeze-only   # disable SNMP + re-align history to "now"
 *   php scripts/snow_globe.php --ipam-only     # rewrite IPAM CIDRs/hostnames only (screenshots)
 *
 * Only run on a non-production / lab instance.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$opts = getopt('', ['root::', 'dry-run', 'seed::', 'admin-pass::', 'org::', 'site::', 'freeze-only', 'skip-freeze', 'ipam-only', 'help']);
if (isset($opts['help'])) {
    echo "Snow Globe: anonymize inventory for demos.\n";
    echo "  --root=PATH     App root (default: parent of scripts/)\n";
    echo "  --dry-run       Print plan only\n";
    echo "  --seed=N        Deterministic seed (default: 20260814)\n";
    echo "  --admin-pass=X  Local admin password after scrub\n";
    echo "  --org=NAME      Demo org name\n";
    echo "  --site=NAME     Demo site name\n";
    echo "  --freeze-only   Only disable SNMP polling and freeze/shift history for charts\n";
    echo "  --skip-freeze   Skip history freeze step (full scrub still disables SNMP)\n";
    echo "  --ipam-only     Rewrite IPAM prefixes/addresses/aligned groups only (no rack/user changes)\n";
    exit(0);
}

$root = isset($opts['root']) && $opts['root'] !== false
    ? (string)$opts['root']
    : dirname(__DIR__);
$root = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $root), DIRECTORY_SEPARATOR);
$dry = array_key_exists('dry-run', $opts);
$freezeOnly = array_key_exists('freeze-only', $opts);
$skipFreeze = array_key_exists('skip-freeze', $opts);
$ipamOnly = array_key_exists('ipam-only', $opts);
$seed = isset($opts['seed']) ? (int)$opts['seed'] : 20260814;
$adminPass = isset($opts['admin-pass']) && $opts['admin-pass'] !== false
    ? (string)$opts['admin-pass']
    : ('Demo-' . substr(bin2hex(random_bytes(4)), 0, 8) . '!');
$orgName = isset($opts['org']) && $opts['org'] !== false ? (string)$opts['org'] : 'Aurora Computing';
$siteName = isset($opts['site']) && $opts['site'] !== false ? (string)$opts['site'] : 'Glacier Campus';

if (!is_file($root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'App.php')) {
    fwrite(STDERR, "App.php not found under {$root}\n");
    exit(1);
}

require $root . '/src/App.php';
App::boot(['light' => true]);

if (!App::isInstalled()) {
    fwrite(STDERR, "Site is not installed (no config).\n");
    exit(1);
}

$pdo = Database::connection();
mt_srand($seed);

function out(string $msg): void
{
    echo $msg . PHP_EOL;
}

/** SQL Server string literal (ODBC PDO has no quote()). */
function sqlStr(string $s): string
{
    return "'" . str_replace("'", "''", $s) . "'";
}

/** SQL Server nullable string or NULL keyword. */
function sqlStrOrNull(?string $s): string
{
    return $s === null ? 'NULL' : sqlStr($s);
}

function tableExists(PDO $pdo, string $table): bool
{
    $st = $pdo->prepare(
        "SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE='BASE TABLE' AND TABLE_NAME = ?"
    );
    $st->execute([$table]);
    return (bool)$st->fetchColumn();
}

function columns(PDO $pdo, string $table): array
{
    $st = $pdo->prepare(
        "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = ?"
    );
    $st->execute([$table]);
    return $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

function hasCol(array $cols, string $name): bool
{
    return in_array($name, $cols, true);
}

/**
 * Upsert a settings row (ODBC rowCount is unreliable).
 *
 * @param array<string,string> $pairs
 */
function upsertSettings(PDO $pdo, array $pairs): int
{
    $n = 0;
    $sel = $pdo->prepare('SELECT 1 FROM settings WHERE setting_key = ?');
    $upd = $pdo->prepare('UPDATE settings SET setting_value = ? WHERE setting_key = ?');
    $ins = $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)');
    foreach ($pairs as $k => $v) {
        $sel->execute([$k]);
        if ($sel->fetchColumn()) {
            $upd->execute([(string)$v, $k]);
        } else {
            $ins->execute([$k, (string)$v]);
        }
        $n++;
    }
    return $n;
}

/**
 * Fully disable SNMP / ICMP live polling so demo hosts are never contacted.
 */
function disableSnmpPolling(PDO $pdo): void
{
    out('--- Disable SNMP / live polling ---');

    $tables = [
        'pdus' => ['snmp_enabled', 'snmp_auto_poll', 'icmp_monitor'],
        'devices' => ['snmp_auto_poll', 'icmp_monitor'],
        'ups_units' => ['snmp_enabled', 'snmp_auto_poll'],
        'cooling_units' => ['snmp_enabled', 'snmp_auto_poll'],
    ];
    foreach ($tables as $table => $flags) {
        if (!tableExists($pdo, $table)) {
            continue;
        }
        $cols = columns($pdo, $table);
        $sets = [];
        foreach ($flags as $f) {
            if (hasCol($cols, $f)) {
                $sets[] = "{$f} = 0";
            }
        }
        // Clear secrets again (safe if already null)
        foreach ([
            'snmp_community', 'snmp_auth_passphrase', 'snmp_priv_passphrase',
            'snmp_security_name', 'snmp_v3_auth_pass', 'snmp_v3_priv_pass',
            'snmp_v3_user', 'snmp_context', 'snmp_v3_context',
        ] as $sec) {
            if (hasCol($cols, $sec)) {
                $sets[] = "{$sec} = NULL";
            }
        }
        if ($sets === []) {
            continue;
        }
        $n = $pdo->exec('UPDATE ' . $table . ' SET ' . implode(', ', $sets));
        out("  {$table}: polling flags cleared (driver rowCount=" . (int)$n . ')');
    }

    upsertSettings($pdo, [
        'snmp_poll_enabled' => '0',
        'snmp_scheduler_enabled' => '0',
        'snmp_scheduler_last_result' => 'disabled by snow-globe (demo freeze)',
        'snmp_scheduler_last_ok' => '0',
        'snmp_scheduler_last_fail' => '0',
        'power_alerts_enabled' => '0',
        'env_alerts_enabled' => '0',
        'alerts_email_enabled' => '0',
        'testing_mode' => '1',
        'demo_snmp_disabled' => '1',
    ]);
    out('  settings: snmp_scheduler_enabled=0, snmp_poll_enabled=0, alerts off');
}

/**
 * Keep history samples (for line charts) but shift timestamps so the series ends at "now".
 * Relative shape / density of the curves is preserved; live polls stay off.
 *
 * @return array{delta_sec:int,tables:array<string,int>}
 */
function freezeHistoryForCharts(PDO $pdo): array
{
    out('--- Freeze history for charts (shift timestamps to end at now) ---');

    // Find the newest sample across history tables
    $maxTs = null;
    $candidates = [
        ['pdu_readings', 'polled_at'],
        ['ups_readings', 'polled_at'],
        ['env_readings', 'recorded_at'],
        ['snmp_readings', 'polled_at'],
    ];
    foreach ($candidates as [$table, $col]) {
        if (!tableExists($pdo, $table)) {
            continue;
        }
        $cols = columns($pdo, $table);
        if (!hasCol($cols, $col)) {
            continue;
        }
        $v = $pdo->query("SELECT MAX({$col}) FROM {$table}")->fetchColumn();
        if ($v && is_string($v)) {
            $t = strtotime($v);
            if ($t !== false && ($maxTs === null || $t > $maxTs)) {
                $maxTs = $t;
            }
        }
    }

    if ($maxTs === null) {
        out('  No history rows found — charts will be empty until data is restored from a backup that includes readings.');
        upsertSettings($pdo, [
            'demo_history_frozen' => '1',
            'demo_history_frozen_at' => date('c'),
            'demo_history_shift_sec' => '0',
        ]);
        return ['delta_sec' => 0, 'tables' => []];
    }

    $now = time();
    $delta = $now - $maxTs;
    // Avoid huge accidental shifts if clocks are wrong; still allow multi-day re-align
    if (abs($delta) < 30) {
        out('  History already ends within 30s of now (delta=' . $delta . 's) — no shift needed.');
        $delta = 0;
    } else {
        out('  Latest sample: ' . date('c', $maxTs));
        out('  Shift delta:   ' . $delta . 's (' . round($delta / 3600, 2) . ' h) so series ends ~now');
    }

    $touched = [];
    if ($delta !== 0) {
        foreach ($candidates as [$table, $col]) {
            if (!tableExists($pdo, $table)) {
                continue;
            }
            $cols = columns($pdo, $table);
            if (!hasCol($cols, $col)) {
                continue;
            }
            $cnt = (int)$pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
            if ($cnt === 0) {
                continue;
            }
            // DATEADD with signed seconds
            $sql = "UPDATE {$table} SET {$col} = DATEADD(SECOND, {$delta}, {$col})";
            $pdo->exec($sql);
            $touched[$table] = $cnt;
            out("  {$table}: shifted {$cnt} row(s) via {$col}");
        }

        // Keep entity "last poll" stamps aligned so detail pages match the frozen series
        $entityStamps = [
            'pdus' => ['last_poll_at'],
            'devices' => ['snmp_last_poll_at'],
            'ups_units' => ['snmp_last_poll_at', 'last_poll_at'],
            'cooling_units' => ['snmp_last_poll_at', 'last_poll_at'],
        ];
        foreach ($entityStamps as $table => $stampCols) {
            if (!tableExists($pdo, $table)) {
                continue;
            }
            $cols = columns($pdo, $table);
            foreach ($stampCols as $col) {
                if (!hasCol($cols, $col)) {
                    continue;
                }
                try {
                    $pdo->exec(
                        "UPDATE {$table} SET {$col} = DATEADD(SECOND, {$delta}, {$col}) WHERE {$col} IS NOT NULL"
                    );
                    out("  {$table}.{$col}: re-aligned");
                } catch (Throwable $e) {
                    out("  {$table}.{$col}: skip (" . $e->getMessage() . ')');
                }
            }
        }
    }

    // Mark freeze so operators know graphs are static demo data
    upsertSettings($pdo, [
        'demo_history_frozen' => '1',
        'demo_history_frozen_at' => date('c'),
        'demo_history_shift_sec' => (string)$delta,
        'demo_history_note' => 'History preserved and time-aligned for charts; SNMP polling disabled.',
    ]);

    return ['delta_sec' => $delta, 'tables' => $touched];
}

/** Stable pseudo-random int from seed+key */
function hInt(int $seed, string $key, int $min, int $max): int
{
    $h = crc32($seed . '|' . $key);
    if ($h < 0) {
        $h = -$h;
    }
    $span = $max - $min + 1;
    return $min + ($h % $span);
}

function demoIp(int $seed, int $id, int $octet2 = 80): string
{
    // 10.{octet2}.x.y — private, not RFC1918-looking-like-corp 172.x
    $x = hInt($seed, "ipx:$id", 1, 254);
    $y = hInt($seed, "ipy:$id", 1, 254);
    return "10.{$octet2}.{$x}.{$y}";
}

function demoMac(int $seed, int $id): string
{
    // Locally administered unicast (02:…)
    $b = [];
    for ($i = 0; $i < 5; $i++) {
        $b[] = sprintf('%02X', hInt($seed, "mac:$id:$i", 0, 255));
    }
    return '02:' . implode(':', $b);
}

function demoSerial(int $seed, int $id, string $prefix = 'SN'): string
{
    return sprintf('%s%08X', $prefix, hInt($seed, "sn:$id", 0x10000000, 0x7FFFFFFF));
}

function demoAsset(int $seed, int $id): string
{
    return sprintf('AT%06d', hInt($seed, "at:$id", 100000, 999999));
}

function ipv4ToInt(string $ip): ?int
{
    $ip = trim($ip);
    if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
        return null;
    }
    $n = ip2long($ip);
    if ($n === false) {
        return null;
    }
    return (int)sprintf('%u', $n);
}

function intToIpv4(int $n): string
{
    return (string)long2ip($n & 0xFFFFFFFF);
}

function ipv4IsPrivate(int $n): bool
{
    $a = ($n >> 24) & 0xFF;
    $b = ($n >> 16) & 0xFF;
    if ($a === 10) {
        return true;
    }
    if ($a === 192 && $b === 168) {
        return true;
    }
    if ($a === 172 && $b >= 16 && $b <= 31) {
        return true;
    }
    return false;
}

function alignIpv4(int $cursor, int $size): int
{
    if ($size <= 1) {
        return $cursor;
    }
    $mask = $size - 1;
    return ($cursor + $mask) & ~$mask;
}

function remapIpv4(?string $ip, int $oldNet, int $newNet): ?string
{
    if ($ip === null) {
        return null;
    }
    $n = ipv4ToInt($ip);
    if ($n === null) {
        return null;
    }
    return intToIpv4($newNet + ($n - $oldNet));
}

/** Map a real hostname to a fictional but realistic label. */
function demoIpamHostname(string $old, array &$seqByRole, array &$hostMap): string
{
    $raw = trim($old);
    if ($raw === '') {
        return '';
    }
    $key = strtolower($raw);
    if (isset($hostMap[$key])) {
        return $hostMap[$key];
    }
    $core = $key;
    $core = preg_replace('/\s+/', ' ', $core) ?? $core;
    $core = preg_replace('/\b(www\.)?[\w.-]+\.(org|com|net|local|edu|gov)\b/', '', $core) ?? $core;
    $isGw = (bool)preg_match('/\b(default\s*gw|default\s*gateway|gateway|def gw)\b/', $core);
    $role = 'node';
    if ($isGw) {
        $role = 'gateway';
    } elseif (preg_match('/firepower|asa|firewall|\bfw\b|ngfw/', $core)) {
        $role = 'fw';
    } elseif (preg_match('/fatpipe|sd-?wan|magicwan|orchestrator/', $core)) {
        $role = 'sdwan';
    } elseif (preg_match('/netscaler|citrix|\badc\b|\bvip\b|aaa/', $core)) {
        $role = 'adc';
    } elseif (preg_match('/anyconnect|\bvpn\b/', $core)) {
        $role = 'vpn';
    } elseif (preg_match('/securelink|encryption/', $core)) {
        $role = 'remote';
    } elseif (preg_match('/hyper-?v|esx|vmotion|scvmm|hvclust|virtual/', $core)) {
        $role = 'virt';
    } elseif (preg_match('/isilon|nas|immstor|pacs|stor|san|veeam|vault/', $core)) {
        $role = 'stor';
    } elseif (preg_match('/\bkvm\b/', $core)) {
        $role = 'kvm';
    } elseif (preg_match('/\bmail\b|\bowa\b|exchange/', $core)) {
        $role = 'mail';
    } elseif (preg_match('/lync|skype|sip|xmpp|webconf|avaya|sbc|collab/', $core)) {
        $role = 'collab';
    } elseif (preg_match('/wsus|sccm|observ|wsus/', $core)) {
        $role = 'mgmt';
    } elseif (preg_match('/webserver|\bweb\b|proxy|isa\b/', $core)) {
        $role = 'web';
    } elseif (preg_match('/\bdns\b/', $core)) {
        $role = 'dns';
    } elseif (preg_match('/dhcp/', $core)) {
        $role = 'dhcp';
    } elseif (preg_match('/ilo|ilom|idrac|ipmi|oob/', $core)) {
        $role = 'oob';
    } elseif (preg_match('/switch|core|router/', $core)) {
        $role = 'net';
    } elseif (preg_match('/pos_/', $core)) {
        $role = 'pos';
    } elseif (preg_match('/pacs|dicom|cardio|sectra/', $core)) {
        $role = 'img';
    }
    $seqByRole[$role] = ($seqByRole[$role] ?? 0) + 1;
    $n = $seqByRole[$role];
    if ($role === 'gateway') {
        $name = 'gateway';
    } else {
        $name = sprintf('%s-%02d', $role, $n);
    }
    $hostMap[$key] = $name;
    return $name;
}

/**
 * Rewrite IPAM so screenshots do not leak real CIDRs, ISPs, or hostnames.
 * Prefix lengths and host offsets stay the same (aligned last-octet groups still work).
 *
 * @return array{prefixes:int,addresses:int,groups:int}
 */
function anonymizeIpam(PDO $pdo, int $seed): array
{
    out('--- IPAM (demo CIDRs + hostnames) ---');
    $stats = ['prefixes' => 0, 'addresses' => 0, 'groups' => 0];
    if (!tableExists($pdo, 'ipam_prefixes')) {
        out('  ipam tables not present — skip');
        return $stats;
    }

    $pfxCols = columns($pdo, 'ipam_prefixes');
    $prefixes = $pdo->query('SELECT * FROM ipam_prefixes')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($prefixes === []) {
        out('  no prefixes');
        return $stats;
    }

    usort($prefixes, static function ($a, $b) {
        $la = (int)($a['prefix_len'] ?? 24);
        $lb = (int)($b['prefix_len'] ?? 24);
        if ($la !== $lb) {
            return $la <=> $lb;
        }
        return ((int)($a['network_int'] ?? 0)) <=> ((int)($b['network_int'] ?? 0));
    });

    $privCursor = ipv4ToInt('10.80.0.0');
    $pubCursor = ipv4ToInt('203.0.113.0');
    if ($privCursor === null || $pubCursor === null) {
        throw new RuntimeException('IPAM remap cursor failed');
    }

    $map = [];
    foreach ($prefixes as $p) {
        $pid = (int)$p['prefix_id'];
        $cidr = trim((string)($p['cidr'] ?? ''));
        $oldNet = null;
        $len = (int)($p['prefix_len'] ?? 24);
        if (preg_match('#^(\d{1,3}(?:\.\d{1,3}){3})/(\d{1,2})$#', $cidr, $m)) {
            $oldNet = ipv4ToInt($m[1]);
            $len = (int)$m[2];
        } elseif (isset($p['network_int']) && $p['network_int'] !== null && $p['network_int'] !== '') {
            $oldNet = (int)$p['network_int'];
        }
        if ($oldNet === null || $len < 8 || $len > 32) {
            out("  skip prefix {$pid} (unparseable CIDR)");
            continue;
        }
        $size = 1 << (32 - $len);
        $public = !ipv4IsPrivate($oldNet);
        if ($public) {
            $pubCursor = alignIpv4($pubCursor, $size);
            $newNet = $pubCursor;
            $pubCursor += $size;
        } else {
            $privCursor = alignIpv4($privCursor, $size);
            $newNet = $privCursor;
            $privCursor += $size;
        }
        $map[$pid] = [
            'old_net' => $oldNet,
            'new_net' => $newNet,
            'len' => $len,
            'cidr' => intToIpv4($newNet) . '/' . $len,
            'public' => $public,
            'row' => $p,
        ];
    }

    $newByAddr = [];
    $hostMap = [];
    $seqByRole = [];
    if (tableExists($pdo, 'ipam_addresses')) {
        $rows = $pdo->query(
            'SELECT address_id, prefix_id, ip, hostname FROM ipam_addresses'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $row) {
            $pid = (int)$row['prefix_id'];
            $aid = (int)$row['address_id'];
            if (!isset($map[$pid])) {
                continue;
            }
            $m = $map[$pid];
            $oldIp = ipv4ToInt((string)$row['ip']);
            $newIp = $oldIp === null
                ? remapIpv4((string)$row['ip'], $m['old_net'], $m['new_net'])
                : intToIpv4($m['new_net'] + ($oldIp - $m['old_net']));
            if ($newIp === null) {
                continue;
            }
            $host = demoIpamHostname((string)($row['hostname'] ?? ''), $seqByRole, $hostMap);
            $newByAddr[$aid] = ['ip' => $newIp, 'ip_int' => ipv4ToInt($newIp), 'hostname' => $host];
        }
    }

    // Park then write finals so unique (cidr/ip) keys cannot collide mid-update.
    foreach ($map as $pid => $m) {
        $park = sprintf('240.%d.%d.0/%d', ($pid >> 8) & 255, $pid & 255, (int)$m['len']);
        $st = $pdo->prepare('UPDATE ipam_prefixes SET cidr = ?, network_int = ?, prefix_len = ? WHERE prefix_id = ?');
        $st->execute([$park, $pid, $m['len'], $pid]);
    }
    if ($newByAddr !== []) {
        $st = $pdo->prepare('UPDATE ipam_addresses SET ip = ?, ip_int = ? WHERE address_id = ?');
        foreach ($newByAddr as $aid => $nv) {
            $parkIp = sprintf('241.%d.%d.%d', ($aid >> 16) & 255, ($aid >> 8) & 255, $aid & 255);
            $st->execute([$parkIp, $aid, $aid]);
        }
    }

    $internetN = 0;
    $hasDhcp = hasCol($pfxCols, 'dhcp_start');
    $hasNotes = hasCol($pfxCols, 'notes');
    $hasDesc = hasCol($pfxCols, 'description');
    $hasRole = hasCol($pfxCols, 'role');
    $hasGw = hasCol($pfxCols, 'gateway');
    $sets = ['cidr = ?', 'name = ?', 'prefix_len = ?', 'network_int = ?'];
    if ($hasGw) {
        $sets[] = 'gateway = ?';
    }
    if ($hasDhcp) {
        $sets[] = 'dhcp_start = ?';
        $sets[] = 'dhcp_end = ?';
    }
    if ($hasDesc) {
        $sets[] = 'description = ?';
    }
    if ($hasNotes) {
        $sets[] = 'notes = NULL';
    }
    if ($hasRole) {
        $sets[] = 'role = ?';
    }
    if (hasCol($pfxCols, 'updated_at')) {
        $sets[] = 'updated_at = SYSUTCDATETIME()';
    }
    $pfxUpd = $pdo->prepare('UPDATE ipam_prefixes SET ' . implode(', ', $sets) . ' WHERE prefix_id = ?');
    foreach ($map as $pid => $m) {
        $p = $m['row'];
        $oldName = trim((string)($p['name'] ?? ''));
        $newNetStr = intToIpv4($m['new_net']);
        $role = ($p['role'] ?? null) ?: null;
        if ($m['public'] || preg_match('/internet|mediacom|hargray|clearwave|\batt\b|at&t|wan circuit/i', $oldName)) {
            $internetN++;
            $name = 'Internet circuit ' . chr(64 + min($internetN, 26));
            $role = 'public';
        } elseif (preg_match('/dmz/i', $oldName)) {
            $name = 'DMZ';
            $role = 'public';
        } elseif ($m['len'] >= 30) {
            $name = 'P2P ' . $newNetStr;
            $role = 'interconnect';
        } elseif ($oldName === '' || preg_match('/^\d{1,3}(\.\d{1,3}){3}/', $oldName)) {
            $vlan = !empty($p['vlan_id']) ? (int)$p['vlan_id'] : 0;
            $name = $vlan > 0 ? ('VLAN ' . $vlan) : $newNetStr;
        } else {
            $name = 'Subnet ' . $pid;
        }
        $params = [$m['cidr'], $name, $m['len'], $m['new_net']];
        if ($hasGw) {
            $params[] = remapIpv4($p['gateway'] ?? null, $m['old_net'], $m['new_net']);
        }
        if ($hasDhcp) {
            $params[] = remapIpv4($p['dhcp_start'] ?? null, $m['old_net'], $m['new_net']);
            $params[] = remapIpv4($p['dhcp_end'] ?? null, $m['old_net'], $m['new_net']);
        }
        if ($hasDesc) {
            $params[] = 'Demo address plan';
        }
        if ($hasRole) {
            $params[] = $role;
        }
        $params[] = $pid;
        $pfxUpd->execute($params);
        $stats['prefixes']++;
    }
    out('  prefixes remapped: ' . $stats['prefixes']);

    if ($newByAddr !== []) {
        $addrCols = columns($pdo, 'ipam_addresses');
        $sets = ['ip = ?', 'ip_int = ?', 'hostname = ?'];
        $hasMac = hasCol($addrCols, 'mac_address');
        if ($hasMac) {
            $sets[] = 'mac_address = ?';
        }
        if (hasCol($addrCols, 'description')) {
            $sets[] = 'description = NULL';
        }
        if (hasCol($addrCols, 'notes')) {
            $sets[] = 'notes = NULL';
        }
        if (hasCol($addrCols, 'updated_at')) {
            $sets[] = 'updated_at = SYSUTCDATETIME()';
        }
        $st = $pdo->prepare('UPDATE ipam_addresses SET ' . implode(', ', $sets) . ' WHERE address_id = ?');
        foreach ($newByAddr as $aid => $nv) {
            $params = [$nv['ip'], $nv['ip_int'], $nv['hostname'] === '' ? null : $nv['hostname']];
            if ($hasMac) {
                $params[] = demoMac($seed, 900000 + $aid);
            }
            $params[] = $aid;
            $st->execute($params);
            $stats['addresses']++;
        }
        out('  addresses remapped: ' . $stats['addresses']);
    }

    if (tableExists($pdo, 'ipam_align_groups')) {
        $groups = $pdo->query('SELECT group_id, name FROM ipam_align_groups ORDER BY group_id')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $gUpd = $pdo->prepare('UPDATE ipam_align_groups SET name = ?, description = ? WHERE group_id = ?');
        $i = 0;
        foreach ($groups as $g) {
            $i++;
            $gUpd->execute(['Aligned group ' . $i, 'Demo same-index assignment', (int)$g['group_id']]);
            $stats['groups']++;
        }
        if (tableExists($pdo, 'ipam_align_members')) {
            $members = $pdo->query('SELECT member_id FROM ipam_align_members ORDER BY group_id, sort_order, member_id')->fetchAll(PDO::FETCH_COLUMN) ?: [];
            $mUpd = $pdo->prepare('UPDATE ipam_align_members SET label = ? WHERE member_id = ?');
            $n = 0;
            foreach ($members as $mid) {
                $n++;
                $mUpd->execute(['Provider ' . chr(64 + min($n, 26)), (int)$mid]);
            }
        }
        if (tableExists($pdo, 'ipam_align_slots')) {
            $slots = $pdo->query('SELECT slot_id, hostname FROM ipam_align_slots')->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $sUpd = $pdo->prepare('UPDATE ipam_align_slots SET hostname = ?, notes = NULL WHERE slot_id = ?');
            foreach ($slots as $s) {
                $host = demoIpamHostname((string)($s['hostname'] ?? ''), $seqByRole, $hostMap);
                if ($host === '') {
                    $host = 'site-' . (int)$s['slot_id'];
                }
                $sUpd->execute([$host, (int)$s['slot_id']]);
            }
        }
        if ($stats['groups'] > 0) {
            out('  aligned groups: ' . $stats['groups']);
        }
    }

    out('  hostnames rewritten (fictional roles). Public prefixes use TEST-NET-3 (203.0.113.0/24).');
    return $stats;
}

/** Map device_type → short role code */
function deviceRoleCode(?string $type, ?string $label): string
{
    $t = strtolower((string)$type);
    $l = strtolower((string)$label);
    $blob = $t . ' ' . $l;
    if (preg_match('/pdu|power distribution/', $blob)) {
        return 'PDU';
    }
    if (preg_match('/ups|battery/', $blob)) {
        return 'UPS';
    }
    if (preg_match('/switch|nexus|catalyst|chassis|router|firewall|core|spine|leaf/', $blob)) {
        return 'SW';
    }
    if (preg_match('/storage|san|nas|netapp|emc|pure/', $blob)) {
        return 'STO';
    }
    if (preg_match('/kvm|console/', $blob)) {
        return 'KVM';
    }
    if (preg_match('/patch|panel/', $blob)) {
        return 'PAT';
    }
    if (preg_match('/server|blade|compute|host|vmware|esxi|hyper/', $blob)) {
        return 'SRV';
    }
    if (preg_match('/sensor|environment|temp|humidity/', $blob)) {
        return 'ENV';
    }
    if (preg_match('/cooling|crac|craH|ahu|chiller/i', $blob)) {
        return 'CRAC';
    }
    return 'DEV';
}

$stats = [
    'tables_touched' => 0,
    'rows_updated' => 0,
    'secrets_cleared' => 0,
    'config_rewritten' => false,
];

out('=== ColdAisle Snow Globe ===');
out('Root:  ' . $root);
out('Seed:  ' . $seed);
out('Org:   ' . $orgName);
out('Site:  ' . $siteName);
out('Mode:  ' . ($dry ? 'DRY-RUN' : ($ipamOnly ? 'IPAM-ONLY' : ($freezeOnly ? 'FREEZE-ONLY' : 'LIVE'))));
out('');

if ($dry) {
    out('Dry-run: would anonymize inventory, clear secrets, rewrite org identity, reset admin,');
    out('disable SNMP polling, and freeze/shift history so line charts stay populated.');
    if ($ipamOnly) {
        out('With --ipam-only: rewrite IPAM CIDRs/hostnames only.');
    }
    out('Re-run without --dry-run to apply.');
    exit(0);
}

if ($ipamOnly) {
    $pdo->beginTransaction();
    try {
        $ipam = anonymizeIpam($pdo, $seed);
        $pdo->commit();
        out('');
        out('IPAM-only complete. Racks, users, and admin password were not changed.');
        out('Prefixes:  ' . $ipam['prefixes']);
        out('Addresses: ' . $ipam['addresses']);
        out('Groups:    ' . $ipam['groups']);
        out('Refresh IPAM in the browser for screenshots.');
        exit(0);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        fwrite(STDERR, 'FAILED: ' . $e->getMessage() . PHP_EOL);
        fwrite(STDERR, $e->getFile() . ':' . $e->getLine() . PHP_EOL);
        exit(1);
    }
}

// Freeze-only: skip anonymize; just lock down polling + chart history
if ($freezeOnly) {
    $pdo->beginTransaction();
    try {
        disableSnmpPolling($pdo);
        if (!$skipFreeze) {
            freezeHistoryForCharts($pdo);
        }
        $pdo->commit();
        out('');
        out('Freeze-only complete. SNMP polling is off; history kept for graphs.');
        exit(0);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        fwrite(STDERR, 'FAILED: ' . $e->getMessage() . PHP_EOL);
        fwrite(STDERR, $e->getFile() . ':' . $e->getLine() . PHP_EOL);
        exit(1);
    }
}

$pdo->beginTransaction();

try {
    // --- 1) Org / site / facility names ---
    if (tableExists($pdo, 'sites')) {
        $st = $pdo->prepare(
            "UPDATE sites SET
                name = ?, code = 'GLC', address = '100 Aurora Way',
                city = 'North Pole', state = 'AK', postal_code = '99705', country = 'US',
                contact_name = 'Demo Ops', contact_phone = '555-0100',
                notes = 'Snow-globe demo site - fictional identity'"
        );
        $st->execute([$siteName]);
        $stats['rows_updated'] += $st->rowCount();
        $stats['tables_touched']++;
        out("sites: updated");
    }

    if (tableExists($pdo, 'datacenters')) {
        $n = $pdo->exec(
            "UPDATE datacenters SET
                name = 'Aurora Hall',
                code = 'AH1',
                delivery_address = 'Loading Dock B - 100 Aurora Way',
                notes = NULL"
        );
        $stats['rows_updated'] += (int)$n;
        $stats['tables_touched']++;
        out("datacenters: updated");
    }

    if (tableExists($pdo, 'rooms')) {
        $cols = columns($pdo, 'rooms');
        $set = ["name = 'White Space A'"];
        if (hasCol($cols, 'code')) {
            $set[] = "code = 'WS-A'";
        }
        if (hasCol($cols, 'notes')) {
            $set[] = 'notes = NULL';
        }
        $n = $pdo->exec('UPDATE rooms SET ' . implode(', ', $set));
        $stats['rows_updated'] += (int)$n;
        $stats['tables_touched']++;
        out("rooms: updated");
    }

    if (tableExists($pdo, 'cabinet_rows')) {
        $rows = $pdo->query('SELECT row_id FROM cabinet_rows ORDER BY row_id')->fetchAll(PDO::FETCH_COLUMN);
        $letters = range('A', 'Z');
        $st = $pdo->prepare('UPDATE cabinet_rows SET name = ?, notes = NULL WHERE row_id = ?');
        $i = 0;
        foreach ($rows as $rid) {
            $letter = $letters[$i % 26] . ($i >= 26 ? (string)(int)floor($i / 26) : '');
            $st->execute(['Row ' . $letter, $rid]);
            $i++;
            $stats['rows_updated']++;
        }
        $stats['tables_touched']++;
        out('cabinet_rows: ' . count($rows));
    }

    // --- 2) Cabinets (rename + light position shift so plan isn't a fingerprint) ---
    if (tableExists($pdo, 'cabinets')) {
        $cabs = $pdo->query(
            'SELECT cabinet_id, row_id, pos_x, pos_y, name FROM cabinets ORDER BY row_id, cabinet_id'
        )->fetchAll(PDO::FETCH_ASSOC);
        $byRow = [];
        foreach ($cabs as $c) {
            $rid = (string)($c['row_id'] ?? '0');
            $byRow[$rid][] = $c;
        }
        $rowLetters = [];
        $ri = 0;
        foreach (array_keys($byRow) as $rid) {
            $rowLetters[$rid] = chr(ord('A') + ($ri % 26));
            $ri++;
        }
        // Stable offset so layout shifts but relative spacing mostly kept
        $dx = 0.37 + (hInt($seed, 'dx', 0, 50) / 100.0);
        $dy = 0.21 + (hInt($seed, 'dy', 0, 50) / 100.0);
        $st = $pdo->prepare(
            'UPDATE cabinets SET name = ?, location_tag = ?, pos_x = ?, pos_y = ?, notes = NULL WHERE cabinet_id = ?'
        );
        foreach ($byRow as $rid => $list) {
            $n = 1;
            foreach ($list as $c) {
                $letter = $rowLetters[$rid] ?? 'X';
                $name = sprintf('R%s-%02d', $letter, $n);
                $px = $c['pos_x'] !== null ? ((float)$c['pos_x'] + $dx) : null;
                $py = $c['pos_y'] !== null ? ((float)$c['pos_y'] + $dy) : null;
                $st->execute([$name, 'GLC-' . $name, $px, $py, $c['cabinet_id']]);
                $n++;
                $stats['rows_updated']++;
            }
        }
        $stats['tables_touched']++;
        out('cabinets: ' . count($cabs) . " (shifted +{$dx}/+{$dy} m)");
    }

    // --- 3) Devices ---
    if (tableExists($pdo, 'devices')) {
        $devs = $pdo->query(
            'SELECT device_id, device_type, label, hostname FROM devices ORDER BY device_id'
        )->fetchAll(PDO::FETCH_ASSOC);
        $roleCounters = [];
        $st = $pdo->prepare(
            'UPDATE devices SET
                label = ?, hostname = ?,
                primary_ip = ?, mgmt_ip = ?,
                serial_no = ?, asset_tag = ?,
                notes = NULL, custom_fields = NULL, tags = NULL,
                snmp_community = NULL, snmp_v3_auth_pass = NULL, snmp_v3_priv_pass = NULL,
                snmp_v3_user = NULL, snmp_v3_context = NULL,
                idrac_host = NULL,
                snmp_auto_poll = 0, icmp_monitor = 0,
                purchase_vendor = NULL, po_number = NULL, rma_number = NULL, rma_notes = NULL,
                warranty_provider = NULL
             WHERE device_id = ?'
        );
        foreach ($devs as $d) {
            $id = (int)$d['device_id'];
            $role = deviceRoleCode($d['device_type'] ?? null, $d['label'] ?? null);
            $roleCounters[$role] = ($roleCounters[$role] ?? 0) + 1;
            $num = $roleCounters[$role];
            $host = sprintf('DEMO-%s-%03d', $role, $num);
            $ip = demoIp($seed, $id, 80);
            $st->execute([
                $host,
                $host,
                $ip,
                $ip,
                demoSerial($seed, $id, 'DV'),
                demoAsset($seed, $id),
                $id,
            ]);
            $stats['rows_updated']++;
            $stats['secrets_cleared']++;
        }
        $stats['tables_touched']++;
        out('devices: ' . count($devs));
    }

    // --- 4) PDUs ---
    if (tableExists($pdo, 'pdus')) {
        $pdus = $pdo->query('SELECT pdu_id, name, cabinet_id FROM pdus ORDER BY pdu_id')->fetchAll(PDO::FETCH_ASSOC);
        $st = $pdo->prepare(
            'UPDATE pdus SET
                name = ?, ip_address = ?,
                serial_no = ?, mac_address = ?,
                notes = NULL,
                snmp_community = NULL,
                snmp_security_name = NULL,
                snmp_auth_passphrase = NULL,
                snmp_priv_passphrase = NULL,
                snmp_context = NULL,
                snmp_auto_poll = 0,
                snmp_enabled = 0,
                icmp_monitor = 0
             WHERE pdu_id = ?'
        );
        $i = 1;
        foreach ($pdus as $p) {
            $id = (int)$p['pdu_id'];
            $name = sprintf('PDU-%03d', $i);
            $st->execute([
                $name,
                demoIp($seed, 10000 + $id, 81),
                demoSerial($seed, $id, 'PD'),
                demoMac($seed, $id),
                $id,
            ]);
            $i++;
            $stats['rows_updated']++;
            $stats['secrets_cleared']++;
        }
        $stats['tables_touched']++;
        out('pdus: ' . count($pdus));
    }

    // --- 5) UPS ---
    if (tableExists($pdo, 'ups_units')) {
        $cols = columns($pdo, 'ups_units');
        $rows = $pdo->query('SELECT ups_id FROM ups_units ORDER BY ups_id')->fetchAll(PDO::FETCH_COLUMN);
        $sets = ['name = ?', 'notes = NULL'];
        $paramsExtra = [];
        if (hasCol($cols, 'hostname')) {
            $sets[] = 'hostname = ?';
        }
        if (hasCol($cols, 'primary_ip')) {
            $sets[] = 'primary_ip = ?';
        }
        if (hasCol($cols, 'serial_no')) {
            $sets[] = 'serial_no = ?';
        }
        if (hasCol($cols, 'asset_tag')) {
            $sets[] = 'asset_tag = ?';
        }
        if (hasCol($cols, 'snmp_community')) {
            $sets[] = 'snmp_community = NULL';
        }
        if (hasCol($cols, 'snmp_auth_passphrase')) {
            $sets[] = 'snmp_auth_passphrase = NULL';
        }
        if (hasCol($cols, 'snmp_priv_passphrase')) {
            $sets[] = 'snmp_priv_passphrase = NULL';
        }
        if (hasCol($cols, 'snmp_security_name')) {
            $sets[] = 'snmp_security_name = NULL';
        }
        if (hasCol($cols, 'snmp_auto_poll')) {
            $sets[] = 'snmp_auto_poll = 0';
        }
        if (hasCol($cols, 'snmp_enabled')) {
            $sets[] = 'snmp_enabled = 0';
        }
        $sql = 'UPDATE ups_units SET ' . implode(', ', $sets) . ' WHERE ups_id = ?';
        $st = $pdo->prepare($sql);
        $i = 1;
        foreach ($rows as $id) {
            $id = (int)$id;
            $name = sprintf('UPS-%02d', $i);
            $bind = [$name];
            if (hasCol($cols, 'hostname')) {
                $bind[] = $name;
            }
            if (hasCol($cols, 'primary_ip')) {
                $bind[] = demoIp($seed, 20000 + $id, 82);
            }
            if (hasCol($cols, 'serial_no')) {
                $bind[] = demoSerial($seed, $id, 'UP');
            }
            if (hasCol($cols, 'asset_tag')) {
                $bind[] = demoAsset($seed, 20000 + $id);
            }
            $bind[] = $id;
            $st->execute($bind);
            $i++;
            $stats['rows_updated']++;
        }
        $stats['tables_touched']++;
        out('ups_units: ' . count($rows));
    }

    // --- 6) Cooling ---
    if (tableExists($pdo, 'cooling_units')) {
        $cols = columns($pdo, 'cooling_units');
        $pk = hasCol($cols, 'cooling_unit_id') ? 'cooling_unit_id' : (hasCol($cols, 'unit_id') ? 'unit_id' : null);
        if ($pk) {
            $rows = $pdo->query("SELECT {$pk} FROM cooling_units ORDER BY {$pk}")->fetchAll(PDO::FETCH_COLUMN);
            $i = 1;
            foreach ($rows as $id) {
                $id = (int)$id;
                $name = sprintf('CRAC-%02d', $i);
                $sets = ["name = " . sqlStr($name)];
                if (hasCol($cols, 'hostname')) {
                    $sets[] = 'hostname = ' . sqlStr($name);
                }
                if (hasCol($cols, 'primary_ip')) {
                    $sets[] = 'primary_ip = ' . sqlStr(demoIp($seed, 30000 + $id, 83));
                }
                if (hasCol($cols, 'serial_no')) {
                    $sets[] = 'serial_no = ' . sqlStr(demoSerial($seed, $id, 'CL'));
                }
                if (hasCol($cols, 'asset_tag')) {
                    $sets[] = 'asset_tag = ' . sqlStr(demoAsset($seed, 30000 + $id));
                }
                if (hasCol($cols, 'notes')) {
                    $sets[] = 'notes = NULL';
                }
                if (hasCol($cols, 'snmp_community')) {
                    $sets[] = 'snmp_community = NULL';
                }
                if (hasCol($cols, 'snmp_auth_passphrase')) {
                    $sets[] = 'snmp_auth_passphrase = NULL';
                }
                if (hasCol($cols, 'snmp_priv_passphrase')) {
                    $sets[] = 'snmp_priv_passphrase = NULL';
                }
                if (hasCol($cols, 'snmp_auto_poll')) {
                    $sets[] = 'snmp_auto_poll = 0';
                }
                if (hasCol($cols, 'snmp_enabled')) {
                    $sets[] = 'snmp_enabled = 0';
                }
                $pdo->exec("UPDATE cooling_units SET " . implode(', ', $sets) . " WHERE {$pk} = {$id}");
                $i++;
                $stats['rows_updated']++;
            }
            $stats['tables_touched']++;
            out('cooling_units: ' . count($rows));
        }
    }

    // --- 7) Env sensors ---
    if (tableExists($pdo, 'env_sensors')) {
        $cols = columns($pdo, 'env_sensors');
        $pk = hasCol($cols, 'sensor_id') ? 'sensor_id' : (hasCol($cols, 'env_sensor_id') ? 'env_sensor_id' : null);
        if ($pk) {
            $rows = $pdo->query("SELECT {$pk} FROM env_sensors ORDER BY {$pk}")->fetchAll(PDO::FETCH_COLUMN);
            $i = 1;
            foreach ($rows as $id) {
                $id = (int)$id;
                $name = sprintf('SENSOR-%02d', $i);
                $sets = ['name = ' . sqlStr($name)];
                if (hasCol($cols, 'location_label')) {
                    $sets[] = 'location_label = ' . sqlStr('Zone ' . chr(64 + min(26, $i)));
                }
                if (hasCol($cols, 'notes')) {
                    $sets[] = 'notes = NULL';
                }
                $pdo->exec("UPDATE env_sensors SET " . implode(', ', $sets) . " WHERE {$pk} = {$id}");
                $i++;
                $stats['rows_updated']++;
            }
            $stats['tables_touched']++;
            out('env_sensors: ' . count($rows));
        }
    }

    // --- 8) Cable paths ---
    if (tableExists($pdo, 'cable_paths')) {
        $cols = columns($pdo, 'cable_paths');
        $paths = $pdo->query('SELECT path_id, path_kind, path_type, name FROM cable_paths ORDER BY path_id')
            ->fetchAll(PDO::FETCH_ASSOC);
        $counters = [];
        foreach ($paths as $p) {
            $id = (int)$p['path_id'];
            $kind = strtolower((string)($p['path_kind'] ?? $p['path_type'] ?? 'path'));
            if (str_contains($kind, 'ladder')) {
                $prefix = 'LAD';
            } elseif (str_contains($kind, 'u_channel') || str_contains($kind, 'uchannel') || str_contains($kind, 'fiber')) {
                $prefix = 'UCH';
            } elseif (str_contains($kind, 'trough')) {
                $prefix = 'TRH';
            } elseif (str_contains($kind, 'conduit')) {
                $prefix = 'CND';
            } else {
                $prefix = 'PTH';
            }
            $counters[$prefix] = ($counters[$prefix] ?? 0) + 1;
            $code = sprintf('%s-%02d', $prefix, $counters[$prefix]);
            $sets = [];
            if (hasCol($cols, 'name')) {
                $sets[] = 'name = ' . sqlStr($code);
            }
            if (hasCol($cols, 'path_code')) {
                $sets[] = 'path_code = ' . sqlStr($code);
            }
            if (hasCol($cols, 'notes')) {
                $sets[] = 'notes = NULL';
            }
            if ($sets) {
                $pdo->exec('UPDATE cable_paths SET ' . implode(', ', $sets) . ' WHERE path_id = ' . $id);
                $stats['rows_updated']++;
            }
        }
        $stats['tables_touched']++;
        out('cable_paths: ' . count($paths));
    }

    // --- 9) Power zones / panels ---
    if (tableExists($pdo, 'power_zones')) {
        $rows = $pdo->query('SELECT zone_id FROM power_zones ORDER BY zone_id')->fetchAll(PDO::FETCH_COLUMN);
        $i = 1;
        foreach ($rows as $id) {
            $pdo->exec(
                'UPDATE power_zones SET name = ' . sqlStr(sprintf('Zone %d', $i))
                . ', description = NULL, notes = NULL WHERE zone_id = ' . (int)$id
            );
            $i++;
            $stats['rows_updated']++;
        }
        $stats['tables_touched']++;
        out('power_zones: ' . count($rows));
    }
    if (tableExists($pdo, 'power_panels')) {
        $cols = columns($pdo, 'power_panels');
        $rows = $pdo->query('SELECT panel_id FROM power_panels ORDER BY panel_id')->fetchAll(PDO::FETCH_COLUMN);
        $i = 1;
        foreach ($rows as $id) {
            $sets = ['name = ' . sqlStr(sprintf('Panel %s', chr(64 + min(26, $i))))];
            if (hasCol($cols, 'location_notes')) {
                $sets[] = 'location_notes = NULL';
            }
            if (hasCol($cols, 'notes')) {
                $sets[] = 'notes = NULL';
            }
            $pdo->exec('UPDATE power_panels SET ' . implode(', ', $sets) . ' WHERE panel_id = ' . (int)$id);
            $i++;
            $stats['rows_updated']++;
        }
        $stats['tables_touched']++;
        out('power_panels: ' . count($rows));
    }

    // --- 10) Contacts & departments ---
    if (tableExists($pdo, 'contacts')) {
        $rows = $pdo->query('SELECT contact_id FROM contacts ORDER BY contact_id')->fetchAll(PDO::FETCH_COLUMN);
        $first = ['Alex', 'Jordan', 'Sam', 'Taylor', 'Casey', 'Riley', 'Morgan', 'Quinn', 'Avery', 'Jamie'];
        $last = ['Frost', 'Glacier', 'North', 'Winter', 'Polar', 'Ice', 'Snow', 'Aurora', 'Borealis', 'Crystal'];
        $st = $pdo->prepare(
            'UPDATE contacts SET first_name = ?, last_name = ?, email = ?, phone = ?, notes = NULL WHERE contact_id = ?'
        );
        foreach ($rows as $id) {
            $id = (int)$id;
            $fn = $first[hInt($seed, "fn:$id", 0, count($first) - 1)];
            $ln = $last[hInt($seed, "ln:$id", 0, count($last) - 1)];
            $st->execute([
                $fn,
                $ln,
                strtolower($fn . '.' . $ln . $id . '@demo.coldaisle.local'),
                sprintf('555-%04d', hInt($seed, "ph:$id", 1000, 9999)),
                $id,
            ]);
            $stats['rows_updated']++;
        }
        $stats['tables_touched']++;
        out('contacts: ' . count($rows));
    }

    if (tableExists($pdo, 'departments')) {
        $rows = $pdo->query('SELECT department_id FROM departments ORDER BY department_id')->fetchAll(PDO::FETCH_COLUMN);
        $names = ['Infrastructure', 'Platform', 'Network', 'Security', 'Facilities', 'Cloud Ops'];
        $i = 0;
        foreach ($rows as $id) {
            $name = $names[$i % count($names)];
            $pdo->exec(
                'UPDATE departments SET name = ' . sqlStr($name)
                . ', manager_name = NULL, contact_email = NULL, contact_phone = NULL, notes = NULL'
                . ' WHERE department_id = ' . (int)$id
            );
            $i++;
            $stats['rows_updated']++;
        }
        $stats['tables_touched']++;
        out('departments: ' . count($rows));
    }

    // --- 11) Users (local admin kept; everyone else demo local) ---
    if (tableExists($pdo, 'users')) {
        $hash = password_hash($adminPass, PASSWORD_DEFAULT);
        // Promote / reset admin
        $admin = $pdo->query(
            "SELECT user_id FROM users WHERE username = 'admin' OR user_id = 1 ORDER BY CASE WHEN username='admin' THEN 0 ELSE 1 END"
        )->fetch(PDO::FETCH_ASSOC);
        if ($admin) {
            $st = $pdo->prepare(
                "UPDATE users SET
                    username = 'admin',
                    email = 'admin@demo.coldaisle.local',
                    display_name = 'Demo Administrator',
                    password_hash = ?,
                    auth_source = 'local',
                    external_id = NULL,
                    is_active = 1,
                    must_change_password = 0
                 WHERE user_id = ?"
            );
            $st->execute([$hash, $admin['user_id']]);
            $stats['rows_updated']++;
        } else {
            // create admin if missing — need a role_id
            $roleId = (int)$pdo->query('SELECT TOP 1 role_id FROM roles ORDER BY role_id')->fetchColumn();
            $st = $pdo->prepare(
                "INSERT INTO users (username, email, display_name, password_hash, auth_source, role_id, is_active, must_change_password, created_at)
                 VALUES ('admin', 'admin@demo.coldaisle.local', 'Demo Administrator', ?, 'local', ?, 1, 0, SYSUTCDATETIME())"
            );
            $st->execute([$hash, $roleId]);
            $stats['rows_updated']++;
        }

        $others = $pdo->query(
            "SELECT user_id FROM users WHERE username <> 'admin' ORDER BY user_id"
        )->fetchAll(PDO::FETCH_COLUMN);
        $st = $pdo->prepare(
            "UPDATE users SET
                username = ?, email = ?, display_name = ?,
                password_hash = ?, auth_source = 'local', external_id = NULL,
                is_active = 0, must_change_password = 0
             WHERE user_id = ?"
        );
        $i = 1;
        foreach ($others as $uid) {
            $uname = sprintf('demo.user%02d', $i);
            $st->execute([
                $uname,
                $uname . '@demo.coldaisle.local',
                'Demo User ' . $i,
                $hash,
                $uid,
            ]);
            $i++;
            $stats['rows_updated']++;
        }
        $stats['tables_touched']++;
        out('users: admin reset + ' . count($others) . ' demo users (disabled)');
    }

    // --- 12) SNMP profiles / targets ---
    if (tableExists($pdo, 'snmp_v3_profiles')) {
        $n = $pdo->exec(
            "UPDATE snmp_v3_profiles SET
                name = 'Demo Profile',
                security_name = 'demo-snmp',
                context_name = NULL,
                notes = 'Snow-globe cleared'"
        );
        // clear sealed secret columns if present
        $cols = columns($pdo, 'snmp_v3_profiles');
        foreach (['auth_passphrase', 'priv_passphrase', 'auth_key', 'priv_key', 'password'] as $c) {
            if (hasCol($cols, $c)) {
                $pdo->exec("UPDATE snmp_v3_profiles SET {$c} = NULL");
            }
        }
        $stats['rows_updated'] += (int)$n;
        $stats['tables_touched']++;
        out('snmp_v3_profiles: cleared');
    }
    if (tableExists($pdo, 'snmp_targets')) {
        $rows = $pdo->query('SELECT target_id FROM snmp_targets ORDER BY target_id')->fetchAll(PDO::FETCH_COLUMN);
        $i = 1;
        foreach ($rows as $id) {
            $pdo->exec(
                'UPDATE snmp_targets SET name = ' . sqlStr(sprintf('Target-%02d', $i))
                . ', host = ' . sqlStr(demoIp($seed, 40000 + (int)$id, 84))
                . ', security_name = NULL, context_name = NULL'
                . ' WHERE target_id = ' . (int)$id
            );
            $i++;
            $stats['rows_updated']++;
        }
        $stats['tables_touched']++;
        out('snmp_targets: ' . count($rows));
    }

    // --- 13) Alert subscriptions / disposal vendors ---
    if (tableExists($pdo, 'alert_subscriptions')) {
        $n = $pdo->exec(
            "UPDATE alert_subscriptions SET
                name = 'Demo alerts',
                email_to = 'alerts@demo.coldaisle.local',
                notify_email = 0"
        );
        $stats['rows_updated'] += (int)$n;
        $stats['tables_touched']++;
        out('alert_subscriptions: scrubbed');
    }
    if (tableExists($pdo, 'disposal_vendors')) {
        $rows = $pdo->query('SELECT vendor_id FROM disposal_vendors ORDER BY vendor_id')->fetchAll(PDO::FETCH_COLUMN);
        $i = 1;
        foreach ($rows as $id) {
            $pdo->exec(
                'UPDATE disposal_vendors SET name = ' . sqlStr('ITAD Partner ' . $i)
                . ", contact_name = 'Demo Contact', contact_email = 'itad{$i}@demo.coldaisle.local',"
                . " contact_phone = '555-0199', address = NULL, notes = NULL"
                . ' WHERE vendor_id = ' . (int)$id
            );
            $i++;
            $stats['rows_updated']++;
        }
        $stats['tables_touched']++;
        out('disposal_vendors: ' . count($rows));
    }

    // --- 14) Free-text notes tables ---
    $noteClears = [
        'device_notes' => null, // truncate-ish
        'cables' => 'notes',
        'pdu_outlets' => 'notes',
        'pdu_breakers' => 'notes',
        'power_circuits' => 'notes',
        'disposals' => null,
        'work_orders' => 'notes',
        'work_order_items' => 'notes',
        'rack_requests' => 'notes',
        'asset_events' => 'notes',
        'audit_items' => 'notes',
        'cabinet_audits' => null,
    ];
    if (tableExists($pdo, 'device_notes')) {
        $n = $pdo->exec('DELETE FROM device_notes');
        $stats['rows_updated'] += (int)$n;
        out('device_notes: deleted ' . (int)$n);
    }
    if (tableExists($pdo, 'cables') && hasCol(columns($pdo, 'cables'), 'notes')) {
        $pdo->exec('UPDATE cables SET notes = NULL');
    }
    if (tableExists($pdo, 'disposals')) {
        $cols = columns($pdo, 'disposals');
        $sets = [];
        foreach (['notes', 'planning_notes', 'verification_notes', 'sanitize_details', 'sanitize_performed_by'] as $c) {
            if (hasCol($cols, $c)) {
                $sets[] = "{$c} = NULL";
            }
        }
        if ($sets) {
            $pdo->exec('UPDATE disposals SET ' . implode(', ', $sets));
        }
    }
    if (tableExists($pdo, 'work_orders')) {
        $cols = columns($pdo, 'work_orders');
        $sets = [];
        foreach (['itsm_request_id', 'itsm_display_id', 'itsm_url', 'itsm_last_error', 'change_ticket'] as $c) {
            if (hasCol($cols, $c)) {
                $sets[] = "{$c} = NULL";
            }
        }
        if ($sets) {
            $pdo->exec('UPDATE work_orders SET ' . implode(', ', $sets));
            out('work_orders: ITSM / ticket fields cleared');
        }
    }

    if (tableExists($pdo, 'device_ports') && hasCol(columns($pdo, 'device_ports'), 'mac_address')) {
        // Scrub port MACs deterministically
        $ports = $pdo->query(
            'SELECT port_id FROM device_ports WHERE mac_address IS NOT NULL AND LTRIM(RTRIM(mac_address)) <> \'\''
        )->fetchAll(PDO::FETCH_COLUMN);
        $st = $pdo->prepare('UPDATE device_ports SET mac_address = ? WHERE port_id = ?');
        foreach ($ports as $pid) {
            $st->execute([demoMac($seed, 50000 + (int)$pid), $pid]);
            $stats['rows_updated']++;
        }
        out('device_ports macs: ' . count($ports));
    }

    // --- 14b) IPAM prefixes / host records / aligned groups ---
    $ipam = anonymizeIpam($pdo, $seed);
    $stats['rows_updated'] += $ipam['prefixes'] + $ipam['addresses'] + $ipam['groups'];
    $stats['tables_touched']++;

    // --- 15) Sessions / tokens / audit (PII + real usernames) ---
    foreach (['auth_sessions', 'password_reset_tokens', 'notifications'] as $t) {
        if (tableExists($pdo, $t)) {
            $n = $pdo->exec("DELETE FROM {$t}");
            out("{$t}: cleared " . (int)$n);
        }
    }
    if (tableExists($pdo, 'audit_log')) {
        // Keep structure but wipe identifying content — or truncate for clean demo
        $n = $pdo->exec('DELETE FROM audit_log');
        out('audit_log: cleared ' . (int)$n);
    }

    // --- 16) Settings table (identity + demo flags) ---
    if (tableExists($pdo, 'settings')) {
        $pairs = [
            'org_name' => $orgName,
            'app_name' => 'ColdAisle',
            'alerts_default_email' => 'alerts@demo.coldaisle.local',
            'alerts_email_enabled' => '0',
            'env_alerts_email' => '',
            'power_alerts_email' => '',
            'donation_paypal_url' => '',
            'donation_show_footer' => '0',
            'auth_ldaps_enabled' => '0',
            'auth_entra_enabled' => '0',
            'auth_local_enabled' => '1',
            'mail_enabled' => '0',
            'testing_mode' => '1',
        ];
        $stats['rows_updated'] += upsertSettings($pdo, $pairs);
        // Remove icmp alert state keys that embed real device ids only (optional keep)
        $pdo->exec("DELETE FROM settings WHERE setting_key LIKE 'icmp_alert_%'");
        $pdo->exec("DELETE FROM settings WHERE setting_key LIKE 'sdp_%'");
        $pdo->exec("DELETE FROM settings WHERE setting_key LIKE 'itsm_%' OR setting_key LIKE 'snow_%' OR setting_key LIKE 'zd_%' OR setting_key LIKE 'jira_%' OR setting_key LIKE 'fs_%'");
        if (tableExists($pdo, 'api_tokens')) {
            $n = $pdo->exec('DELETE FROM api_tokens');
            out('api_tokens: cleared ' . (int)$n);
        }
        $stats['tables_touched']++;
        out('settings: org/demo flags applied (SDP tokens cleared)');
    }

    // --- 17) Disable SNMP completely + freeze chart history ---
    disableSnmpPolling($pdo);
    if (!$skipFreeze) {
        $freeze = freezeHistoryForCharts($pdo);
        $stats['history_shift_sec'] = $freeze['delta_sec'];
    } else {
        out('Skipping history freeze (--skip-freeze).');
    }

    $pdo->commit();
    out('');
    out('DB commit OK.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'FAILED: ' . $e->getMessage() . PHP_EOL);
    fwrite(STDERR, $e->getFile() . ':' . $e->getLine() . PHP_EOL);
    exit(1);
}

// --- 18) Rewrite config.php (outside DB txn) ---
try {
    $cfgPath = $root . '/config/config.php';
    if (is_file($cfgPath)) {
        /** @var array<string,mixed> $cfg */
        $cfg = require $cfgPath;
        $cfg['org_name'] = $orgName;
        $cfg['base_url'] = $cfg['base_url'] ?? '';
        if (!isset($cfg['security']) || !is_array($cfg['security'])) {
            $cfg['security'] = [];
        }
        $cfg['security']['force_https'] = false;
        $cfg['security']['hsts'] = false;
        $cfg['security']['cookie_secure'] = 'auto';

        if (!isset($cfg['auth']) || !is_array($cfg['auth'])) {
            $cfg['auth'] = [];
        }
        $cfg['auth']['local'] = ['enabled' => true];
        $cfg['auth']['ldaps'] = [
            'enabled' => false,
            'host' => '',
            'port' => 636,
            'base_dn' => '',
            'user_filter' => '(sAMAccountName={username})',
            'bind_dn' => '',
            'bind_password' => '',
            'use_ssl' => true,
            'start_tls' => false,
            'tls_insecure' => false,
            'default_role_id' => 4,
            'require_security_group' => false,
        ];
        $cfg['auth']['entra'] = [
            'enabled' => false,
            'tenant_id' => '',
            'client_id' => '',
            'client_secret' => '',
            'redirect_uri' => '',
            'scopes' => 'openid profile email offline_access',
            'default_role_id' => null,
        ];
        if (!isset($cfg['mail']) || !is_array($cfg['mail'])) {
            $cfg['mail'] = [];
        }
        $cfg['mail']['enabled'] = false;
        $cfg['mail']['host'] = '';
        $cfg['mail']['username'] = '';
        $cfg['mail']['password'] = '';
        $cfg['mail']['from_email'] = 'noreply@demo.coldaisle.local';
        $cfg['mail']['from_name'] = 'ColdAisle Demo';
        $cfg['snow_globe_at'] = date('c');
        $cfg['snow_globe_seed'] = $seed;

        $export = var_export($cfg, true);
        $php = "<?php\n/** ColdAisle configuration — snow-globe demo identity */\ndeclare(strict_types=1);\n\nreturn {$export};\n";
        if (file_put_contents($cfgPath, $php) === false) {
            throw new RuntimeException('Could not write config.php');
        }
        $stats['config_rewritten'] = true;
        out('config.php: org/auth/mail scrubbed, force_https off');
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Config rewrite warning: ' . $e->getMessage() . PHP_EOL);
}

// Marker file
@file_put_contents(
    $root . '/storage/tmp/snow_globe_done.txt',
    date('c') . " seed={$seed} org={$orgName}\n"
);

out('');
out('=== Snow Globe complete ===');
out('Tables touched:  ' . $stats['tables_touched']);
out('Row ops (~):     ' . $stats['rows_updated']);
out('Config rewritten:' . ($stats['config_rewritten'] ? ' yes' : ' no'));
out('');
out('Demo login:');
out('  URL:      http://localhost/login.php');
out('  Username: admin');
out('  Password: ' . $adminPass);
out('');
out('Topology (racks, U positions, cabling geometry) preserved; identity is fictional.');
out('SNMP scheduler + per-device auto-poll are OFF; history samples kept/shifted for line charts.');
out('Do not point this instance at production networks.');
