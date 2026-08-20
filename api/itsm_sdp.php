<?php
/**
 * Inbound webhook for ManageEngine ServiceDesk Plus Cloud (Custom Trigger / Webhook).
 *
 * Auth: shared secret as ?token=, X-ColdAisle-Token, X-Webhook-Secret, or Bearer.
 * GET  → health check (token required)
 * POST → create/update work order from request payload
 *
 * No session. SDP cannot send CSRF.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/App.php';
App::boot();

if (!App::isInstalled()) {
    App::json(['ok' => false, 'error' => 'Not installed'], 503);
}

if (!class_exists('SdpCloudService')) {
    App::json(['ok' => false, 'error' => 'SdpCloudService not deployed'], 503);
}

function itsm_sdp_token(): string
{
    $hdr = (string)($_SERVER['HTTP_X_COLDAISLE_TOKEN'] ?? $_SERVER['HTTP_X_WEBHOOK_SECRET'] ?? '');
    if ($hdr !== '') {
        return $hdr;
    }
    $auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (preg_match('/^Bearer\s+(\S+)/i', $auth, $m)) {
        return $m[1];
    }
    $q = (string)($_GET['token'] ?? $_POST['token'] ?? '');
    return $q;
}

$cfg = SdpCloudService::config();
if (empty($cfg['enabled'])) {
    App::json(['ok' => false, 'error' => 'ServiceDesk Cloud integration is disabled'], 403);
}
if (empty($cfg['webhook_enabled'])) {
    App::json(['ok' => false, 'error' => 'ServiceDesk webhook is disabled (outbound pull does not need a public URL)'], 403);
}
if (!SdpCloudService::verifyWebhookToken(itsm_sdp_token())) {
    App::json(['ok' => false, 'error' => 'Forbidden — invalid or missing webhook token'], 403);
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'GET' || $method === 'HEAD') {
    App::json([
        'ok' => true,
        'service' => 'sdp',
        'detail' => 'ServiceDesk Cloud webhook is listening.',
    ]);
}
if ($method !== 'POST' && $method !== 'PUT') {
    App::json(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$raw = (string)file_get_contents('php://input');
$payload = [];
if ($raw !== '') {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $payload = $decoded;
    } else {
        parse_str($raw, $parsed);
        $payload = is_array($parsed) ? $parsed : [];
    }
}
if ($payload === [] && is_array($_POST) && $_POST !== []) {
    $payload = $_POST;
}

try {
    $result = SdpCloudService::handleInboundWebhook($payload);
    App::json($result, $result['ok'] ? 200 : 400);
} catch (Throwable $e) {
    App::log('SDP webhook: ' . $e->getMessage(), 'error');
    App::json(['ok' => false, 'error' => $e->getMessage()], 500);
}
