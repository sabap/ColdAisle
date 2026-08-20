<?php
/**
 * Device SNMP metric history for charts.
 * GET ?device_id= &hours=24
 */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$user = App::requireAuth();
if (!AuthManager::can($user, 'view_devices') && !AuthManager::can($user, 'view_power')) {
    App::json(['error' => 'Permission denied'], 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    App::json(['error' => 'GET only'], 405);
}

$id = (int)($_GET['device_id'] ?? $_GET['id'] ?? 0);
if ($id < 1) {
    App::json(['error' => 'device_id required'], 400);
}
$hours = isset($_GET['hours']) ? (int)$_GET['hours'] : 24;

if (!class_exists('DeviceSnmpHistoryService')) {
    App::json(['error' => 'DeviceSnmpHistoryService not available'], 503);
}

$data = DeviceSnmpHistoryService::series($id, $hours);
App::json($data);
