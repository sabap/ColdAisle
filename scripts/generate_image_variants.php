<?php
/**
 * Backfill small (.sm.jpg) faceplate variants for rack elevation / row / 3D.
 *
 * Usage (from app root):
 *   php scripts/generate_image_variants.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/src/App.php';
require_once $root . '/src/Services/ImageUpload.php';

App::boot();

echo "Scanning storage/uploads/templates for missing .sm variants…\n";
$stats = ImageUpload::backfillSmallVariants(static function (string $msg): void {
    echo '  ' . $msg . "\n";
});

echo sprintf(
    "Done. scanned=%d created=%d skipped=%d failed=%d\n",
    $stats['scanned'],
    $stats['created'],
    $stats['skipped'],
    $stats['failed']
);
