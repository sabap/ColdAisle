<?php
/**
 * Web app manifest for Add to Home Screen (Tech / field kit).
 */
declare(strict_types=1);

require_once __DIR__ . '/src/App.php';
App::boot(['light' => true]);

$base = rtrim(App::baseUrl(), '/');
$start = $base . '/pages/tech.php';
$icons = [
    [
        'src' => $base . '/assets/img/favicon-180.png',
        'sizes' => '180x180',
        'type' => 'image/png',
        'purpose' => 'any',
    ],
    [
        'src' => $base . '/assets/img/logo.svg',
        'sizes' => 'any',
        'type' => 'image/svg+xml',
        'purpose' => 'any',
    ],
];

$manifest = [
    'name' => 'ColdAisle Field',
    'short_name' => 'ColdAisle',
    'description' => 'Field kit: scan a cabinet QR, audit the rack, compare to last visit.',
    'start_url' => $start,
    'scope' => $base . '/',
    'display' => 'standalone',
    'orientation' => 'any',
    'background_color' => '#0f172a',
    'theme_color' => '#0f172a',
    'icons' => $icons,
];

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: private, max-age=3600');
echo json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
