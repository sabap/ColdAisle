<?php
/**
 * ColdAisle - Authenticated media server for storage/uploads
 * Usage: media.php?f=templates/12/front.jpg
 */
declare(strict_types=1);

require_once __DIR__ . '/src/App.php';
App::boot();

if (!App::isInstalled()) {
    http_response_code(503);
    exit('Not installed');
}

// Require login for inventory images
if (!AuthManager::user()) {
    http_response_code(401);
    exit('Unauthorized');
}

$rel = (string)($_GET['f'] ?? '');
$rel = str_replace(['\\', "\0"], ['/', ''], $rel);
$rel = ltrim($rel, '/');
if ($rel === '' || str_contains($rel, '..')) {
    http_response_code(400);
    exit('Bad path');
}

$base = realpath(__DIR__ . '/storage/uploads');
$full = realpath(__DIR__ . '/storage/uploads/' . $rel);
if (!$base || !$full || !str_starts_with(strtolower($full), strtolower($base)) || !is_file($full)) {
    http_response_code(404);
    exit('Not found');
}

$ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
$types = [
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'gif' => 'image/gif',
    'webp' => 'image/webp',
];
$mime = $types[$ext] ?? 'application/octet-stream';

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($full));
header('Cache-Control: private, max-age=86400');
header('X-Content-Type-Options: nosniff');
readfile($full);
exit;
