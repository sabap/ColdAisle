<?php
/**
 * Power history time-series for charts and CSV export.
 *
 * GET params:
 *   scope=site|zone|pdu
 *   id= (zone_id or pdu_id)
 *   hours=24 (rolling) OR from=Y-m-d & to=Y-m-d
 *   format=json|csv
 *   preset=week|month|year (optional convenience)
 */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

// Charts: view_power; reports catalog also allows view_reports
$user = App::requireAuth();
if (!AuthManager::can($user, 'view_power') && !AuthManager::can($user, 'view_reports')) {
    App::json(['error' => 'Permission denied'], 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    App::json(['error' => 'GET only'], 405);
}

if (!class_exists('PowerHistoryService')) {
    App::json(['error' => 'PowerHistoryService not available'], 503);
}

$scope = strtolower(trim((string)($_GET['scope'] ?? 'site')));
$id = isset($_GET['id']) && $_GET['id'] !== '' ? (int)$_GET['id'] : null;
$hours = isset($_GET['hours']) ? (int)$_GET['hours'] : 24;
$from = isset($_GET['from']) ? trim((string)$_GET['from']) : null;
$to = isset($_GET['to']) ? trim((string)$_GET['to']) : null;
$format = strtolower(trim((string)($_GET['format'] ?? 'json')));
$preset = strtolower(trim((string)($_GET['preset'] ?? '')));

if ($from === '') {
    $from = null;
}
if ($to === '') {
    $to = null;
}

// Presets override hours when from/to not set
if ($from === null && $to === null && $preset !== '') {
    $hours = match ($preset) {
        'week' => 24 * 7,
        'month' => 24 * 31,
        'year' => 24 * 365,
        'day', '24h' => 24,
        default => $hours,
    };
}

$data = PowerHistoryService::series($scope, $id, $hours, $from, $to);

if ($format === 'csv') {
    $rows = PowerHistoryService::tableRows($data);
    $filename = 'power-history-' . ($data['scope'] ?? 'site')
        . ($id ? '-' . $id : '')
        . '-' . date('Ymd-His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    if ($out === false) {
        App::json(['error' => 'Cannot write CSV'], 500);
    }
    fputcsv($out, ['time', 'kw', 'watts', 'volts', 'amps']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['time'],
            $r['kw'] ?? '',
            $r['watts'] ?? '',
            $r['volts'] ?? '',
            $r['amps'] ?? '',
        ]);
    }
    fclose($out);
    exit;
}

App::json($data);
