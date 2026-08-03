<?php
/**
 * Env sensor history series for charts.
 * GET: sensor_id, hours (default 24, max 168)
 */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$user = AuthManager::user();
if (!AuthManager::can($user, 'view_cooling')) {
    App::json(['error' => 'Forbidden'], 403);
}

$sensorId = (int)($_GET['sensor_id'] ?? $_GET['id'] ?? 0);
$hours = (int)($_GET['hours'] ?? 24);
if ($hours < 1) {
    $hours = 24;
}
if ($hours > 168) {
    $hours = 168;
}
if ($sensorId < 1) {
    App::json(['error' => 'sensor_id required'], 400);
}

$sensor = Database::fetchOne(
    'SELECT sensor_id, name, sensor_kind, unit, last_value, last_humidity, is_active
     FROM env_sensors WHERE sensor_id = ?',
    [$sensorId]
);
if (!$sensor || empty($sensor['is_active'])) {
    App::json(['error' => 'Sensor not found'], 404);
}

$since = date('Y-m-d H:i:s', time() - $hours * 3600);
$kind = (string)($sensor['sensor_kind'] ?? 'temperature');

try {
    $rows = Database::fetchAll(
        'SELECT value, recorded_at, metric
         FROM env_readings
         WHERE sensor_id = ? AND recorded_at >= ?
         ORDER BY recorded_at ASC',
        [$sensorId, $since]
    );
} catch (Throwable $e) {
    // metric column optional
    try {
        $rows = Database::fetchAll(
            'SELECT value, recorded_at
             FROM env_readings
             WHERE sensor_id = ? AND recorded_at >= ?
             ORDER BY recorded_at ASC',
            [$sensorId, $since]
        );
        foreach ($rows as &$row) {
            $row['metric'] = null;
        }
        unset($row);
    } catch (Throwable $e2) {
        $rows = [];
    }
}

$t = [];
$temp = [];
$humidity = [];

foreach ($rows as $row) {
    $at = (string)($row['recorded_at'] ?? '');
    if ($at === '') {
        continue;
    }
    $metric = strtolower(trim((string)($row['metric'] ?? '')));
    $val = is_numeric($row['value'] ?? null) ? (float)$row['value'] : null;
    if ($val === null) {
        continue;
    }

    // Bucket by minute for chart alignment
    $key = substr(str_replace('T', ' ', $at), 0, 16); // YYYY-MM-DD HH:MM
    if (!isset($t[$key])) {
        $t[$key] = $at;
        $temp[$key] = null;
        $humidity[$key] = null;
    }

    if ($metric === 'humidity' || ($kind === 'humidity' && $metric === '')) {
        $humidity[$key] = $val;
    } elseif ($metric === 'temperature' || $metric === 'primary' || $metric === '') {
        // Primary series
        if ($kind === 'humidity') {
            $humidity[$key] = $val;
        } else {
            $temp[$key] = $val;
        }
    } else {
        $temp[$key] = $val;
    }
}

// Sort keys chronologically; convert temp series to site display unit
$keys = array_keys($t);
sort($keys);
$tOut = [];
$tempOut = [];
$humOut = [];
$convertTemp = class_exists('TempUnitService') && TempUnitService::isTempKind($kind) && $kind !== 'humidity';
foreach ($keys as $k) {
    $tOut[] = $t[$k];
    $tv = $temp[$k];
    if ($convertTemp && $tv !== null && is_numeric($tv)) {
        $tv = TempUnitService::fromC((float)$tv);
    }
    $tempOut[] = $tv;
    $humOut[] = $humidity[$k];
}

// Include latest point from sensor row if series empty but last_value set
if ($tOut === [] && $sensor['last_value'] !== null && $sensor['last_value'] !== '') {
    $tOut[] = (string)($sensor['last_seen_at'] ?? date('Y-m-d H:i:s'));
    if ($kind === 'humidity') {
        $humOut[] = (float)$sensor['last_value'];
        $tempOut[] = null;
    } else {
        $lv = (float)$sensor['last_value'];
        if ($convertTemp) {
            $lv = TempUnitService::fromC($lv) ?? $lv;
        }
        $tempOut[] = $lv;
        $hum = $sensor['last_humidity'] ?? null;
        $humOut[] = ($hum !== null && $hum !== '') ? (float)$hum : null;
    }
}

$dispUnit = (string)($sensor['unit'] ?? '°C');
if (class_exists('TempUnitService') && TempUnitService::isTempKind($kind) && $kind !== 'humidity') {
    $dispUnit = TempUnitService::symbol();
} elseif ($kind === 'humidity') {
    $dispUnit = '%RH';
}

App::json([
    'ok' => true,
    'sensor_id' => $sensorId,
    'name' => (string)$sensor['name'],
    'sensor_kind' => $kind,
    'unit' => $dispUnit,
    'temp_unit' => class_exists('TempUnitService') ? TempUnitService::siteUnit() : 'C',
    'hours' => $hours,
    't' => $tOut,
    'temp' => $tempOut,
    'humidity' => $humOut,
    'points' => count($tOut),
]);
