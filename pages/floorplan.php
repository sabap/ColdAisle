<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/App.php';
require_once dirname(__DIR__) . '/includes/layout.php';
App::boot();
$user = App::requirePermission('view_floorplan');
// Mutations go through api/floorplan.php (gated there)

$rooms = Database::fetchAll(
    'SELECT r.room_id, r.name, r.width_m, r.depth_m, dc.name AS dc_name
     FROM rooms r
     INNER JOIN datacenters dc ON dc.datacenter_id = r.datacenter_id
     WHERE r.is_active = 1
     ORDER BY dc.name, r.name'
);

$units = SettingsService::get('length_units', 'metric');
if ($units !== 'imperial') {
    $units = 'metric';
}

layout_header('Floor Planner', $user, 'floorplan');
?>

<div class="card" id="floorplanner">
    <div class="planner-toolbar">
        <label class="text-muted" style="font-size:.85rem">Room</label>
        <select id="roomSelect" class="form-control" style="width:auto;min-width:220px">
            <?php if (!$rooms): ?>
                <option value="">No rooms — create one under Data Centers</option>
            <?php endif; ?>
            <?php foreach ($rooms as $r): ?>
                <option value="<?= (int)$r['room_id'] ?>">
                    <?= App::e($r['dc_name'] . ' / ' . $r['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button type="button" class="btn btn-primary btn-sm" id="btnAddCab">+ Cabinet</button>
        <button type="button" class="btn btn-secondary btn-sm" id="btnEditRoom" title="Edit room size, grid, and North">Edit Room / North</button>
        <button type="button" class="btn btn-secondary btn-sm" id="toggleUnits" title="Toggle metric / standard (imperial)">
            <?= $units === 'imperial' ? 'Units: ft / in' : 'Units: m / mm' ?>
        </button>
        <button type="button" class="btn btn-primary btn-sm" id="toggleGrid" title="Show 1 ft grid on the floor">Grid: On</button>
        <button type="button" class="btn btn-primary btn-sm" id="toggleSnap" title="Snap cabinets to grid when placing or moving">Snap: On</button>
        <span class="nudge-controls" title="Arrow keys nudge selected (unlocked) cabinets">
            <label class="text-muted" style="font-size:.8rem;margin:0">Nudge</label>
            <input type="number" id="nudgeAmount" class="form-control" style="width:4.2rem;padding:.2rem .35rem;font-size:.85rem" min="0.01" step="any" value="1">
            <select id="nudgeUnit" class="form-control" style="width:auto;min-width:3.5rem;padding:.2rem .35rem;font-size:.85rem">
                <option value="in" selected>in</option>
                <option value="ft">ft</option>
                <option value="mm">mm</option>
                <option value="cm">cm</option>
                <option value="m">m</option>
            </select>
        </span>
        <button type="button" class="btn btn-secondary btn-sm" id="btnZoomOut" title="Zoom out">−</button>
        <span id="zoomLabel" class="text-muted" style="font-size:.8rem;min-width:2.5rem;text-align:center">100%</span>
        <button type="button" class="btn btn-secondary btn-sm" id="btnZoomIn" title="Zoom in">+</button>
        <button type="button" class="btn btn-secondary btn-sm" id="btnZoomReset" title="Reset zoom">Reset</button>
        <button type="button" class="btn btn-secondary btn-sm" id="toggle3d">3D View</button>
        <span class="text-muted" style="font-size:.8rem;margin-left:auto">
            SHIFT+click multi-select · Arrows nudge · Drag floor to pan · Scroll zoom
        </span>
    </div>
    <div class="planner-layout">
        <div class="planner-palette">
            <h3 style="margin-top:0;font-size:.95rem">Cabinet Templates</h3>
            <div class="form-row" style="margin-bottom:.65rem">
                <label style="font-size:.8rem">Vendor</label>
                <select id="vendorSelect" class="form-control" style="font-size:.85rem">
                    <option value="all">All vendors</option>
                    <!-- filled by rack-catalog.js -->
                </select>
            </div>
            <div id="paletteList" class="palette-list">
                <!-- populated from vendor catalog -->
            </div>
            <p class="text-muted" style="font-size:.75rem;margin-top:.75rem;margin-bottom:.85rem">
                Catalog uses published <strong>external</strong> W×D footprints. Set <strong>Front faces</strong> after placing.
            </p>

            <h3 style="margin-top:0;font-size:.95rem">Row / room power</h3>
            <p class="text-muted" style="font-size:.72rem;margin:.25rem 0 .5rem">
                Place a footprint (creates a new row PDU) or drag an existing unplaced PDU onto the plan.
            </p>
            <div id="pduPresetList" class="palette-list palette-list-compact">
                <!-- footprint presets filled by floorplan.js -->
            </div>
            <h4 style="margin:.85rem 0 .35rem;font-size:.82rem;color:var(--muted)">Unplaced PDUs</h4>
            <div id="pduUnplacedList" class="palette-list palette-list-compact">
                <p class="text-muted" style="font-size:.75rem;margin:0">Load a room to see unplaced row PDUs.</p>
            </div>
        </div>
        <div class="planner-stage">
            <div class="planner-canvas-wrap" id="plannerCanvasWrap">
                <canvas id="planner-canvas"></canvas>
            </div>
            <div id="view3d" style="display:none;flex:1;min-height:0;background:#0a0f18"></div>
        </div>
        <div class="planner-props" id="planner-props">
            <p class="text-muted">Select a cabinet or drop one from the palette. With nothing selected you can edit room size, grid, and North.</p>
        </div>
    </div>
</div>

<script>
  window.WINDCIM = window.WINDCIM || {};
  window.WINDCIM.lengthUnits = <?= json_encode($units) ?>;
</script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js"></script>
<script src="<?= App::e(App::url('assets/js/dcim-3d.js')) ?>?v=3"></script>
<script src="<?= App::e(App::url('assets/js/rack-catalog.js')) ?>?v=1"></script>
<script src="<?= App::e(App::url('assets/js/floorplan.js')) ?>?v=19"></script>
<?php layout_footer(); ?>
