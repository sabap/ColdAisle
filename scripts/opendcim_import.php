<?php
/**
 * ColdAisle — OpenDCIM import CLI.
 *
 * Usage:
 *   php scripts/opendcim_import.php --test
 *   php scripts/opendcim_import.php --summary
 *   php scripts/opendcim_import.php --plan
 *   php scripts/opendcim_import.php --import              # Mode A dry-run (default)
 *   php scripts/opendcim_import.php --import --execute    # Mode A writes
 *
 * Connection:
 *   --url= --user= --key= --resolve=host:ip --insecure
 *   env OPENDCIM_* or config.php 'opendcim'
 *
 * Mode A: merge into existing DC by name; never overwrite cabinet floor positions.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/src/App.php';
require_once $root . '/src/Services/OpenDcimClient.php';
require_once $root . '/src/Services/OpenDcimImportService.php';
require_once $root . '/includes/power_helpers.php';

/** @var array<string,mixed> $args */
$args = [];
foreach (array_slice($argv, 1) as $a) {
    if (in_array($a, ['--help', '-h'], true)) {
        $args['help'] = true;
        continue;
    }
    if ($a === '--test') {
        $args['test'] = true;
        continue;
    }
    if ($a === '--summary') {
        $args['summary'] = true;
        continue;
    }
    if ($a === '--plan') {
        $args['plan'] = true;
        continue;
    }
    if ($a === '--import') {
        $args['import'] = true;
        continue;
    }
    if ($a === '--execute') {
        $args['execute'] = true;
        continue;
    }
    if ($a === '--insecure') {
        $args['insecure'] = true;
        continue;
    }
    if ($a === '--include-disposed') {
        $args['include_disposed'] = true;
        continue;
    }
    if ($a === '--no-ports') {
        $args['no_ports'] = true;
        continue;
    }
    if ($a === '--no-power') {
        $args['no_power'] = true;
        continue;
    }
    if ($a === '--no-audits') {
        $args['no_audits'] = true;
        continue;
    }
    if ($a === '--no-images') {
        $args['no_images'] = true;
        continue;
    }
    if (str_starts_with($a, '--cache-dir=')) {
        $args['cache_dir'] = substr($a, 12);
        continue;
    }
    if ($a === '--offline') {
        // Default probe dump location in this repo
        $args['cache_dir'] = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'opendcim_probe';
        continue;
    }
    if (str_starts_with($a, '--url=')) {
        $args['url'] = substr($a, 6);
        continue;
    }
    if (str_starts_with($a, '--user=')) {
        $args['user'] = substr($a, 7);
        continue;
    }
    if (str_starts_with($a, '--key=')) {
        $args['key'] = substr($a, 6);
        continue;
    }
    if (str_starts_with($a, '--resolve=')) {
        $args['resolve'] = substr($a, 10);
        continue;
    }
    if (str_starts_with($a, '--mode=')) {
        $args['mode'] = strtoupper(substr($a, 7));
        continue;
    }
    if (str_starts_with($a, '--target-dc=')) {
        $args['target_dc'] = (int)substr($a, 12);
        continue;
    }
    if (str_starts_with($a, '--dc=')) {
        $args['dc'] = substr($a, 5);
        continue;
    }
    fwrite(STDERR, "Unknown arg: {$a}\n");
    exit(2);
}

$hasCmd = !empty($args['test']) || !empty($args['summary']) || !empty($args['plan']) || !empty($args['import']);
if (!empty($args['help']) || !$hasCmd) {
    echo <<<HELP
ColdAisle OpenDCIM import — Mode A (preserve floor plan)

Discovery:
  php scripts/opendcim_import.php --test
  php scripts/opendcim_import.php --summary
  php scripts/opendcim_import.php --plan

Import (Mode A):
  php scripts/opendcim_import.php --import                 # dry-run (no writes)
  php scripts/opendcim_import.php --import --execute       # write to local DB
  php scripts/opendcim_import.php --import --execute --target-dc=1
  php scripts/opendcim_import.php --import --execute --dc=1

Offline (no DC network — use JSON dumps from a prior probe/export):
  php scripts/opendcim_import.php --offline --test
  php scripts/opendcim_import.php --offline --summary
  php scripts/opendcim_import.php --offline --import --target-dc=1
  php scripts/opendcim_import.php --cache-dir=C:\\path\\to\\dumps --import

  Dump files expected in cache dir (from an online machine):
    api_v1_datacenter.json, api_v1_cabinet.json, api_v1_device.json,
    api_v1_devicetemplate.json, api_v1_department.json, api_v1_people.json
  Optional per-device: api_v1_powerport_{id}.json, api_v1_deviceport_{id}.json
  (without those, devices/PDUs still import; outlet labels/maps are sparse)

Connection (online):
  --url=https://dcim.example.org
  --user=dcim
  --key=YOUR_API_KEY
  --resolve=dcim.example.org:192.0.2.10
  --insecure
  Env: OPENDCIM_URL OPENDCIM_USER OPENDCIM_KEY OPENDCIM_RESOLVE OPENDCIM_INSECURE=1
  Or config.php 'opendcim' block

Options:
  --mode=A                   Only Mode A writes are implemented
  --target-dc=ID             Merge into this local datacenter_id
  --dc=1,35                  Only these OpenDCIM DataCenterIDs
  --include-disposed         Include disposed devices
  --no-ports                 Skip deviceport import
  --no-power                 Skip CDU/PDU + power mapping
  --offline                  Use storage/tmp/opendcim_probe dumps
  --cache-dir=PATH           Custom offline dump directory

Mode A never overwrites existing cabinet pos_x/pos_y/rotation/front_facing.

Where to run --execute:
  On a host that has ColdAisle SQL + (online OpenDCIM OR offline dumps).
  Lab PC with no DC route: use --offline dumps + local SQL only.

HELP;
    exit(empty($args['help']) ? 2 : 0);
}

try {
    App::boot();
} catch (Throwable $e) {
    fwrite(STDERR, 'App boot: ' . $e->getMessage() . "\n");
    if (!empty($args['import']) && !empty($args['execute'])) {
        fwrite(STDERR, "Cannot execute import without database.\n");
        exit(1);
    }
}

$cfg = buildOpenDcimConfig($args, class_exists('App') ? App::config() : []);
try {
    $client = new OpenDcimClient($cfg);
} catch (Throwable $e) {
    fwrite(STDERR, 'Config error: ' . $e->getMessage() . "\n");
    exit(1);
}

$importOpts = [
    'mode' => $args['mode'] ?? OpenDcimImportService::MODE_MERGE,
    'dry_run' => empty($args['execute']),
    'include_disposed' => !empty($args['include_disposed']),
    'include_ports' => empty($args['no_ports']),
    'include_power' => empty($args['no_power']),
    'include_audits' => empty($args['no_audits']),
    'include_images' => empty($args['no_images']),
    'target_datacenter_id' => $args['target_dc'] ?? null,
    'progress' => static function (string $msg): void {
        fwrite(STDERR, $msg . "\n");
    },
];
if (!empty($args['dc'])) {
    $importOpts['datacenter_ids'] = array_map('trim', explode(',', (string)$args['dc']));
}

$svc = new OpenDcimImportService($client, $importOpts);
$outDir = $root . '/storage/tmp/opendcim_import';
if (!is_dir($outDir)) {
    @mkdir($outDir, 0775, true);
}

try {
    if (!empty($args['test'])) {
        $r = $client->testConnection();
        echo "Base URL: {$r['base_url']}\n";
        echo 'OK: ' . ($r['ok'] ? 'yes' : 'no') . "\n";
        echo "Counts:\n";
        foreach ($r['counts'] as $k => $n) {
            echo sprintf("  %-16s %s\n", $k, $n < 0 ? 'ERROR' : $n);
        }
        if ($r['errors']) {
            echo "Errors:\n";
            foreach ($r['errors'] as $e) {
                echo "  - {$e}\n";
            }
        }
        exit($r['ok'] ? 0 : 1);
    }

    if (!empty($args['summary'])) {
        $sum = $svc->fetchSummary();
        $file = $outDir . '/summary_' . date('Ymd_His') . '.json';
        file_put_contents($file, json_encode($sum, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        echo json_encode($sum, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        echo "Wrote {$file}\n";
        exit(0);
    }

    if (!empty($args['plan'])) {
        $plan = $svc->planModeA();
        $file = $outDir . '/plan_mode_a_' . date('Ymd_His') . '.json';
        file_put_contents($file, json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        echo json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        echo "Wrote {$file}\n";
        exit(0);
    }

    if (!empty($args['import'])) {
        if (($importOpts['mode'] ?? 'A') !== OpenDcimImportService::MODE_MERGE
            && strtoupper((string)($importOpts['mode'] ?? 'A')) !== 'A') {
            fwrite(STDERR, "Only Mode A is implemented for --import.\n");
            exit(2);
        }
        $result = $svc->runModeA();
        $file = $outDir . '/import_mode_a_' . ($result['dry_run'] ? 'dryrun_' : 'exec_') . date('Ymd_His') . '.json';
        file_put_contents($file, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        echo "\n--- Stats ---\n";
        foreach ($result['stats'] as $k => $n) {
            echo sprintf("  %-28s %d\n", $k, $n);
        }
        if ($result['errors']) {
            echo "\n--- Errors ---\n";
            foreach ($result['errors'] as $e) {
                echo "  - {$e}\n";
            }
        }
        echo "\nWrote {$file}\n";
        echo $result['dry_run']
            ? "(dry-run — pass --execute to write)\n"
            : "(executed — Mode A writes applied)\n";
        exit($result['ok'] ? 0 : 1);
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}

/**
 * @param array<string,mixed> $args
 * @param array<string,mixed> $appConfig
 * @return array<string,mixed>
 */
function buildOpenDcimConfig(array $args, array $appConfig): array
{
    $od = is_array($appConfig['opendcim'] ?? null) ? $appConfig['opendcim'] : [];

    $cacheDir = trim((string)($args['cache_dir'] ?? getenv('OPENDCIM_CACHE_DIR') ?: ($od['cache_dir'] ?? '')));
    if ($cacheDir !== '') {
        return [
            'cache_dir' => $cacheDir,
            'user_id' => 'cache',
            'api_key' => 'cache',
        ];
    }

    $url = (string)($args['url'] ?? getenv('OPENDCIM_URL') ?: ($od['base_url'] ?? ''));
    $user = (string)($args['user'] ?? getenv('OPENDCIM_USER') ?: ($od['user_id'] ?? ''));
    $key = (string)($args['key'] ?? getenv('OPENDCIM_KEY') ?: ($od['api_key'] ?? ''));

    $insecure = !empty($args['insecure'])
        || getenv('OPENDCIM_INSECURE') === '1'
        || (array_key_exists('tls_verify', $od) && empty($od['tls_verify']));

    $resolve = [];
    if (is_array($od['resolve'] ?? null)) {
        foreach ($od['resolve'] as $h => $ip) {
            $resolve[(string)$h] = (string)$ip;
        }
    }
    $resolveStr = (string)($args['resolve'] ?? getenv('OPENDCIM_RESOLVE') ?: '');
    if ($resolveStr !== '' && preg_match('/^(.+):(\d{1,3}(?:\.\d{1,3}){3})$/', $resolveStr, $m)) {
        $resolve[$m[1]] = $m[2];
    }

    return [
        'base_url' => $url,
        'user_id' => $user,
        'api_key' => $key,
        'tls_verify' => !$insecure,
        'timeout' => (int)($od['timeout'] ?? 120),
        'resolve' => $resolve,
    ];
}
