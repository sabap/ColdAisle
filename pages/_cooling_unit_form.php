<?php
/**
 * Shared cooling unit add/edit form.
 * Expects: $edit (array), $formAction ('add_unit'|'update_unit'),
 *          $rooms, $peerUnits, $types, $roles, $media, $statuses, $ashrae
 */
declare(strict_types=1);

$edit = $edit ?? [];
$formAction = $formAction ?? 'add_unit';
$isUpdate = $formAction === 'update_unit';
$selfId = (int)($edit['cooling_unit_id'] ?? 0);
?>
<form method="post" class="form-grid form-grid-3">
    <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
    <input type="hidden" name="action" value="<?= App::e($formAction) ?>">
    <?php if ($isUpdate): ?>
        <input type="hidden" name="cooling_unit_id" value="<?= $selfId ?>">
    <?php endif; ?>

    <div class="form-row"><label>Name</label>
        <input class="form-control" name="name" required value="<?= App::e($edit['name'] ?? '') ?>"
               placeholder="e.g. CRAC-1 / CRAH-A"></div>
    <div class="form-row"><label>Type</label>
        <select class="form-control" name="unit_type">
            <?php foreach ($types as $val => $lab): ?>
                <option value="<?= App::e($val) ?>" <?= ($edit['unit_type'] ?? 'crac') === $val ? 'selected' : '' ?>>
                    <?= App::e($lab) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-row"><label>Role</label>
        <select class="form-control" name="unit_role">
            <?php foreach ($roles as $val => $lab): ?>
                <option value="<?= App::e($val) ?>" <?= ($edit['unit_role'] ?? 'primary') === $val ? 'selected' : '' ?>>
                    <?= App::e($lab) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-row"><label>Standby of (primary unit)</label>
        <select class="form-control" name="standby_of_id">
            <option value="">—</option>
            <?php foreach ($peerUnits as $p):
                if ($selfId > 0 && (int)$p['cooling_unit_id'] === $selfId) {
                    continue;
                }
                ?>
                <option value="<?= (int)$p['cooling_unit_id'] ?>"
                    <?= (int)($edit['standby_of_id'] ?? 0) === (int)$p['cooling_unit_id'] ? 'selected' : '' ?>>
                    <?= App::e($p['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <p class="text-muted" style="font-size:.75rem;margin:.25rem 0 0">Link standby → primary for active/standby pairs.</p>
    </div>
    <div class="form-row"><label>Cooling medium</label>
        <select class="form-control" name="cooling_medium">
            <?php foreach ($media as $val => $lab): ?>
                <option value="<?= App::e($val) ?>" <?= ($edit['cooling_medium'] ?? 'dx') === $val ? 'selected' : '' ?>>
                    <?= App::e($lab) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-row"><label>Status</label>
        <select class="form-control" name="status">
            <?php foreach ($statuses as $val => $lab): ?>
                <option value="<?= App::e($val) ?>" <?= ($edit['status'] ?? 'production') === $val ? 'selected' : '' ?>>
                    <?= App::e($lab) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-row"><label>Room</label>
        <select class="form-control" name="room_id">
            <option value="">—</option>
            <?php foreach ($rooms as $r):
                $label = trim(($r['dc_name'] ?? '') . ' / ' . ($r['name'] ?? ''), ' /');
                ?>
                <option value="<?= (int)$r['room_id'] ?>"
                    <?= (int)($edit['room_id'] ?? 0) === (int)$r['room_id'] ? 'selected' : '' ?>>
                    <?= App::e($label ?: ('Room #' . $r['room_id'])) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="form-row full"><h4 class="mt-0" style="margin-bottom:0;font-size:.95rem;color:var(--muted)">Identity</h4></div>
    <div class="form-row"><label>Manufacturer</label>
        <input class="form-control" name="manufacturer" value="<?= App::e($edit['manufacturer'] ?? '') ?>"
               placeholder="Liebert, Stulz, APC…"></div>
    <div class="form-row"><label>Model</label>
        <input class="form-control" name="model" value="<?= App::e($edit['model'] ?? '') ?>"></div>
    <div class="form-row"><label>Serial number</label>
        <input class="form-control" name="serial_no" value="<?= App::e($edit['serial_no'] ?? '') ?>"></div>
    <div class="form-row"><label>Asset tag</label>
        <input class="form-control" name="asset_tag" value="<?= App::e($edit['asset_tag'] ?? '') ?>"></div>
    <div class="form-row"><label>Primary IP</label>
        <input class="form-control" name="primary_ip" value="<?= App::e($edit['primary_ip'] ?? '') ?>"
               placeholder="10.x.x.x" autocomplete="off"></div>
    <div class="form-row"><label>Hostname</label>
        <input class="form-control" name="hostname" value="<?= App::e($edit['hostname'] ?? '') ?>"></div>

    <div class="form-row full"><h4 class="mt-0" style="margin-bottom:0;font-size:.95rem;color:var(--muted)">Capacity &amp; setpoints</h4></div>
    <div class="form-row"><label>Rated cooling (kW)</label>
        <input class="form-control" type="number" step="0.1" name="rated_kw_cooling"
               value="<?= App::e((string)($edit['rated_kw_cooling'] ?? '')) ?>"></div>
    <div class="form-row"><label>Rated tons</label>
        <input class="form-control" type="number" step="0.1" name="rated_tons"
               value="<?= App::e((string)($edit['rated_tons'] ?? '')) ?>"></div>
    <div class="form-row"><label>Rated airflow (CFM)</label>
        <input class="form-control" type="number" step="1" name="rated_cfm"
               value="<?= App::e((string)($edit['rated_cfm'] ?? '')) ?>"></div>
    <div class="form-row"><label>Supply setpoint (°C)</label>
        <input class="form-control" type="number" step="0.1" name="supply_temp_setpoint_c"
               value="<?= App::e((string)($edit['supply_temp_setpoint_c'] ?? '')) ?>"></div>
    <div class="form-row"><label>Return setpoint (°C)</label>
        <input class="form-control" type="number" step="0.1" name="return_temp_setpoint_c"
               value="<?= App::e((string)($edit['return_temp_setpoint_c'] ?? '')) ?>"></div>
    <div class="form-row"><label>ASHRAE class</label>
        <select class="form-control" name="ashrae_class">
            <?php foreach ($ashrae as $val => $lab): ?>
                <option value="<?= App::e($val) ?>" <?= ($edit['ashrae_class'] ?? 'recommended') === $val ? 'selected' : '' ?>>
                    <?= App::e($lab) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="form-row full"><h4 class="mt-0" style="margin-bottom:0;font-size:.95rem;color:var(--muted)">Warranty &amp; dates</h4></div>
    <div class="form-row"><label>Warranty provider</label>
        <input class="form-control" name="warranty_provider" value="<?= App::e($edit['warranty_provider'] ?? '') ?>"></div>
    <div class="form-row"><label>Warranty end</label>
        <input class="form-control" type="date" name="warranty_end" value="<?= App::e((string)($edit['warranty_end'] ?? '')) ?>"></div>
    <div class="form-row"><label>Install date</label>
        <input class="form-control" type="date" name="install_date" value="<?= App::e((string)($edit['install_date'] ?? '')) ?>"></div>
    <div class="form-row"><label>Manufacture date</label>
        <input class="form-control" type="date" name="manufacture_date" value="<?= App::e((string)($edit['manufacture_date'] ?? '')) ?>"></div>

    <div class="form-row full"><h4 class="mt-0" style="margin-bottom:0;font-size:.95rem;color:var(--muted)">Footprint (floor plan)</h4></div>
    <div class="form-row"><label>Width (mm)</label>
        <input class="form-control" type="number" name="width_mm" min="100"
               value="<?= App::e((string)($edit['width_mm'] ?? '1200')) ?>"></div>
    <div class="form-row"><label>Depth (mm)</label>
        <input class="form-control" type="number" name="depth_mm" min="100"
               value="<?= App::e((string)($edit['depth_mm'] ?? '900')) ?>"></div>
    <div class="form-row"><label>Height (mm)</label>
        <input class="form-control" type="number" name="height_mm" min="100"
               value="<?= App::e((string)($edit['height_mm'] ?? '2000')) ?>"></div>
    <div class="form-row"><label>Color</label>
        <input class="form-control" type="color" name="color_hex"
               value="<?= App::e($edit['color_hex'] ?? '#0ea5e9') ?>"></div>

    <div class="form-row full"><h4 class="mt-0" style="margin-bottom:0;font-size:.95rem;color:var(--muted)">SNMP (foundation)</h4></div>
    <div class="form-row full">
        <label><input type="checkbox" name="snmp_enabled" value="1" <?= !empty($edit['snmp_enabled']) ? 'checked' : '' ?>>
            SNMP enabled</label>
    </div>
    <div class="form-row"><label>Version</label>
        <select class="form-control" name="snmp_version">
            <?php foreach (['2c' => 'v2c', '1' => 'v1', '3' => 'v3'] as $val => $lab): ?>
                <option value="<?= $val ?>" <?= ($edit['snmp_version'] ?? '2c') === $val ? 'selected' : '' ?>><?= $lab ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-row"><label>Community</label>
        <input class="form-control" name="snmp_community" value="<?= App::e($edit['snmp_community'] ?? '') ?>"
               placeholder="public" autocomplete="off"></div>
    <div class="form-row"><label>Port</label>
        <input class="form-control" type="number" name="snmp_port" value="<?= App::e((string)($edit['snmp_port'] ?? '161')) ?>"></div>
    <div class="form-row full">
        <label><input type="checkbox" name="snmp_auto_poll" value="1" <?= !empty($edit['snmp_auto_poll']) ? 'checked' : '' ?>>
            Include in scheduled polls (when worker supports cooling)</label>
    </div>

    <div class="form-row full"><label>Notes</label>
        <textarea class="form-control" name="notes" rows="3"><?= App::e($edit['notes'] ?? '') ?></textarea></div>

    <div class="form-row full">
        <button type="submit" class="btn btn-primary"><?= $isUpdate ? 'Save changes' : 'Create unit' ?></button>
    </div>
</form>
