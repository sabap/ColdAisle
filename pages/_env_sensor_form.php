<?php
/**
 * Shared env sensor add/edit form.
 * Expects: $edit, $formAction, $kinds, $hosts, $placements, $rooms, $coolingUnits, $pdus, $cabinets
 */
declare(strict_types=1);

$edit = $edit ?? [];
$formAction = $formAction ?? 'add_sensor';
$isUpdate = $formAction === 'update_sensor';
$hostType = (string)($edit['host_type'] ?? 'standalone');
?>
<form method="post" class="form-grid form-grid-3" id="envSensorForm">
    <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
    <input type="hidden" name="action" value="<?= App::e($formAction) ?>">
    <?php if ($isUpdate): ?>
        <input type="hidden" name="sensor_id" value="<?= (int)($edit['sensor_id'] ?? 0) ?>">
    <?php endif; ?>

    <div class="form-row"><label>Name</label>
        <input class="form-control" name="name" required value="<?= App::e($edit['name'] ?? '') ?>"
               placeholder="e.g. Cold aisle row A / CRAC-1 supply"></div>
    <div class="form-row"><label>Kind</label>
        <select class="form-control" name="sensor_kind">
            <?php foreach ($kinds as $val => $lab): ?>
                <option value="<?= App::e($val) ?>" <?= ($edit['sensor_kind'] ?? 'temperature') === $val ? 'selected' : '' ?>>
                    <?= App::e($lab) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-row"><label>Unit</label>
        <input class="form-control" name="unit" value="<?= App::e($edit['unit'] ?? '°C') ?>"
               placeholder="°C, %RH, Pa…"></div>
    <div class="form-row"><label>Host type</label>
        <select class="form-control" name="host_type" id="env_host_type">
            <?php foreach ($hosts as $val => $lab): ?>
                <option value="<?= App::e($val) ?>" <?= $hostType === $val ? 'selected' : '' ?>>
                    <?= App::e($lab) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-row env-host-field" data-hosts="cooling_unit"><label>Cooling unit</label>
        <select class="form-control" name="cooling_unit_id">
            <option value="">—</option>
            <?php foreach ($coolingUnits as $cu): ?>
                <option value="<?= (int)$cu['cooling_unit_id'] ?>"
                    <?= (int)($edit['cooling_unit_id'] ?? 0) === (int)$cu['cooling_unit_id'] ? 'selected' : '' ?>>
                    <?= App::e($cu['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-row env-host-field" data-hosts="pdu"><label>PDU</label>
        <select class="form-control" name="pdu_id">
            <option value="">—</option>
            <?php foreach ($pdus as $p): ?>
                <option value="<?= (int)$p['pdu_id'] ?>"
                    <?= (int)($edit['pdu_id'] ?? 0) === (int)$p['pdu_id'] ? 'selected' : '' ?>>
                    <?= App::e($p['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-row env-host-field" data-hosts="cabinet"><label>Cabinet</label>
        <select class="form-control" name="cabinet_id">
            <option value="">—</option>
            <?php foreach ($cabinets as $c): ?>
                <option value="<?= (int)$c['cabinet_id'] ?>"
                    <?= (int)($edit['cabinet_id'] ?? 0) === (int)$c['cabinet_id'] ? 'selected' : '' ?>>
                    <?= App::e($c['name']) ?>
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
    <div class="form-row"><label>Placement</label>
        <select class="form-control" name="placement">
            <?php foreach ($placements as $val => $lab): ?>
                <option value="<?= App::e($val) ?>" <?= ($edit['placement'] ?? 'ambient') === $val ? 'selected' : '' ?>>
                    <?= App::e($lab) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-row"><label>Location label</label>
        <input class="form-control" name="location_label" value="<?= App::e($edit['location_label'] ?? '') ?>"
               placeholder="Optional free text"></div>

    <div class="form-row full"><h4 class="mt-0" style="margin-bottom:0;font-size:.95rem;color:var(--muted)">Thresholds</h4></div>
    <div class="form-row"><label>Warn low</label>
        <input class="form-control" type="number" step="any" name="warn_low"
               value="<?= App::e((string)($edit['warn_low'] ?? '')) ?>"></div>
    <div class="form-row"><label>Warn high</label>
        <input class="form-control" type="number" step="any" name="warn_high"
               value="<?= App::e((string)($edit['warn_high'] ?? '')) ?>"></div>
    <div class="form-row"><label>Crit low</label>
        <input class="form-control" type="number" step="any" name="crit_low"
               value="<?= App::e((string)($edit['crit_low'] ?? '')) ?>"></div>
    <div class="form-row"><label>Crit high</label>
        <input class="form-control" type="number" step="any" name="crit_high"
               value="<?= App::e((string)($edit['crit_high'] ?? '')) ?>"></div>

    <div class="form-row full"><h4 class="mt-0" style="margin-bottom:0;font-size:.95rem;color:var(--muted)">SNMP (optional)</h4></div>
    <div class="form-row full"><label>OID</label>
        <input class="form-control" name="snmp_oid" value="<?= App::e($edit['snmp_oid'] ?? '') ?>"
               placeholder="e.g. 1.3.6.1.4.1… (polled later via host or site template)"></div>
    <div class="form-row full"><label>Notes</label>
        <textarea class="form-control" name="notes" rows="2"><?= App::e($edit['notes'] ?? '') ?></textarea></div>

    <div class="form-row full">
        <button type="submit" class="btn btn-primary"><?= $isUpdate ? 'Save changes' : 'Create sensor' ?></button>
    </div>
</form>
<script>
(function () {
  var sel = document.getElementById('env_host_type');
  if (!sel) return;
  function sync() {
    var h = sel.value;
    document.querySelectorAll('#envSensorForm .env-host-field').forEach(function (row) {
      var hosts = (row.getAttribute('data-hosts') || '').split(/\s+/);
      row.style.display = hosts.indexOf(h) >= 0 ? '' : 'none';
    });
  }
  sel.addEventListener('change', sync);
  sync();
})();
</script>
