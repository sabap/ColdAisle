<?php
/**
 * PO / purchase / warranty / install fields for PDUs and UPS.
 * Expects $lifecycle (row array).
 */
declare(strict_types=1);
$lifecycle = $lifecycle ?? [];
$lc = static function (string $k) use ($lifecycle): string {
    $v = $lifecycle[$k] ?? '';
    return $v === null ? '' : (string)$v;
};
?>
<div class="form-row full">
    <h4 class="mt-0" style="margin-bottom:0;font-size:.95rem;color:var(--muted)">Purchase &amp; warranty</h4>
    <p class="text-muted" style="font-size:.75rem;margin:.25rem 0 0">Same lifecycle fields as devices — used on Reports → Warranty.</p>
</div>
<div class="form-row"><label>PO number</label>
    <input class="form-control" name="po_number" value="<?= App::e($lc('po_number')) ?>" placeholder="Purchase order"></div>
<div class="form-row"><label>Purchase date</label>
    <input class="form-control" type="date" name="purchase_date" value="<?= App::e($lc('purchase_date')) ?>"></div>
<div class="form-row"><label>Purchase vendor</label>
    <input class="form-control" name="purchase_vendor" value="<?= App::e($lc('purchase_vendor')) ?>"></div>
<div class="form-row"><label>Purchase cost</label>
    <input class="form-control" type="number" step="0.01" min="0" name="purchase_cost"
           value="<?= App::e($lc('purchase_cost')) ?>"></div>
<div class="form-row"><label>Warranty company</label>
    <input class="form-control" name="warranty_provider" value="<?= App::e($lc('warranty_provider')) ?>"></div>
<div class="form-row"><label>Warranty expiration</label>
    <input class="form-control" type="date" name="warranty_end" value="<?= App::e($lc('warranty_end')) ?>"></div>
<div class="form-row"><label>Install date</label>
    <input class="form-control" type="date" name="install_date" value="<?= App::e($lc('install_date')) ?>"></div>
