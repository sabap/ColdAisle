<?php
/**
 * Shared PDU add/edit form partial.
 * Expects: $edit (array), $formAction ('add_pdu'|'update_pdu'),
 *          $cabinets, $rows, $zones, optional preselect zone from $filterZone
 */
declare(strict_types=1);

$edit = $edit ?? [];
$formAction = $formAction ?? 'add_pdu';
$isUpdate = $formAction === 'update_pdu';
$preZone = (int)($edit['zone_id'] ?? ($filterZone ?? 0));
?>
<form method="post" class="form-grid" id="addPduForm">
    <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
    <input type="hidden" name="action" value="<?= App::e($formAction) ?>">
    <?php if ($isUpdate): ?>
        <input type="hidden" name="pdu_id" value="<?= (int)($edit['pdu_id'] ?? 0) ?>">
    <?php endif; ?>

    <div class="form-row"><label>Name</label>
        <input class="form-control" name="name" required value="<?= App::e($edit['name'] ?? '') ?>"></div>
    <div class="form-row"><label>Vendor</label>
        <input class="form-control" name="manufacturer" placeholder="APC, Raritan, ServerTech…"
               value="<?= App::e($edit['manufacturer'] ?? '') ?>"></div>
    <div class="form-row"><label>Model</label>
        <input class="form-control" name="model" value="<?= App::e($edit['model'] ?? '') ?>"></div>
    <div class="form-row"><label>Scope</label>
        <select class="form-control" name="pdu_scope" id="power_pdu_scope">
            <?php foreach (['rack' => 'Rack PDU', 'row' => 'Row PDU', 'room' => 'Room PDU'] as $val => $lab): ?>
                <option value="<?= $val ?>" <?= ($edit['pdu_scope'] ?? 'rack') === $val ? 'selected' : '' ?>><?= $lab ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-row power-rack-fields"><label>Cabinet</label>
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
    <div class="form-row power-row-fields" style="display:none"><label>Cabinet row</label>
        <select class="form-control" name="row_id" id="power_row_id">
            <option value="">—</option>
            <?php foreach ($rows as $r):
                $rlabel = trim(($r['dc_name'] ?? '') . ' / ' . ($r['room_name'] ?? '') . ' / ' . ($r['name'] ?? ''), ' /');
                ?>
                <option value="<?= (int)$r['row_id'] ?>"
                        data-zone="<?= (int)($r['zone_id'] ?? 0) ?>"
                    <?= (int)($edit['row_id'] ?? 0) === (int)$r['row_id'] ? 'selected' : '' ?>>
                    <?= App::e($rlabel ?: ('Row #' . $r['row_id'])) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-row"><label>Zone</label>
        <select class="form-control" name="zone_id" id="power_zone_id">
            <option value="">—</option>
            <?php foreach ($zones as $z): ?>
                <option value="<?= (int)$z['zone_id'] ?>" data-voltage="<?= (int)($z['voltage'] ?? 0) ?>"
                    <?= $preZone === (int)$z['zone_id'] ? 'selected' : '' ?>>
                    <?= App::e($z['name']) ?><?= !empty($z['voltage']) ? ' (' . (int)$z['voltage'] . 'V)' : '' ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="form-row full"><h4 class="mt-0" style="margin-bottom:0;font-size:.95rem;color:var(--muted)">Electrical</h4></div>
    <div class="form-row"><label>Phases</label>
        <select class="form-control" name="phases" id="power_phases">
            <option value="1" <?= (int)($edit['phases'] ?? 1) === 1 ? 'selected' : '' ?>>Single-phase (1φ)</option>
            <option value="2" <?= (int)($edit['phases'] ?? 1) === 2 ? 'selected' : '' ?>>Two-phase / split-phase (2φ)</option>
            <option value="3" <?= (int)($edit['phases'] ?? 1) === 3 ? 'selected' : '' ?>>Three-phase (3φ)</option>
        </select>
    </div>
    <div class="form-row" id="power_wiring_row"><label>Wiring</label>
        <select class="form-control" name="phase_wiring" id="power_phase_wiring">
            <?php
            $pw = $edit['phase_wiring'] ?? 'single';
            foreach ([
                'single' => 'Single-phase',
                'split_phase' => 'Split-phase (L1/L2/N · 120/240)',
                'two_phase' => 'Two-phase (L1/L2)',
                'wye' => 'Wye / star (3P+N)',
                'delta' => 'Delta (3P)',
            ] as $val => $lab): ?>
                <option value="<?= $val ?>" <?= $pw === $val ? 'selected' : '' ?>><?= $lab ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-row"><label id="power_in_ll_label">Input voltage</label>
        <input class="form-control" type="number" min="1" name="input_voltage" id="power_input_voltage"
               value="<?= App::e((string)($edit['input_voltage'] ?? $edit['rated_volts'] ?? '208')) ?>"></div>
    <div class="form-row power-ln-fields" style="display:none"><label>Input voltage (L–N)</label>
        <input class="form-control" type="number" min="1" name="input_voltage_ln" id="power_input_voltage_ln"
               value="<?= App::e((string)($edit['input_voltage_ln'] ?? '')) ?>"></div>
    <div class="form-row"><label id="power_out_label">Output voltage</label>
        <input class="form-control" type="number" min="1" name="output_voltage" id="power_output_voltage"
               value="<?= App::e((string)($edit['output_voltage'] ?? '208')) ?>"></div>
    <div class="form-row power-out-ln-fields" style="display:none"><label>Output voltage (L–N)</label>
        <input class="form-control" type="number" min="1" name="output_voltage_ln" id="power_output_voltage_ln"
               value="<?= App::e((string)($edit['output_voltage_ln'] ?? '')) ?>"></div>
    <div class="form-row power-phase-hint full" id="power_phase_hint">
        <p class="text-muted mb-0" style="font-size:.78rem;margin:0"></p>
    </div>
    <div class="form-row power-zone-sync full" style="display:none">
        <label>
            <input type="checkbox" name="sync_zone_voltage" value="1" id="power_sync_zone"
                <?= !empty($edit['sync_zone_voltage']) || !$isUpdate ? 'checked' : '' ?>>
            Auto-update power zone voltage from this PDU’s input voltage
        </label>
    </div>

    <div class="form-row power-rack-fields"><label>Mount style</label>
        <select class="form-control" name="mount_style" id="power_mount_style">
            <option value="vertical_rear" <?= ($edit['mount_style'] ?? '') === 'vertical_rear' || empty($edit['mount_style']) ? 'selected' : '' ?>>Vertical rear (0U rails)</option>
            <option value="u_mounted" <?= ($edit['mount_style'] ?? '') === 'u_mounted' ? 'selected' : '' ?>>U-mounted (rack positions)</option>
        </select>
    </div>
    <div class="form-row power-u-fields" style="display:none"><label>Position (U)</label>
        <input class="form-control" type="number" min="1" name="position_u" value="<?= App::e((string)($edit['position_u'] ?? '')) ?>"></div>
    <div class="form-row power-u-fields" style="display:none"><label>U height</label>
        <input class="form-control" type="number" min="1" max="10" name="u_height" value="<?= App::e((string)($edit['u_height'] ?? '1')) ?>"></div>
    <div class="form-row"><label>Input connector</label>
        <select class="form-control" name="input_type" id="power_input_type">
            <option value="">—</option>
            <?php foreach (['L6-30P','L6-20P','L5-30P','L5-20P','L14-30P','CS8365','IEC 60309 3P+N+E 32A','IEC 60309 3P+N+E 16A','Hardwired','C20','Other'] as $t): ?>
                <option <?= ($edit['input_type'] ?? '') === $t ? 'selected' : '' ?>><?= App::e($t) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-row"><label>AMP rating (feed)</label>
        <input class="form-control" type="number" step="0.1" name="rated_amps" value="<?= App::e((string)($edit['rated_amps'] ?? '30')) ?>"></div>
    <div class="form-row"><label>IP address</label>
        <input class="form-control" name="ip_address" value="<?= App::e($edit['ip_address'] ?? '') ?>"></div>

    <div class="form-row full"><h4 class="mt-0" style="margin-bottom:0;font-size:.95rem;color:var(--muted)">Distribution outputs</h4></div>
    <div class="form-row"><label>Output type</label>
        <select class="form-control" name="output_mode" id="power_output_mode">
            <option value="outlets" <?= ($edit['output_mode'] ?? 'outlets') !== 'breakers' ? 'selected' : '' ?>>Outlets (receptacles)</option>
            <option value="breakers" <?= ($edit['output_mode'] ?? '') === 'breakers' ? 'selected' : '' ?>>Breakers / pigtails</option>
        </select>
        <p class="text-muted" style="font-size:.75rem;margin:.25rem 0 0">
            Row PDUs often use breakers with pigtails to racks (multi-slot breakers). Rack PDUs usually use outlets.
        </p>
    </div>
    <?php
    // Keep number inputs valid for HTML5 even when the inactive mode is hidden
    // (browsers still validate display:none fields and block submit with no visible error).
    $formNumOutlets = max(1, (int)($edit['num_outlets'] ?? 24));
    $formNumBreakerSlots = max(1, (int)($edit['num_breaker_slots'] ?? 42));
    ?>
    <div class="form-row power-outlet-fields"><label>Outlets</label>
        <input class="form-control" type="number" min="1" max="128" name="num_outlets"
               value="<?= App::e((string)$formNumOutlets) ?>"></div>
    <div class="form-row power-outlet-fields"><label>Outlet type (NEMA/IEC)</label>
        <select class="form-control" name="outlet_type">
            <?php foreach (['C13','C19','C14','C20','5-15R','5-20R','L5-20R','L5-30R','L6-20R','L6-30R','L14-30R','Other'] as $t): ?>
                <option<?= $t === 'C13' ? ' selected' : '' ?>><?= App::e($t) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-row power-outlet-fields"><label>Outlet AMP (default)</label>
        <input class="form-control" type="number" step="0.1" name="outlet_amps" value="10"></div>
    <div class="form-row power-breaker-fields" style="display:none"><label>Breaker positions (slots)</label>
        <input class="form-control" type="number" min="1" max="128" name="num_breaker_slots"
               value="<?= App::e((string)$formNumBreakerSlots) ?>"
               title="Total slot positions on the breaker panel (e.g. 42)"></div>
    <div class="form-row power-breaker-fields" style="display:none"><label>Panel layout / numbering</label>
        <select class="form-control" name="breaker_layout" id="power_breaker_layout">
            <?php
            $curLayout = $edit['breaker_layout'] ?? 'odd_right_even_left';
            foreach (power_breaker_layout_options() as $val => $lab):
                ?>
                <option value="<?= App::e($val) ?>" <?= $curLayout === $val ? 'selected' : '' ?>><?= App::e($lab) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-row power-breaker-fields" style="display:none"><label>Columns (visual)</label>
        <select class="form-control" name="breaker_columns">
            <?php for ($c = 1; $c <= 3; $c++): ?>
                <option value="<?= $c ?>" <?= (int)($edit['breaker_columns'] ?? 2) === $c ? 'selected' : '' ?>><?= $c ?></option>
            <?php endfor; ?>
        </select>
        <p class="text-muted" style="font-size:.72rem;margin:.25rem 0 0">Usually 2. Layout presets set this when needed.</p>
    </div>
    <div class="form-row full power-breaker-fields" style="display:none">
        <p class="text-muted" style="font-size:.8rem;margin:0">
            After saving, click free slots on the panel grid to select poles for each breaker (supports non-contiguous poles such as 1, 3, 5).
        </p>
    </div>

    <?php
    $snmpProfiles = $snmpProfiles ?? [];
    ?>
    <div class="form-row full"><label><input type="checkbox" name="snmp_enabled" value="1" id="power_snmp_enabled"
        <?= !empty($edit['snmp_enabled']) ? 'checked' : '' ?>> Enable SNMP</label></div>
    <div class="form-row power-snmp-any" style="display:none"><label>SNMP version</label>
        <select class="form-control" name="snmp_version" id="power_snmp_version">
            <?php foreach (['1','2c','3'] as $v): ?>
                <option value="<?= $v ?>" <?= ($edit['snmp_version'] ?? '2c') === $v ? 'selected' : '' ?>><?= $v ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-row power-snmp-any" style="display:none"><label>SNMP port</label>
        <input class="form-control" type="number" name="snmp_port" value="<?= App::e((string)($edit['snmp_port'] ?? '161')) ?>"></div>
    <div class="form-row power-snmp-v12" style="display:none"><label>Public community</label>
        <input class="form-control" name="snmp_community" value=""
               placeholder="<?= !empty($edit['snmp_community'])
                   ? '•••• saved (leave blank to keep)'
                   : 'public' ?>"
               autocomplete="off"></div>
    <div class="form-row full power-snmp-v3" style="display:none">
        <label>SNMPv3 credential profile</label>
        <select class="form-control" name="snmp_v3_profile_id" id="power_snmp_v3_profile_id">
            <option value="">— Manual / none —</option>
            <?php foreach ($snmpProfiles as $sp): ?>
                <option value="<?= (int)$sp['profile_id'] ?>"
                        data-user="<?= App::e($sp['security_name'] ?? '') ?>"
                        data-level="<?= App::e($sp['security_level'] ?? '') ?>"
                        data-auth-proto="<?= App::e($sp['auth_protocol'] ?? '') ?>"
                        data-priv-proto="<?= App::e($sp['priv_protocol'] ?? '') ?>"
                        data-context="<?= App::e($sp['context_name'] ?? '') ?>"
                    <?= (int)($edit['snmp_v3_profile_id'] ?? 0) === (int)$sp['profile_id'] ? 'selected' : '' ?>>
                    <?= App::e($sp['name']) ?>
                    (<?= App::e($sp['security_level'] ?? '') ?> · <?= App::e($sp['security_name'] ?? '') ?>)
                </option>
            <?php endforeach; ?>
        </select>
        <p class="text-muted" style="font-size:.75rem;margin:.3rem 0 0">
            Manage profiles under <a href="<?= App::e(App::url('pages/snmp.php#profiles')) ?>">SNMP → Profiles</a>.
            Selecting a profile fills the fields below; passphrases are applied from the profile on save (not shown in the browser).
        </p>
    </div>
    <div class="form-row power-snmp-v3" style="display:none"><label>Security level</label>
        <select class="form-control" name="snmp_v3_sec_level" id="power_snmp_v3_sec_level">
            <option value="">—</option>
            <?php foreach (['noAuthNoPriv','authNoPriv','authPriv'] as $lvl): ?>
                <option value="<?= $lvl ?>" <?= ($edit['snmp_v3_sec_level'] ?? '') === $lvl ? 'selected' : '' ?>><?= $lvl ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-row power-snmp-v3" style="display:none"><label>SNMP user</label>
        <input class="form-control" name="snmp_security_name" id="power_snmp_security_name"
               value="<?= App::e($edit['snmp_security_name'] ?? '') ?>" autocomplete="off"></div>
    <div class="form-row power-snmp-v3" style="display:none"><label>Auth protocol</label>
        <select class="form-control" name="snmp_auth_protocol" id="power_snmp_auth_protocol">
            <option value="">—</option>
            <?php foreach (['SHA','SHA256','SHA384','SHA512','MD5'] as $ap): ?>
                <option value="<?= $ap ?>" <?= strtoupper((string)($edit['snmp_auth_protocol'] ?? '')) === $ap ? 'selected' : '' ?>><?= $ap ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-row power-snmp-v3" style="display:none"><label>Auth passphrase</label>
        <input class="form-control" type="password" name="snmp_auth_passphrase" id="power_snmp_auth_passphrase"
               value="" placeholder="<?= !empty($edit['snmp_auth_passphrase']) ? '•••• saved (leave blank to keep)' : '' ?>"
               autocomplete="new-password"></div>
    <div class="form-row power-snmp-v3" style="display:none"><label>Priv protocol</label>
        <select class="form-control" name="snmp_priv_protocol" id="power_snmp_priv_protocol">
            <option value="">—</option>
            <?php foreach (['AES','AES256','AES192','DES'] as $pp): ?>
                <option value="<?= $pp ?>" <?= strtoupper((string)($edit['snmp_priv_protocol'] ?? '')) === $pp ? 'selected' : '' ?>><?= $pp ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-row power-snmp-v3" style="display:none"><label>Priv passphrase</label>
        <input class="form-control" type="password" name="snmp_priv_passphrase" id="power_snmp_priv_passphrase"
               value="" placeholder="<?= !empty($edit['snmp_priv_passphrase']) ? '•••• saved (leave blank to keep)' : '' ?>"
               autocomplete="new-password"></div>
    <div class="form-row power-snmp-v3" style="display:none"><label>Context</label>
        <input class="form-control" name="snmp_context" id="power_snmp_context"
               value="<?= App::e($edit['snmp_context'] ?? '') ?>"></div>
    <div class="form-row full"><label>Notes</label>
        <textarea class="form-control" name="notes" rows="2"><?= App::e($edit['notes'] ?? '') ?></textarea></div>
    <div class="form-row">
        <button class="btn btn-primary" type="submit"><?= $isUpdate ? 'Save PDU' : 'Add PDU' ?></button>
    </div>
</form>
<script>
(function () {
    var root = document.getElementById('addPduForm');
    if (!root || root.getAttribute('data-bound')) return;
    root.setAttribute('data-bound', '1');
    var mount = root.querySelector('#power_mount_style');
    var snmpEn = root.querySelector('#power_snmp_enabled');
    var snmpVer = root.querySelector('#power_snmp_version');
    var scope = root.querySelector('#power_pdu_scope');
    var phases = root.querySelector('#power_phases');
    var wiring = root.querySelector('#power_phase_wiring');
    var inLl = root.querySelector('#power_input_voltage');
    var inLn = root.querySelector('#power_input_voltage_ln');
    var outV = root.querySelector('#power_output_voltage');
    var outLn = root.querySelector('#power_output_voltage_ln');
    var inLabel = root.querySelector('#power_in_ll_label');
    var outLabel = root.querySelector('#power_out_label');
    var hint = root.querySelector('#power_phase_hint p');
    var rowSel = root.querySelector('#power_row_id');
    var zoneSel = root.querySelector('#power_zone_id');
    var applyingPreset = false;
    var isUpdate = <?= $isUpdate ? 'true' : 'false' ?>;

    var WIRING_OPTS = {
        1: [{ v: 'single', t: 'Single-phase' }],
        2: [
            { v: 'split_phase', t: 'Split-phase (L1/L2/N · 120/240)' },
            { v: 'two_phase', t: 'Two-phase (L1/L2)' }
        ],
        3: [
            { v: 'wye', t: 'Wye / star (3P+N)' },
            { v: 'delta', t: 'Delta (3P)' }
        ]
    };
    var PRESETS = {
        '1|single': { inLl: 120, inLn: null, out: 120, outLn: null, hint: 'Single-phase: one hot + neutral.' },
        '2|split_phase': { inLl: 240, inLn: 120, out: 120, outLn: 120, hint: 'Split-phase: L1–L2 = 240 V, L–N = 120 V.' },
        '2|two_phase': { inLl: 240, inLn: null, out: 240, outLn: null, hint: 'Two-phase L1/L2. Enter L–L voltages.' },
        '3|wye': { inLl: 208, inLn: 120, out: 208, outLn: 120, hint: '3-phase wye: L–L ≈ 208 V, L–N ≈ 120 V.' },
        '3|delta': { inLl: 208, inLn: null, out: 208, outLn: null, hint: '3-phase delta: L–L voltages. Zone uses input L–L.' }
    };

    function qsa(sel) { return root.querySelectorAll(sel); }
    function toggleMount() {
        var isU = mount && mount.value === 'u_mounted';
        var isRack = !scope || scope.value === 'rack';
        qsa('.power-u-fields').forEach(function (el) { el.style.display = (isRack && isU) ? '' : 'none'; });
    }
    function toggleSnmp() {
        var on = snmpEn && snmpEn.checked;
        var v = snmpVer ? snmpVer.value : '2c';
        qsa('.power-snmp-any').forEach(function (el) { el.style.display = on ? '' : 'none'; });
        qsa('.power-snmp-v12').forEach(function (el) { el.style.display = on && (v === '1' || v === '2c') ? '' : 'none'; });
        qsa('.power-snmp-v3').forEach(function (el) { el.style.display = on && v === '3' ? '' : 'none'; });
    }
    var outMode = root.querySelector('#power_output_mode');
    /** Hide inactive mode fields and disable their inputs so HTML5 validation cannot block submit. */
    function setGroupEnabled(selector, enabled) {
        qsa(selector).forEach(function (el) {
            el.style.display = enabled ? '' : 'none';
            el.querySelectorAll('input, select, textarea').forEach(function (inp) {
                inp.disabled = !enabled;
            });
        });
    }
    function toggleOutputMode() {
        var mode = outMode ? outMode.value : 'outlets';
        var br = mode === 'breakers';
        setGroupEnabled('.power-outlet-fields', !br);
        setGroupEnabled('.power-breaker-fields', br);
        // Ensure number fields stay within min when re-enabled
        var nOut = root.querySelector('input[name="num_outlets"]');
        var nBrk = root.querySelector('input[name="num_breaker_slots"]');
        if (nOut && (!nOut.value || parseInt(nOut.value, 10) < 1)) nOut.value = '24';
        if (nBrk && (!nBrk.value || parseInt(nBrk.value, 10) < 1)) nBrk.value = '42';
    }
    function toggleScope() {
        var s = scope ? scope.value : 'rack';
        var isRack = s === 'rack';
        var isRow = s === 'row';
        qsa('.power-rack-fields').forEach(function (el) { el.style.display = isRack ? '' : 'none'; });
        qsa('.power-row-fields').forEach(function (el) { el.style.display = isRow ? '' : 'none'; });
        qsa('.power-zone-sync').forEach(function (el) { el.style.display = (s === 'row' || s === 'room') ? '' : 'none'; });
        if (!isUpdate && isRow && phases && phases.value === '1') {
            phases.value = '3';
            refreshWiringOptions(true);
        }
        // Default row PDUs toward breakers if still on first create defaults
        if (!isUpdate && isRow && outMode && outMode.value === 'outlets' && !outMode.dataset.touched) {
            // leave user choice; don't force
        }
        toggleMount();
    }
    function refreshWiringOptions(applyPreset) {
        if (!phases || !wiring) return;
        var p = parseInt(phases.value, 10) || 1;
        var opts = WIRING_OPTS[p] || WIRING_OPTS[1];
        var prev = wiring.value;
        wiring.innerHTML = '';
        opts.forEach(function (o) {
            var opt = document.createElement('option');
            opt.value = o.v; opt.textContent = o.t;
            wiring.appendChild(opt);
        });
        wiring.value = opts.some(function (o) { return o.v === prev; }) ? prev : opts[0].v;
        togglePhaseFields(applyPreset !== false);
    }
    function togglePhaseFields(applyPreset) {
        if (!phases || !wiring) return;
        var p = parseInt(phases.value, 10) || 1;
        var w = wiring.value;
        var showLn = (p === 2 && w === 'split_phase') || (p === 3 && w === 'wye');
        qsa('.power-ln-fields').forEach(function (el) { el.style.display = showLn ? '' : 'none'; });
        qsa('.power-out-ln-fields').forEach(function (el) { el.style.display = showLn ? '' : 'none'; });
        if (inLabel) inLabel.textContent = (p === 1) ? 'Input voltage' : 'Input voltage (L–L)';
        if (outLabel) outLabel.textContent = (p === 1) ? 'Output voltage' : (showLn ? 'Output voltage (L–L)' : 'Output voltage');
        var preset = PRESETS[p + '|' + w] || PRESETS['1|single'];
        if (hint) hint.textContent = preset.hint || '';
        if (applyPreset && !applyingPreset && !isUpdate) {
            applyingPreset = true;
            if (inLl) inLl.value = preset.inLl != null ? preset.inLl : '';
            if (inLn) inLn.value = preset.inLn != null ? preset.inLn : '';
            if (outV) outV.value = preset.out != null ? preset.out : '';
            if (outLn) outLn.value = preset.outLn != null ? preset.outLn : '';
            applyingPreset = false;
        }
    }
    function deriveLn() {
        if (applyingPreset || !inLl) return;
        var p = parseInt(phases.value, 10) || 1;
        var w = wiring.value;
        var ll = parseFloat(inLl.value);
        if (!ll) return;
        if (p === 2 && w === 'split_phase' && inLn) inLn.value = Math.round(ll / 2);
        if (p === 3 && w === 'wye' && inLn) inLn.value = Math.round(ll / 1.732);
    }
    if (rowSel && zoneSel) {
        rowSel.addEventListener('change', function () {
            var opt = rowSel.options[rowSel.selectedIndex];
            var z = opt && opt.getAttribute('data-zone');
            if (z && z !== '0' && !zoneSel.value) zoneSel.value = z;
        });
    }
    if (mount) mount.addEventListener('change', toggleMount);
    if (snmpEn) snmpEn.addEventListener('change', toggleSnmp);
    if (snmpVer) snmpVer.addEventListener('change', toggleSnmp);
    if (scope) scope.addEventListener('change', toggleScope);
    if (outMode) {
        outMode.addEventListener('change', function () {
            outMode.dataset.touched = '1';
            toggleOutputMode();
        });
    }
    if (phases) phases.addEventListener('change', function () { refreshWiringOptions(true); });
    if (wiring) wiring.addEventListener('change', function () { togglePhaseFields(true); });
    if (inLl) inLl.addEventListener('change', deriveLn);

    // SNMPv3 profile → fill non-secret fields (passphrases applied server-side on save)
    var snmpProf = root.querySelector('#power_snmp_v3_profile_id');
    if (snmpProf) {
        snmpProf.addEventListener('change', function () {
            var opt = snmpProf.options[snmpProf.selectedIndex];
            if (!opt || !opt.value) return;
            var setVal = function (sel, v) {
                var el = root.querySelector(sel);
                if (el && v != null && v !== '') el.value = v;
            };
            setVal('#power_snmp_security_name', opt.getAttribute('data-user'));
            setVal('#power_snmp_v3_sec_level', opt.getAttribute('data-level'));
            setVal('#power_snmp_auth_protocol', opt.getAttribute('data-auth-proto'));
            setVal('#power_snmp_priv_protocol', opt.getAttribute('data-priv-proto'));
            setVal('#power_snmp_context', opt.getAttribute('data-context') || '');
            // Clear visible pass fields — profile secrets applied on save from DB
            var ap = root.querySelector('#power_snmp_auth_passphrase');
            var pp = root.querySelector('#power_snmp_priv_passphrase');
            if (ap) { ap.value = ''; ap.placeholder = 'Applied from profile on save'; }
            if (pp) { pp.value = ''; pp.placeholder = 'Applied from profile on save'; }
            if (snmpVer) snmpVer.value = '3';
            toggleSnmp();
        });
    }

    toggleScope();
    toggleOutputMode();
    refreshWiringOptions(false);
    toggleSnmp();
})();
</script>
