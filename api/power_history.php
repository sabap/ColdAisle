<?php
/**
 * Power history time-series for charts.
 * GET ?scope=site|zone|pdu&id=&hours=24
 */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$user = App::requirePermission('view_power');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    App::json(['error' => 'GET only'], 405);
}

$scope = strtolower(trim((string)($_GET['scope'] ?? 'site')));
$id = isset($_GET['id']) ? (int)$_GET['id'] : null;
$hours = isset($_GET['hours']) ? (int)$_GET['hours'] : 24;

if (!class_exists('PowerHistoryService')) {
    App::json(['error' => 'PowerHistoryService not available'], 503);
}

$data = PowerHistoryService::series($scope, $id, $hours);
App::json($data);
