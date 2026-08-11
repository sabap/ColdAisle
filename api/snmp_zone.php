<?php
/**
 * Zone-scoped SNMP actions (poll all eligible units in a power zone).
 */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once dirname(__DIR__) . '/src/Services/SnmpPoller.php';

$method = api_method();
$user = AuthManager::user();

if (!AuthManager::canEditPower($user) && !AuthManager::canEditSnmp($user)) {
    App::json(['error' => 'Forbidden — need edit power or SNMP permission'], 403);
}

try {
    if ($method !== 'POST') {
        App::json(['error' => 'POST required'], 405);
    }
    api_require_csrf();
    $body = api_read_json();
    $action = (string)($body['action'] ?? $_POST['action'] ?? '');
    if ($action !== 'poll_zone') {
        App::json(['error' => 'Unknown action'], 400);
    }
    $zoneId = (int)($body['zone_id'] ?? $_POST['zone_id'] ?? 0);
    if ($zoneId < 1) {
        App::json(['error' => 'zone_id required'], 400);
    }
    $zone = Database::fetchOne('SELECT zone_id, name FROM power_zones WHERE zone_id = ?', [$zoneId]);
    if (!$zone) {
        App::json(['error' => 'Zone not found'], 404);
    }

    // Zone polls can touch many devices — allow a longer PHP run when possible
    if (function_exists('set_time_limit')) {
        @set_time_limit(180);
    }

    $result = SnmpPoller::pollZone($zoneId);
    $ok = (int)$result['success'];
    $fail = (int)$result['failed'];
    $msg = sprintf(
        'Zone %s: %d ok, %d failed%s',
        (string)$zone['name'],
        $ok,
        $fail,
        ($ok + $fail) === 0 ? ' (no PDUs/UPS with site template + IP on this zone)' : ''
    );
    App::json([
        'ok' => $fail === 0,
        'message' => $msg,
        'zone_id' => $zoneId,
        'zone_name' => (string)$zone['name'],
        'success' => $ok,
        'failed' => $fail,
        'skipped' => (int)$result['skipped'],
        'items' => $result['items'],
    ]);
} catch (Throwable $e) {
    App::log('api/snmp_zone: ' . $e->getMessage(), 'error');
    App::json(['error' => $e->getMessage()], 500);
}
