<?php
/**
 * Backfill faceplate variants (.sm.jpg + .md.jpg) under storage/uploads/templates.
 *
 * Usage (from app root):
 *   php scripts/generate_image_variants.php
 *   php scripts/generate_image_variants.php md    # medium only
 *   php scripts/generate_image_variants.php sm    # small only
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/src/App.php';
require_once $root . '/src/Services/ImageUpload.php';

App::boot();

$arg = strtolower(trim((string)($argv[1] ?? '')));
$variants = match ($arg) {
    'sm', 'small' => [ImageUpload::VARIANT_SM],
    'md', 'medium' => [ImageUpload::VARIANT_MD],
    default => [ImageUpload::VARIANT_SM, ImageUpload::VARIANT_MD],
};

echo 'Scanning storage/uploads/templates for missing variants (' . implode(', ', $variants) . ")…\n";
$stats = ImageUpload::backfillSmallVariants(static function (string $msg): void {
    echo '  ' . $msg . "\n";
}, $variants);

echo sprintf(
    "Done. scanned=%d created=%d skipped=%d failed=%d\n",
    $stats['scanned'],
    $stats['created'],
    $stats['skipped'],
    $stats['failed']
);
