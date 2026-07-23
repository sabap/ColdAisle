/**
 * ColdAisle - 2D floor planner
 * - Metric / imperial display
 * - Configurable grid (default 1 ft) + snap-to-grid
 * - Cabinet front/rear + compass facing
 * - Scroll-wheel zoom
 * - Data-center North edge (top/right/bottom/left of plan)
 *
 * Storage: meters for room/positions, mm for cabinet W/D, front_facing N/S/E/W.
 */
(function () {
  'use strict';

  const BASE_SCALE = 40; // px per meter at zoom=1
  const ORIGIN = 28; // room origin padding (px)
  const M_PER_FT = 0.3048;
  const MM_PER_IN = 25.4;
  const DRAG_MIME = 'application/x-coldaisle-cabinet';
  const DRAG_TEXT_PREFIX = 'COLDAISLE_CAB:';
  const DRAG_MIME_PDU = 'application/x-coldaisle-pdu';
  const DRAG_TEXT_PREFIX_PDU = 'COLDAISLE_PDU:';

  /** Thin footprint presets for row/room power (not cabinet SKUs). */
  const PDU_FOOTPRINT_PRESETS = [
    { key: 'rpp', name: 'Floor RPP / panel', width_mm: 600, depth_mm: 300, height_mm: 1800, color_hex: '#b45309', pdu_scope: 'row' },
    { key: 'busway', name: 'Busway drop box', width_mm: 300, depth_mm: 300, height_mm: 400, color_hex: '#a16207', pdu_scope: 'row' },
    { key: 'wall', name: 'Wall panel', width_mm: 800, depth_mm: 200, height_mm: 1200, color_hex: '#92400e', pdu_scope: 'row' },
  ];

  function initPlanner(root) {
    const canvas = root.querySelector('#planner-canvas');
    const ctx = canvas.getContext('2d');
    const roomSelect = root.querySelector('#roomSelect');
    const propsEl = root.querySelector('#planner-props');
    const view3d = root.querySelector('#view3d');
    const mode3dBtn = root.querySelector('#toggle3d');
    const unitsBtn = root.querySelector('#toggleUnits');
    const roomSizeBtn = root.querySelector('#btnEditRoom');
    const gridBtn = root.querySelector('#toggleGrid');
    const snapBtn = root.querySelector('#toggleSnap');
    const zoomLabel = root.querySelector('#zoomLabel');

    let room = null;
    let cabinets = [];
    let floorPdus = []; // placed row/room PDUs on this room
    let unplacedPdus = []; // available to place
    let roomRows = []; // cabinet_rows for current room
    let powerZones = []; // zones for DC (row → zone)
    let selectedId = null; // primary cabinet selection (props panel focus)
    let selectedPduId = null; // selected floor PDU (exclusive with cabinets)
    const selectedIds = new Set(); // multi-select cabinets (SHIFT+click)
    let drag = null;
    let show3d = false;
    let view3dInstance = null;
    let units = (window.ColdAisle && window.ColdAisle.lengthUnits) || 'metric';
    let pendingTemplate = null; // cabinet template
    let pendingPdu = null; // { kind: 'preset'|'existing', ... }
    let zoom = 1;
    let showGrid = true;
    let snapToGrid = true;
    let gridFt = 1; // grid cell size in feet (also used when display is metric)
    let northEdge = 'top'; // which plan edge is geographic North
    let anchorId = null; // cabinet_id used as front-alignment anchor
    /** Cabinet IDs whose floor position may be dragged / snap-moved. All others are locked. */
    const unlockedIds = new Set();
    /** PDU IDs unlocked for drag/move on the plan. */
    const unlockedPduIds = new Set();
    const DRAG_THRESHOLD_PX = 6; // ignore tiny pointer jitter when unlocked
    let pan = null; // { lastX, lastY, pointerId } — drag empty floor to scroll view
    let nudgeAmount = 1;
    let nudgeUnit = 'in'; // in | ft | mm | cm | m

    // --- units ---
    function isImperial() { return units === 'imperial'; }
    function mToDisplay(m) {
      m = Number(m) || 0;
      return isImperial() ? m / M_PER_FT : m;
    }
    function displayToM(v) {
      v = Number(v) || 0;
      return isImperial() ? v * M_PER_FT : v;
    }
    function mmToDisplay(mm) {
      mm = Number(mm) || 0;
      return isImperial() ? mm / MM_PER_IN : mm;
    }
    function displayToMm(v) {
      v = Number(v) || 0;
      return isImperial() ? Math.round(v * MM_PER_IN) : Math.round(v);
    }
    function lengthLabel() { return isImperial() ? 'ft' : 'm'; }
    function sizeLabel() { return isImperial() ? 'in' : 'mm'; }
    function fmtLen(m, digits) {
      // Position fields need extra precision so Save does not destroy front-align /
      // adjacent-snap offsets (2 dp in feet is only ~3 mm — still not enough for round-trip).
      const d = digits != null ? digits : 4;
      return mToDisplay(m).toFixed(d);
    }
    function fmtSize(mm) {
      const v = mmToDisplay(mm);
      return isImperial() ? v.toFixed(1) : String(Math.round(v));
    }

    function roomW() { return Number(room && room.width_m) || 20; }
    function roomD() { return Number(room && room.depth_m) || 15; }
    function roomId() { return room ? Number(room.room_id) : 0; }
    function scale() { return BASE_SCALE * zoom; }

    /** Grid step in meters */
    function gridStepM() {
      const ft = gridFt > 0 ? gridFt : 1;
      return ft * M_PER_FT;
    }

    function snapScalar(v, force) {
      if (!snapToGrid && !force) return v;
      const g = gridStepM();
      return Math.round(v / g) * g;
    }

    /**
     * Local size (unrotated) and axis-aligned footprint after rotation.
     * pos_x/pos_y is the top-left of the *unrotated* local box; draw rotates around its center.
     * After rotation, visual AABB may differ when width ≠ depth — snap must use that AABB.
     */
    function cabGeom(cOrOpts) {
      const o = cOrOpts || {};
      const w = (Number(o.width_mm) || 600) / 1000;
      const d = (Number(o.depth_mm) || 1200) / 1000;
      let deg;
      if (o.front_facing) {
        deg = facingToRotation(o.front_facing);
      } else if (o.rotation_deg != null && o.rotation_deg !== '') {
        deg = Number(o.rotation_deg) || 0;
      } else if (o.cabinet_id != null) {
        deg = cabRotation(o);
      } else {
        deg = 0;
      }
      const rad = (deg * Math.PI) / 180;
      const cos = Math.abs(Math.cos(rad));
      const sin = Math.abs(Math.sin(rad));
      // AABB of rectangle rotated around its center
      const aabbW = w * cos + d * sin;
      const aabbH = w * sin + d * cos;
      return { localW: w, localD: d, aabbW: aabbW, aabbH: aabbH, rotation: deg };
    }

    function centerFromPos(posX, posY, geom) {
      return {
        cx: posX + geom.localW / 2,
        cy: posY + geom.localD / 2,
      };
    }

    function posFromCenter(cx, cy, geom) {
      return {
        x: cx - geom.localW / 2,
        y: cy - geom.localD / 2,
      };
    }

    function aabbFromPos(posX, posY, geom) {
      const c = centerFromPos(posX, posY, geom);
      return {
        left: c.cx - geom.aabbW / 2,
        top: c.cy - geom.aabbH / 2,
        right: c.cx + geom.aabbW / 2,
        bottom: c.cy + geom.aabbH / 2,
      };
    }

    /**
     * World-space front edge midpoint + outward normal.
     * Local front is the top edge of the unrotated rect (toward -local Y).
     */
    function frontGeometry(c) {
      const geom = cabGeom(c);
      const posX = Number(c.pos_x) || 0;
      const posY = Number(c.pos_y) || 0;
      const cen = centerFromPos(posX, posY, geom);
      const rad = (geom.rotation * Math.PI) / 180;
      const d2 = geom.localD / 2;
      const sin = Math.sin(rad);
      const cos = Math.cos(rad);
      // rotate local (0, -d2)
      const fx = cen.cx + d2 * sin;
      const fy = cen.cy - d2 * cos;
      // outward normal (direction front faces)
      const nx = sin;
      const ny = -cos;
      const axis = Math.abs(nx) >= Math.abs(ny) ? 'x' : 'y';
      return {
        fx: fx,
        fy: fy,
        nx: nx,
        ny: ny,
        axis: axis,
        cx: cen.cx,
        cy: cen.cy,
        geom: geom,
        facing: (c.front_facing || rotationToFacing(geom.rotation) || 'north').toLowerCase(),
      };
    }

    /** True if two AABBs touch or nearly touch (edge gap ≤ tol) with overlap on the other axis. */
    function aabbsTouch(a, b, tol) {
      tol = tol != null ? tol : Math.max(0.02, gridStepM() * 0.15);
      const xOverlap = a.left < b.right + tol && a.right > b.left - tol;
      const yOverlap = a.top < b.bottom + tol && a.bottom > b.top - tol;
      if (!xOverlap || !yOverlap) return false;
      const gapX = Math.max(0, Math.max(a.left - b.right, b.left - a.right));
      const gapY = Math.max(0, Math.max(a.top - b.bottom, b.top - a.bottom));
      // Touching if gap on one axis is small and the other overlaps substantially
      const xSep = gapX <= tol;
      const ySep = gapY <= tol;
      const xStrong = Math.min(a.right, b.right) - Math.max(a.left, b.left) > -tol;
      const yStrong = Math.min(a.bottom, b.bottom) - Math.max(a.top, b.top) > -tol;
      return (xSep && yStrong) || (ySep && xStrong);
    }

    /**
     * Move cabinet so its front line matches target front (same facing).
     * Only slides along the depth axis (normal to the front) — side position unchanged.
     */
    function alignFrontToTarget(cab, targetFront) {
      const f = frontGeometry(cab);
      if (f.facing !== targetFront.facing) {
        return null; // different facing — skip
      }
      let ncx = f.cx;
      let ncy = f.cy;
      if (f.axis === 'y') {
        // Front is horizontal; move in Y
        ncy = f.cy + (targetFront.fy - f.fy);
      } else {
        ncx = f.cx + (targetFront.fx - f.fx);
      }
      const pos = posFromCenter(ncx, ncy, f.geom);
      const clamped = clampCabinetPosition(pos.x, pos.y, cab);
      return clamped;
    }

    /**
     * Clamp cabinet AABB inside the room without changing alignment to grid.
     * Used by Save and snap-to-adjacent so intentional off-grid positions are kept.
     */
    function clampCabinetPosition(posX, posY, cOrOpts) {
      const geom = cabGeom(cOrOpts);
      const aabb = aabbFromPos(posX, posY, geom);
      let left = Math.max(0, Math.min(Math.max(0, roomW() - geom.aabbW), aabb.left));
      let top = Math.max(0, Math.min(Math.max(0, roomD() - geom.aabbH), aabb.top));
      const cx = left + geom.aabbW / 2;
      const cy = top + geom.aabbH / 2;
      const pos = posFromCenter(cx, cy, geom);
      return {
        x: Math.round(pos.x * 1000) / 1000,
        y: Math.round(pos.y * 1000) / 1000,
      };
    }

    /**
     * Snap cabinet so its *rotated* footprint edges align to the grid.
     * force=true always snaps (for "Snap to Grid" button even if snap toggle is off).
     * Do NOT call this from Save — that undoes snap-to-adjacent rack.
     */
    function snapCabinetPosition(posX, posY, cOrOpts, force) {
      const geom = cabGeom(cOrOpts);
      const doSnap = snapToGrid || force;
      let left;
      let top;
      if (doSnap) {
        const aabb = aabbFromPos(posX, posY, geom);
        left = snapScalar(aabb.left, true);
        top = snapScalar(aabb.top, true);
      } else {
        const aabb = aabbFromPos(posX, posY, geom);
        left = aabb.left;
        top = aabb.top;
      }
      // Keep fully inside room using AABB
      left = Math.max(0, Math.min(Math.max(0, roomW() - geom.aabbW), left));
      top = Math.max(0, Math.min(Math.max(0, roomD() - geom.aabbH), top));
      if (doSnap) {
        // Re-snap after clamp so edges stay on grid when possible
        left = snapScalar(left, true);
        top = snapScalar(top, true);
        left = Math.max(0, Math.min(Math.max(0, roomW() - geom.aabbW), left));
        top = Math.max(0, Math.min(Math.max(0, roomD() - geom.aabbH), top));
      }
      const cx = left + geom.aabbW / 2;
      const cy = top + geom.aabbH / 2;
      const pos = posFromCenter(cx, cy, geom);
      return {
        x: Math.round(pos.x * 1000) / 1000,
        y: Math.round(pos.y * 1000) / 1000,
      };
    }

    /** @deprecated path — keep name used in a few call sites */
    function snapM(v) {
      return snapScalar(v, false);
    }

    function clampPos(x, y, cabWmm, cabDmm, rotOrCab) {
      const opts = {
        width_mm: cabWmm,
        depth_mm: cabDmm,
        rotation_deg: typeof rotOrCab === 'number' ? rotOrCab : (rotOrCab && rotOrCab.rotation_deg),
        front_facing: rotOrCab && rotOrCab.front_facing,
      };
      return snapCabinetPosition(x, y, opts, false);
    }

    /**
     * Map compass front_facing + northEdge → canvas rotation (degrees).
     * Local cabinet front is the top edge of the unrotated rect (-Y).
     */
    function facingToRotation(facing) {
      const f = String(facing || 'north').toLowerCase();
      const face = { north: 0, east: 90, south: 180, west: 270 };
      const northOff = { top: 0, right: 90, bottom: 180, left: 270 };
      const base = face[f] != null ? face[f] : 0;
      const off = northOff[northEdge] != null ? northOff[northEdge] : 0;
      return (base + off) % 360;
    }

    function rotationToFacing(deg) {
      const northOff = { top: 0, right: 90, bottom: 180, left: 270 };
      const off = northOff[northEdge] != null ? northOff[northEdge] : 0;
      let d = ((Number(deg) || 0) - off) % 360;
      if (d < 0) d += 360;
      // nearest cardinal
      const opts = [
        { f: 'north', a: 0 },
        { f: 'east', a: 90 },
        { f: 'south', a: 180 },
        { f: 'west', a: 270 },
      ];
      let best = opts[0];
      let bestDiff = 999;
      opts.forEach(function (o) {
        let diff = Math.abs(d - o.a);
        if (diff > 180) diff = 360 - diff;
        if (diff < bestDiff) {
          bestDiff = diff;
          best = o;
        }
      });
      return best.f;
    }

    function cabRotation(c) {
      if (c.front_facing) {
        return facingToRotation(c.front_facing);
      }
      return Number(c.rotation_deg) || 0;
    }

    function canvasPoint(e) {
      const rect = canvas.getBoundingClientRect();
      const sx = canvas.width / Math.max(rect.width, 1);
      const sy = canvas.height / Math.max(rect.height, 1);
      return {
        x: (e.clientX - rect.left) * sx,
        y: (e.clientY - rect.top) * sy,
      };
    }

    function worldFromCanvas(mx, my) {
      return {
        x: (mx - ORIGIN) / scale(),
        y: (my - ORIGIN) / scale(),
      };
    }

    function resizeCanvas() {
      const wrap = root.querySelector('.planner-canvas-wrap');
      const pad = ORIGIN * 2 + 40; // extra padding so edges are reachable when panning
      const viewW = wrap ? (wrap.clientWidth || 600) : 600;
      const viewH = wrap ? (wrap.clientHeight || 480) : 480;
      // Canvas must be at least the viewport size, and larger when room*zoom exceeds it
      // so both horizontal and vertical overflow exist for pan.
      const w = Math.max(Math.ceil(roomW() * scale() + pad), viewW);
      const h = Math.max(Math.ceil(roomD() * scale() + pad), viewH);
      canvas.width = Math.round(w);
      canvas.height = Math.round(h);
      canvas.style.width = canvas.width + 'px';
      canvas.style.height = canvas.height + 'px';
      if (zoomLabel) {
        zoomLabel.textContent = Math.round(zoom * 100) + '%';
      }
      draw();
    }

    function cabRect(c) {
      const s = scale();
      const w = (Number(c.width_mm) || 600) / 1000 * s;
      const d = (Number(c.depth_mm) || 1200) / 1000 * s;
      const x = (Number(c.pos_x) || 0) * s + ORIGIN;
      const y = (Number(c.pos_y) || 0) * s + ORIGIN;
      return { x: x, y: y, w: w, d: d };
    }

    function drawGrid() {
      if (!showGrid || !room) return;
      const s = scale();
      const g = gridStepM() * s;
      if (g < 4) return; // too dense when zoomed out

      const rw = roomW() * s;
      const rd = roomD() * s;
      ctx.save();
      ctx.beginPath();
      ctx.rect(ORIGIN, ORIGIN, rw, rd);
      ctx.clip();

      ctx.strokeStyle = 'rgba(148, 163, 184, 0.22)';
      ctx.lineWidth = 1;
      // vertical lines
      for (let x = 0; x <= rw + 0.5; x += g) {
        ctx.beginPath();
        ctx.moveTo(ORIGIN + x, ORIGIN);
        ctx.lineTo(ORIGIN + x, ORIGIN + rd);
        ctx.stroke();
      }
      // horizontal
      for (let y = 0; y <= rd + 0.5; y += g) {
        ctx.beginPath();
        ctx.moveTo(ORIGIN, ORIGIN + y);
        ctx.lineTo(ORIGIN + rw, ORIGIN + y);
        ctx.stroke();
      }

      // every 5 cells slightly stronger
      const major = g * 5;
      if (major >= 8) {
        ctx.strokeStyle = 'rgba(148, 163, 184, 0.4)';
        for (let x = 0; x <= rw + 0.5; x += major) {
          ctx.beginPath();
          ctx.moveTo(ORIGIN + x, ORIGIN);
          ctx.lineTo(ORIGIN + x, ORIGIN + rd);
          ctx.stroke();
        }
        for (let y = 0; y <= rd + 0.5; y += major) {
          ctx.beginPath();
          ctx.moveTo(ORIGIN, ORIGIN + y);
          ctx.lineTo(ORIGIN + rw, ORIGIN + y);
          ctx.stroke();
        }
      }
      ctx.restore();

      // grid legend
      ctx.fillStyle = '#64748b';
      ctx.font = '10px Segoe UI';
      ctx.textAlign = 'left';
      const cellLabel = isImperial()
        ? (gridFt + ' ft grid')
        : ((gridFt * M_PER_FT).toFixed(2) + ' m grid (' + gridFt + ' ft)');
      ctx.fillText(cellLabel + (snapToGrid ? ' · snap on' : ' · snap off'), ORIGIN, ORIGIN + rd + 16);
    }

    function drawCompass() {
      // Draw N marker on the north edge of the room
      const s = scale();
      const rw = roomW() * s;
      const rd = roomD() * s;
      const cx = ORIGIN + rw / 2;
      const cy = ORIGIN + rd / 2;
      let nx = cx;
      let ny = cy;
      let rot = 0;
      switch (northEdge) {
        case 'right':
          nx = ORIGIN + rw - 14;
          ny = cy;
          rot = 90;
          break;
        case 'bottom':
          nx = cx;
          ny = ORIGIN + rd - 14;
          rot = 180;
          break;
        case 'left':
          nx = ORIGIN + 14;
          ny = cy;
          rot = 270;
          break;
        default: // top
          nx = cx;
          ny = ORIGIN + 14;
          rot = 0;
          break;
      }

      ctx.save();
      ctx.translate(nx, ny);
      ctx.rotate((rot * Math.PI) / 180);
      // arrow pointing "up" in local space = toward north edge outward-ish
      ctx.fillStyle = '#ef4444';
      ctx.beginPath();
      ctx.moveTo(0, -10);
      ctx.lineTo(6, 4);
      ctx.lineTo(-6, 4);
      ctx.closePath();
      ctx.fill();
      ctx.fillStyle = '#fca5a5';
      ctx.font = 'bold 11px Segoe UI';
      ctx.textAlign = 'center';
      ctx.textBaseline = 'top';
      ctx.fillText('N', 0, 5);
      ctx.restore();

      // edge labels S/E/W relative to north
      const labels = compassEdgeLabels();
      ctx.fillStyle = '#64748b';
      ctx.font = 'bold 10px Segoe UI';
      ctx.textAlign = 'center';
      ctx.textBaseline = 'middle';
      ctx.fillText(labels.top, cx, ORIGIN - 10);
      ctx.fillText(labels.bottom, cx, ORIGIN + rd + 10);
      ctx.save();
      ctx.translate(ORIGIN - 12, cy);
      ctx.rotate(-Math.PI / 2);
      ctx.fillText(labels.left, 0, 0);
      ctx.restore();
      ctx.save();
      ctx.translate(ORIGIN + rw + 12, cy);
      ctx.rotate(Math.PI / 2);
      ctx.fillText(labels.right, 0, 0);
      ctx.restore();
    }

    function compassEdgeLabels() {
      // Map plan edges to compass letters given northEdge
      const order = ['N', 'E', 'S', 'W'];
      const start = { top: 0, right: 1, bottom: 2, left: 3 }[northEdge] || 0;
      // edge top gets order[start], right gets next, etc. clockwise from top
      return {
        top: order[start % 4],
        right: order[(start + 1) % 4],
        bottom: order[(start + 2) % 4],
        left: order[(start + 3) % 4],
      };
    }

    function draw() {
      ctx.clearRect(0, 0, canvas.width, canvas.height);

      // Room fill
      ctx.fillStyle = '#0b1220';
      ctx.fillRect(ORIGIN, ORIGIN, roomW() * scale(), roomD() * scale());

      drawGrid();

      // Room boundary
      ctx.strokeStyle = '#475569';
      ctx.lineWidth = 2;
      ctx.strokeRect(ORIGIN, ORIGIN, roomW() * scale(), roomD() * scale());

      ctx.fillStyle = '#94a3b8';
      ctx.font = '12px Segoe UI';
      ctx.textAlign = 'left';
      ctx.textBaseline = 'alphabetic';
      const sizeTxt = fmtLen(roomW()) + ' × ' + fmtLen(roomD()) + ' ' + lengthLabel();
      ctx.fillText((room && room.name ? room.name : 'Room') + ' (' + sizeTxt + ')', ORIGIN, 14);

      if (room) {
        drawCompass();
      }

      if (pendingTemplate || pendingPdu) {
        ctx.fillStyle = pendingPdu ? '#f59e0b' : '#3b82f6';
        ctx.font = '11px Segoe UI';
        const msg = pendingPdu
          ? 'Click on the floor to place: ' + (pendingPdu.name || 'PDU')
          : 'Click on the floor to place the selected template…';
        ctx.fillText(msg, ORIGIN, canvas.height - 10);
      }

      // Row/room PDUs under cabinets so racks paint on top when overlapping
      floorPdus.forEach(function (p) {
        drawFloorPdu(p);
      });

      cabinets.forEach(function (c) {
        const r = cabRect(c);
        const selected = isSelected(c);
        const rot = cabRotation(c);
        const facing = (c.front_facing || rotationToFacing(rot)).toUpperCase();

        ctx.save();
        ctx.translate(r.x + r.w / 2, r.y + r.d / 2);
        ctx.rotate((rot * Math.PI) / 180);

        // body
        ctx.fillStyle = c.color_hex || '#2d3748';
        ctx.strokeStyle = selected ? '#3b82f6' : '#64748b';
        ctx.lineWidth = selected ? 3 : 1;
        ctx.fillRect(-r.w / 2, -r.d / 2, r.w, r.d);
        ctx.strokeRect(-r.w / 2, -r.d / 2, r.w, r.d);

        // FRONT edge (local top) — blue + label
        ctx.fillStyle = '#3b82f6';
        ctx.fillRect(-r.w / 2, -r.d / 2, r.w, Math.max(4, 5 * zoom));
        ctx.fillStyle = '#93c5fd';
        ctx.font = 'bold ' + Math.max(8, Math.round(9 * Math.min(zoom, 1.5))) + 'px Segoe UI';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'top';
        ctx.fillText('FRONT', 0, -r.d / 2 + 5);

        // REAR edge (local bottom) — amber
        ctx.fillStyle = '#b45309';
        ctx.fillRect(-r.w / 2, r.d / 2 - Math.max(3, 4 * zoom), r.w, Math.max(3, 4 * zoom));
        ctx.fillStyle = '#fcd34d';
        ctx.textBaseline = 'bottom';
        ctx.fillText('REAR', 0, r.d / 2 - 3);

        // name / U / facing / row
        ctx.fillStyle = '#e2e8f0';
        ctx.font = 'bold 11px Segoe UI';
        ctx.textBaseline = 'middle';
        ctx.fillText(String(c.name || '').slice(0, 10), 0, -2);
        ctx.font = '9px Segoe UI';
        ctx.fillStyle = '#94a3b8';
        const rowTag = c.row_name ? (' · ' + String(c.row_name).slice(0, 8)) : '';
        ctx.fillText((c.u_height || 42) + 'U · ' + facing + rowTag, 0, 12);

        // Anchor marker
        if (Number(anchorId) === Number(c.cabinet_id)) {
          ctx.fillStyle = '#f59e0b';
          ctx.font = 'bold 14px Segoe UI';
          ctx.fillText('⚓', 0, -r.d / 2 + 18);
        }
        // Unlock marker (selected + unlocked)
        if (selected && !isPositionLocked(c)) {
          ctx.fillStyle = '#22c55e';
          ctx.font = 'bold 12px Segoe UI';
          ctx.fillText('🔓', r.w / 2 - 8, -r.d / 2 + 14);
        }

        ctx.restore();
      });
    }

    function pduRect(p) {
      return cabRect({
        width_mm: p.width_mm || 600,
        depth_mm: p.depth_mm || 300,
        pos_x: p.pos_x,
        pos_y: p.pos_y,
      });
    }

    function pduRotation(p) {
      if (p.front_facing) return facingToRotation(p.front_facing);
      return Number(p.rotation_deg) || 0;
    }

    function drawFloorPdu(p) {
      const r = pduRect(p);
      const selected = Number(selectedPduId) === Number(p.pdu_id);
      const rot = pduRotation(p);
      const body = p.color_hex || p.zone_color || '#b45309';
      const border = selected ? '#fbbf24' : (p.zone_color || '#f59e0b');

      ctx.save();
      ctx.translate(r.x + r.w / 2, r.y + r.d / 2);
      ctx.rotate((rot * Math.PI) / 180);

      ctx.fillStyle = body;
      ctx.globalAlpha = 0.92;
      ctx.strokeStyle = border;
      ctx.lineWidth = selected ? 3 : 1.5;
      ctx.fillRect(-r.w / 2, -r.d / 2, r.w, r.d);
      ctx.globalAlpha = 1;
      ctx.strokeRect(-r.w / 2, -r.d / 2, r.w, r.d);

      // Front strip (power)
      ctx.fillStyle = '#fbbf24';
      ctx.fillRect(-r.w / 2, -r.d / 2, r.w, Math.max(3, 4 * zoom));

      ctx.fillStyle = '#fff7ed';
      ctx.font = 'bold ' + Math.max(9, Math.round(10 * Math.min(zoom, 1.4))) + 'px Segoe UI';
      ctx.textAlign = 'center';
      ctx.textBaseline = 'middle';
      ctx.fillText('⚡', 0, -4);
      ctx.font = 'bold ' + Math.max(8, Math.round(9 * Math.min(zoom, 1.3))) + 'px Segoe UI';
      ctx.fillText(String(p.name || 'PDU').slice(0, 12), 0, 10);
      ctx.font = Math.max(7, Math.round(8 * Math.min(zoom, 1.2))) + 'px Segoe UI';
      ctx.fillStyle = '#fde68a';
      const sub = (p.pdu_scope || 'row') + (p.zone_name ? ' · ' + String(p.zone_name).slice(0, 8) : '');
      ctx.fillText(sub, 0, 22);

      if (selected && !isPduPositionLocked(p)) {
        ctx.fillStyle = '#22c55e';
        ctx.font = 'bold 11px Segoe UI';
        ctx.fillText('🔓', r.w / 2 - 8, -r.d / 2 + 12);
      }
      ctx.restore();
    }

    /**
     * @returns {{type:'cabinet', obj:object}|{type:'pdu', obj:object}|null}
     */
    function hitTest(mx, my) {
      // Cabinets first (drawn on top)
      for (let i = cabinets.length - 1; i >= 0; i--) {
        const r = cabRect(cabinets[i]);
        if (mx >= r.x && mx <= r.x + r.w && my >= r.y && my <= r.y + r.d) {
          return { type: 'cabinet', obj: cabinets[i] };
        }
      }
      for (let i = floorPdus.length - 1; i >= 0; i--) {
        const r = pduRect(floorPdus[i]);
        if (mx >= r.x && mx <= r.x + r.w && my >= r.y && my <= r.y + r.d) {
          return { type: 'pdu', obj: floorPdus[i] };
        }
      }
      return null;
    }

    function isPduPositionLocked(pOrId) {
      if (pOrId == null) return true;
      const id = typeof pOrId === 'object' ? Number(pOrId.pdu_id) : Number(pOrId);
      if (!id) return true;
      return !unlockedPduIds.has(id);
    }

    function setPduPositionLocked(pOrId, locked) {
      const id = typeof pOrId === 'object' ? Number(pOrId.pdu_id) : Number(pOrId);
      if (!id) return;
      if (locked) unlockedPduIds.delete(id);
      else unlockedPduIds.add(id);
    }

    function selectPdu(p) {
      selectedIds.clear();
      selectedId = null;
      selectedPduId = p ? Number(p.pdu_id) : null;
      renderProps();
      draw();
      updateCanvasCursor();
    }

    function isSelected(cOrId) {
      if (cOrId == null) return false;
      const id = typeof cOrId === 'object' ? Number(cOrId.cabinet_id) : Number(cOrId);
      return selectedIds.has(id);
    }

    function getSelectedCabinets() {
      return cabinets.filter(function (c) { return selectedIds.has(Number(c.cabinet_id)); });
    }

    /**
     * @param {object|null} c
     * @param {{additive?: boolean}} [opts] additive = SHIFT+click multi-select
     */
    function selectCabinet(c, opts) {
      opts = opts || {};
      const additive = !!opts.additive;
      selectedPduId = null; // exclusive with PDU selection
      if (!c) {
        if (!additive) {
          selectedIds.clear();
          selectedId = null;
        }
        renderProps();
        draw();
        updateCanvasCursor();
        return;
      }
      const id = Number(c.cabinet_id);
      if (additive) {
        if (selectedIds.has(id)) {
          selectedIds.delete(id);
          if (Number(selectedId) === id) {
            selectedId = selectedIds.size ? Array.from(selectedIds)[selectedIds.size - 1] : null;
          }
        } else {
          selectedIds.add(id);
          selectedId = id;
        }
      } else {
        selectedIds.clear();
        selectedIds.add(id);
        selectedId = id;
      }
      renderProps();
      draw();
      updateCanvasCursor();
    }

    function nudgeStepMeters() {
      const a = Math.abs(Number(nudgeAmount));
      if (!(a > 0)) return 0;
      switch (String(nudgeUnit || 'in')) {
        case 'mm': return a / 1000;
        case 'cm': return a / 100;
        case 'm': return a;
        case 'ft': return a * M_PER_FT;
        case 'in':
        default: return a * 0.0254;
      }
    }

    /**
     * Nudge selected unlocked cabinets or a floor PDU by (dx, dy) in plan meters.
     * dirX/dirY are -1, 0, or 1 (plan axes: +X right, +Y down).
     */
    function nudgeSelected(dirX, dirY) {
      const step = nudgeStepMeters();
      if (!(step > 0)) {
        ColdAisle.toast('Set a nudge distance greater than zero', 'error');
        return;
      }
      const dx = (dirX || 0) * step;
      const dy = (dirY || 0) * step;
      const unitLabel = nudgeAmount + ' ' + nudgeUnit;

      // Floor PDU selection takes priority when active
      if (selectedPduId) {
        const p = floorPdus.find(function (x) { return Number(x.pdu_id) === Number(selectedPduId); });
        if (!p) {
          ColdAisle.toast('Select a PDU or cabinet first', 'error');
          return;
        }
        if (isPduPositionLocked(p)) {
          ColdAisle.toast('PDU is locked — Unlock position first', 'error');
          return;
        }
        const cl = clampCabinetPosition(
          (Number(p.pos_x) || 0) + dx,
          (Number(p.pos_y) || 0) + dy,
          p
        );
        p.pos_x = cl.x;
        p.pos_y = cl.y;
        const xEl = propsEl.querySelector('#fp_x');
        const yEl = propsEl.querySelector('#fp_y');
        if (xEl) xEl.value = fmtLen(p.pos_x);
        if (yEl) yEl.value = fmtLen(p.pos_y);
        draw();
        ColdAisle.toast('Nudged PDU by ' + unitLabel + ' — Save to keep', 'info');
        return;
      }

      const sel = getSelectedCabinets();
      if (!sel.length) {
        ColdAisle.toast('Select a cabinet or floor PDU first', 'error');
        return;
      }
      const unlocked = sel.filter(function (c) { return !isPositionLocked(c); });
      if (!unlocked.length) {
        ColdAisle.toast('Selected cabinet(s) are locked — Unlock position first', 'error');
        return;
      }
      unlocked.forEach(function (c) {
        const nx = (Number(c.pos_x) || 0) + dx;
        const ny = (Number(c.pos_y) || 0) + dy;
        // Soft clamp only — do not grid-snap nudges
        const cl = clampCabinetPosition(nx, ny, c);
        c.pos_x = cl.x;
        c.pos_y = cl.y;
      });
      const primary = cabinets.find(function (x) { return Number(x.cabinet_id) === Number(selectedId); });
      if (primary) syncPosFieldsFromCabinet(primary);
      draw();
      // Keep multi-select props panel so Save & lock stays visible after nudge
      if (sel.length > 1) {
        renderMultiProps(sel);
      }
      ColdAisle.toast(
        'Nudged ' + unlocked.length + ' cabinet(s) by ' + unitLabel +
          (sel.length > unlocked.length ? ' (' + (sel.length - unlocked.length) + ' locked skipped)' : '') +
          ' — click Save & lock positions to keep',
        'info'
      );
    }

    function loadNudgePrefs() {
      try {
        const a = localStorage.getItem('coldaisle_nudge_amount') || localStorage.getItem('windcim_nudge_amount');
        const u = localStorage.getItem('coldaisle_nudge_unit') || localStorage.getItem('windcim_nudge_unit');
        if (a != null && a !== '' && !isNaN(Number(a))) nudgeAmount = Number(a);
        if (u && ['in', 'ft', 'mm', 'cm', 'm'].indexOf(u) >= 0) nudgeUnit = u;
      } catch (e) { /* ignore */ }
      const amtEl = root.querySelector('#nudgeAmount');
      const unitEl = root.querySelector('#nudgeUnit');
      if (amtEl) amtEl.value = String(nudgeAmount);
      if (unitEl) unitEl.value = nudgeUnit;
    }

    function saveNudgePrefs() {
      try {
        localStorage.setItem('coldaisle_nudge_amount', String(nudgeAmount));
        localStorage.setItem('coldaisle_nudge_unit', String(nudgeUnit));
      } catch (e) { /* ignore */ }
    }

    function isPositionLocked(cOrId) {
      if (cOrId == null) return true;
      const id = typeof cOrId === 'object' ? Number(cOrId.cabinet_id) : Number(cOrId);
      if (!id) return true;
      return !unlockedIds.has(id);
    }

    function setPositionLocked(cOrId, locked) {
      const id = typeof cOrId === 'object' ? Number(cOrId.cabinet_id) : Number(cOrId);
      if (!id) return;
      if (locked) unlockedIds.delete(id);
      else unlockedIds.add(id);
    }

    function requireUnlocked(c, actionLabel) {
      if (!c) return false;
      if (!isPositionLocked(c)) return true;
      ColdAisle.toast(
        'Position is locked. Click “Unlock position” before ' + (actionLabel || 'moving') + '.',
        'error'
      );
      return false;
    }

    function getScrollWrap() {
      return root.querySelector('.planner-canvas-wrap');
    }

    function updateCanvasCursor(overObject) {
      if (pan) {
        canvas.style.cursor = 'grabbing';
        return;
      }
      if (overObject === true) {
        if (selectedPduId) {
          const p = floorPdus.find(function (x) { return Number(x.pdu_id) === Number(selectedPduId); });
          canvas.style.cursor = (p && !isPduPositionLocked(p)) ? 'move' : 'pointer';
          return;
        }
        const c = cabinets.find(function (x) { return Number(x.cabinet_id) === Number(selectedId); });
        canvas.style.cursor = (c && !isPositionLocked(c)) ? 'move' : 'pointer';
        return;
      }
      if (overObject === false) {
        canvas.style.cursor = 'grab';
        return;
      }
      // default based on selection
      if (selectedPduId) {
        const p2 = floorPdus.find(function (x) { return Number(x.pdu_id) === Number(selectedPduId); });
        canvas.style.cursor = (p2 && !isPduPositionLocked(p2)) ? 'move' : 'grab';
        return;
      }
      const c = cabinets.find(function (x) { return Number(x.cabinet_id) === Number(selectedId); });
      if (c && !isPositionLocked(c)) {
        canvas.style.cursor = 'move';
      } else {
        canvas.style.cursor = 'grab';
      }
    }

    function togglePositionLock() {
      const c = cabinets.find(function (x) { return Number(x.cabinet_id) === Number(selectedId); });
      if (!c) return;
      const nowLocked = !isPositionLocked(c);
      setPositionLocked(c, nowLocked);
      if (nowLocked) {
        drag = null;
        ColdAisle.toast('Position locked', 'info');
      } else {
        ColdAisle.toast('Position unlocked — drag or use snap tools, then Save', 'success');
      }
      renderProps();
      updateCanvasCursor();
      draw();
    }

    function updateToolbarButtons() {
      if (unitsBtn) {
        unitsBtn.textContent = isImperial() ? 'Units: ft / in' : 'Units: m / mm';
      }
      if (gridBtn) {
        gridBtn.textContent = showGrid ? 'Grid: On' : 'Grid: Off';
        gridBtn.classList.toggle('btn-primary', showGrid);
        gridBtn.classList.toggle('btn-secondary', !showGrid);
      }
      if (snapBtn) {
        snapBtn.textContent = snapToGrid ? 'Snap: On' : 'Snap: Off';
        snapBtn.classList.toggle('btn-primary', snapToGrid);
        snapBtn.classList.toggle('btn-secondary', !snapToGrid);
      }
      updatePaletteLabels();
    }

    function updatePaletteLabels() {
      root.querySelectorAll('.palette-item').forEach(function (item) {
        const w = parseInt(item.dataset.width || '600', 10);
        const d = parseInt(item.dataset.depth || '1200', 10);
        const label = item.querySelector('.palette-size');
        if (label) {
          label.textContent = fmtSize(w) + '×' + fmtSize(d) + ' ' + sizeLabel();
        }
      });
    }

    function bindPaletteItem(item) {
      item.setAttribute('draggable', 'true');
      item.addEventListener('dragstart', function (e) {
        const payload = templateFromPaletteItem(item);
        const json = JSON.stringify(payload);
        try { e.dataTransfer.setData(DRAG_MIME, json); } catch (err) { /* ignore */ }
        e.dataTransfer.setData('text/plain', DRAG_TEXT_PREFIX + json);
        e.dataTransfer.effectAllowed = 'copy';
        item.classList.add('dragging');
      });
      item.addEventListener('dragend', function () { item.classList.remove('dragging'); });
      item.addEventListener('click', function () {
        clearPaletteSelection();
        item.classList.add('selected');
        pendingPdu = null;
        pendingTemplate = templateFromPaletteItem(item);
        draw();
        ColdAisle.toast('Click on the floor plan to place: ' + (payloadName(pendingTemplate) || 'cabinet'), 'info');
      });
    }

    function payloadName(p) {
      return (p && (p.name || p.label)) || '';
    }

    function renderVendorPalette() {
      const cat = window.ColdAisleRackCatalog;
      const vendorSelect = root.querySelector('#vendorSelect');
      const list = root.querySelector('#paletteList');
      if (!list) return;

      if (cat && vendorSelect && !vendorSelect.dataset.ready) {
        cat.getVendors().forEach(function (v) {
          const opt = document.createElement('option');
          opt.value = v.id;
          opt.textContent = v.name;
          vendorSelect.appendChild(opt);
        });
        vendorSelect.dataset.ready = '1';
        vendorSelect.addEventListener('change', function () {
          renderVendorPalette();
        });
      }

      const vendorId = vendorSelect ? vendorSelect.value : 'all';
      const models = cat ? cat.getModels(vendorId) : [];

      list.innerHTML = '';
      if (!cat || !models.length) {
        // Fallback static generics if catalog failed to load
        const fallback = [
          { u: 42, w: 600, d: 1200, color: '#2d3748', name: '42U Standard' },
          { u: 48, w: 600, d: 1200, color: '#1e3a5f', name: '48U Tall' },
          { u: 42, w: 800, d: 1200, color: '#3b2f2f', name: '42U Wide' },
          { u: 24, w: 600, d: 1000, color: '#1a3329', name: '24U Half' },
        ];
        fallback.forEach(function (f) {
          const el = document.createElement('div');
          el.className = 'palette-item';
          el.draggable = true;
          el.dataset.u = String(f.u);
          el.dataset.width = String(f.w);
          el.dataset.depth = String(f.d);
          el.dataset.color = f.color;
          el.dataset.facing = 'north';
          el.dataset.name = f.name;
          el.innerHTML = '<div class="rack-icon" style="background:' + f.color + '"></div>' +
            esc(f.name) + '<br><small class="text-muted palette-size">' + f.w + '×' + f.d + ' mm</small>';
          list.appendChild(el);
          bindPaletteItem(el);
        });
        updatePaletteLabels();
        return;
      }

      models.forEach(function (m) {
        const el = document.createElement('div');
        el.className = 'palette-item';
        el.draggable = true;
        el.dataset.u = String(m.u_height);
        el.dataset.width = String(m.width_mm);
        el.dataset.depth = String(m.depth_mm);
        el.dataset.color = m.color_hex;
        el.dataset.facing = 'north';
        el.dataset.name = m.vendor_short + ' ' + m.u_height + 'U';
        el.dataset.label = m.label;
        el.dataset.modelKey = m.model_key;
        el.dataset.vendor = m.vendor;
        el.dataset.vendorName = m.vendor_name;
        el.dataset.family = m.family || '';
        el.dataset.skuSummary = m.sku_summary || '';
        el.title = m.sku_summary || m.label;

        const iconH = Math.max(28, Math.min(64, Math.round(m.u_height * 1.1)));
        const iconW = Math.max(28, Math.min(56, Math.round(m.width_mm / 18)));
        el.innerHTML =
          '<div class="rack-icon" style="width:' + iconW + 'px;height:' + iconH + 'px;background:' + m.color_hex +
          ';border-color:' + (m.color_hex.toLowerCase() === '#f2f2f2' || m.color_hex.toLowerCase() === '#e8e8e8' ? '#94a3b8' : '#64748b') +
          '"></div>' +
          '<div class="palette-title">' + esc(m.u_height + 'U · ' + m.family) + '</div>' +
          '<small class="text-muted palette-size">' + m.width_mm + '×' + m.depth_mm + ' mm</small>' +
          '<small class="text-muted palette-meta">' + esc(m.color_name || '') +
          (m.skus && m.skus.length > 1 ? ' · ' + m.skus.length + ' SKUs' : '') + '</small>';
        list.appendChild(el);
        bindPaletteItem(el);
      });
      updatePaletteLabels();
    }

    function northOptionsHtml(selected) {
      const opts = [
        ['top', 'Top of plan = North'],
        ['right', 'Right of plan = North'],
        ['bottom', 'Bottom of plan = North'],
        ['left', 'Left of plan = North'],
      ];
      return opts.map(function (o) {
        return '<option value="' + o[0] + '"' + (selected === o[0] ? ' selected' : '') + '>' + o[1] + '</option>';
      }).join('');
    }

    function facingOptionsHtml(selected) {
      const opts = ['north', 'south', 'east', 'west'];
      return opts.map(function (f) {
        const label = f.charAt(0).toUpperCase() + f.slice(1) + ' (front faces ' + f + ')';
        return '<option value="' + f + '"' + (selected === f ? ' selected' : '') + '>' + label + '</option>';
      }).join('');
    }

    function renderProps() {
      if (selectedPduId) {
        renderPduProps();
        return;
      }
      const multi = getSelectedCabinets();
      if (multi.length > 1) {
        renderMultiProps(multi);
        return;
      }
      const c = cabinets.find(function (x) { return Number(x.cabinet_id) === Number(selectedId); });
      if (!c) {
        if (!room) {
          propsEl.innerHTML = '<p class="text-muted">Select a room, then drop a cabinet or row PDU from the palette.</p>';
          return;
        }
        propsEl.innerHTML =
          '<h3 style="margin-top:0">Room / Floor Plan</h3>' +
          '<div class="form-row"><label>Room name</label>' +
          '<input class="form-control" id="r_name" value="' + esc(room.name || '') + '"></div>' +
          '<div class="form-row"><label>Width (' + lengthLabel() + ')</label>' +
          '<input class="form-control" type="number" step="0.01" min="0.1" id="r_w" value="' + fmtLen(roomW()) + '"></div>' +
          '<div class="form-row"><label>Depth / Length (' + lengthLabel() + ')</label>' +
          '<input class="form-control" type="number" step="0.01" min="0.1" id="r_d" value="' + fmtLen(roomD()) + '"></div>' +
          '<div class="form-row"><label>Compass — North is…</label>' +
          '<select class="form-control" id="r_north">' + northOptionsHtml(northEdge) + '</select>' +
          '<p class="text-muted" style="font-size:.75rem;margin:.25rem 0 0">Sets which edge of this data center plan is geographic North. Affects N/S/E/W labels and cabinet facing.</p></div>' +
          '<div class="form-row"><label>Grid size (ft)</label>' +
          '<input class="form-control" type="number" step="0.25" min="0.25" max="20" id="r_grid_ft" value="' + gridFt + '">' +
          '<p class="text-muted" style="font-size:.75rem;margin:.25rem 0 0">Default 1 ft × 1 ft tiles. Used for drawing and snap.</p></div>' +
          '<div class="form-actions">' +
          '<button type="button" class="btn btn-primary btn-sm" id="r_save">Save room &amp; layout</button>' +
          '</div>' +
          '<hr style="border-color:var(--border);margin:1rem 0">' +
          '<p class="text-muted" style="font-size:.8rem">' +
          'Blue edge = <strong>FRONT</strong>, amber = <strong>REAR</strong>. ' +
          'Scroll wheel zooms. Toggle Grid / Snap in the toolbar.' +
          '</p>';
        propsEl.querySelector('#r_save').onclick = saveRoom;
        return;
      }

      const facing = (c.front_facing || rotationToFacing(c.rotation_deg) || 'north').toLowerCase();
      const rackUrl = (window.ColdAisle && window.ColdAisle.baseUrl ? window.ColdAisle.baseUrl.replace(/\/$/, '') : '') +
        '/pages/cabinets.php?id=' + encodeURIComponent(c.cabinet_id);
      const isAnchor = Number(anchorId) === Number(c.cabinet_id);
      const locked = isPositionLocked(c);
      const rowOpts = rowOptionsHtml(c.row_id);
      const posRo = locked ? ' readonly' : '';
      const posStyle = locked ? ' style="opacity:.75;background:var(--surface-2)"' : '';

      propsEl.innerHTML =
        '<h3 style="margin-top:0">Cabinet Properties' +
        (isAnchor ? ' <span class="badge" style="background:#f59e0b;color:#111">⚓ Anchor</span>' : '') +
        (locked
          ? ' <span class="badge" style="background:#475569;color:#e2e8f0">🔒 Locked</span>'
          : ' <span class="badge" style="background:#16a34a;color:#fff">🔓 Unlocked</span>') +
        '</h3>' +
        '<div class="form-row" style="margin-bottom:.65rem">' +
        '<button type="button" class="btn ' + (locked ? 'btn-primary' : 'btn-secondary') + ' btn-sm" id="p_lock" style="width:100%">' +
        (locked ? '🔓 Unlock position' : '🔒 Lock position') +
        '</button>' +
        '<p class="text-muted" style="font-size:.75rem;margin:.35rem 0 0">' +
        (locked
          ? 'Position is locked — click Unlock to drag or use snap / align tools.'
          : 'Position unlocked — drag or use tools, then Save (auto-locks).') +
        '</p></div>' +
        '<div class="form-row"><label>Name</label>' +
        '<input class="form-control" id="p_name" value="' + esc(c.name) + '"></div>' +
        '<div class="form-row"><label>Row</label>' +
        '<div style="display:flex;gap:.35rem;align-items:center">' +
        '<select class="form-control" id="p_row" style="flex:1">' + rowOpts + '</select>' +
        '<button type="button" class="btn btn-secondary btn-sm" id="p_row_new" title="Create a new row in this room">+ Row</button>' +
        '</div>' +
        '<p class="text-muted" style="font-size:.75rem;margin:.25rem 0 0">Rows group cabinets; you can later map a row to a power zone.</p></div>' +
        '<div class="form-row"><label>U Height</label>' +
        '<input class="form-control" type="number" id="p_u" min="1" max="60" value="' + (c.u_height || 42) + '"></div>' +
        '<div class="form-row"><label>Width (' + sizeLabel() + ')</label>' +
        '<input class="form-control" type="number" step="0.1" id="p_w" value="' + fmtSize(c.width_mm || 600) + '"></div>' +
        '<div class="form-row"><label>Depth (' + sizeLabel() + ')</label>' +
        '<input class="form-control" type="number" step="0.1" id="p_d" value="' + fmtSize(c.depth_mm || 1200) + '"></div>' +
        '<div class="form-row"><label>Pos X (' + lengthLabel() + ')</label>' +
        '<input class="form-control" type="number" step="0.0001" id="p_x" data-user-edited="0" value="' + fmtLen(c.pos_x) + '"' + posRo + posStyle + '></div>' +
        '<div class="form-row"><label>Pos Y (' + lengthLabel() + ')</label>' +
        '<input class="form-control" type="number" step="0.0001" id="p_y" data-user-edited="0" value="' + fmtLen(c.pos_y) + '"' + posRo + posStyle + '></div>' +
        '<div class="form-row"><label>Front faces</label>' +
        '<select class="form-control" id="p_facing">' + facingOptionsHtml(facing) + '</select>' +
        '<p class="text-muted" style="font-size:.75rem;margin:.25rem 0 0">Blue strip = front · Amber strip = rear</p></div>' +
        '<div class="form-row"><label>Color</label>' +
        '<input class="form-control" type="color" id="p_color" value="' + (c.color_hex || '#2d3748') + '"></div>' +
        '<div class="form-actions" style="flex-wrap:wrap;gap:.35rem">' +
        '<button type="button" class="btn btn-primary btn-sm" id="p_save">Save</button>' +
        '<button type="button" class="btn btn-secondary btn-sm" id="p_snap" title="Snap rotated footprint edges to the grid"' + (locked ? ' disabled' : '') + '>Snap to Grid</button>' +
        '<button type="button" class="btn btn-secondary btn-sm" id="p_align_front" title="Align this rack front to a touching neighbor"' + (locked ? ' disabled' : '') + '>Align Front</button>' +
        '<button type="button" class="btn btn-secondary btn-sm" id="p_anchor" title="Set as front anchor for touching cluster">' +
        (isAnchor ? '⚓ Anchored' : 'Set Anchor') + '</button>' +
        '<button type="button" class="btn btn-secondary btn-sm" id="p_align_cluster" title="Align fronts of all touching same-facing racks to the anchor">Align Cluster Fronts</button>' +
        '<button type="button" class="btn btn-secondary btn-sm" id="p_rot_ccw" title="Rotate 90° counter-clockwise"' + (locked ? ' disabled' : '') + '>↺ 90°</button>' +
        '<button type="button" class="btn btn-secondary btn-sm" id="p_rot_cw" title="Rotate 90° clockwise"' + (locked ? ' disabled' : '') + '>↻ 90°</button>' +
        '<button type="button" class="btn btn-danger btn-sm" id="p_del">Delete</button>' +
        '<a class="btn btn-secondary btn-sm" href="' + rackUrl + '">Open Rack</a>' +
        '</div>' +
        '<div class="form-row" style="margin-top:.75rem">' +
        '<label>Snap to adjacent rack</label>' +
        '<p class="text-muted" style="font-size:.75rem;margin:0 0 .4rem">Requires unlocked position. Plan directions, not compass.</p>' +
        '<div class="adj-snap-pad" role="group" aria-label="Snap to adjacent rack">' +
        '<span></span>' +
        '<button type="button" class="btn btn-secondary btn-sm adj-snap" data-dir="up" title="Snap up"' + (locked ? ' disabled' : '') + '>▲</button>' +
        '<span></span>' +
        '<button type="button" class="btn btn-secondary btn-sm adj-snap" data-dir="left" title="Snap left"' + (locked ? ' disabled' : '') + '>◀</button>' +
        '<button type="button" class="btn btn-ghost btn-sm" disabled style="opacity:.5;cursor:default" title="Selected cabinet">◆</button>' +
        '<button type="button" class="btn btn-secondary btn-sm adj-snap" data-dir="right" title="Snap right"' + (locked ? ' disabled' : '') + '>▶</button>' +
        '<span></span>' +
        '<button type="button" class="btn btn-secondary btn-sm adj-snap" data-dir="down" title="Snap down"' + (locked ? ' disabled' : '') + '>▼</button>' +
        '<span></span>' +
        '</div></div>' +
        '<p class="text-muted" style="font-size:.75rem;margin:.5rem 0 0">' +
        'Position stays locked after Save to prevent accidental drag-snap. Unlock to move, then Save again.' +
        '</p>';
      propsEl.querySelector('#p_lock').onclick = togglePositionLock;
      propsEl.querySelector('#p_save').onclick = saveProps;
      propsEl.querySelector('#p_del').onclick = deleteSelected;
      propsEl.querySelector('#p_snap').onclick = function () { recalibrateSnapSelected(); };
      propsEl.querySelector('#p_align_front').onclick = function () { alignFrontToNeighbor(); };
      propsEl.querySelector('#p_anchor').onclick = function () { setFrontAnchor(); };
      propsEl.querySelector('#p_align_cluster').onclick = function () { alignClusterFrontsToAnchor(); };
      propsEl.querySelector('#p_rot_cw').onclick = function () { rotateSelected(90); };
      propsEl.querySelector('#p_rot_ccw').onclick = function () { rotateSelected(-90); };
      propsEl.querySelector('#p_row_new').onclick = function () { createRowAndAssign(); };
      propsEl.querySelectorAll('.adj-snap').forEach(function (btn) {
        btn.onclick = function () { snapToAdjacentRack(btn.getAttribute('data-dir')); };
      });
      // Manual position edits only when unlocked
      ['#p_x', '#p_y'].forEach(function (sel) {
        const el = propsEl.querySelector(sel);
        if (!el) return;
        el.addEventListener('input', function () {
          if (isPositionLocked(c)) return;
          el.dataset.userEdited = '1';
        });
        el.addEventListener('change', function () {
          if (isPositionLocked(c)) return;
          el.dataset.userEdited = '1';
          c.pos_x = displayToM(propsEl.querySelector('#p_x').value);
          c.pos_y = displayToM(propsEl.querySelector('#p_y').value);
          draw();
        });
      });
      propsEl.querySelector('#p_facing').onchange = function () {
        if (!requireUnlocked(c, 'changing facing / rotation')) {
          propsEl.querySelector('#p_facing').value = facing;
          return;
        }
        const fac = propsEl.querySelector('#p_facing').value;
        c.front_facing = fac;
        c.rotation_deg = facingToRotation(fac);
        syncPosFieldsFromCabinet(c);
        draw();
      };
    }

    function zoneOptionsHtml(selectedId) {
      let html = '<option value="">— No zone —</option>';
      powerZones.forEach(function (z) {
        const id = Number(z.zone_id);
        const sel = selectedId != null && Number(selectedId) === id ? ' selected' : '';
        html += '<option value="' + id + '"' + sel + '>' + esc(z.name || ('Zone ' + id)) + '</option>';
      });
      return html;
    }

    function renderPduProps() {
      const p = floorPdus.find(function (x) { return Number(x.pdu_id) === Number(selectedPduId); });
      if (!p) {
        selectedPduId = null;
        renderProps();
        return;
      }
      const facing = (p.front_facing || rotationToFacing(p.rotation_deg) || 'north').toLowerCase();
      const locked = isPduPositionLocked(p);
      const posRo = locked ? ' readonly' : '';
      const posStyle = locked ? ' style="opacity:.75;background:var(--surface-2)"' : '';
      const base = (window.ColdAisle && window.ColdAisle.baseUrl ? window.ColdAisle.baseUrl.replace(/\/$/, '') : '');
      const pduUrl = base + '/pages/power_pdus.php?id=' + encodeURIComponent(p.pdu_id);

      propsEl.innerHTML =
        '<h3 style="margin-top:0">Floor PDU' +
        (locked
          ? ' <span class="badge" style="background:#475569;color:#e2e8f0">🔒 Locked</span>'
          : ' <span class="badge" style="background:#16a34a;color:#fff">🔓 Unlocked</span>') +
        '</h3>' +
        '<p class="text-muted" style="font-size:.8rem;margin-top:0">' +
        'Row/room power asset · scope <strong>' + esc(p.pdu_scope || 'row') + '</strong>' +
        (p.zone_name ? ' · zone <strong>' + esc(p.zone_name) + '</strong>' : '') +
        '</p>' +
        '<div class="form-row" style="margin-bottom:.65rem">' +
        '<button type="button" class="btn ' + (locked ? 'btn-primary' : 'btn-secondary') + ' btn-sm" id="fp_lock" style="width:100%">' +
        (locked ? '🔓 Unlock position' : '🔒 Lock position') +
        '</button></div>' +
        '<div class="form-row"><label>Name</label>' +
        '<input class="form-control" id="fp_name" value="' + esc(p.name || '') + '"></div>' +
        '<div class="form-row"><label>Zone</label>' +
        '<select class="form-control" id="fp_zone">' + zoneOptionsHtml(p.zone_id) + '</select></div>' +
        '<div class="form-row"><label>Row (optional)</label>' +
        '<select class="form-control" id="fp_row">' + rowOptionsHtml(p.row_id) + '</select></div>' +
        '<div class="form-row"><label>Width (' + sizeLabel() + ')</label>' +
        '<input class="form-control" type="number" step="0.1" id="fp_w" value="' + fmtSize(p.width_mm || 600) + '"' + posRo + posStyle + '></div>' +
        '<div class="form-row"><label>Depth (' + sizeLabel() + ')</label>' +
        '<input class="form-control" type="number" step="0.1" id="fp_d" value="' + fmtSize(p.depth_mm || 300) + '"' + posRo + posStyle + '></div>' +
        '<div class="form-row"><label>Height (' + sizeLabel() + ')</label>' +
        '<input class="form-control" type="number" step="0.1" id="fp_h" value="' + fmtSize(p.height_mm || 1800) + '">' +
        '<p class="text-muted" style="font-size:.72rem;margin:.25rem 0 0">Used in 3D view (typical floor RPP ≈ 1800 mm / 71 in).</p></div>' +
        '<div class="form-row"><label>Pos X (' + lengthLabel() + ')</label>' +
        '<input class="form-control" type="number" step="0.0001" id="fp_x" data-user-edited="0" value="' + fmtLen(p.pos_x) + '"' + posRo + posStyle + '></div>' +
        '<div class="form-row"><label>Pos Y (' + lengthLabel() + ')</label>' +
        '<input class="form-control" type="number" step="0.0001" id="fp_y" data-user-edited="0" value="' + fmtLen(p.pos_y) + '"' + posRo + posStyle + '></div>' +
        '<div class="form-row"><label>Front faces</label>' +
        '<select class="form-control" id="fp_facing"' + (locked ? ' disabled' : '') + '>' + facingOptionsHtml(facing) + '</select></div>' +
        '<div class="form-row"><label>Color</label>' +
        '<input class="form-control" type="color" id="fp_color" value="' + (p.color_hex || p.zone_color || '#b45309') + '"></div>' +
        '<div class="form-actions" style="flex-wrap:wrap;gap:.35rem">' +
        '<button type="button" class="btn btn-primary btn-sm" id="fp_save">Save</button>' +
        '<button type="button" class="btn btn-secondary btn-sm" id="fp_snap"' + (locked ? ' disabled' : '') + '>Snap to Grid</button>' +
        '<button type="button" class="btn btn-secondary btn-sm" id="fp_rot_ccw"' + (locked ? ' disabled' : '') + '>↺ 90°</button>' +
        '<button type="button" class="btn btn-secondary btn-sm" id="fp_rot_cw"' + (locked ? ' disabled' : '') + '>↻ 90°</button>' +
        '<button type="button" class="btn btn-warning btn-sm" id="fp_unplace" title="Remove from plan but keep the PDU">Unplace</button>' +
        '<a class="btn btn-secondary btn-sm" href="' + pduUrl + '">Open PDU</a>' +
        '</div>' +
        '<p class="text-muted" style="font-size:.75rem;margin:.65rem 0 0">' +
        'Save keeps the current position (nudge/drag) without grid-snapping. Use <strong>Snap to Grid</strong> only when you want that. ' +
        'Unplace clears floor position only — breakers, zone, and power config stay on the PDU.' +
        '</p>';

      propsEl.querySelector('#fp_lock').onclick = function () {
        const nowLocked = !isPduPositionLocked(p);
        setPduPositionLocked(p, nowLocked);
        ColdAisle.toast(nowLocked ? 'PDU position locked' : 'PDU unlocked — move then Save', nowLocked ? 'info' : 'success');
        renderProps();
        draw();
        updateCanvasCursor();
      };
      propsEl.querySelector('#fp_save').onclick = function () { saveFloorPduProps(p); };
      propsEl.querySelector('#fp_snap').onclick = function () {
        if (isPduPositionLocked(p)) return;
        const sn = snapCabinetPosition(Number(p.pos_x) || 0, Number(p.pos_y) || 0, p, true);
        p.pos_x = sn.x;
        p.pos_y = sn.y;
        const xEl = propsEl.querySelector('#fp_x');
        const yEl = propsEl.querySelector('#fp_y');
        if (xEl) { xEl.value = fmtLen(p.pos_x); xEl.dataset.userEdited = '0'; }
        if (yEl) { yEl.value = fmtLen(p.pos_y); yEl.dataset.userEdited = '0'; }
        saveFloorPduProps(p, true);
      };
      propsEl.querySelector('#fp_rot_cw').onclick = function () { rotateFloorPdu(p, 90); };
      propsEl.querySelector('#fp_rot_ccw').onclick = function () { rotateFloorPdu(p, -90); };
      propsEl.querySelector('#fp_unplace').onclick = function () { unplaceFloorPdu(p); };
      propsEl.querySelector('#fp_facing').onchange = function () {
        if (isPduPositionLocked(p)) return;
        p.front_facing = propsEl.querySelector('#fp_facing').value;
        p.rotation_deg = facingToRotation(p.front_facing);
        draw();
      };
      // Manual position edits only when unlocked
      ['#fp_x', '#fp_y'].forEach(function (sel) {
        const el = propsEl.querySelector(sel);
        if (!el) return;
        el.addEventListener('input', function () {
          if (isPduPositionLocked(p)) return;
          el.dataset.userEdited = '1';
        });
        el.addEventListener('change', function () {
          if (isPduPositionLocked(p)) return;
          el.dataset.userEdited = '1';
          p.pos_x = displayToM(propsEl.querySelector('#fp_x').value);
          p.pos_y = displayToM(propsEl.querySelector('#fp_y').value);
          draw();
        });
      });
    }

    /**
     * Save floor PDU. Position is clamped inside the room only — never grid-snapped on Save
     * (same fix as cabinets). Use Snap to Grid for intentional grid alignment.
     */
    async function saveFloorPduProps(p, silent) {
      if (!p) return;
      const locked = isPduPositionLocked(p);
      const nameEl = propsEl.querySelector('#fp_name');
      const payload = {
        pdu_id: Number(p.pdu_id),
        name: nameEl ? nameEl.value.trim() : p.name,
        zone_id: propsEl.querySelector('#fp_zone') ? (propsEl.querySelector('#fp_zone').value || null) : p.zone_id,
        row_id: propsEl.querySelector('#fp_row') ? (propsEl.querySelector('#fp_row').value || null) : p.row_id,
        color_hex: propsEl.querySelector('#fp_color') ? propsEl.querySelector('#fp_color').value : p.color_hex,
      };
      // Height is always editable (3D only; not floor position lock)
      const hEl = propsEl.querySelector('#fp_h');
      if (hEl) {
        payload.height_mm = Math.max(100, Math.round(displayToMm(hEl.value)));
      } else if (p.height_mm) {
        payload.height_mm = Number(p.height_mm);
      }
      // Preserve pre-save coords so a locked save cannot drift
      const savedPosX = Number(p.pos_x) || 0;
      const savedPosY = Number(p.pos_y) || 0;
      const savedRot = pduRotation(p);
      const savedFacing = (p.front_facing || rotationToFacing(savedRot) || 'north').toLowerCase();
      const savedW = Number(p.width_mm) || 600;
      const savedD = Number(p.depth_mm) || 300;

      if (!locked) {
        const wEl = propsEl.querySelector('#fp_w');
        const dEl = propsEl.querySelector('#fp_d');
        const xEl = propsEl.querySelector('#fp_x');
        const yEl = propsEl.querySelector('#fp_y');
        const facEl = propsEl.querySelector('#fp_facing');
        if (wEl) payload.width_mm = Math.round(displayToMm(wEl.value));
        if (dEl) payload.depth_mm = Math.round(displayToMm(dEl.value));
        // Prefer in-memory position (nudge/drag) unless the user typed in the fields
        let posX = savedPosX;
        let posY = savedPosY;
        if (xEl && yEl && (xEl.dataset.userEdited === '1' || yEl.dataset.userEdited === '1')) {
          posX = displayToM(xEl.value);
          posY = displayToM(yEl.value);
        }
        const fac = facEl ? facEl.value : savedFacing;
        const draft = Object.assign({}, p, {
          width_mm: payload.width_mm != null ? payload.width_mm : p.width_mm,
          depth_mm: payload.depth_mm != null ? payload.depth_mm : p.depth_mm,
          front_facing: fac,
          rotation_deg: facingToRotation(fac),
        });
        // Clamp only — do NOT snap to grid on Save
        const cl = clampCabinetPosition(posX, posY, draft);
        payload.pos_x = cl.x;
        payload.pos_y = cl.y;
        payload.front_facing = fac;
        payload.rotation_deg = facingToRotation(fac);
        p.pos_x = payload.pos_x;
        p.pos_y = payload.pos_y;
        p.front_facing = fac;
        p.rotation_deg = payload.rotation_deg;
        if (payload.width_mm != null) p.width_mm = payload.width_mm;
        if (payload.depth_mm != null) p.depth_mm = payload.depth_mm;
      } else {
        // Locked: still send height (+ keep footprint for 3D); omit pos to avoid re-clamp/snap
        payload.width_mm = savedW;
        payload.depth_mm = savedD;
        payload.pos_x = savedPosX;
        payload.pos_y = savedPosY;
        payload.front_facing = savedFacing;
        payload.rotation_deg = savedRot;
      }
      if (payload.height_mm != null) p.height_mm = payload.height_mm;
      try {
        const res = await ColdAisle.api('api/floorplan.php?action=update_floor_pdu', {
          method: 'POST',
          body: payload,
        });
        const updated = res.pdu || payload;
        Object.assign(p, updated);
        // Always restore exact pre-save coords if locked; if unlocked keep clamped values we just set
        if (locked) {
          p.pos_x = savedPosX;
          p.pos_y = savedPosY;
          p.rotation_deg = savedRot;
          p.front_facing = savedFacing;
        } else {
          p.pos_x = payload.pos_x != null ? payload.pos_x : p.pos_x;
          p.pos_y = payload.pos_y != null ? payload.pos_y : p.pos_y;
        }
        if (payload.zone_id) {
          const z = powerZones.find(function (x) { return Number(x.zone_id) === Number(payload.zone_id); });
          p.zone_name = z ? z.name : p.zone_name;
          p.zone_color = z ? z.color_hex : p.zone_color;
        } else if (payload.zone_id === null) {
          p.zone_name = null;
        }
        if (payload.row_id) {
          const rr = roomRows.find(function (r) { return Number(r.row_id) === Number(payload.row_id); });
          p.row_name = rr ? rr.name : p.row_name;
        }
        setPduPositionLocked(p, true);
        // Refresh form fields with full-precision saved position
        if (propsEl.querySelector('#fp_x')) {
          propsEl.querySelector('#fp_x').value = fmtLen(p.pos_x);
          propsEl.querySelector('#fp_x').dataset.userEdited = '0';
        }
        if (propsEl.querySelector('#fp_y')) {
          propsEl.querySelector('#fp_y').value = fmtLen(p.pos_y);
          propsEl.querySelector('#fp_y').dataset.userEdited = '0';
        }
        if (!silent) {
          ColdAisle.toast(
            locked ? 'PDU saved (position unchanged, locked)' : 'PDU saved (position locked)',
            'success'
          );
        } else {
          ColdAisle.toast('Snapped to grid and saved', 'success');
        }
        renderProps();
        draw();
        refresh3d();
      } catch (e) {
        ColdAisle.toast(e.message || 'Save failed', 'error');
      }
    }

    async function rotateFloorPdu(p, delta) {
      if (!p || isPduPositionLocked(p)) {
        ColdAisle.toast('Unlock PDU position first', 'error');
        return;
      }
      let deg = (pduRotation(p) + delta) % 360;
      if (deg < 0) deg += 360;
      p.rotation_deg = deg;
      p.front_facing = rotationToFacing(deg);
      if (snapToGrid) {
        const sn = snapCabinetPosition(Number(p.pos_x) || 0, Number(p.pos_y) || 0, p, true);
        p.pos_x = sn.x;
        p.pos_y = sn.y;
      }
      draw();
      try {
        await ColdAisle.api('api/floorplan.php?action=update_floor_pdu', {
          method: 'POST',
          body: {
            pdu_id: Number(p.pdu_id),
            front_facing: p.front_facing,
            rotation_deg: p.rotation_deg,
            pos_x: p.pos_x,
            pos_y: p.pos_y,
            width_mm: p.width_mm,
            depth_mm: p.depth_mm,
          },
        });
        ColdAisle.toast('Facing → ' + String(p.front_facing).toUpperCase(), 'success');
        renderProps();
      } catch (e) {
        ColdAisle.toast(e.message || 'Rotate failed', 'error');
      }
    }

    async function unplaceFloorPdu(p) {
      if (!p || !confirm('Remove this PDU from the floor plan? The PDU record is kept (breakers, zone, etc.).')) return;
      try {
        await ColdAisle.api('api/floorplan.php?action=unplace_pdu', {
          method: 'POST',
          body: { pdu_id: Number(p.pdu_id) },
        });
        floorPdus = floorPdus.filter(function (x) { return Number(x.pdu_id) !== Number(p.pdu_id); });
        unlockedPduIds.delete(Number(p.pdu_id));
        // Return to unplaced list
        const copy = Object.assign({}, p);
        copy.pos_x = null;
        copy.pos_y = null;
        copy.room_id = null;
        unplacedPdus.push(copy);
        unplacedPdus.sort(function (a, b) {
          return String(a.name || '').localeCompare(String(b.name || ''));
        });
        selectedPduId = null;
        renderUnplacedPduPalette();
        renderProps();
        draw();
        ColdAisle.toast('PDU removed from plan (still in Power → PDUs)', 'success');
      } catch (e) {
        ColdAisle.toast(e.message || 'Unplace failed', 'error');
      }
    }

    /** Push full-precision position into the props form (clears userEdited). */
    function syncPosFieldsFromCabinet(c) {
      const xEl = propsEl.querySelector('#p_x');
      const yEl = propsEl.querySelector('#p_y');
      if (!xEl || !yEl || !c) return;
      xEl.value = fmtLen(c.pos_x);
      yEl.value = fmtLen(c.pos_y);
      xEl.dataset.userEdited = '0';
      yEl.dataset.userEdited = '0';
    }

    function rowOptionsHtml(selectedId) {
      let html = '<option value="">— No row —</option>';
      roomRows.forEach(function (r) {
        const id = Number(r.row_id);
        const sel = selectedId != null && Number(selectedId) === id ? ' selected' : '';
        const zoneBit = r.zone_id ? ' (zone)' : '';
        html += '<option value="' + id + '"' + sel + '>' + esc(r.name || ('Row ' + id)) + zoneBit + '</option>';
      });
      return html;
    }

    function renderMultiProps(list) {
      const unlocked = list.filter(function (c) { return !isPositionLocked(c); });
      const names = list.map(function (c) { return esc(c.name || ('#' + c.cabinet_id)); }).join(', ');
      propsEl.innerHTML =
        '<h3 style="margin-top:0">Multiple cabinets</h3>' +
        '<p class="text-muted" style="font-size:.85rem"><strong>' + list.length + '</strong> selected' +
        ' · <strong>' + unlocked.length + '</strong> unlocked</p>' +
        '<p style="font-size:.8rem;line-height:1.4">' + names + '</p>' +
        '<div class="form-actions" style="flex-wrap:wrap;gap:.35rem">' +
        '<button type="button" class="btn btn-primary btn-sm" id="m_save"' +
        (unlocked.length ? '' : ' disabled') + '>💾 Save &amp; lock positions</button>' +
        '<button type="button" class="btn btn-secondary btn-sm" id="m_unlock">🔓 Unlock all</button>' +
        '<button type="button" class="btn btn-secondary btn-sm" id="m_lock" title="UI lock only — does not write to the database">' +
        '🔒 UI lock only</button>' +
        '<button type="button" class="btn btn-secondary btn-sm" id="m_clear">Clear selection</button>' +
        '</div>' +
        '<hr style="border-color:var(--border);margin:1rem 0">' +
        '<p class="text-muted" style="font-size:.8rem;margin:0">' +
        'Nudge/drag only moves cabinets in the browser until you <strong>Save &amp; lock positions</strong>. ' +
        'UI lock alone does not persist. SHIFT+click to multi-select; click one rack for full properties.' +
        '</p>';
      propsEl.querySelector('#m_save').onclick = function () {
        saveSelectedCabinetPositions(list);
      };
      propsEl.querySelector('#m_unlock').onclick = function () {
        list.forEach(function (c) { setPositionLocked(c, false); });
        ColdAisle.toast('Unlocked ' + list.length + ' cabinet(s)', 'success');
        renderProps();
        draw();
        updateCanvasCursor();
      };
      propsEl.querySelector('#m_lock').onclick = function () {
        list.forEach(function (c) { setPositionLocked(c, true); });
        ColdAisle.toast(
          'UI-locked ' + list.length + ' cabinet(s). Positions are NOT saved until you use Save & lock.',
          'warning'
        );
        renderProps();
        draw();
        updateCanvasCursor();
      };
      propsEl.querySelector('#m_clear').onclick = function () {
        selectCabinet(null);
      };
    }

    /**
     * Persist pos_x/pos_y for every cabinet in the list (typically multi-select after nudge/drag).
     * Then lock them so further accidental moves need Unlock again.
     */
    async function saveSelectedCabinetPositions(list) {
      const targets = (list || getSelectedCabinets()).slice();
      if (!targets.length) {
        ColdAisle.toast('No cabinets selected', 'error');
        return;
      }
      // Prefer unlocked ones; if all locked, still allow saving current in-memory positions
      // for the selection (user may have nudged then UI-locked without saving).
      let toSave = targets.filter(function (c) { return !isPositionLocked(c); });
      if (!toSave.length) {
        toSave = targets;
      }
      let ok = 0;
      let fail = 0;
      for (let i = 0; i < toSave.length; i++) {
        const c = toSave[i];
        const posX = Number(c.pos_x) || 0;
        const posY = Number(c.pos_y) || 0;
        const facing = (c.front_facing || rotationToFacing(c.rotation_deg) || 'north').toLowerCase();
        const rot = cabRotation(c);
        try {
          const res = await ColdAisle.api('api/cabinets.php', {
            method: 'PUT',
            forcePostOverride: true,
            body: {
              cabinet_id: Number(c.cabinet_id),
              pos_x: posX,
              pos_y: posY,
              rotation_deg: rot,
              front_facing: facing,
            },
          });
          if (res && res.cabinet) {
            // Keep the coordinates we saved (server may round); do not let other fields wipe pos
            c.pos_x = res.cabinet.pos_x != null ? Number(res.cabinet.pos_x) : posX;
            c.pos_y = res.cabinet.pos_y != null ? Number(res.cabinet.pos_y) : posY;
            if (res.cabinet.rotation_deg != null) c.rotation_deg = Number(res.cabinet.rotation_deg);
            if (res.cabinet.front_facing) c.front_facing = res.cabinet.front_facing;
          } else {
            c.pos_x = posX;
            c.pos_y = posY;
          }
          setPositionLocked(c, true);
          ok++;
        } catch (e) {
          fail++;
          console.warn('Failed saving cabinet', c.cabinet_id, e);
        }
      }
      draw();
      renderProps();
      updateCanvasCursor();
      refresh3d();
      if (fail && !ok) {
        ColdAisle.toast('Failed to save positions (' + fail + ' error(s))', 'error');
      } else if (fail) {
        ColdAisle.toast('Saved ' + ok + ' cabinet(s); ' + fail + ' failed', 'warning');
      } else {
        ColdAisle.toast('Saved & locked ' + ok + ' cabinet position(s)', 'success');
      }
    }

    function esc(s) {
      return String(s == null ? '' : s).replace(/[&<>"']/g, function (ch) {
        return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[ch];
      });
    }

    async function rotateSelected(delta) {
      const c = cabinets.find(function (x) { return Number(x.cabinet_id) === Number(selectedId); });
      if (!c) return;
      if (!requireUnlocked(c, 'rotating')) return;
      let deg = (cabRotation(c) + delta) % 360;
      if (deg < 0) deg += 360;
      c.rotation_deg = deg;
      c.front_facing = rotationToFacing(deg);
      // Re-align rotated footprint to grid when snap is on
      if (snapToGrid) {
        const sn = snapCabinetPosition(Number(c.pos_x) || 0, Number(c.pos_y) || 0, c, true);
        c.pos_x = sn.x;
        c.pos_y = sn.y;
      }
      if (propsEl.querySelector('#p_facing')) {
        propsEl.querySelector('#p_facing').value = c.front_facing;
      }
      syncPosFieldsFromCabinet(c);
      draw();
      try {
        await ColdAisle.api('api/cabinets.php', {
          method: 'PUT',
          forcePostOverride: true,
          body: {
            cabinet_id: Number(c.cabinet_id),
            rotation_deg: c.rotation_deg,
            front_facing: c.front_facing,
            pos_x: c.pos_x,
            pos_y: c.pos_y,
          },
        });
        ColdAisle.toast('Facing → ' + c.front_facing.toUpperCase(), 'success');
      } catch (e) {
        ColdAisle.toast(e.message || 'Rotate failed', 'error');
      }
    }

    /** Force snap using current rotation (works even if Snap toggle is off). */
    async function recalibrateSnapSelected() {
      const c = cabinets.find(function (x) { return Number(x.cabinet_id) === Number(selectedId); });
      if (!c) return;
      if (!requireUnlocked(c, 'snapping to grid')) return;
      const sn = snapCabinetPosition(Number(c.pos_x) || 0, Number(c.pos_y) || 0, c, true);
      c.pos_x = sn.x;
      c.pos_y = sn.y;
      await persistCabinetPosition(c, 'Snapped to grid (rotation-aware)');
    }

    function getCabAabb(c) {
      const geom = cabGeom(c);
      const aabb = aabbFromPos(Number(c.pos_x) || 0, Number(c.pos_y) || 0, geom);
      return {
        left: aabb.left,
        top: aabb.top,
        right: aabb.right,
        bottom: aabb.bottom,
        cx: (aabb.left + aabb.right) / 2,
        cy: (aabb.top + aabb.bottom) / 2,
        w: geom.aabbW,
        h: geom.aabbH,
        geom: geom,
      };
    }

    function rangesOverlap(a0, a1, b0, b1, pad) {
      pad = pad || 0;
      return a0 < b1 + pad && a1 > b0 - pad;
    }

    /**
     * Find nearest other cabinet in a plan direction (up/down/left/right).
     * Requires overlap on the perpendicular axis so we abut a real neighbor, not a diagonal rack.
     */
    function findAdjacentCabinet(self, dir) {
      const sa = getCabAabb(self);
      const selfId = Number(self.cabinet_id);
      // Allow slight miss on perpendicular overlap (half a foot) so nearly-aligned rows still match
      const pad = gridStepM() * 0.5;
      let best = null;
      let bestScore = Infinity;

      cabinets.forEach(function (other) {
        if (Number(other.cabinet_id) === selfId) return;
        const oa = getCabAabb(other);
        let gap;
        let ok = false;

        if (dir === 'right') {
          ok = rangesOverlap(sa.top, sa.bottom, oa.top, oa.bottom, pad) && oa.cx >= sa.cx - 0.001;
          // Prefer cabinets whose left edge is at/after our left; score by edge gap (how far to move)
          gap = oa.left - sa.right;
          if (ok && oa.left < sa.left - 0.05) ok = false; // mostly to our left — skip
        } else if (dir === 'left') {
          ok = rangesOverlap(sa.top, sa.bottom, oa.top, oa.bottom, pad) && oa.cx <= sa.cx + 0.001;
          gap = sa.left - oa.right;
          if (ok && oa.right > sa.right + 0.05) ok = false;
        } else if (dir === 'down') {
          ok = rangesOverlap(sa.left, sa.right, oa.left, oa.right, pad) && oa.cy >= sa.cy - 0.001;
          gap = oa.top - sa.bottom;
          if (ok && oa.top < sa.top - 0.05) ok = false;
        } else if (dir === 'up') {
          ok = rangesOverlap(sa.left, sa.right, oa.left, oa.right, pad) && oa.cy <= sa.cy + 0.001;
          gap = sa.top - oa.bottom;
          if (ok && oa.bottom > sa.bottom + 0.05) ok = false;
        }

        if (!ok) return;

        // Prefer smallest positive gap (nearest in that direction); allow small negative (overlap) as 0
        const score = gap < 0 ? Math.abs(gap) * 0.25 : gap;
        // Also prefer stronger perpendicular overlap
        let overlapLen = 0;
        if (dir === 'left' || dir === 'right') {
          overlapLen = Math.min(sa.bottom, oa.bottom) - Math.max(sa.top, oa.top);
        } else {
          overlapLen = Math.min(sa.right, oa.right) - Math.max(sa.left, oa.left);
        }
        const adjusted = score - overlapLen * 0.01;
        if (adjusted < bestScore) {
          bestScore = adjusted;
          best = { cabinet: other, aabb: oa, gap: gap };
        }
      });

      return best;
    }

    /**
     * Move selected cabinet so its AABB edge touches the neighbor in `dir`.
     * dir: up | down | left | right (plan coordinates; Y increases downward).
     */
    async function snapToAdjacentRack(dir) {
      const c = cabinets.find(function (x) { return Number(x.cabinet_id) === Number(selectedId); });
      if (!c) return;
      if (!requireUnlocked(c, 'snapping to an adjacent rack')) return;
      dir = String(dir || '').toLowerCase();
      if (['up', 'down', 'left', 'right'].indexOf(dir) < 0) return;

      const hit = findAdjacentCabinet(c, dir);
      if (!hit) {
        ColdAisle.toast('No rack found to the ' + dir + ' (with overlapping row/column)', 'error');
        return;
      }

      const sa = getCabAabb(c);
      const oa = hit.aabb;
      let left = sa.left;
      let top = sa.top;

      if (dir === 'right') {
        left = oa.left - sa.w;
        // keep current vertical position
      } else if (dir === 'left') {
        left = oa.right;
      } else if (dir === 'down') {
        top = oa.top - sa.h;
      } else if (dir === 'up') {
        top = oa.bottom;
      }

      // Clamp fully inside room
      left = Math.max(0, Math.min(Math.max(0, roomW() - sa.w), left));
      top = Math.max(0, Math.min(Math.max(0, roomD() - sa.h), top));

      const cx = left + sa.w / 2;
      const cy = top + sa.h / 2;
      const pos = posFromCenter(cx, cy, sa.geom);
      c.pos_x = Math.round(pos.x * 1000) / 1000;
      c.pos_y = Math.round(pos.y * 1000) / 1000;

      const neighborName = hit.cabinet.name || ('#' + hit.cabinet.cabinet_id);
      await persistCabinetPosition(c, 'Snapped ' + dir + ' against ' + neighborName);
    }

    async function persistCabinetPosition(c, successMsg) {
      syncPosFieldsFromCabinet(c);
      draw();
      try {
        await ColdAisle.api('api/cabinets.php', {
          method: 'PUT',
          forcePostOverride: true,
          body: {
            cabinet_id: Number(c.cabinet_id),
            pos_x: c.pos_x,
            pos_y: c.pos_y,
          },
        });
        if (successMsg) ColdAisle.toast(successMsg, 'success');
        refresh3d();
      } catch (e) {
        ColdAisle.toast(e.message || 'Failed to save position', 'error');
      }
    }

    /** Directly touching neighbors (AABB), optional same-facing filter. */
    function getTouchingCabinets(self, sameFacingOnly) {
      const sa = getCabAabb(self);
      const selfId = Number(self.cabinet_id);
      const selfFacing = (self.front_facing || rotationToFacing(self.rotation_deg) || 'north').toLowerCase();
      return cabinets.filter(function (other) {
        if (Number(other.cabinet_id) === selfId) return false;
        if (sameFacingOnly) {
          const of = (other.front_facing || rotationToFacing(other.rotation_deg) || 'north').toLowerCase();
          if (of !== selfFacing) return false;
        }
        return aabbsTouch(sa, getCabAabb(other));
      });
    }

    /**
     * Connected component of touching cabinets with same facing, starting from seed.
     * Stops at gaps — will not jump to a parallel cold-aisle row.
     */
    function touchingCluster(seed) {
      const seedFacing = (seed.front_facing || rotationToFacing(seed.rotation_deg) || 'north').toLowerCase();
      const seen = new Set();
      const queue = [seed];
      const out = [];
      while (queue.length) {
        const cur = queue.shift();
        const id = Number(cur.cabinet_id);
        if (seen.has(id)) continue;
        seen.add(id);
        out.push(cur);
        getTouchingCabinets(cur, true).forEach(function (n) {
          if (!seen.has(Number(n.cabinet_id))) queue.push(n);
        });
      }
      // Ensure all share seed facing (already filtered)
      return out.filter(function (c) {
        return (c.front_facing || rotationToFacing(c.rotation_deg) || 'north').toLowerCase() === seedFacing;
      });
    }

    /** Align this rack's front to the best touching neighbor (prefer side neighbors). */
    async function alignFrontToNeighbor() {
      const c = cabinets.find(function (x) { return Number(x.cabinet_id) === Number(selectedId); });
      if (!c) return;
      if (!requireUnlocked(c, 'aligning front')) return;
      const neighbors = getTouchingCabinets(c, true);
      if (!neighbors.length) {
        ColdAisle.toast('No touching rack with the same front facing', 'error');
        return;
      }
      // Prefer neighbor with largest contact length (side-by-side in a row)
      const sa = getCabAabb(c);
      let best = null;
      let bestScore = -1;
      neighbors.forEach(function (n) {
        const na = getCabAabb(n);
        const xOverlap = Math.min(sa.right, na.right) - Math.max(sa.left, na.left);
        const yOverlap = Math.min(sa.bottom, na.bottom) - Math.max(sa.top, na.top);
        const score = Math.max(xOverlap, 0) + Math.max(yOverlap, 0);
        if (score > bestScore) {
          bestScore = score;
          best = n;
        }
      });
      const target = frontGeometry(best);
      const pos = alignFrontToTarget(c, target);
      if (!pos) {
        ColdAisle.toast('Could not align fronts (facing mismatch)', 'error');
        return;
      }
      c.pos_x = pos.x;
      c.pos_y = pos.y;
      await persistCabinetPosition(c, 'Front aligned to ' + (best.name || 'neighbor'));
    }

    function setFrontAnchor() {
      const c = cabinets.find(function (x) { return Number(x.cabinet_id) === Number(selectedId); });
      if (!c) return;
      if (Number(anchorId) === Number(c.cabinet_id)) {
        anchorId = null;
        ColdAisle.toast('Anchor cleared', 'info');
      } else {
        anchorId = Number(c.cabinet_id);
        ColdAisle.toast('Anchor set: ' + (c.name || ('#' + c.cabinet_id)) + ' — use Align Cluster Fronts', 'success');
      }
      renderProps();
      draw();
    }

    /**
     * Align fronts of the touching same-facing cluster to the anchor rack.
     * Only the connected component of touching racks — other rows are untouched.
     */
    async function alignClusterFrontsToAnchor() {
      let anchor = cabinets.find(function (x) { return Number(x.cabinet_id) === Number(anchorId); });
      if (!anchor) {
        // Fall back to selected as anchor
        anchor = cabinets.find(function (x) { return Number(x.cabinet_id) === Number(selectedId); });
        if (!anchor) {
          ColdAisle.toast('Select a rack and Set Anchor first', 'error');
          return;
        }
        anchorId = Number(anchor.cabinet_id);
      }
      const cluster = touchingCluster(anchor);
      if (cluster.length < 2) {
        ColdAisle.toast('No touching racks with the same front facing around the anchor', 'error');
        return;
      }
      // Only move unlocked cabinets in the cluster (anchor may stay locked as reference)
      const target = frontGeometry(anchor);
      let moved = 0;
      let skippedLocked = 0;
      const updates = [];
      cluster.forEach(function (cab) {
        if (Number(cab.cabinet_id) === Number(anchor.cabinet_id)) return;
        if (isPositionLocked(cab)) {
          skippedLocked++;
          return;
        }
        const pos = alignFrontToTarget(cab, target);
        if (!pos) return;
        if (Math.abs(pos.x - (Number(cab.pos_x) || 0)) > 0.0005 ||
            Math.abs(pos.y - (Number(cab.pos_y) || 0)) > 0.0005) {
          cab.pos_x = pos.x;
          cab.pos_y = pos.y;
          updates.push(cab);
          moved++;
        }
      });
      if (!moved && skippedLocked) {
        ColdAisle.toast(
          'Cluster neighbors are locked. Unlock each rack (or Unlock + Align Front) before cluster align.',
          'error'
        );
        return;
      }
      draw();
      for (let i = 0; i < updates.length; i++) {
        const cab = updates[i];
        try {
          await ColdAisle.api('api/cabinets.php', {
            method: 'PUT',
            forcePostOverride: true,
            body: {
              cabinet_id: Number(cab.cabinet_id),
              pos_x: cab.pos_x,
              pos_y: cab.pos_y,
            },
          });
        } catch (e) {
          ColdAisle.toast(e.message || 'Failed saving ' + cab.name, 'error');
        }
      }
      const sel = cabinets.find(function (x) { return Number(x.cabinet_id) === Number(selectedId); });
      if (sel) syncPosFieldsFromCabinet(sel);
      let msg = moved
        ? ('Aligned ' + moved + ' unlocked rack(s) to anchor ' + (anchor.name || ''))
        : 'Cluster already front-aligned';
      if (skippedLocked && moved) {
        msg += ' (' + skippedLocked + ' locked skipped)';
      }
      ColdAisle.toast(msg, moved ? 'success' : 'info');
      refresh3d();
    }

    async function createRowAndAssign() {
      if (!roomId()) return;
      const name = window.prompt('New row name', 'Row ' + String.fromCharCode(65 + Math.min(roomRows.length, 25)));
      if (name === null) return;
      try {
        const res = await ColdAisle.api('api/rows.php', {
          method: 'POST',
          body: { room_id: roomId(), name: name.trim() || undefined },
        });
        if (res.row) {
          roomRows.push(res.row);
          const c = cabinets.find(function (x) { return Number(x.cabinet_id) === Number(selectedId); });
          if (c) {
            c.row_id = res.row.row_id;
            c.row_name = res.row.name;
            if (propsEl.querySelector('#p_row')) {
              // refresh options
              renderProps();
              if (propsEl.querySelector('#p_row')) {
                propsEl.querySelector('#p_row').value = String(res.row.row_id);
              }
            }
          }
          ColdAisle.toast('Row created: ' + res.row.name, 'success');
          draw();
        }
      } catch (e) {
        ColdAisle.toast(e.message || 'Failed to create row', 'error');
      }
    }

    async function saveRoom() {
      if (!room) return;
      const name = propsEl.querySelector('#r_name').value.trim();
      const width_m = Math.round(displayToM(propsEl.querySelector('#r_w').value) * 100) / 100;
      const depth_m = Math.round(displayToM(propsEl.querySelector('#r_d').value) * 100) / 100;
      const edge = propsEl.querySelector('#r_north').value;
      const gft = parseFloat(propsEl.querySelector('#r_grid_ft').value) || 1;
      if (!(width_m > 0) || !(depth_m > 0)) {
        ColdAisle.toast('Width and depth must be greater than zero', 'error');
        return;
      }
      try {
        const res = await ColdAisle.api('api/floorplan.php?action=update_room', {
          method: 'POST',
          body: {
            room_id: roomId(),
            name: name || room.name,
            width_m: width_m,
            depth_m: depth_m,
            north_edge: edge,
          },
        });
        room = res.room || Object.assign({}, room, {
          name: name,
          width_m: width_m,
          depth_m: depth_m,
          north_edge: edge,
        });
        northEdge = (room.north_edge || edge || 'top').toLowerCase();
        gridFt = gft;
        await persistPlannerPrefs();
        // Recompute rotations from facing after north change
        cabinets.forEach(function (cab) {
          if (cab.front_facing) {
            cab.rotation_deg = facingToRotation(cab.front_facing);
          }
        });
        const opt = roomSelect.querySelector('option[value="' + roomId() + '"]');
        if (opt && room.dc_name) {
          opt.textContent = room.dc_name + ' / ' + room.name;
        }
        resizeCanvas();
        refresh3d();
        renderProps();
        ColdAisle.toast('Room & layout saved', 'success');
      } catch (e) {
        ColdAisle.toast(e.message || 'Failed to save room', 'error');
      }
    }

    async function persistPlannerPrefs() {
      try {
        await ColdAisle.api('api/floorplan.php?action=set_planner_prefs', {
          method: 'POST',
          body: {
            show_grid: showGrid,
            snap_to_grid: snapToGrid,
            grid_ft: gridFt,
          },
        });
      } catch (e) {
        console.warn('Could not persist planner prefs', e);
      }
    }

    async function saveProps() {
      const c = cabinets.find(function (x) { return Number(x.cabinet_id) === Number(selectedId); });
      if (!c) return;
      const locked = isPositionLocked(c);
      const xEl = propsEl.querySelector('#p_x');
      const yEl = propsEl.querySelector('#p_y');
      const facing = propsEl.querySelector('#p_facing').value;
      const rowVal = propsEl.querySelector('#p_row') ? propsEl.querySelector('#p_row').value : '';

      // Non-position fields always saved
      const payload = {
        cabinet_id: Number(c.cabinet_id),
        name: propsEl.querySelector('#p_name').value,
        u_height: parseInt(propsEl.querySelector('#p_u').value, 10),
        width_mm: displayToMm(propsEl.querySelector('#p_w').value),
        depth_mm: displayToMm(propsEl.querySelector('#p_d').value),
        color_hex: propsEl.querySelector('#p_color').value,
        row_id: rowVal === '' ? null : parseInt(rowVal, 10),
        front_facing: facing,
      };

      // Position / rotation: only when UNLOCKED. Locked save must not touch coordinates
      // (sending pos + clamp was re-snapping cabinets when editing name/row).
      const savedPosX = Number(c.pos_x) || 0;
      const savedPosY = Number(c.pos_y) || 0;
      const savedRot = cabRotation(c);

      if (!locked) {
        let posX = savedPosX;
        let posY = savedPosY;
        if (xEl && yEl && (xEl.dataset.userEdited === '1' || yEl.dataset.userEdited === '1')) {
          posX = displayToM(xEl.value);
          posY = displayToM(yEl.value);
        }
        const rot = facingToRotation(facing);
        const clamped = clampCabinetPosition(posX, posY, {
          width_mm: payload.width_mm,
          depth_mm: payload.depth_mm,
          front_facing: facing,
          rotation_deg: rot,
        });
        payload.pos_x = clamped.x;
        payload.pos_y = clamped.y;
        payload.rotation_deg = rot;
        c.pos_x = payload.pos_x;
        c.pos_y = payload.pos_y;
        c.rotation_deg = rot;
        c.front_facing = facing;
      }
      // When locked: omit pos_x, pos_y, rotation_deg from payload entirely

      try {
        const res = await ColdAisle.api('api/cabinets.php', {
          method: 'PUT',
          forcePostOverride: true,
          body: payload,
        });
        Object.assign(c, res.cabinet || payload);
        // Always restore pre-save coordinates if locked; if unlocked keep what we just set
        if (locked) {
          c.pos_x = savedPosX;
          c.pos_y = savedPosY;
          c.rotation_deg = savedRot;
          c.front_facing = facing; // facing label may update without moving
        } else {
          c.pos_x = payload.pos_x != null ? payload.pos_x : c.pos_x;
          c.pos_y = payload.pos_y != null ? payload.pos_y : c.pos_y;
        }
        if (payload.row_id) {
          const rr = roomRows.find(function (r) { return Number(r.row_id) === Number(payload.row_id); });
          c.row_name = rr ? rr.name : c.row_name;
          c.row_id = payload.row_id;
        } else {
          c.row_id = null;
          c.row_name = null;
        }
        // Lock after successful save
        setPositionLocked(c, true);
        syncPosFieldsFromCabinet(c);
        renderProps();
        updateCanvasCursor();
        draw();
        refresh3d();
        ColdAisle.toast(
          locked ? 'Cabinet saved (position unchanged, locked)' : 'Cabinet saved (position locked)',
          'success'
        );
      } catch (e) {
        ColdAisle.toast(e.message || 'Save failed', 'error');
      }
    }

    async function deleteSelected() {
      if (!selectedId || !confirm('Delete this cabinet?')) return;
      try {
        await ColdAisle.api('api/cabinets.php?id=' + encodeURIComponent(selectedId), {
          method: 'DELETE',
          forcePostOverride: true,
        });
        cabinets = cabinets.filter(function (c) { return Number(c.cabinet_id) !== Number(selectedId); });
        selectedId = null;
        renderProps();
        draw();
        refresh3d();
        ColdAisle.toast('Cabinet deleted', 'success');
      } catch (e) {
        ColdAisle.toast(e.message || 'Delete failed', 'error');
      }
    }

    async function loadRoom(id) {
      if (!id) {
        room = null;
        cabinets = [];
        floorPdus = [];
        unplacedPdus = [];
        propsEl.innerHTML = '<p class="text-muted">Create a room first under Data Centers.</p>';
        renderUnplacedPduPalette();
        draw();
        return;
      }
      try {
        const data = await ColdAisle.api('api/floorplan.php?room_id=' + encodeURIComponent(id));
        room = data.room;
        cabinets = data.cabinets || [];
        floorPdus = data.placed_pdus || [];
        unplacedPdus = data.unplaced_pdus || [];
        roomRows = data.rows || [];
        powerZones = data.zones || [];
        unlockedIds.clear(); // all placed racks load locked
        unlockedPduIds.clear();
        selectedIds.clear();
        selectedId = null;
        selectedPduId = null;
        northEdge = String((room && room.north_edge) || 'top').toLowerCase();
        if (data.units === 'imperial' || data.units === 'metric') {
          units = data.units;
          if (window.ColdAisle) window.ColdAisle.lengthUnits = units;
        }
        if (data.planner) {
          showGrid = data.planner.show_grid !== false;
          snapToGrid = data.planner.snap_to_grid !== false;
          gridFt = Number(data.planner.grid_ft) > 0 ? Number(data.planner.grid_ft) : 1;
        }
        cabinets.forEach(function (cab) {
          if (!cab.front_facing && cab.rotation_deg != null) {
            cab.front_facing = rotationToFacing(cab.rotation_deg);
          }
          if (cab.front_facing) {
            cab.rotation_deg = facingToRotation(cab.front_facing);
          }
        });
        floorPdus.forEach(function (p) {
          if (!p.front_facing && p.rotation_deg != null) {
            p.front_facing = rotationToFacing(p.rotation_deg);
          }
          if (p.front_facing) {
            p.rotation_deg = facingToRotation(p.front_facing);
          }
          if (!p.width_mm) p.width_mm = 600;
          if (!p.depth_mm) p.depth_mm = 300;
          if (!p.height_mm) p.height_mm = 1800;
          if (!p.color_hex) p.color_hex = p.zone_color || '#b45309';
        });
        selectedId = null;
        pendingTemplate = null;
        pendingPdu = null;
        clearPaletteSelection();
        updateToolbarButtons();
        renderPduPresetPalette();
        renderUnplacedPduPalette();
        renderProps();
        resizeCanvas();
        refresh3d();
      } catch (e) {
        ColdAisle.toast(e.message || 'Failed to load room', 'error');
      }
    }

    function renderPduPresetPalette() {
      const list = root.querySelector('#pduPresetList');
      if (!list) return;
      list.innerHTML = '';
      PDU_FOOTPRINT_PRESETS.forEach(function (pr) {
        const el = document.createElement('div');
        el.className = 'palette-item pdu-preset';
        el.draggable = true;
        el.dataset.pduKind = 'preset';
        el.dataset.presetKey = pr.key;
        el.dataset.name = pr.name;
        el.dataset.width = String(pr.width_mm);
        el.dataset.depth = String(pr.depth_mm);
        el.dataset.height = String(pr.height_mm || 1800);
        el.dataset.color = pr.color_hex;
        el.dataset.scope = pr.pdu_scope || 'row';
        el.dataset.facing = 'north';
        const iconW = Math.max(22, Math.min(48, Math.round(pr.width_mm / 20)));
        const iconH = Math.max(16, Math.min(40, Math.round(pr.depth_mm / 12)));
        el.innerHTML =
          '<div class="rack-icon" style="width:' + iconW + 'px;height:' + iconH + 'px;background:' + pr.color_hex +
          ';margin:0 auto .25rem"></div>' +
          '<div class="palette-title">⚡ ' + esc(pr.name) + '</div>' +
          '<small class="text-muted palette-size">' + pr.width_mm + '×' + pr.depth_mm +
          ' × H' + (pr.height_mm || 1800) + ' mm</small>';
        list.appendChild(el);
        bindPduPaletteItem(el);
      });
      updatePaletteLabels();
    }

    function renderUnplacedPduPalette() {
      const list = root.querySelector('#pduUnplacedList');
      if (!list) return;
      list.innerHTML = '';
      if (!unplacedPdus.length) {
        list.innerHTML = '<p class="text-muted" style="font-size:.75rem;margin:0">No unplaced row/room PDUs for this DC.</p>';
        return;
      }
      unplacedPdus.forEach(function (p) {
        const el = document.createElement('div');
        el.className = 'palette-item pdu-unplaced';
        el.draggable = true;
        el.dataset.pduKind = 'existing';
        el.dataset.pduId = String(p.pdu_id);
        el.dataset.name = p.name || ('PDU ' + p.pdu_id);
        el.dataset.width = String(p.width_mm || 600);
        el.dataset.depth = String(p.depth_mm || 300);
        el.dataset.height = String(p.height_mm || 1800);
        el.dataset.color = p.color_hex || p.zone_color || '#b45309';
        el.dataset.facing = p.front_facing || 'north';
        el.dataset.scope = p.pdu_scope || 'row';
        el.dataset.zoneId = p.zone_id != null ? String(p.zone_id) : '';
        el.dataset.rowId = p.row_id != null ? String(p.row_id) : '';
        el.title = (p.zone_name ? p.zone_name + ' · ' : '') + (p.pdu_scope || 'row');
        el.innerHTML =
          '<div class="palette-title">⚡ ' + esc(p.name || ('PDU #' + p.pdu_id)) + '</div>' +
          '<small class="text-muted">' + esc(p.pdu_scope || 'row') +
          (p.zone_name ? ' · ' + esc(p.zone_name) : '') + '</small>';
        list.appendChild(el);
        bindPduPaletteItem(el);
      });
    }

    function pduPayloadFromItem(item) {
      const kind = item.dataset.pduKind || 'preset';
      return {
        kind: kind,
        pdu_id: kind === 'existing' ? parseInt(item.dataset.pduId || '0', 10) : null,
        name: item.dataset.name || 'Row PDU',
        width_mm: parseInt(item.dataset.width || '600', 10),
        depth_mm: parseInt(item.dataset.depth || '300', 10),
        height_mm: parseInt(item.dataset.height || '1800', 10),
        color_hex: item.dataset.color || '#b45309',
        front_facing: item.dataset.facing || 'north',
        pdu_scope: item.dataset.scope || 'row',
        zone_id: item.dataset.zoneId ? parseInt(item.dataset.zoneId, 10) : null,
        row_id: item.dataset.rowId ? parseInt(item.dataset.rowId, 10) : null,
        output_mode: 'breakers',
        num_breaker_slots: 42,
        phases: 3,
        phase_wiring: 'wye',
      };
    }

    function bindPduPaletteItem(item) {
      item.setAttribute('draggable', 'true');
      item.addEventListener('dragstart', function (e) {
        const payload = pduPayloadFromItem(item);
        const json = JSON.stringify(payload);
        try { e.dataTransfer.setData(DRAG_MIME_PDU, json); } catch (err) { /* ignore */ }
        e.dataTransfer.setData('text/plain', DRAG_TEXT_PREFIX_PDU + json);
        e.dataTransfer.effectAllowed = 'copy';
        item.classList.add('dragging');
      });
      item.addEventListener('dragend', function () { item.classList.remove('dragging'); });
      item.addEventListener('click', function () {
        clearPaletteSelection();
        item.classList.add('selected');
        pendingTemplate = null;
        pendingPdu = pduPayloadFromItem(item);
        draw();
        ColdAisle.toast('Click on the floor plan to place: ' + (pendingPdu.name || 'PDU'), 'info');
      });
    }

    async function placePduAt(posX, posY, payload) {
      if (!room || !roomId()) {
        ColdAisle.toast('Select a room first', 'error');
        return;
      }
      const defs = payload || {};
      const facing = defs.front_facing || 'north';
      const draft = {
        width_mm: defs.width_mm || 600,
        depth_mm: defs.depth_mm || 300,
        height_mm: defs.height_mm || 1800,
        front_facing: facing,
        rotation_deg: facingToRotation(facing),
      };
      const sn = snapCabinetPosition(posX, posY, draft, false);
      try {
        let res;
        if (defs.kind === 'existing' && defs.pdu_id) {
          res = await ColdAisle.api('api/floorplan.php?action=place_pdu', {
            method: 'POST',
            body: {
              pdu_id: Number(defs.pdu_id),
              room_id: roomId(),
              pos_x: sn.x,
              pos_y: sn.y,
              width_mm: draft.width_mm,
              depth_mm: draft.depth_mm,
              height_mm: draft.height_mm,
              front_facing: facing,
              rotation_deg: draft.rotation_deg,
              color_hex: defs.color_hex || '#b45309',
              zone_id: defs.zone_id,
              row_id: defs.row_id,
            },
          });
          unplacedPdus = unplacedPdus.filter(function (x) { return Number(x.pdu_id) !== Number(defs.pdu_id); });
          renderUnplacedPduPalette();
        } else {
          res = await ColdAisle.api('api/floorplan.php?action=create_floor_pdu', {
            method: 'POST',
            body: {
              room_id: roomId(),
              name: defs.name || ('Row PDU ' + (floorPdus.length + 1)),
              pdu_scope: defs.pdu_scope || 'row',
              pos_x: sn.x,
              pos_y: sn.y,
              width_mm: draft.width_mm,
              depth_mm: draft.depth_mm,
              height_mm: draft.height_mm,
              front_facing: facing,
              rotation_deg: draft.rotation_deg,
              color_hex: defs.color_hex || '#b45309',
              zone_id: defs.zone_id,
              row_id: defs.row_id,
              output_mode: defs.output_mode || 'breakers',
              num_breaker_slots: defs.num_breaker_slots || 42,
              phases: defs.phases || 3,
              phase_wiring: defs.phase_wiring || 'wye',
            },
          });
        }
        if (!res || !res.pdu) {
          throw new Error('Server did not return the PDU');
        }
        const p = res.pdu;
        if (!p.width_mm) p.width_mm = draft.width_mm;
        if (!p.depth_mm) p.depth_mm = draft.depth_mm;
        if (!p.height_mm) p.height_mm = draft.height_mm;
        floorPdus.push(p);
        setPduPositionLocked(p, false);
        selectPdu(p);
        draw();
        refresh3d();
        ColdAisle.toast(
          (defs.kind === 'existing' ? 'PDU placed' : 'Row PDU created') + ' (unlocked — adjust then Save)',
          'success'
        );
      } catch (e) {
        ColdAisle.toast(e.message || 'Failed to place PDU', 'error');
      }
    }

    async function createCabinetAt(posX, posY, defaults) {
      if (!room || !roomId()) {
        ColdAisle.toast('Select a room first', 'error');
        return;
      }
      const defs = defaults || {};
      const facing = defs.front_facing || 'north';
      const draft = Object.assign({
        width_mm: 600,
        depth_mm: 1200,
        front_facing: facing,
        rotation_deg: facingToRotation(facing),
      }, defs);
      draft.rotation_deg = facingToRotation(draft.front_facing || facing);
      // Treat click as approximate top-left of AABB, then convert via rotation-aware snap
      const sn = snapCabinetPosition(posX, posY, draft, false);

      const autoName = defs.name || defs.label || ('CAB-' + (cabinets.length + 1));
      const payload = Object.assign({
        room_id: roomId(),
        name: autoName,
        u_height: 42,
        width_mm: 600,
        depth_mm: 1200,
        pos_x: sn.x,
        pos_y: sn.y,
        front_facing: facing,
        rotation_deg: facingToRotation(facing),
        color_hex: '#2d3748',
      }, defs);
      payload.pos_x = sn.x;
      payload.pos_y = sn.y;
      payload.name = payload.name || autoName;
      payload.front_facing = payload.front_facing || facing;
      payload.rotation_deg = facingToRotation(payload.front_facing);
      // Keep only API fields for POST (extras used for notes/model)
      if (payload.label) delete payload.label;
      // vendor_name / sku_summary / model_key accepted by API

      try {
        const res = await ColdAisle.api('api/cabinets.php', { method: 'POST', body: payload });
        if (!res || !res.cabinet) {
          throw new Error('Server did not return the new cabinet');
        }
        cabinets.push(res.cabinet);
        // New placements stay unlocked until Save so you can fine-tune
        setPositionLocked(res.cabinet, false);
        selectCabinet(res.cabinet);
        draw();
        refresh3d();
        ColdAisle.toast(
          'Cabinet placed (unlocked — adjust then Save to lock). Front → ' +
            (res.cabinet.front_facing || facing).toUpperCase(),
          'success'
        );
      } catch (e) {
        ColdAisle.toast(e.message || 'Failed to place cabinet', 'error');
      }
    }

    function parseTemplateData(dt) {
      if (!dt) return null;
      let raw = '';
      try { raw = dt.getData(DRAG_MIME) || ''; } catch (e) { /* ignore */ }
      if (!raw) {
        try { raw = dt.getData('text/plain') || dt.getData('Text') || ''; } catch (e2) { raw = ''; }
      }
      if (!raw) return null;
      if (raw.indexOf(DRAG_TEXT_PREFIX) === 0) raw = raw.slice(DRAG_TEXT_PREFIX.length);
      try { return JSON.parse(raw); } catch (e) { return null; }
    }

    function templateFromPaletteItem(item) {
      const vendorShort = (item.dataset.vendorName || item.dataset.vendor || '').split('/')[0].trim();
      const u = parseInt(item.dataset.u || '42', 10);
      const defaultName = item.dataset.name ||
        ((vendorShort ? vendorShort + ' ' : '') + u + 'U');
      return {
        u_height: u,
        width_mm: parseInt(item.dataset.width || '600', 10),
        depth_mm: parseInt(item.dataset.depth || '1200', 10),
        color_hex: item.dataset.color || '#2d3748',
        front_facing: item.dataset.facing || 'north',
        name: defaultName,
        label: item.dataset.label || defaultName,
        model_key: item.dataset.modelKey || '',
        vendor: item.dataset.vendor || '',
        vendor_name: item.dataset.vendorName || '',
        family: item.dataset.family || '',
        sku_summary: item.dataset.skuSummary || '',
      };
    }

    function clearPaletteSelection() {
      root.querySelectorAll('.palette-item').forEach(function (el) {
        el.classList.remove('selected');
      });
      pendingPdu = null;
    }

    function refresh3d() {
      if (!show3d || !view3d || !window.ColdAisle3D) return;
      if (view3dInstance && view3dInstance.dispose) view3dInstance.dispose();
      view3dInstance = ColdAisle3D.mount(view3d, {
        cabinets: cabinets,
        pdus: floorPdus,
        rooms: room ? [room] : [],
        interactive: true,
      });
    }

    // --- interactions ---
    // Left-drag empty floor (or middle-button anywhere) pans the view via scroll wrap.
    // Left-drag unlocked cabinet moves the cabinet.
    canvas.addEventListener('pointerdown', function (e) {
      const pt = canvasPoint(e);
      const hit = hitTest(pt.x, pt.y);
      const isMiddle = e.button === 1;
      const isLeft = e.button === 0 || e.button == null;

      // Middle mouse: always pan
      if (isMiddle) {
        e.preventDefault();
        pan = { lastX: e.clientX, lastY: e.clientY, pointerId: e.pointerId };
        drag = null;
        updateCanvasCursor();
        try { canvas.setPointerCapture(e.pointerId); } catch (err) { /* ignore */ }
        return;
      }

      if (!isLeft) return;

      if ((pendingTemplate || pendingPdu) && !hit) {
        const w = worldFromCanvas(pt.x, pt.y);
        if (pendingPdu) {
          const pduTmpl = pendingPdu;
          pendingPdu = null;
          pendingTemplate = null;
          clearPaletteSelection();
          placePduAt(w.x, w.y, pduTmpl);
        } else {
          const tmpl = pendingTemplate;
          pendingTemplate = null;
          clearPaletteSelection();
          createCabinetAt(w.x, w.y, tmpl);
        }
        return;
      }

      if (hit && hit.type === 'pdu') {
        const pdu = hit.obj;
        selectPdu(pdu);
        pan = null;
        if (!isPduPositionLocked(pdu) && !e.shiftKey) {
          const r = pduRect(pdu);
          drag = {
            kind: 'pdu',
            id: Number(pdu.pdu_id),
            ox: pt.x - r.x,
            oy: pt.y - r.y,
            startX: pt.x,
            startY: pt.y,
            active: false,
            startWorld: {
              x: Number(pdu.pos_x) || 0,
              y: Number(pdu.pos_y) || 0,
            },
          };
          try { canvas.setPointerCapture(e.pointerId); } catch (err) { /* ignore */ }
        } else {
          drag = null;
        }
        updateCanvasCursor(true);
      } else if (hit && hit.type === 'cabinet') {
        const cab = hit.obj;
        const additive = !!(e.shiftKey);
        selectCabinet(cab, { additive: additive });
        pan = null;
        // Only start a drag session when unlocked — locked racks are selectable only
        // Multi-select: drag moves all unlocked selected together
        if (!isPositionLocked(cab) && !additive) {
          const r = cabRect(cab);
          const group = getSelectedCabinets().filter(function (c) { return !isPositionLocked(c); });
          const origins = {};
          group.forEach(function (c) {
            origins[Number(c.cabinet_id)] = {
              x: Number(c.pos_x) || 0,
              y: Number(c.pos_y) || 0,
            };
          });
          drag = {
            kind: 'cabinet',
            id: Number(cab.cabinet_id),
            ox: pt.x - r.x,
            oy: pt.y - r.y,
            startX: pt.x,
            startY: pt.y,
            active: false,
            groupIds: group.map(function (c) { return Number(c.cabinet_id); }),
            origins: origins,
            startWorld: {
              x: Number(cab.pos_x) || 0,
              y: Number(cab.pos_y) || 0,
            },
          };
          try { canvas.setPointerCapture(e.pointerId); } catch (err) { /* ignore */ }
        } else {
          drag = null;
        }
        updateCanvasCursor(true);
      } else {
        // Empty floor: pan the plan view (clear multi-select unless SHIFT held)
        if (!e.shiftKey) {
          selectCabinet(null);
          selectedPduId = null;
        }
        drag = null;
        pan = { lastX: e.clientX, lastY: e.clientY, pointerId: e.pointerId };
        updateCanvasCursor();
        try { canvas.setPointerCapture(e.pointerId); } catch (err) { /* ignore */ }
      }
    });

    canvas.addEventListener('pointermove', function (e) {
      // Hover cursor when not dragging
      if (!drag && !pan) {
        const pt = canvasPoint(e);
        const hit = hitTest(pt.x, pt.y);
        updateCanvasCursor(!!hit);
      }

      // Pan view
      if (pan) {
        const wrap = getScrollWrap();
        if (wrap) {
          const dx = e.clientX - pan.lastX;
          const dy = e.clientY - pan.lastY;
          wrap.scrollLeft -= dx;
          wrap.scrollTop -= dy;
          pan.lastX = e.clientX;
          pan.lastY = e.clientY;
        }
        canvas.style.cursor = 'grabbing';
        return;
      }

      if (!drag) return;
      if (drag.kind === 'pdu') {
        const p = floorPdus.find(function (x) { return Number(x.pdu_id) === drag.id; });
        if (!p || isPduPositionLocked(p)) {
          drag = null;
          return;
        }
        const ptP = canvasPoint(e);
        if (!drag.active) {
          const dx = ptP.x - drag.startX;
          const dy = ptP.y - drag.startY;
          if ((dx * dx + dy * dy) < DRAG_THRESHOLD_PX * DRAG_THRESHOLD_PX) return;
          drag.active = true;
        }
        let x = (ptP.x - drag.ox - ORIGIN) / scale();
        let y = (ptP.y - drag.oy - ORIGIN) / scale();
        const sn = snapCabinetPosition(x, y, p, false);
        p.pos_x = sn.x;
        p.pos_y = sn.y;
        const xEl = propsEl.querySelector('#fp_x');
        const yEl = propsEl.querySelector('#fp_y');
        if (xEl) xEl.value = fmtLen(p.pos_x);
        if (yEl) yEl.value = fmtLen(p.pos_y);
        draw();
        return;
      }

      const c = cabinets.find(function (x) { return Number(x.cabinet_id) === drag.id; });
      if (!c || isPositionLocked(c)) {
        drag = null;
        return;
      }
      const pt = canvasPoint(e);
      if (!drag.active) {
        const dx = pt.x - drag.startX;
        const dy = pt.y - drag.startY;
        if ((dx * dx + dy * dy) < DRAG_THRESHOLD_PX * DRAG_THRESHOLD_PX) {
          return; // ignore click jitter
        }
        drag.active = true;
      }
      let x = (pt.x - drag.ox - ORIGIN) / scale();
      let y = (pt.y - drag.oy - ORIGIN) / scale();
      const sn = snapCabinetPosition(x, y, c, false);
      const deltaX = sn.x - (drag.startWorld ? drag.startWorld.x : (Number(c.pos_x) || 0));
      const deltaY = sn.y - (drag.startWorld ? drag.startWorld.y : (Number(c.pos_y) || 0));
      // Move primary + all other unlocked selected as a group
      const ids = drag.groupIds && drag.groupIds.length ? drag.groupIds : [drag.id];
      ids.forEach(function (id) {
        const cab = cabinets.find(function (x) { return Number(x.cabinet_id) === Number(id); });
        if (!cab || isPositionLocked(cab)) return;
        const orig = (drag.origins && drag.origins[id]) || { x: Number(cab.pos_x) || 0, y: Number(cab.pos_y) || 0 };
        const cl = clampCabinetPosition(orig.x + deltaX, orig.y + deltaY, cab);
        cab.pos_x = cl.x;
        cab.pos_y = cl.y;
      });
      // Re-apply snap to primary only for grid feel; group keeps relative offsets from origins+delta
      // (primary already at sn via delta from startWorld)
      c.pos_x = sn.x;
      c.pos_y = sn.y;
      syncPosFieldsFromCabinet(c);
      draw();
    });

    function endPanOrDrag(e) {
      if (pan) {
        pan = null;
        updateCanvasCursor();
        return;
      }
      if (!drag) return;
      const wasActive = drag.active;
      const kind = drag.kind || 'cabinet';
      const dragId = drag.id;
      drag = null;
      if (kind === 'pdu') {
        const p = floorPdus.find(function (x) { return Number(x.pdu_id) === Number(dragId); });
        if (!p || !wasActive || isPduPositionLocked(p)) {
          updateCanvasCursor();
          return;
        }
        draw();
        updateCanvasCursor();
        ColdAisle.toast('PDU position updated — click Save to keep & lock', 'info');
        return;
      }
      const c = cabinets.find(function (x) { return Number(x.cabinet_id) === Number(dragId); });
      if (!c || !wasActive || isPositionLocked(c)) {
        updateCanvasCursor();
        return;
      }
      // Keep in-memory position from drag; do not auto-persist until Save
      syncPosFieldsFromCabinet(c);
      draw();
      updateCanvasCursor();
      const multi = getSelectedCabinets();
      if (multi.length > 1) {
        renderMultiProps(multi);
        ColdAisle.toast('Moved ' + multi.length + ' cabinet(s) — click Save & lock positions to keep', 'info');
      } else {
        ColdAisle.toast('Position updated (unlocked) — click Save to keep & lock', 'info');
      }
    }

    canvas.addEventListener('pointerup', endPanOrDrag);
    canvas.addEventListener('pointercancel', endPanOrDrag);
    // Prevent middle-click auto-scroll chrome and context issues on the canvas
    canvas.addEventListener('auxclick', function (e) {
      if (e.button === 1) e.preventDefault();
    });

    // Scroll-wheel zoom (keeps world point under cursor via wrap scroll)
    canvas.addEventListener('wheel', function (e) {
      e.preventDefault();
      const pt = canvasPoint(e);
      const before = worldFromCanvas(pt.x, pt.y);
      const factor = e.deltaY > 0 ? 0.9 : 1.1;
      zoom = Math.min(6, Math.max(0.2, zoom * factor));
      resizeCanvas();
      const wrap = root.querySelector('.planner-canvas-wrap');
      if (wrap) {
        const afterX = before.x * scale() + ORIGIN;
        const afterY = before.y * scale() + ORIGIN;
        const wrapRect = wrap.getBoundingClientRect();
        wrap.scrollLeft = afterX - (e.clientX - wrapRect.left);
        wrap.scrollTop = afterY - (e.clientY - wrapRect.top);
      }
    }, { passive: false });

    // Palette built from vendor catalog + PDU footprints
    renderVendorPalette();
    renderPduPresetPalette();
    renderUnplacedPduPalette();

    canvas.addEventListener('dragover', function (e) {
      e.preventDefault();
      if (e.dataTransfer) e.dataTransfer.dropEffect = 'copy';
    });
    function parsePduDropData(dt) {
      if (!dt) return null;
      let raw = '';
      try { raw = dt.getData(DRAG_MIME_PDU) || ''; } catch (e) { /* ignore */ }
      if (!raw) {
        try { raw = dt.getData('text/plain') || dt.getData('Text') || ''; } catch (e2) { raw = ''; }
      }
      if (!raw) return null;
      if (raw.indexOf(DRAG_TEXT_PREFIX_PDU) === 0) {
        try { return JSON.parse(raw.slice(DRAG_TEXT_PREFIX_PDU.length)); } catch (e3) { return null; }
      }
      // text/plain may be cabinet or pdu
      if (raw.indexOf(DRAG_TEXT_PREFIX) === 0) return null;
      try {
        const o = JSON.parse(raw);
        if (o && (o.kind === 'preset' || o.kind === 'existing' || o.pdu_id)) return o;
      } catch (e4) { /* ignore */ }
      return null;
    }

    function handlePlannerDrop(e) {
      const pduDefaults = parsePduDropData(e.dataTransfer);
      if (pduDefaults) {
        const pt = canvasPoint(e);
        const w = worldFromCanvas(pt.x, pt.y);
        placePduAt(w.x, w.y, pduDefaults);
        pendingTemplate = null;
        pendingPdu = null;
        clearPaletteSelection();
        return true;
      }
      const defaults = parseTemplateData(e.dataTransfer);
      if (!defaults) return false;
      const pt2 = canvasPoint(e);
      const w2 = worldFromCanvas(pt2.x, pt2.y);
      createCabinetAt(w2.x, w2.y, defaults);
      pendingTemplate = null;
      pendingPdu = null;
      clearPaletteSelection();
      return true;
    }

    canvas.addEventListener('drop', function (e) {
      e.preventDefault();
      e.stopPropagation();
      if (!handlePlannerDrop(e)) {
        ColdAisle.toast('Drop failed — click a template, then click the floor instead', 'error');
      }
    });

    const wrapEl = root.querySelector('.planner-canvas-wrap');
    if (wrapEl) {
      wrapEl.addEventListener('dragover', function (e) {
        e.preventDefault();
        if (e.dataTransfer) e.dataTransfer.dropEffect = 'copy';
      });
      wrapEl.addEventListener('drop', function (e) {
        if (e.target === canvas) return;
        e.preventDefault();
        if (!handlePlannerDrop(e)) {
          ColdAisle.toast('Drop failed — click a template, then click the floor instead', 'error');
        }
      });
      // Zoom when wheel over wrap (not only canvas)
      wrapEl.addEventListener('wheel', function (e) {
        if (e.target === canvas) return; // canvas handler owns it
        if (!e.ctrlKey && Math.abs(e.deltaY) > 0) {
          // allow native scroll if not over canvas; still zoom with ctrl optional
        }
      }, { passive: true });
    }

    roomSelect.addEventListener('change', function () {
      loadRoom(roomSelect.value);
    });

    if (mode3dBtn) {
      mode3dBtn.addEventListener('click', function () {
        show3d = !show3d;
        const wrap2d = root.querySelector('.planner-canvas-wrap');
        if (show3d) {
          wrap2d.style.display = 'none';
          view3d.style.display = 'block';
          mode3dBtn.textContent = '2D Plan';
          refresh3d();
        } else {
          wrap2d.style.display = 'block';
          view3d.style.display = 'none';
          mode3dBtn.textContent = '3D View';
          draw();
        }
      });
    }

    if (unitsBtn) {
      unitsBtn.addEventListener('click', async function () {
        units = isImperial() ? 'metric' : 'imperial';
        if (window.ColdAisle) window.ColdAisle.lengthUnits = units;
        updateToolbarButtons();
        renderProps();
        draw();
        try {
          await ColdAisle.api('api/floorplan.php?action=set_units', {
            method: 'POST',
            body: { units: units },
          });
        } catch (e) {
          console.warn(e);
        }
      });
    }

    if (gridBtn) {
      gridBtn.addEventListener('click', function () {
        showGrid = !showGrid;
        updateToolbarButtons();
        draw();
        persistPlannerPrefs();
      });
    }

    if (snapBtn) {
      snapBtn.addEventListener('click', function () {
        snapToGrid = !snapToGrid;
        updateToolbarButtons();
        draw();
        persistPlannerPrefs();
      });
    }

    if (roomSizeBtn) {
      roomSizeBtn.addEventListener('click', function () {
        selectCabinet(null);
        renderProps();
        const el = propsEl.querySelector('#r_w');
        if (el) el.focus();
      });
    }

    root.querySelector('#btnAddCab') && root.querySelector('#btnAddCab').addEventListener('click', function () {
      createCabinetAt(gridStepM(), gridStepM(), { front_facing: 'north' });
    });

    root.querySelector('#btnZoomIn') && root.querySelector('#btnZoomIn').addEventListener('click', function () {
      zoom = Math.min(6, zoom * 1.15);
      resizeCanvas();
    });
    root.querySelector('#btnZoomOut') && root.querySelector('#btnZoomOut').addEventListener('click', function () {
      zoom = Math.max(0.2, zoom / 1.15);
      resizeCanvas();
    });
    root.querySelector('#btnZoomReset') && root.querySelector('#btnZoomReset').addEventListener('click', function () {
      zoom = 1;
      resizeCanvas();
    });

    // Nudge toolbar
    loadNudgePrefs();
    const nudgeAmtEl = root.querySelector('#nudgeAmount');
    const nudgeUnitEl = root.querySelector('#nudgeUnit');
    if (nudgeAmtEl) {
      nudgeAmtEl.addEventListener('change', function () {
        nudgeAmount = Math.abs(Number(nudgeAmtEl.value)) || 1;
        nudgeAmtEl.value = String(nudgeAmount);
        saveNudgePrefs();
      });
    }
    if (nudgeUnitEl) {
      nudgeUnitEl.addEventListener('change', function () {
        nudgeUnit = nudgeUnitEl.value || 'in';
        saveNudgePrefs();
      });
    }

    // Arrow keys nudge (ignore when typing in fields)
    document.addEventListener('keydown', function (e) {
      if (!root.isConnected) return;
      const t = e.target;
      if (t && (t.tagName === 'INPUT' || t.tagName === 'SELECT' || t.tagName === 'TEXTAREA' || t.isContentEditable)) {
        return;
      }
      // Only when floor planner is visible / has selection context
      if (!room) return;
      const map = {
        ArrowLeft: [-1, 0],
        ArrowRight: [1, 0],
        ArrowUp: [0, -1],
        ArrowDown: [0, 1],
      };
      const d = map[e.key];
      if (!d) return;
      if (!selectedIds.size && !selectedPduId) return;
      e.preventDefault();
      nudgeSelected(d[0], d[1]);
    });

    // Init
    updateToolbarButtons();
    if (roomSelect && roomSelect.value) {
      loadRoom(roomSelect.value);
    } else {
      propsEl.innerHTML = '<p class="text-muted">Create a room first under Data Centers.</p>';
    }

    window.addEventListener('resize', resizeCanvas);
  }

  document.addEventListener('DOMContentLoaded', function () {
    const root = document.getElementById('floorplanner');
    if (root) initPlanner(root);
  });
})();
