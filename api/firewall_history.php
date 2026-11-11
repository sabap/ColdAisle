<?php
/**
 * Firewall chart series for the detail page. Reads stored samples only.
 */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once dirname(__DIR__) . '/src/Services/FirewallHistory.php';

if (api_method() !== 'GET') {
    App::json(['error' => 'Method not allowed'], 405);
}
api_require_permission('view_firewalls');

$id = (int)($_GET['id'] ?? 0);
$row = FirewallHistory::one($id);
if ($row === null) {
    App::json(['error' => 'Not found'], 404);
}
App::json(['ok' => true, 'firewall' => $row]);
