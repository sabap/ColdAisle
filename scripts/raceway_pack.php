<?php
/**
 * Pack / unpack cable_paths (raceway centerlines) as JSON for lab → production.
 *
 * Does not copy cabinets, cables, or other inventory. Geometry is waypoints +
 * kind/elev/width. Matching default is path_id (same IDs as the source snapshot)
 * so deleted production rows can be re-inserted and cable hops may reconnect.
 *
 *   php scripts/raceway_pack.php export --root=C:\inetpub\wwwroot\WinDCIM --file=C:\temp\raceway_pack.json
 *   php scripts/raceway_pack.php import --file=C:\temp\raceway_pack.json --dry-run
 *   php scripts/raceway_pack.php import --file=C:\temp\raceway_pack.json
 *
 * Optional:
 *   --room-id=N     Force every path into this destination room_id
 *   --skip-codes    Update geometry only; leave name/path_code on existing rows
 *   --match=code    Match dest rows by room + path_code instead of path_id
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

/** Manual argv parse — PHP getopt breaks on Windows --file=C:\path (colon). */
$argvRest = $argv ?? [];
array_shift($argvRest);
$cli = [
    'action' => 'help',
    'root' => '',
    'file' => '',
    'dry-run' => false,
    'skip-codes' => false,
    'help' => false,
    'room-id' => '',
    'match' => 'id',
];
$nArg = count($argvRest);
for ($i = 0; $i < $nArg; $i++) {
    $a = (string)$argvRest[$i];
    if ($a === '--help' || $a === '-h') {
        $cli['help'] = true;
        continue;
    }
    if ($a === '--dry-run') {
        $cli['dry-run'] = true;
        continue;
    }
    if ($a === '--skip-codes') {
        $cli['skip-codes'] = true;
        continue;
    }
    foreach (['root', 'file', 'room-id', 'match'] as $name) {
        $long = '--' . $name;
        if ($a === $long) {
            $cli[$name] = (string)($argvRest[$i + 1] ?? '');
            $i++;
            continue 2;
        }
        $pref = $long . '=';
        if (str_starts_with($a, $pref)) {
            $cli[$name] = substr($a, strlen($pref));
            continue 2;
        }
    }
    if ($a !== '' && !str_starts_with($a, '-')) {
        $cli['action'] = strtolower($a);
    }
}

$action = $cli['action'];
if ($cli['help'] || $action === 'help' || !in_array($action, ['export', 'import'], true)) {
    echo "Raceway pack: export/import cable_paths JSON (centerlines only).\n";
    echo "  export --root PATH --file FILE.json\n";
    echo "  import --root PATH --file FILE.json [--dry-run] [--room-id N] [--skip-codes] [--match id|code]\n";
    echo "Use a space after --root / --file so Windows paths with C:\\ work.\n";
    exit($cli['help'] ? 0 : 1);
}

$root = $cli['root'] !== '' ? $cli['root'] : dirname(__DIR__);
$root = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $root), DIRECTORY_SEPARATOR);
$file = $cli['file'] !== ''
    ? $cli['file']
    : ($root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'raceway_pack.json');
$dry = $cli['dry-run'];
$skipCodes = $cli['skip-codes'];
$match = strtolower(trim($cli['match']));
if ($match !== 'code') {
    $match = 'id';
}
$forceRoom = $cli['room-id'] !== '' ? (int)$cli['room-id'] : 0;

if (!is_file($root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'App.php')) {
    fwrite(STDERR, "App.php not found under {$root}\n");
    exit(1);
}

require $root . '/src/App.php';
App::boot(['light' => true]);
if (!App::isInstalled()) {
    fwrite(STDERR, "Site is not installed (no config) under {$root}\n");
    exit(1);
}

function rwOut(string $msg): void
{
    echo $msg . PHP_EOL;
}

function rwSnowglobedCode(?string $code): bool
{
    $c = strtoupper(trim((string)$code));
    return (bool)preg_match('/^(LAD|UCH|TRH|CND|PTH)-\d+$/', $c);
}

/** @return list<string> */
function rwPathColumns(): array
{
    $prefer = [
        'path_id', 'room_id', 'name', 'path_type', 'waypoints', 'color_hex', 'notes',
        'media_class', 'path_kind', 'feed_to', 'width_m', 'elevation_m', 'is_active',
        'path_code', 'segment_class',
    ];
    try {
        $rows = Database::fetchAll(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'cable_paths'"
        );
        $have = [];
        foreach ($rows as $r) {
            $have[strtolower((string)$r['COLUMN_NAME'])] = (string)$r['COLUMN_NAME'];
        }
        $out = [];
        foreach ($prefer as $c) {
            if (isset($have[strtolower($c)])) {
                $out[] = $have[strtolower($c)];
            }
        }
        return $out;
    } catch (Throwable $e) {
        return $prefer;
    }
}

if ($action === 'export') {
    $cols = rwPathColumns();
    $select = implode(', ', array_map(static fn($c) => 'cp.[' . str_replace(']', ']]', $c) . ']', $cols));
    $sql = "SELECT {$select}, r.name AS _room_name, r.width_m AS _room_width_m, r.depth_m AS _room_depth_m,
            dc.name AS _dc_name
            FROM cable_paths cp
            LEFT JOIN rooms r ON r.room_id = cp.room_id
            LEFT JOIN datacenters dc ON dc.datacenter_id = r.datacenter_id
            ORDER BY cp.room_id, cp.path_id";
    $rows = Database::fetchAll($sql);
    $paths = [];
    $snow = 0;
    foreach ($rows as $row) {
        $path = [];
        foreach ($cols as $c) {
            $path[$c] = $row[$c] ?? null;
        }
        $path['_room_name'] = $row['_room_name'] ?? null;
        $path['_room_width_m'] = $row['_room_width_m'] ?? null;
        $path['_room_depth_m'] = $row['_room_depth_m'] ?? null;
        $path['_dc_name'] = $row['_dc_name'] ?? null;
        $pts = [];
        if (class_exists('CablePlantService')) {
            $pts = CablePlantService::parseWaypoints($path['waypoints'] ?? null);
        }
        $path['_point_count'] = count($pts);
        if (rwSnowglobedCode((string)($path['path_code'] ?? $path['name'] ?? ''))) {
            $snow++;
        }
        $paths[] = $path;
    }
    $pack = [
        'format' => 'coldaisle-raceway-pack',
        'format_version' => 1,
        'exported_at' => date('c'),
        'source_root' => $root,
        'source_version' => class_exists('App') ? App::VERSION : '',
        'codes_look_snowglobed' => $snow > 0,
        'snowglobed_code_count' => $snow,
        'path_count' => count($paths),
        'note' => $snow > 0
            ? 'path_code/name look Snow Globe renamed (LAD-/UCH-). Waypoints/path_id are still the production snapshot. Rename codes after import if needed.'
            : 'path_code values are as stored on the source site.',
        'paths' => $paths,
    ];
    $dir = dirname($file);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        fwrite(STDERR, "Cannot write directory {$dir}\n");
        exit(1);
    }
    $json = json_encode($pack, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false || file_put_contents($file, $json) === false) {
        fwrite(STDERR, "Failed to write {$file}\n");
        exit(1);
    }
    rwOut('Wrote ' . count($paths) . ' raceway(s) to ' . $file);
    if ($snow > 0) {
        rwOut("Note: {$snow} path_code(s) look Snow Globe renamed (LAD-/UCH-). Geometry and path_id are still usable.");
    }
    exit(0);
}

// --- import ---
if (!is_file($file)) {
    fwrite(STDERR, "Pack file not found: {$file}\n");
    exit(1);
}
$raw = file_get_contents($file);
$pack = json_decode((string)$raw, true);
if (!is_array($pack) || ($pack['format'] ?? '') !== 'coldaisle-raceway-pack') {
    fwrite(STDERR, "Not a coldaisle-raceway-pack JSON file.\n");
    exit(1);
}
$paths = $pack['paths'] ?? [];
if (!is_array($paths) || $paths === []) {
    fwrite(STDERR, "Pack has no paths.\n");
    exit(1);
}

$cols = rwPathColumns();
$colSet = array_fill_keys(array_map('strtolower', $cols), true);
$geomKeys = ['waypoints', 'path_kind', 'path_type', 'feed_to', 'media_class', 'width_m', 'elevation_m', 'color_hex', 'segment_class', 'is_active'];

$destRooms = [];
try {
    foreach (Database::fetchAll('SELECT room_id, name FROM rooms') as $r) {
        $destRooms[(int)$r['room_id']] = (string)$r['name'];
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Cannot read rooms: ' . $e->getMessage() . "\n");
    exit(1);
}

$existingById = [];
$existingByCode = [];
foreach (Database::fetchAll('SELECT * FROM cable_paths') as $row) {
    $id = (int)($row['path_id'] ?? 0);
    $existingById[$id] = $row;
    $rid = (int)($row['room_id'] ?? 0);
    $code = strtoupper(trim((string)($row['path_code'] ?? $row['name'] ?? '')));
    if ($code !== '') {
        $existingByCode[$rid . '|' . $code] = $row;
    }
}

$planInsert = 0;
$planUpdate = 0;
$errors = [];
$ops = [];

foreach ($paths as $i => $src) {
    if (!is_array($src)) {
        continue;
    }
    $srcId = (int)($src['path_id'] ?? 0);
    $srcRoom = $forceRoom > 0 ? $forceRoom : (int)($src['room_id'] ?? 0);
    $srcCode = strtoupper(trim((string)($src['path_code'] ?? $src['name'] ?? '')));
    $label = ($src['path_code'] ?? $src['name'] ?? ('#' . $srcId)) . ' (id ' . $srcId . ')';

    if ($srcRoom > 0 && !isset($destRooms[$srcRoom])) {
        $errors[] = "{$label}: destination has no room_id {$srcRoom}. Pass --room-id=N for the live hall.";
        continue;
    }

    $dest = null;
    if ($match === 'code' && $srcCode !== '') {
        $dest = $existingByCode[$srcRoom . '|' . $srcCode] ?? null;
    } elseif ($srcId > 0) {
        $dest = $existingById[$srcId] ?? null;
    }

    $fields = [];
    foreach ($geomKeys as $k) {
        if (!isset($colSet[strtolower($k)])) {
            continue;
        }
        if (array_key_exists($k, $src)) {
            $fields[$k] = $src[$k];
        }
    }
    if (!$skipCodes) {
        foreach (['name', 'path_code', 'notes'] as $k) {
            if (isset($colSet[strtolower($k)]) && array_key_exists($k, $src)) {
                $fields[$k] = $src[$k];
            }
        }
    }
    if (isset($colSet['room_id'])) {
        $fields['room_id'] = $srcRoom > 0 ? $srcRoom : null;
    }

    if ($dest) {
        $planUpdate++;
        $ops[] = ['op' => 'update', 'path_id' => (int)$dest['path_id'], 'label' => $label, 'fields' => $fields];
    } else {
        if ($srcId < 1) {
            $errors[] = "{$label}: no path_id and no matching destination row.";
            continue;
        }
        $planInsert++;
        $ops[] = ['op' => 'insert', 'path_id' => $srcId, 'label' => $label, 'fields' => $fields];
    }
}

rwOut(($dry ? '[dry-run] ' : '') . 'Pack: ' . count($paths) . ' path(s). Update ' . $planUpdate . ', insert ' . $planInsert . '.');
if (!empty($pack['codes_look_snowglobed'])) {
    rwOut('Warning: pack codes look Snow Globe renamed (LAD-/UCH-). Geometry is still the original centerlines.');
}
if ($forceRoom > 0) {
    rwOut('Forcing room_id=' . $forceRoom . ' (' . ($destRooms[$forceRoom] ?? '?') . ')');
}
foreach ($errors as $e) {
    rwOut('ERROR: ' . $e);
}
foreach ($ops as $op) {
    rwOut(sprintf('  %s path_id=%d %s', $op['op'], $op['path_id'], $op['label']));
}

if ($errors) {
    fwrite(STDERR, "Fix the errors above (usually --room-id) and re-run.\n");
    exit(1);
}
if ($dry) {
    rwOut('Dry-run only — database unchanged.');
    exit(0);
}

$pdo = Database::connection();
$updated = 0;
$inserted = 0;
try {
    $pdo->beginTransaction();
    foreach ($ops as $op) {
        $fields = $op['fields'];
        if ($op['op'] === 'update') {
            Database::update('cable_paths', $fields, 'path_id = :id', [':id' => $op['path_id']]);
            $updated++;
            continue;
        }
        $fields['path_id'] = $op['path_id'];
        $names = array_keys($fields);
        $placeholders = implode(', ', array_fill(0, count($names), '?'));
        $colSql = implode(', ', array_map(static fn($c) => '[' . str_replace(']', ']]', $c) . ']', $names));
        $pdo->exec('SET IDENTITY_INSERT cable_paths ON');
        Database::query(
            'INSERT INTO cable_paths (' . $colSql . ') VALUES (' . $placeholders . ')',
            array_values($fields)
        );
        $pdo->exec('SET IDENTITY_INSERT cable_paths OFF');
        $inserted++;
    }
    $pdo->commit();
} catch (Throwable $e) {
    try {
        $pdo->exec('SET IDENTITY_INSERT cable_paths OFF');
    } catch (Throwable $e2) {
    }
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Import failed: ' . $e->getMessage() . "\n");
    exit(1);
}

rwOut("Imported: updated {$updated}, inserted {$inserted}.");
rwOut('Reload Floor planner. If codes are LAD-/UCH-, rename them on Cabling / raceway props.');
exit(0);
