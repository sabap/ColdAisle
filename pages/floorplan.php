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
        <span class="nudge-controls" title="Arrow keys nudge selected (unlocked) cabinets, PDUs, UPS, or cooling units">
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
        <button type="button" class="btn btn-secondary btn-sm" id="toggleRaceways" title="Show cable raceways / fiber troughs">Raceways: On</button>
        <button type="button" class="btn btn-secondary btn-sm" id="btnDrawRaceway" title="Click points on the floor; double-click or Finish to save">Draw raceway</button>
        <button type="button" class="btn btn-secondary btn-sm" id="btnUndoRacewayPt" hidden title="Remove last vertex (Backspace)">Undo point</button>
        <button type="button" class="btn btn-secondary btn-sm" id="btnClearRacewayPts" hidden title="Clear all draft vertices; stay in draw mode">Clear points</button>
        <button type="button" class="btn btn-primary btn-sm" id="btnFinishRaceway" hidden title="Save polyline">Finish path</button>
        <button type="button" class="btn btn-ghost btn-sm" id="btnCancelRaceway" hidden title="Exit raceway draw mode">Exit draw</button>
        <button type="button" class="btn btn-danger btn-sm" id="btnDeleteRaceway" hidden title="Delete selected saved raceway">Delete path</button>
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

            <h3 style="margin-top:1rem;font-size:.95rem">Cooling</h3>
            <p class="text-muted" style="font-size:.72rem;margin:.25rem 0 .5rem">
                Place CRAC/CRAH, in-row, or pump footprints, or drag existing unplaced units.
            </p>
            <div id="coolingPresetList" class="palette-list palette-list-compact">
                <!-- cooling presets filled by floorplan.js -->
            </div>
            <h4 style="margin:.85rem 0 .35rem;font-size:.82rem;color:var(--muted)">Unplaced cooling</h4>
            <div id="coolingUnplacedList" class="palette-list palette-list-compact">
                <p class="text-muted" style="font-size:.75rem;margin:0">Load a room to see unplaced units.</p>
            </div>

            <h3 style="margin-top:1rem;font-size:.95rem">UPS</h3>
            <p class="text-muted" style="font-size:.72rem;margin:.25rem 0 .5rem">
                In-row Symmetra / UPS frames for floor + 3D. In-rack is inventory-only by default.
            </p>
            <div id="upsPresetList" class="palette-list palette-list-compact"></div>
            <h4 style="margin:.85rem 0 .35rem;font-size:.82rem;color:var(--muted)">Unplaced UPS</h4>
            <div id="upsUnplacedList" class="palette-list palette-list-compact">
                <p class="text-muted" style="font-size:.75rem;margin:0">Load a room to see unplaced UPS units.</p>
            </div>
        </div>
        <div class="planner-stage">
            <div class="planner-canvas-wrap" id="plannerCanvasWrap">
                <canvas id="planner-canvas"></canvas>
            </div>
            <div id="view3d" style="display:none;flex:1;min-height:0;background:#0a0f18"></div>
            <!-- Viewport-fixed tips (not drawn on canvas — survives pan/zoom) -->
            <div id="plannerHud" class="planner-hud" hidden role="status" aria-live="polite"></div>
        </div>
        <div class="planner-props" id="planner-props">
            <p class="text-muted">Select a cabinet or drop one from the palette. With nothing selected you can edit room size, grid, and North.</p>
        </div>
    </div>
</div>

<!-- Raceway finish dialog (pathway code, type, OH/UF) -->
<div class="modal-overlay modal-overlay-glass" id="racewayFinishModal" hidden>
    <div class="modal-panel modal-panel-glass" role="dialog" aria-modal="true" aria-labelledby="rwFinishTitle" style="max-width:28rem">
        <div class="modal-header">
            <h2 id="rwFinishTitle">Finish raceway</h2>
            <button type="button" class="modal-close" id="rwFinishCancel" aria-label="Close">&times;</button>
        </div>
        <div class="modal-body form-grid" style="gap:.65rem">
            <div class="form-row full">
                <label for="rwSegClass">Segment class</label>
                <select class="form-control" id="rwSegClass">
                    <option value="rs">RS — row span (along row)</option>
                    <option value="orc">ORC — outer row connector</option>
                    <option value="irc">IRC — inner row connector</option>
                    <option value="custom">Custom code</option>
                </select>
            </div>
            <div class="form-row full" id="rwRowPairWrap">
                <label for="rwRowPair" id="rwRowPairLabel">Row letter (A–Z)</label>
                <input class="form-control" id="rwRowPair" value="A" placeholder="A or AB">
            </div>
            <div class="form-row full">
                <label for="rwPathCode">Pathway code *</label>
                <input class="form-control" id="rwPathCode" required placeholder="RS-A / ORC-AB.1">
                <p class="text-muted" style="font-size:.75rem;margin:.25rem 0 0">Unique in this room (TIA-606 style pathway ID).</p>
            </div>
            <div class="form-row">
                <label for="rwPathKind">Raceway type *</label>
                <select class="form-control" id="rwPathKind">
                    <option value="ladder">Ladder tray</option>
                    <option value="fiber_raceway" selected>Fiber raceway</option>
                    <option value="conduit">Conduit</option>
                </select>
            </div>
            <div class="form-row">
                <label for="rwFeed">Cabinet feed *</label>
                <select class="form-control" id="rwFeed">
                    <option value="overhead">Overhead</option>
                    <option value="underfloor">Underfloor (raised floor)</option>
                    <option value="both">Both / mixed drops</option>
                    <option value="horizontal">Horizontal only</option>
                </select>
            </div>
            <div class="form-row">
                <label for="rwMedia">Media class</label>
                <select class="form-control" id="rwMedia">
                    <option value="fiber" selected>Fiber</option>
                    <option value="copper">Copper</option>
                    <option value="mixed">Mixed</option>
                    <option value="power">Power</option>
                </select>
            </div>
            <div class="form-row">
                <label for="rwWidth">Width <span class="text-muted" id="rwWidthUnit">(m)</span></label>
                <input class="form-control" type="number" step="0.01" min="0.03" id="rwWidth" value="0.30" title="Tray / trough width for 3D">
            </div>
            <div class="form-row">
                <label for="rwElev">Elevation AFF <span class="text-muted" id="rwElevUnit">(m)</span></label>
                <input class="form-control" type="number" step="0.05" id="rwElev" value="2.70" title="Height above finished floor for 3D (underfloor: negative)">
                <p class="text-muted" style="font-size:.72rem;margin:.2rem 0 0">Typical overhead tray ~2.7 m; underfloor ~−0.3 m.</p>
            </div>
            <div class="form-row full">
                <label for="rwDisplayName">Display name (optional)</label>
                <input class="form-control" id="rwDisplayName" placeholder="Defaults to pathway code">
            </div>
            <div class="form-row full">
                <label for="rwNotes">Notes</label>
                <input class="form-control" id="rwNotes" placeholder="Optional">
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" id="rwFinishCancel2">Back to drawing</button>
            <button type="button" class="btn btn-primary" id="rwFinishSave">Save raceway</button>
        </div>
    </div>
</div>
<style>
  body.raceway-draw-mode .planner-palette { opacity: 0.35; pointer-events: none; }
  body.raceway-draw-mode #planner-canvas { cursor: crosshair; }
  .planner-stage { position: relative; }
  .planner-hud {
    position: absolute;
    left: 10px;
    right: 10px;
    bottom: 10px;
    z-index: 5;
    pointer-events: none;
    max-width: min(42rem, calc(100% - 20px));
    padding: .55rem .75rem;
    border-radius: 10px;
    background: rgba(15, 23, 42, 0.92);
    border: 1px solid rgba(56, 189, 248, 0.35);
    box-shadow: 0 6px 20px rgba(0,0,0,.35);
    color: #e2e8f0;
    font-size: .8rem;
    line-height: 1.45;
  }
  .planner-hud strong { color: #7dd3fc; }
  .planner-hud kbd {
    display: inline-block;
    padding: .05rem .35rem;
    border-radius: 4px;
    background: rgba(148,163,184,.2);
    border: 1px solid rgba(148,163,184,.35);
    font-size: .72rem;
    font-family: inherit;
  }
  .planner-hud .hud-title { font-weight: 700; color: #fbbf24; margin: 0 0 .3rem; font-size: .85rem; }
  .planner-hud ul { margin: .2rem 0 0; padding-left: 1.15rem; }
  .planner-hud li { margin: .15rem 0; }
</style>

<script>
  window.ColdAisle = window.ColdAisle || {};
  window.ColdAisle.lengthUnits = <?= json_encode($units) ?>;
</script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js"></script>
<script src="<?= App::e(App::url('assets/js/dcim-3d.js')) ?>?v=18"></script>
<script src="<?= App::e(App::url('assets/js/rack-catalog.js')) ?>?v=1"></script>
<script src="<?= App::e(App::url('assets/js/floorplan.js')) ?>?v=35"></script>
<script>
(function () {
  var c2 = document.getElementById('rwFinishCancel2');
  if (c2) {
    c2.addEventListener('click', function () {
      var m = document.getElementById('racewayFinishModal');
      if (m) m.hidden = true;
    });
  }
})();
</script>
<?php layout_footer(); ?>
