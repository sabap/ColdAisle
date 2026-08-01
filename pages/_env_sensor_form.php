<?php
/**
 * Shared env sensor add/edit form.
 * Expects: $edit, $formAction, $kinds, $hosts, $placements, $rooms, $coolingUnits, $pdus, $cabinets, $devices
 */
declare(strict_types=1);

$edit = $edit ?? [];
$formAction = $formAction ?? 'add_sensor';
$isUpdate = $formAction === 'update_sensor';
$hostType = (string)($edit['host_type'] ?? 'standalone');
$devices = $devices ?? [];
?>
<form method="post" class="form-grid form-grid-3" id="envSensorForm">
    <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
    <input type="hidden" name="action" value="<?= App::e($formAction) ?>">
    <?php if ($isUpdate): ?>
        <input type="hidden" name="sensor_id" value="<?= (int)($edit['sensor_id'] ?? 0) ?>">
    <?php endif; ?>

    <div class="form-row"><label>Name</label>
        <input class="form-control" name="name" required value="<?= App::e($edit['name'] ?? '') ?>"
               placeholder="e.g. Cold aisle row A / AP9340 probe 1 temp"></div>
    <div class="form-row"><label>Kind</label>
        <select class="form-control" name="sensor_kind" id="env_sensor_kind">
            <?php foreach ($kinds as $val => $lab): ?>
                <option value="<?= App::e($val) ?>" <?= ($edit['sensor_kind'] ?? 'temperature') === $val ? 'selected' : '' ?>>
                    <?= App::e($lab) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <p class="text-muted" style="font-size:.75rem;margin:.25rem 0 0">
            Use <strong>Temperature + humidity (combo)</strong> for dual probes (e.g. TH01 port). Poll will fill both metrics later.
        </p>
    </div>
    <div class="form-row"><label>Unit</label>
        <input class="form-control" name="unit" id="env_sensor_unit"
               value="<?= App::e($edit['unit'] ?? '°C') ?>"
               placeholder="°C, %RH, °C / %RH, Pa…"></div>
    <div class="form-row"><label>Host type</label>
        <select class="form-control" name="host_type" id="env_host_type">
            <?php foreach ($hosts as $val => $lab): ?>
                <option value="<?= App::e($val) ?>" <?= $hostType === $val ? 'selected' : '' ?>>
                    <?= App::e($lab) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <p class="text-muted" style="font-size:.75rem;margin:.25rem 0 0">
            Prefer <strong>Device</strong> and select your AP9340 (or other env manager) so SNMP and inventory stay one host.
        </p>
    </div>
    <div class="form-row env-host-field" data-hosts="device"><label>Host device</label>
        <select class="form-control" name="device_id" id="env_device_id">
            <option value="">— Select device —</option>
            <?php foreach ($devices as $d):
                $dlab = $d['label'];
                $meta = trim(($d['manufacturer'] ?? '') . ' ' . ($d['model'] ?? ''));
                $dtype = (string)($d['device_type'] ?? '');
                if ($meta !== '') {
                    $dlab .= ' · ' . $meta;
                }
                if ($dtype !== '') {
                    $dlab .= ' [' . $dtype . ']';
                }
                ?>
                <option value="<?= (int)$d['device_id'] ?>"
                        data-cabinet="<?= (int)($d['cabinet_id'] ?? 0) ?>"
                        data-pos="<?= (int)($d['position_u'] ?? 0) ?>"
                    <?= (int)($edit['device_id'] ?? 0) === (int)$d['device_id'] ? 'selected' : '' ?>>
                    <?= App::e($dlab) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <p class="text-muted" style="font-size:.75rem;margin:.25rem 0 0" id="env_device_hint">
            Env monitors and expansion modules are listed first. Open the device for Discover OIDs / PowerNet MIB.
        </p>
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
    <div class="form-row env-host-field" data-hosts="cabinet device"><label>Cabinet (location)</label>
        <select class="form-control" name="cabinet_id" id="env_cabinet_id">
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

    <div class="form-row full">
        <h4 class="mt-0" style="margin-bottom:.35rem;font-size:.95rem;color:var(--muted)">SNMP (optional — safe to leave blank)</h4>
        <p class="text-muted" style="font-size:.8rem;margin:0 0 .5rem;max-width:40rem">
            You do <strong>not</strong> need these to create sensors. Poll will later match sensors to the
            <strong>site OID template</strong> on the Env Manager (MM) using a probe number or map key.
            <br>Examples for Index: <code>1</code> (probe 1), <code>MM:1</code>, or map key <code>temperature.1</code>.
            Full numeric OID is only needed if this sensor is polled outside a site template.
        </p>
    </div>
    <div class="form-row"><label>Probe / map key</label>
        <input class="form-control" name="snmp_index" value="<?= App::e($edit['snmp_index'] ?? '') ?>"
               placeholder="e.g. 1  or  TH01:1  or  temperature.1"></div>
    <div class="form-row"><label>Full OID (advanced)</label>
        <input class="form-control" name="snmp_oid" value="<?= App::e($edit['snmp_oid'] ?? '') ?>"
               placeholder="Usually blank if host device has a site template"></div>
    <div class="form-row full"><h4 class="mt-0" style="margin-bottom:0;font-size:.95rem;color:var(--muted)">3D / floor position (optional)</h4></div>
    <div class="form-row"><label>Pos X (m)</label>
        <input class="form-control" type="number" step="0.001" name="pos_x"
               value="<?= App::e((string)($edit['pos_x'] ?? '')) ?>"></div>
    <div class="form-row"><label>Pos Y (m)</label>
        <input class="form-control" type="number" step="0.001" name="pos_y"
               value="<?= App::e((string)($edit['pos_y'] ?? '')) ?>"></div>
    <div class="form-row"><label>Height Z (m)</label>
        <input class="form-control" type="number" step="0.001" name="pos_z"
               value="<?= App::e((string)($edit['pos_z'] ?? '')) ?>"
               placeholder="Future 3D height"></div>
    <div class="form-row full"><label>Notes</label>
        <textarea class="form-control" name="notes" rows="2"><?= App::e($edit['notes'] ?? '') ?></textarea></div>

    <div class="form-row full">
        <button type="submit" class="btn btn-primary"><?= $isUpdate ? 'Save changes' : 'Create sensor' ?></button>
    </div>
</form>
<script>
(function () {
  var form = document.getElementById('envSensorForm');
  if (!form) return;
  // When two forms exist (edit page + modal), scope to this form's elements
  var sel = form.querySelector('#env_host_type') || document.getElementById('env_host_type');
  var dev = form.querySelector('#env_device_id') || document.getElementById('env_device_id');
  var cab = form.querySelector('#env_cabinet_id') || document.getElementById('env_cabinet_id');
  var kind = form.querySelector('#env_sensor_kind');
  var unit = form.querySelector('#env_sensor_unit');
  var unitDefaults = {
    temperature: '°C',
    humidity: '%RH',
    temp_humidity: '°C / %RH',
    dew_point: '°C',
    differential_pressure: 'Pa',
    airflow: 'CFM',
    leak: 'state',
    other: ''
  };
  function sync() {
    if (!sel) return;
    var h = sel.value;
    form.querySelectorAll('.env-host-field').forEach(function (row) {
      var hosts = (row.getAttribute('data-hosts') || '').split(/\s+/);
      row.style.display = hosts.indexOf(h) >= 0 ? '' : 'none';
    });
  }
  function fillCabFromDevice() {
    if (!dev || !cab) return;
    var opt = dev.options[dev.selectedIndex];
    if (!opt) return;
    var cid = opt.getAttribute('data-cabinet') || '';
    if (cid && cid !== '0' && !cab.value) {
      cab.value = cid;
    }
  }
  function syncUnitFromKind() {
    if (!kind || !unit) return;
    var def = unitDefaults[kind.value];
    if (def === undefined) return;
    // Only auto-fill when empty or still a known default (don't clobber custom units)
    var cur = (unit.value || '').trim();
    var known = Object.keys(unitDefaults).map(function (k) { return unitDefaults[k]; });
    if (cur === '' || known.indexOf(cur) >= 0) {
      unit.value = def;
    }
  }
  if (sel) sel.addEventListener('change', sync);
  if (dev) dev.addEventListener('change', fillCabFromDevice);
  if (kind) kind.addEventListener('change', syncUnitFromKind);
  sync();
  fillCabFromDevice();
})();
</script>
