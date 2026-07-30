<?php
/**
 * ColdAisle - Authenticated media server for storage/uploads
 * Usage: media.php?f=templates/12/front.jpg
 *        media.php?f=templates/12/front.md.jpg  (medium — cabinet/row elevation)
 *        media.php?f=templates/12/front.sm.jpg  (small — 3D / list thumbs)
 *
 * Missing .sm / .md companions are generated on first request from the full image.
 */
declare(strict_types=1);

require_once __DIR__ . '/src/App.php';
// Light boot: no schema/crypto/pending walk — faceplate bursts must stay cheap
App::boot(['light' => true]);

if (!App::isInstalled()) {
    http_response_code(503);
    exit('Not installed');
}

// Require login for inventory images
if (!AuthManager::user()) {
    http_response_code(401);
    exit('Unauthorized');
}
// Do not hold the session lock while streaming bytes (parallel thumb loads)
App::releaseSessionLock();

$rel = (string)($_GET['f'] ?? '');
$rel = str_replace(['\\', "\0"], ['/', ''], $rel);
$rel = ltrim($rel, '/');
if ($rel === '' || str_contains($rel, '..')) {
    http_response_code(400);
    exit('Bad path');
}

$base = realpath(__DIR__ . '/storage/uploads');
$full = $base ? realpath(__DIR__ . '/storage/uploads/' . $rel) : false;

// Lazy-generate .sm.jpg / .md.jpg from full faceplate when missing (backfill)
if ((!$full || !is_file($full)) && preg_match('/\.(sm|md)\.jpe?g$/i', $rel, $m)) {
    if (!class_exists('ImageUpload')) {
        require_once __DIR__ . '/src/Services/ImageUpload.php';
    }
    $variant = strtolower($m[1]);
    $stem = preg_replace('/\.(sm|md)\.jpe?g$/i', '', $rel) ?? '';
    $candidates = [$stem . '.jpg', $stem . '.jpeg', $stem . '.png', $stem . '.gif', $stem . '.webp'];
    foreach ($candidates as $cand) {
        $candAbs = __DIR__ . '/storage/uploads/' . $cand;
        if (is_file($candAbs)) {
            $created = ImageUpload::ensureVariant($cand, $variant);
            if ($created) {
                $full = realpath(__DIR__ . '/storage/uploads/' . $created);
            }
            break;
        }
    }
}

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

// Long cache: variants are content-addressed by path; re-upload overwrites file
$maxAge = preg_match('/\.(sm|md)\.jpe?g$/i', $rel) ? 604800 : 86400;

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($full));
header('Cache-Control: private, max-age=' . $maxAge);
header('X-Content-Type-Options: nosniff');
readfile($full);
exit;
