/**
 * ColdAisle — Three.js rectangular rack / floor visualization
 * Textures front/rear faces with device template images stacked by U position.
 */
(function (global) {
  'use strict';

  function mmToM(mm) { return (Number(mm) || 0) / 1000; }

  function mediaBase() {
    var b = (global.ColdAisle && global.ColdAisle.baseUrl)
      || (global.WINDCIM && global.WINDCIM.baseUrl)
      || '';
    return String(b).replace(/\/$/, '');
  }

  function absUrl(url) {
    if (!url) return '';
    if (/^https?:\/\//i.test(url)) return url;
    var base = mediaBase();
    if (url.charAt(0) === '/') return (base ? base.replace(/^(https?:\/\/[^/]+).*/i, '$1') : '') + url;
    return (base ? base + '/' : '') + url.replace(/^\//, '');
  }

  // Deduplicate loads: many devices share one template faceplate URL
  var imageCache = Object.create(null);
  // Cap concurrent media.php hits so IIS/PHP is not flooded on first paint
  var IMAGE_CONCURRENCY = 8;
  var imageInFlight = 0;
  var imageWaitQueue = [];

  function pumpImageQueue() {
    while (imageInFlight < IMAGE_CONCURRENCY && imageWaitQueue.length) {
      var job = imageWaitQueue.shift();
      imageInFlight++;
      job.start().then(function (result) {
        imageInFlight--;
        job.resolve(result);
        pumpImageQueue();
      }, function () {
        imageInFlight--;
        job.resolve(null);
        pumpImageQueue();
      });
    }
  }

  function loadImage(url) {
    if (!url) {
      return Promise.resolve(null);
    }
    var key = absUrl(url);
    if (Object.prototype.hasOwnProperty.call(imageCache, key)) {
      return imageCache[key];
    }
    imageCache[key] = new Promise(function (resolve) {
      imageWaitQueue.push({
        resolve: resolve,
        start: function () {
          return new Promise(function (res) {
            var img = new Image();
            // Same-origin media.php (session cookie) — do not set crossOrigin
            img.onload = function () { res(img); };
            img.onerror = function () { res(null); };
            img.src = key;
          });
        },
      });
      pumpImageQueue();
    });
    return imageCache[key];
  }

  function drawCover(ctx, img, x, y, w, h) {
    if (!img || w <= 0 || h <= 0) return;
    var iw = img.naturalWidth || img.width;
    var ih = img.naturalHeight || img.height;
    if (!iw || !ih) return;
    var scale = Math.max(w / iw, h / ih);
    var dw = iw * scale;
    var dh = ih * scale;
    var dx = x + (w - dw) / 2;
    var dy = y + (h - dh) / 2;
    ctx.drawImage(img, dx, dy, dw, dh);
  }

  function typeColor(type) {
    var t = String(type || '').toLowerCase();
    if (t.indexOf('switch') >= 0) return '#059669';
    if (t.indexOf('pdu') >= 0) return '#ea580c';
    if (t.indexOf('storage') >= 0) return '#7c3aed';
    if (t.indexOf('router') >= 0) return '#0891b2';
    if (t.indexOf('chassis') >= 0) return '#64748b';
    if (t.indexOf('server') >= 0) return '#2563eb';
    return '#334155';
  }

  function deviceOnFace(d, face) {
    var half = !!Number(d.half_depth);
    var rear = !!Number(d.back_side);
    if (!half) return true;
    return face === 'rear' ? rear : !rear;
  }

  function faceDims(cab) {
    var units = Math.max(1, Number(cab.u_height) || 42);
    // Modest texels — room-scale racks don't need sharp faceplates
    var pxPerU = 12;
    var h = Math.max(48, Math.round(units * pxPerU));
    var w = Math.max(40, Math.round(h * (19 / (units * 1.75))));
    return { units: units, w: w, h: h };
  }

  function faceSignature(cab, face) {
    var parts = [
      String(cab.cabinet_id || ''),
      face,
      String(cab.u_height || 42),
    ];
    (cab.devices || []).forEach(function (d) {
      if (d.position_u == null || !deviceOnFace(d, face)) return;
      var url = face === 'rear' ? (d.rear_image || d.front_image) : (d.front_image || d.rear_image);
      parts.push([
        d.device_id || '',
        d.position_u || '',
        d.u_height || 1,
        d.device_type || '',
        url || '',
      ].join(':'));
    });
    // short hash
    var s = parts.join('|');
    var h = 2166136261;
    for (var i = 0; i < s.length; i++) {
      h ^= s.charCodeAt(i);
      h = Math.imul(h, 16777619);
    }
    return 'ca3d-v1-' + (h >>> 0).toString(36);
  }

  function faceCacheGet(sig) {
    try {
      if (!global.sessionStorage) return null;
      var raw = sessionStorage.getItem(sig);
      if (!raw || raw.length < 32) return null;
      return raw;
    } catch (e) {
      return null;
    }
  }

  function faceCacheSet(sig, dataUrl) {
    try {
      if (!global.sessionStorage || !dataUrl) return;
      // Cap ~2.5MB of face cache entries; drop oldest keys with our prefix
      var budget = 2.5 * 1024 * 1024;
      var used = 0;
      var keys = [];
      for (var i = 0; i < sessionStorage.length; i++) {
        var k = sessionStorage.key(i);
        if (k && k.indexOf('ca3d-v1-') === 0) {
          keys.push(k);
          used += (sessionStorage.getItem(k) || '').length;
        }
      }
      used += dataUrl.length;
      while (used > budget && keys.length) {
        var drop = keys.shift();
        used -= (sessionStorage.getItem(drop) || '').length;
        sessionStorage.removeItem(drop);
      }
      sessionStorage.setItem(sig, dataUrl);
    } catch (e) {
      // quota / private mode — ignore
    }
  }

  function texFromCanvas(canvas) {
    var tex = new THREE.CanvasTexture(canvas);
    tex.needsUpdate = true;
    tex.minFilter = THREE.LinearFilter;
    tex.magFilter = THREE.LinearFilter;
    if (THREE.sRGBEncoding !== undefined) {
      tex.encoding = THREE.sRGBEncoding;
    }
    return tex;
  }

  function texFromDataUrl(dataUrl) {
    return new Promise(function (resolve) {
      var img = new Image();
      img.onload = function () {
        var tex = new THREE.Texture(img);
        tex.needsUpdate = true;
        tex.minFilter = THREE.LinearFilter;
        tex.magFilter = THREE.LinearFilter;
        if (THREE.sRGBEncoding !== undefined) {
          tex.encoding = THREE.sRGBEncoding;
        }
        resolve(tex);
      };
      img.onerror = function () { resolve(null); };
      img.src = dataUrl;
    });
  }

  function paintBayBackground(ctx, w, h, units) {
    ctx.fillStyle = '#0f172a';
    ctx.fillRect(0, 0, w, h);
    ctx.strokeStyle = '#334155';
    ctx.lineWidth = 1;
    for (var u = 0; u <= units; u++) {
      var y = h - (u / units) * h;
      ctx.beginPath();
      ctx.moveTo(0, y);
      ctx.lineTo(w, y);
      ctx.stroke();
    }
    ctx.fillStyle = '#94a3b8';
    ctx.fillRect(0, 0, Math.max(2, w * 0.03), h);
    ctx.fillRect(w - Math.max(2, w * 0.03), 0, Math.max(2, w * 0.03), h);
  }

  function paintDeviceSlot(ctx, d, img, units, w, h) {
    var pos = Math.max(1, Number(d.position_u) || 1);
    var uh = Math.max(1, Number(d.u_height) || 1);
    var bottomU = pos - 1;
    var topU = pos - 1 + uh;
    var yTop = h - (topU / units) * h;
    var yBot = h - (bottomU / units) * h;
    var dh = Math.max(1, yBot - yTop);

    if (img) {
      drawCover(ctx, img, 0, yTop, w, dh);
    } else {
      ctx.fillStyle = typeColor(d.device_type);
      ctx.fillRect(0, yTop, w, dh);
      ctx.strokeStyle = 'rgba(15,23,42,0.8)';
      ctx.strokeRect(0.5, yTop + 0.5, w - 1, dh - 1);
      if (dh >= 10) {
        ctx.fillStyle = '#e2e8f0';
        ctx.font = 'bold ' + Math.max(8, Math.min(12, dh * 0.35)) + 'px Segoe UI,sans-serif';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillText(String(d.label || '').slice(0, 12), w / 2, yTop + dh / 2);
      }
    }
    ctx.strokeStyle = 'rgba(148,163,184,0.45)';
    ctx.beginPath();
    ctx.moveTo(0, yTop);
    ctx.lineTo(w, yTop);
    ctx.stroke();
  }

  /** Instant type-color face (no network) — first paint. */
  function buildFaceTextureSolid(cab, face) {
    var dim = faceDims(cab);
    var canvas = document.createElement('canvas');
    canvas.width = dim.w;
    canvas.height = dim.h;
    var ctx = canvas.getContext('2d');
    paintBayBackground(ctx, dim.w, dim.h, dim.units);
    (cab.devices || []).forEach(function (d) {
      if (d.position_u == null || !deviceOnFace(d, face)) return;
      paintDeviceSlot(ctx, d, null, dim.units, dim.w, dim.h);
    });
    return texFromCanvas(canvas);
  }

  /**
   * Full face with small faceplate images (or session cache).
   * Y maps U1 at bottom of texture (matches rack elevation).
   */
  function buildFaceTexture(cab, face) {
    var sig = faceSignature(cab, face);
    var cached = faceCacheGet(sig);
    if (cached) {
      return texFromDataUrl(cached);
    }

    var dim = faceDims(cab);
    var canvas = document.createElement('canvas');
    canvas.width = dim.w;
    canvas.height = dim.h;
    var ctx = canvas.getContext('2d');
    paintBayBackground(ctx, dim.w, dim.h, dim.units);

    var devices = (cab.devices || []).filter(function (d) {
      return d.position_u != null && deviceOnFace(d, face);
    });

    var loaders = devices.map(function (d) {
      var url = face === 'rear' ? (d.rear_image || d.front_image) : (d.front_image || d.rear_image);
      return loadImage(url).then(function (img) {
        return { d: d, img: img };
      });
    });

    return Promise.all(loaders).then(function (rows) {
      rows.forEach(function (row) {
        paintDeviceSlot(ctx, row.d, row.img, dim.units, dim.w, dim.h);
      });
      try {
        // JPEG data URL is much smaller than PNG for sessionStorage
        faceCacheSet(sig, canvas.toDataURL('image/jpeg', 0.72));
      } catch (e) { /* ignore */ }
      return texFromCanvas(canvas);
    });
  }

  /** Run async jobs with limited concurrency. */
  function mapPool(items, limit, worker) {
    return new Promise(function (resolve) {
      if (!items.length) {
        resolve([]);
        return;
      }
      var i = 0;
      var active = 0;
      var results = new Array(items.length);
      var done = 0;
      function next() {
        while (active < limit && i < items.length) {
          (function (idx) {
            active++;
            Promise.resolve(worker(items[idx], idx)).then(function (r) {
              results[idx] = r;
            }, function () {
              results[idx] = null;
            }).then(function () {
              active--;
              done++;
              if (done === items.length) resolve(results);
              else next();
            });
          })(i++);
        }
      }
      next();
    });
  }

  // --- Raceway / cable path geometry (plan X,Y → scene X,Z; elevation → Y) ---

  function racewayDefaultWidth(kind) {
    var k = String(kind || 'ladder');
    if (k === 'conduit') return 0.05;
    if (k === 'fiber_raceway' || k === 'fiber_trough') return 0.15;
    return 0.30;
  }

  function racewayDefaultElev(feed) {
    return String(feed || 'overhead') === 'underfloor' ? -0.30 : 2.70;
  }

  function parseRacewayPoints(path) {
    var raw = path.waypoints_list || path.waypoints || [];
    if (typeof raw === 'string' && raw) {
      try { raw = JSON.parse(raw); } catch (e) { raw = []; }
    }
    if (!Array.isArray(raw)) return [];
    var out = [];
    for (var i = 0; i < raw.length; i++) {
      var pt = raw[i];
      if (!pt) continue;
      var x = Number(pt.x != null ? pt.x : pt[0]);
      var y = Number(pt.y != null ? pt.y : pt[1]);
      if (!isFinite(x) || !isFinite(y)) continue;
      out.push({
        x: x,
        y: y,
        z: pt.z != null ? Number(pt.z) : null,
        corner: String(pt.corner || 'sharp'),
        radius_m: Number(pt.radius_m) || 0,
      });
    }
    return out;
  }

  /** Sample plan polyline with fillets → list of {x,y} plan meters. */
  function sampleRacewayPlan(pts) {
    if (!pts || pts.length < 1) return [];
    if (pts.length === 1) return [{ x: pts[0].x, y: pts[0].y }];
    var out = [];
    out.push({ x: pts[0].x, y: pts[0].y });
    for (var i = 1; i < pts.length; i++) {
      var prev = pts[i - 1];
      var cur = pts[i];
      var next = pts[i + 1];
      var isEnd = i === pts.length - 1;
      var r = Number(cur.radius_m) || 0;
      var wantFillet = !isEnd && next
        && String(cur.corner || 'sharp') === 'fillet'
        && r > 0.05;
      if (!wantFillet) {
        out.push({ x: cur.x, y: cur.y });
        continue;
      }
      var dIn = Math.hypot(cur.x - prev.x, cur.y - prev.y);
      var dOut = Math.hypot(next.x - cur.x, next.y - cur.y);
      if (dIn < 1e-4 || dOut < 1e-4) {
        out.push({ x: cur.x, y: cur.y });
        continue;
      }
      var maxR = Math.min(dIn, dOut) * 0.45;
      r = Math.min(Math.max(0.05, r), maxR);
      var uxIn = (cur.x - prev.x) / dIn;
      var uyIn = (cur.y - prev.y) / dIn;
      var uxOut = (next.x - cur.x) / dOut;
      var uyOut = (next.y - cur.y) / dOut;
      var t1x = cur.x - uxIn * r;
      var t1y = cur.y - uyIn * r;
      var t2x = cur.x + uxOut * r;
      var t2y = cur.y + uyOut * r;
      // Approach to fillet start
      out.push({ x: t1x, y: t1y });
      // Sample quadratic curve (control = corner)
      var steps = Math.max(4, Math.min(16, Math.ceil(r * 20)));
      for (var s = 1; s <= steps; s++) {
        var t = s / steps;
        var omt = 1 - t;
        var qx = omt * omt * t1x + 2 * omt * t * cur.x + t * t * t2x;
        var qy = omt * omt * t1y + 2 * omt * t * cur.y + t * t * t2y;
        out.push({ x: qx, y: qy });
      }
    }
    // Dedup nearly coincident samples
    var clean = [];
    for (var j = 0; j < out.length; j++) {
      var p = out[j];
      if (!clean.length) {
        clean.push(p);
        continue;
      }
      var last = clean[clean.length - 1];
      if (Math.hypot(p.x - last.x, p.y - last.y) > 0.008) clean.push(p);
    }
    return clean;
  }

  function planToScene3d(planPts, elevM, pathPts) {
    // pathPts optional for per-vertex z override
    var elev = isFinite(elevM) ? elevM : 2.7;
    var out = [];
    for (var i = 0; i < planPts.length; i++) {
      var p = planPts[i];
      var y = elev;
      // Prefer explicit waypoint z when original points align (first/last often)
      if (pathPts && pathPts[i] && pathPts[i].z != null && isFinite(pathPts[i].z)) {
        y = pathPts[i].z;
      }
      out.push(new THREE.Vector3(p.x, y, p.y));
    }
    return out;
  }

  /**
   * Raceway piece material: clone so each segment can fade independently
   * with camera-distance transparency (near cam → see-through).
   */
  function racewayPieceMaterial(baseMat) {
    var m = baseMat.clone();
    m.transparent = true;
    m.opacity = 1;
    m.depthWrite = true;
    m.userData.racewayFade = true;
    m.userData.fadeBaseOpacity = 1;
    return m;
  }

  function placeOrientedBox(group, mat, from, to, width, height, upNudge) {
    var dir = new THREE.Vector3().subVectors(to, from);
    var len = dir.length();
    if (len < 0.004) return;
    dir.normalize();
    var mid = new THREE.Vector3().addVectors(from, to).multiplyScalar(0.5);
    if (upNudge) mid.y += upNudge;
    var geo = new THREE.BoxGeometry(len, height, width);
    var mesh = new THREE.Mesh(geo, racewayPieceMaterial(mat));
    // Align local +X with dir
    var quat = new THREE.Quaternion();
    quat.setFromUnitVectors(new THREE.Vector3(1, 0, 0), dir);
    mesh.quaternion.copy(quat);
    mesh.position.copy(mid);
    mesh.userData.racewayFade = true;
    group.add(mesh);
  }

  function buildLadderRaceway(group, centerline, width, mat) {
    if (!centerline || centerline.length < 2) return;
    var halfW = Math.max(0.04, width / 2);
    var railH = 0.055;
    var railT = 0.022;
    var rungPitch = 0.28;
    var rungH = 0.02;
    var rungT = 0.02;
    // Side rails follow offset polylines
    var left = [];
    var right = [];
    var i;
    for (i = 0; i < centerline.length; i++) {
      var p = centerline[i];
      var tangent;
      if (i < centerline.length - 1) {
        tangent = new THREE.Vector3().subVectors(centerline[i + 1], p);
      } else {
        tangent = new THREE.Vector3().subVectors(p, centerline[i - 1]);
      }
      // Horizontal right vector (plan)
      var flat = new THREE.Vector3(tangent.x, 0, tangent.z);
      if (flat.lengthSq() < 1e-8) flat.set(1, 0, 0);
      else flat.normalize();
      var rightV = new THREE.Vector3().crossVectors(flat, new THREE.Vector3(0, 1, 0)).normalize();
      // If degenerate (vertical), skip offset
      if (!isFinite(rightV.x)) rightV.set(0, 0, 1);
      left.push(new THREE.Vector3(
        p.x - rightV.x * halfW,
        p.y,
        p.z - rightV.z * halfW
      ));
      right.push(new THREE.Vector3(
        p.x + rightV.x * halfW,
        p.y,
        p.z + rightV.z * halfW
      ));
    }
    for (i = 0; i < left.length - 1; i++) {
      placeOrientedBox(group, mat, left[i], left[i + 1], railT, railH, railH / 2);
      placeOrientedBox(group, mat, right[i], right[i + 1], railT, railH, railH / 2);
    }
    // Rungs along cumulative length
    var cum = 0;
    var nextRung = rungPitch * 0.5;
    for (i = 0; i < centerline.length - 1; i++) {
      var a = centerline[i];
      var b = centerline[i + 1];
      var segLen = a.distanceTo(b);
      if (segLen < 1e-4) continue;
      var dir = new THREE.Vector3().subVectors(b, a).normalize();
      var flatT = new THREE.Vector3(dir.x, 0, dir.z);
      if (flatT.lengthSq() < 1e-8) flatT.set(1, 0, 0);
      else flatT.normalize();
      var rightV2 = new THREE.Vector3().crossVectors(flatT, new THREE.Vector3(0, 1, 0)).normalize();
      if (!isFinite(rightV2.x)) rightV2.set(0, 0, 1);
      var segStart = cum;
      var segEnd = cum + segLen;
      while (nextRung <= segEnd + 1e-6) {
        var t = (nextRung - segStart) / segLen;
        if (t >= 0 && t <= 1.0001) {
          var c = new THREE.Vector3().lerpVectors(a, b, Math.min(1, t));
          var L = new THREE.Vector3(
            c.x - rightV2.x * halfW,
            c.y + railH * 0.35,
            c.z - rightV2.z * halfW
          );
          var R = new THREE.Vector3(
            c.x + rightV2.x * halfW,
            c.y + railH * 0.35,
            c.z + rightV2.z * halfW
          );
          placeOrientedBox(group, mat, L, R, rungT, rungH, 0);
        }
        nextRung += rungPitch;
      }
      cum = segEnd;
    }
  }

  function buildTroughRaceway(group, centerline, width, mat) {
    if (!centerline || centerline.length < 2) return;
    var floorT = 0.018;
    var sideH = 0.06;
    var sideT = 0.012;
    var halfW = Math.max(0.03, width / 2);
    var i;
    for (i = 0; i < centerline.length - 1; i++) {
      var a = centerline[i];
      var b = centerline[i + 1];
      placeOrientedBox(group, mat, a, b, width, floorT, floorT / 2);
      var dir = new THREE.Vector3().subVectors(b, a);
      if (dir.lengthSq() < 1e-8) continue;
      dir.normalize();
      var flat = new THREE.Vector3(dir.x, 0, dir.z);
      if (flat.lengthSq() < 1e-8) flat.set(1, 0, 0);
      else flat.normalize();
      var rightV = new THREE.Vector3().crossVectors(flat, new THREE.Vector3(0, 1, 0)).normalize();
      var aL = a.clone().addScaledVector(rightV, -halfW);
      var bL = b.clone().addScaledVector(rightV, -halfW);
      var aR = a.clone().addScaledVector(rightV, halfW);
      var bR = b.clone().addScaledVector(rightV, halfW);
      placeOrientedBox(group, mat, aL, bL, sideT, sideH, sideH / 2);
      placeOrientedBox(group, mat, aR, bR, sideT, sideH, sideH / 2);
    }
  }

  function buildConduitRaceway(group, centerline, diameter, mat) {
    if (!centerline || centerline.length < 2) return;
    var r = Math.max(0.015, diameter / 2);
    for (var i = 0; i < centerline.length - 1; i++) {
      var a = centerline[i];
      var b = centerline[i + 1];
      var len = a.distanceTo(b);
      if (len < 0.004) continue;
      var geo = new THREE.CylinderGeometry(r, r, len, 10, 1, false);
      var mesh = new THREE.Mesh(geo, racewayPieceMaterial(mat));
      var mid = new THREE.Vector3().addVectors(a, b).multiplyScalar(0.5);
      var dir = new THREE.Vector3().subVectors(b, a).normalize();
      // Cylinder default axis is +Y
      var quat = new THREE.Quaternion();
      quat.setFromUnitVectors(new THREE.Vector3(0, 1, 0), dir);
      mesh.quaternion.copy(quat);
      mesh.position.copy(mid);
      mesh.userData.racewayFade = true;
      group.add(mesh);
    }
  }

  function buildRacewayGroup(path) {
    var pts = parseRacewayPoints(path);
    if (pts.length < 2) return null;
    var kind = String(path.path_kind || path.path_type || 'ladder').toLowerCase();
    if (kind === 'fiber_trough') kind = 'fiber_raceway';
    if (kind === 'tray') kind = 'ladder';
    var feed = String(path.feed_to || path.path_type || 'overhead').toLowerCase();
    var width = Number(path.width_m);
    if (!isFinite(width) || width <= 0) width = racewayDefaultWidth(kind);
    var elev = Number(path.elevation_m);
    if (!isFinite(elev)) elev = racewayDefaultElev(feed);
    var plan = sampleRacewayPlan(pts);
    var centerline = planToScene3d(plan, elev, null);
    if (centerline.length < 2) return null;

    var hex = path.color_hex || '#2563eb';
    var color = new THREE.Color(hex);
    var mat = new THREE.MeshStandardMaterial({
      color: color,
      metalness: kind === 'conduit' ? 0.65 : 0.45,
      roughness: kind === 'conduit' ? 0.35 : 0.5,
      transparent: true,
      opacity: 1,
    });
    var group = new THREE.Group();
    group.userData = { cablePath: path, kind: kind, racewayCenterline: centerline };

    if (kind === 'conduit') {
      buildConduitRaceway(group, centerline, width, mat);
    } else if (kind === 'fiber_raceway' || kind === 'raceway' || kind === 'underfloor') {
      buildTroughRaceway(group, centerline, width, mat);
    } else {
      // ladder (default) — actual ladder pattern with adjustable width
      buildLadderRaceway(group, centerline, width, mat);
    }

    // Soft centerline guide (helps at distance); also camera-fades
    try {
      var lineGeo = new THREE.BufferGeometry().setFromPoints(centerline);
      var lineMat = new THREE.LineBasicMaterial({
        color: color,
        transparent: true,
        opacity: 0.28,
        depthWrite: false,
      });
      lineMat.userData.racewayFade = true;
      lineMat.userData.fadeBaseOpacity = 0.28;
      var line = new THREE.Line(lineGeo, lineMat);
      line.userData.racewayFade = true;
      group.add(line);
    } catch (e) { /* ignore */ }

    return group;
  }

  /** smoothstep 0..1 */
  function smoothstep01(edge0, edge1, x) {
    if (edge1 <= edge0) return x >= edge1 ? 1 : 0;
    var t = (x - edge0) / (edge1 - edge0);
    if (t < 0) t = 0;
    if (t > 1) t = 1;
    return t * t * (3 - 2 * t);
  }

  function mount(container, options) {
    if (!global.THREE) {
      container.innerHTML = '<div class="empty-state"><p>Three.js failed to load.</p></div>';
      return null;
    }

    options = options || {};
    var cabinets = options.cabinets || [];
    var floorPdus = options.pdus || options.floor_pdus || [];
    var floorCooling = options.cooling || options.cooling_units || options.floor_cooling || [];
    var floorUps = options.ups || options.ups_units || options.floor_ups || [];
    var envSensors = options.envSensors || options.env_sensors || [];
    var cablePaths = options.cablePaths || options.cable_paths || options.raceways || [];
    var cableRoutes = options.cableRoutes || options.cable_routes || options.connectionRoutes || [];
    var showObjectLabels = options.showObjectLabels !== false && options.objectLabels !== false;
    var showRacewaysOpt = options.showRaceways !== false && options.racewaysVisible !== false;
    if (!showRacewaysOpt) {
      cablePaths = [];
    }
    // Near-camera raceway fade sphere (see-through so trays never block the room)
    var racewayCamFade = options.racewayCamFade !== false && options.racewayFade !== false;
    // Inner radius (m): fully ghosted. Outer: fully solid. Auto-scales with orbit if unset.
    var racewayFadeNearOpt = Number(options.racewayFadeNear);
    var racewayFadeFarOpt = Number(options.racewayFadeFar);
    var racewayFadeMinAlpha = Number(options.racewayFadeMinAlpha);
    if (!isFinite(racewayFadeMinAlpha)) racewayFadeMinAlpha = 0.05;
    if (racewayFadeMinAlpha < 0) racewayFadeMinAlpha = 0;
    if (racewayFadeMinAlpha > 0.5) racewayFadeMinAlpha = 0.5;
    var heatOverlay = options.heatOverlay !== false;
    var rooms = options.rooms || [];
    var interactive = options.interactive !== false;
    var logoUrl = options.logoUrl || (mediaBase() ? mediaBase() + '/assets/img/logo.svg' : 'assets/img/logo.svg');
    var autoRotate = !!options.autoRotate;
    var autoRotateSpeed = Number(options.autoRotateSpeed);
    if (!isFinite(autoRotateSpeed) || autoRotateSpeed === 0) {
      autoRotateSpeed = 0.003;
    }
    // 'front' = room overview (default, fast). 'both' = front + rear (floor planner).
    // 'none' = solid racks only (public NOC — no auth for media.php faceplates).
    var textureFaces = (options.textureFaces === 'both' || options.textureFaces === 'front'
      || options.textureFaces === 'none')
      ? options.textureFaces
      : 'front';
    var textureConcurrency = Math.max(1, Math.min(6, Number(options.textureConcurrency) || 3));

    var width = container.clientWidth || 600;
    var height = container.clientHeight || 400;

    var scene = new THREE.Scene();
    scene.background = new THREE.Color(0x0a0f18);
    scene.fog = new THREE.Fog(0x0a0f18, 40, 120);

    var camera = new THREE.PerspectiveCamera(45, width / height, 0.1, 500);
    camera.position.set(18, 16, 22);

    var renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true });
    renderer.setSize(width, height);
    renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
    container.innerHTML = '';
    container.appendChild(renderer.domElement);

    scene.add(new THREE.AmbientLight(0xffffff, 0.6));
    var dir = new THREE.DirectionalLight(0xffffff, 0.8);
    dir.position.set(10, 20, 10);
    scene.add(dir);
    var fill = new THREE.DirectionalLight(0x88aaff, 0.28);
    fill.position.set(-8, 8, -5);
    scene.add(fill);

    var room = rooms[0] || { width_m: 30, depth_m: 20, name: 'Floor' };
    // Prefer room dimensions from first cabinet if present
    if (cabinets[0] && cabinets[0].room_width) {
      room = {
        width_m: cabinets[0].room_width,
        depth_m: cabinets[0].room_depth,
        name: cabinets[0].room_name || room.name,
      };
    }
    var fw = Number(room.width_m) || 30;
    var fd = Number(room.depth_m) || 20;

    var floorGeo = new THREE.PlaneGeometry(fw, fd);
    var floorMat = new THREE.MeshStandardMaterial({
      color: 0x1a2332,
      roughness: 0.9,
      metalness: 0.1,
    });
    var floor = new THREE.Mesh(floorGeo, floorMat);
    floor.rotation.x = -Math.PI / 2;
    floor.position.set(fw / 2, 0, fd / 2);
    scene.add(floor);

    var grid = new THREE.GridHelper(Math.max(fw, fd), Math.max(fw, fd), 0x3b82f6, 0x1e293b);
    grid.position.set(fw / 2, 0.01, fd / 2);
    scene.add(grid);

    var edge = new THREE.LineSegments(
      new THREE.EdgesGeometry(new THREE.BoxGeometry(fw, 0.05, fd)),
      new THREE.LineBasicMaterial({ color: 0x475569 })
    );
    edge.position.set(fw / 2, 0.02, fd / 2);
    scene.add(edge);

    var rackGroup = new THREE.Group();
    scene.add(rackGroup);

    var uHeightM = 0.04445; // 1U ≈ 44.45mm

    // Status chip while faceplates stream in
    var statusEl = document.createElement('div');
    statusEl.className = 'dcim-3d-status';
    statusEl.textContent = 'Building racks…';
    statusEl.style.cssText = 'position:absolute;left:10px;bottom:10px;z-index:2;font:12px/1.3 Segoe UI,sans-serif;color:#94a3b8;background:rgba(15,23,42,.72);padding:4px 8px;border-radius:6px;pointer-events:none';
    if (getComputedStyle(container).position === 'static') {
      container.style.position = 'relative';
    }
    container.appendChild(statusEl);

    var faceJobs = []; // { cab, face, mat }

    // Soft health pulse drivers: { mat, prop:'emissiveIntensity'|'opacity', base, amp, speed }
    var healthPulseMats = [];
    // Live health update targets keyed by cabinet_id
    var cabinetHealthNodes = Object.create(null);

    function cabinetHealthStatus(cab) {
      var h = cab && (cab.health || cab.health_status);
      if (!h) return 'unknown';
      if (typeof h === 'string') return h;
      return String(h.status || cab.health_status || 'unknown');
    }

    function normalizeHealthStatus(st) {
      st = String(st || 'unknown').toLowerCase();
      if (st === 'down') return 'crit';
      if (st === 'degraded') return 'warn';
      if (st === 'up') return 'ok';
      return st;
    }

    function healthTintFrom(baseHex, st, displayHex) {
      if (displayHex) return displayHex;
      st = normalizeHealthStatus(st);
      var base = new THREE.Color(baseHex || '#2d3748');
      if (st === 'crit') base.lerp(new THREE.Color(0xef4444), 0.62);
      else if (st === 'warn') base.lerp(new THREE.Color(0xeab308), 0.5);
      else if (st === 'ok') base.lerp(new THREE.Color(0x22c55e), 0.12);
      return '#' + base.getHexString();
    }

    function healthAlertHex(st) {
      st = normalizeHealthStatus(st);
      if (st === 'crit') return 0xef4444;
      if (st === 'warn') return 0xeab308;
      if (st === 'ok') return 0x22c55e;
      return 0x64748b;
    }

    /** Soft radial texture for floor bloom / aura (no hard ring edge). */
    function softRadialTexture(hexColor) {
      var c = document.createElement('canvas');
      c.width = 256;
      c.height = 256;
      var ctx = c.getContext('2d');
      var col = new THREE.Color(hexColor);
      var r0 = Math.round(col.r * 255);
      var g0 = Math.round(col.g * 255);
      var b0 = Math.round(col.b * 255);
      var g = ctx.createRadialGradient(128, 128, 0, 128, 128, 128);
      g.addColorStop(0.0, 'rgba(' + r0 + ',' + g0 + ',' + b0 + ',0.85)');
      g.addColorStop(0.25, 'rgba(' + r0 + ',' + g0 + ',' + b0 + ',0.4)');
      g.addColorStop(0.55, 'rgba(' + r0 + ',' + g0 + ',' + b0 + ',0.12)');
      g.addColorStop(1.0, 'rgba(' + r0 + ',' + g0 + ',' + b0 + ',0)');
      ctx.fillStyle = g;
      ctx.fillRect(0, 0, 256, 256);
      var tex = new THREE.CanvasTexture(c);
      tex.needsUpdate = true;
      return tex;
    }

    function clearHealthPulsesFor(node) {
      if (!node || !node.pulseRefs) return;
      healthPulseMats = healthPulseMats.filter(function (p) {
        return node.pulseRefs.indexOf(p) < 0;
      });
      node.pulseRefs = [];
    }

    /**
     * Apply / refresh soft glow for a rack node (no hard outline boxes).
     * Warn/crit: additive volume shells + floor bloom + body emissive breathe.
     */
    function applyCabinetHealthVisual(node, st, displayHex) {
      if (!node || !node.bodyMat) return;
      st = normalizeHealthStatus(st);
      var baseHex = node.baseColorHex || '#2d3748';
      var tint = healthTintFrom(baseHex, st, displayHex || null);
      node.bodyMat.color.set(tint);
      node.bodyMat.needsUpdate = true;
      node.health = st;
      if (node.mesh && node.mesh.userData) node.mesh.userData.health = st;

      clearHealthPulsesFor(node);

      // Reset body emissive
      node.bodyMat.emissive.setHex(0x000000);
      node.bodyMat.emissiveIntensity = 0;

      // Soft rails (not chrome hard lines when unhealthy)
      if (node.railMats) {
        node.railMats.forEach(function (rm) {
          if (st === 'crit' || st === 'warn') {
            rm.color.setHex(st === 'crit' ? 0x7f1d1d : 0x713f12);
            rm.metalness = 0.25;
            rm.roughness = 0.65;
          } else {
            rm.color.setHex(0x64748b);
            rm.metalness = 0.45;
            rm.roughness = 0.45;
          }
          rm.needsUpdate = true;
        });
      }

      // Hide glow group for ok/unknown
      if (node.glowGroup) {
        var alert = st === 'crit' || st === 'warn';
        node.glowGroup.visible = alert;
        if (!alert) return;

        var alertHex = healthAlertHex(st);
        var isCrit = st === 'crit';
        var speed = isCrit ? 2.2 : 1.5;

        node.bodyMat.emissive.setHex(alertHex);
        node.bodyMat.emissiveIntensity = isCrit ? 0.35 : 0.18;
        var pe = { mat: node.bodyMat, prop: 'emissiveIntensity', base: isCrit ? 0.28 : 0.12, amp: isCrit ? 0.38 : 0.18, speed: speed };
        node.pulseRefs.push(pe);
        healthPulseMats.push(pe);

        // Update shell colors / pulse opacity
        (node.shellMats || []).forEach(function (sm, i) {
          sm.color.setHex(alertHex);
          var baseOp = isCrit ? (0.14 - i * 0.035) : (0.1 - i * 0.03);
          var ampOp = isCrit ? 0.12 : 0.07;
          sm.opacity = baseOp;
          sm.needsUpdate = true;
          var po = { mat: sm, prop: 'opacity', base: Math.max(0.03, baseOp), amp: ampOp, speed: speed };
          node.pulseRefs.push(po);
          healthPulseMats.push(po);
        });

        if (node.floorGlowMat) {
          if (node.floorGlowMat.map) node.floorGlowMat.map.dispose();
          node.floorGlowMat.map = softRadialTexture(alertHex);
          node.floorGlowMat.color.setHex(0xffffff);
          node.floorGlowMat.opacity = isCrit ? 0.55 : 0.38;
          node.floorGlowMat.needsUpdate = true;
          var pf = {
            mat: node.floorGlowMat,
            prop: 'opacity',
            base: isCrit ? 0.4 : 0.28,
            amp: isCrit ? 0.28 : 0.16,
            speed: speed * 0.9,
          };
          node.pulseRefs.push(pf);
          healthPulseMats.push(pf);
        }

        // Subtle crown glow (soft disc, not a hard sphere badge)
        if (node.crownMat) {
          if (node.crownMat.map) node.crownMat.map.dispose();
          node.crownMat.map = softRadialTexture(alertHex);
          node.crownMat.opacity = isCrit ? 0.5 : 0.35;
          node.crownMat.needsUpdate = true;
          if (node.crownMesh) node.crownMesh.visible = true;
          var pc = {
            mat: node.crownMat,
            prop: 'opacity',
            base: isCrit ? 0.35 : 0.22,
            amp: isCrit ? 0.3 : 0.16,
            speed: speed,
          };
          node.pulseRefs.push(pc);
          healthPulseMats.push(pc);
        }
      }
    }

    cabinets.forEach(function (cab) {
      var w = mmToM(cab.width_mm) || 0.6;
      var d = mmToM(cab.depth_mm) || 1.2;
      var h = (Number(cab.u_height) || 42) * uHeightM;
      var x = Number(cab.pos_x) || 0;
      var z = Number(cab.pos_y) || 0;
      var rot = (Number(cab.rotation_deg) || 0) * Math.PI / 180;
      var healthSt = normalizeHealthStatus(cabinetHealthStatus(cab));
      var baseColorHex = cab.color_hex || '#2d3748';
      var color = new THREE.Color(healthTintFrom(baseColorHex, healthSt, cab.health_display_hex));

      var geo = new THREE.BoxGeometry(w, h, d);
      var mat = new THREE.MeshStandardMaterial({
        color: color,
        roughness: 0.55,
        metalness: 0.35,
      });
      var mesh = new THREE.Mesh(geo, mat);
      mesh.position.set(x + w / 2, h / 2, z + d / 2);
      mesh.rotation.y = rot;
      mesh.userData = { cabinet: cab, health: healthSt };

      // Front / rear face planes — solid first (instant), images upgrade async
      var faceW = w * 0.98;
      var faceH = h * 0.98;
      var frontGeo = new THREE.PlaneGeometry(faceW, faceH);
      var frontMat = new THREE.MeshStandardMaterial({
        color: 0xffffff,
        roughness: 0.45,
        metalness: 0.15,
        map: buildFaceTextureSolid(cab, 'front'),
      });
      var front = new THREE.Mesh(frontGeo, frontMat);
      front.position.set(0, 0, d / 2 + 0.003);
      mesh.add(front);
      if (textureFaces !== 'none') {
        faceJobs.push({ cab: cab, face: 'front', mat: frontMat });
      }

      var rearMat = new THREE.MeshStandardMaterial({
        color: 0xffffff,
        roughness: 0.45,
        metalness: 0.15,
        map: buildFaceTextureSolid(cab, 'rear'),
      });
      var rear = new THREE.Mesh(frontGeo.clone(), rearMat);
      rear.position.set(0, 0, -d / 2 - 0.003);
      rear.rotation.y = Math.PI;
      mesh.add(rear);
      if (textureFaces === 'both') {
        faceJobs.push({ cab: cab, face: 'rear', mat: rearMat });
      }

      // Soft side accents (not hard chrome rails)
      var railMats = [];
      [-w * 0.48, w * 0.48].forEach(function (rx) {
        var railMat = new THREE.MeshStandardMaterial({
          color: 0x64748b,
          metalness: 0.45,
          roughness: 0.45,
        });
        railMats.push(railMat);
        var rail = new THREE.Mesh(new THREE.BoxGeometry(0.01, h * 0.96, 0.01), railMat);
        rail.position.set(rx, 0, d / 2 + 0.003);
        mesh.add(rail);
      });

      // Name label on top
      var canvas = document.createElement('canvas');
      canvas.width = 256;
      canvas.height = 64;
      var ctx = canvas.getContext('2d');
      ctx.fillStyle = '#0f172a';
      ctx.fillRect(0, 0, 256, 64);
      ctx.fillStyle = '#e2e8f0';
      ctx.font = 'bold 28px Segoe UI, sans-serif';
      ctx.textAlign = 'center';
      ctx.fillText(String(cab.name || 'CAB').slice(0, 16), 128, 40);
      var tex = new THREE.CanvasTexture(canvas);
      var label = new THREE.Mesh(
        new THREE.PlaneGeometry(w, w * 0.25),
        new THREE.MeshBasicMaterial({ map: tex, transparent: true })
      );
      label.position.set(0, h / 2 + 0.08, 0);
      label.rotation.x = -Math.PI / 2;
      label.userData = { objectLabel: true };
      label.visible = showObjectLabels;
      mesh.add(label);

      // Soft health glow group (shells + floor bloom + crown) — no hard outlines
      // High renderOrder so overhead raceways (transparent) do not bury alert glows
      var glowGroup = new THREE.Group();
      glowGroup.name = 'healthGlow';
      glowGroup.visible = false;
      glowGroup.renderOrder = 20;
      mesh.add(glowGroup);

      var shellMats = [];
      // Nested additive shells — soft volume, feathered by low opacity
      [1.08, 1.18, 1.3].forEach(function (scale, si) {
        var shellMat = new THREE.MeshBasicMaterial({
          color: 0xef4444,
          transparent: true,
          opacity: 0.08,
          depthWrite: false,
          depthTest: true,
          blending: THREE.AdditiveBlending,
          side: THREE.DoubleSide,
        });
        shellMats.push(shellMat);
        var shell = new THREE.Mesh(
          new THREE.BoxGeometry(w * scale, h * (1 + si * 0.02), d * scale),
          shellMat
        );
        shell.renderOrder = 21 + si;
        glowGroup.add(shell);
      });

      // Floor bloom under rack (world-Y: place as child at bottom of mesh)
      var floorGlowMat = new THREE.MeshBasicMaterial({
        map: softRadialTexture(0xef4444),
        color: 0xffffff,
        transparent: true,
        opacity: 0.45,
        depthWrite: false,
        depthTest: true,
        blending: THREE.AdditiveBlending,
        side: THREE.DoubleSide,
      });
      var floorGlow = new THREE.Mesh(
        new THREE.PlaneGeometry(Math.max(w, d) * 2.4, Math.max(w, d) * 2.4),
        floorGlowMat
      );
      floorGlow.rotation.x = -Math.PI / 2;
      floorGlow.position.set(0, -h / 2 + 0.02, 0);
      floorGlow.renderOrder = 20;
      glowGroup.add(floorGlow);

      // Soft crown aura above rack (radial, not a hard sphere)
      var crownMat = new THREE.MeshBasicMaterial({
        map: softRadialTexture(0xef4444),
        color: 0xffffff,
        transparent: true,
        opacity: 0.4,
        depthWrite: false,
        depthTest: true,
        blending: THREE.AdditiveBlending,
        side: THREE.DoubleSide,
      });
      var crownMesh = new THREE.Mesh(
        new THREE.PlaneGeometry(Math.min(w, d) * 1.4, Math.min(w, d) * 1.4),
        crownMat
      );
      crownMesh.rotation.x = -Math.PI / 2;
      crownMesh.position.set(0, h / 2 + 0.06, 0);
      crownMesh.visible = false;
      crownMesh.renderOrder = 24;
      glowGroup.add(crownMesh);

      var cabId = Number(cab.cabinet_id) || 0;
      var node = {
        mesh: mesh,
        bodyMat: mat,
        baseColorHex: baseColorHex,
        railMats: railMats,
        glowGroup: glowGroup,
        shellMats: shellMats,
        floorGlowMat: floorGlowMat,
        crownMat: crownMat,
        crownMesh: crownMesh,
        pulseRefs: [],
        health: healthSt,
      };
      if (cabId > 0) {
        cabinetHealthNodes[cabId] = node;
      }
      applyCabinetHealthVisual(node, healthSt, cab.health_display_hex || null);

      rackGroup.add(mesh);
    });

    // Stream faceplate textures after the scene is interactive (fronts first, limited concurrency)
    var cancelled = false;
    var totalJobs = faceJobs.length;
    var doneJobs = 0;
    if (totalJobs === 0) {
      statusEl.textContent = '';
      statusEl.style.display = 'none';
    } else {
      statusEl.textContent = 'Loading faceplates 0/' + totalJobs + '…';
      // Yield so first frames render solid racks
      setTimeout(function () {
        if (cancelled) return;
        mapPool(faceJobs, textureConcurrency, function (job) {
          if (cancelled) return null;
          return buildFaceTexture(job.cab, job.face).then(function (tex) {
            if (cancelled || !tex) return;
            var old = job.mat.map;
            job.mat.map = tex;
            job.mat.needsUpdate = true;
            if (old && old.dispose) old.dispose();
            doneJobs++;
            statusEl.textContent = 'Loading faceplates ' + doneJobs + '/' + totalJobs + '…';
          });
        }).then(function () {
          if (cancelled) return;
          statusEl.textContent = 'Ready';
          setTimeout(function () {
            if (statusEl && statusEl.parentNode) statusEl.style.display = 'none';
          }, 900);
        });
      }, 40);
    }

    // Row / room floor PDUs — translucent zone-colored body + wireframe edges
    // (health border: warn/crit tint via zone/body color blend when provided)
    floorPdus.forEach(function (pdu) {
      var w = mmToM(pdu.width_mm) || 0.6;
      var d = mmToM(pdu.depth_mm) || 0.3;
      var h = mmToM(pdu.height_mm) || 1.8;
      if (h < 0.1) h = 1.8;
      var x = Number(pdu.pos_x) || 0;
      var z = Number(pdu.pos_y) || 0;
      var rot = (Number(pdu.rotation_deg) || 0) * Math.PI / 180;
      var hex = pdu.zone_color || pdu.color_hex || '#f59e0b';
      if (!/^#[0-9A-Fa-f]{6}$/.test(String(hex))) hex = '#f59e0b';
      var color = new THREE.Color(hex);
      var pduH = (pdu.health && pdu.health.status) || pdu.health_status || '';
      if (pduH === 'crit' || pduH === 'down') {
        color.lerp(new THREE.Color(0xef4444), 0.5);
      } else if (pduH === 'warn' || pduH === 'degraded') {
        color.lerp(new THREE.Color(0xeab308), 0.4);
      }

      var geo = new THREE.BoxGeometry(w, h, d);
      var mat = new THREE.MeshStandardMaterial({
        color: color,
        transparent: true,
        opacity: 0.38,
        roughness: 0.55,
        metalness: 0.25,
        depthWrite: false,
      });
      if (pduH === 'crit' || pduH === 'warn' || pduH === 'down' || pduH === 'degraded') {
        mat.emissive = new THREE.Color(pduH === 'crit' || pduH === 'down' ? 0xef4444 : 0xeab308);
        mat.emissiveIntensity = 0.2;
        healthPulseMats.push({
          mat: mat,
          base: 0.12,
          amp: 0.18,
          speed: pduH === 'crit' || pduH === 'down' ? 2.5 : 1.6,
        });
      }
      var mesh = new THREE.Mesh(geo, mat);
      mesh.position.set(x + w / 2, h / 2, z + d / 2);
      mesh.rotation.y = rot;
      mesh.userData = { pdu: pdu, health: pduH };

      // Solid wireframe outline in zone/health color
      var edges = new THREE.LineSegments(
        new THREE.EdgesGeometry(geo),
        new THREE.LineBasicMaterial({ color: color, linewidth: 1 })
      );
      mesh.add(edges);

      // Slightly brighter top cap for readability
      var topMat = new THREE.MeshStandardMaterial({
        color: color,
        transparent: true,
        opacity: 0.55,
        roughness: 0.4,
        metalness: 0.3,
      });
      var top = new THREE.Mesh(
        new THREE.PlaneGeometry(w * 0.96, d * 0.96),
        topMat
      );
      top.rotation.x = -Math.PI / 2;
      top.position.set(0, h / 2 + 0.002, 0);
      mesh.add(top);

      // Name label
      var canvas = document.createElement('canvas');
      canvas.width = 256;
      canvas.height = 64;
      var ctx = canvas.getContext('2d');
      ctx.fillStyle = 'rgba(15, 23, 42, 0.85)';
      ctx.fillRect(0, 0, 256, 64);
      ctx.fillStyle = '#fde68a';
      ctx.font = 'bold 26px Segoe UI, sans-serif';
      ctx.textAlign = 'center';
      ctx.fillText('⚡ ' + String(pdu.name || 'PDU').slice(0, 14), 128, 40);
      var tex = new THREE.CanvasTexture(canvas);
      var label = new THREE.Mesh(
        new THREE.PlaneGeometry(Math.max(w, 0.4), Math.max(w, 0.4) * 0.28),
        new THREE.MeshBasicMaterial({ map: tex, transparent: true, depthTest: true })
      );
      label.position.set(0, h / 2 + 0.1, 0);
      label.rotation.x = -Math.PI / 2;
      label.userData = { objectLabel: true };
      label.visible = showObjectLabels;
      mesh.add(label);

      rackGroup.add(mesh);
    });

    // Cooling / AC units — translucent body + wireframe + ColdAisle snowflake on top
    function coolingTypeShort(t) {
      var s = String(t || '').toLowerCase();
      if (s === 'crac') return 'CRAC';
      if (s === 'crah') return 'CRAH';
      if (s === 'in_row') return 'In-row';
      if (s === 'ahu') return 'AHU';
      if (s === 'chiller') return 'Chiller';
      if (s === 'cdu') return 'CDU';
      if (s.indexOf('pump') >= 0) return 'Pump';
      return s ? s.slice(0, 10) : 'Cooling';
    }

    function coolingDefaultHex(t) {
      var s = String(t || '').toLowerCase();
      if (s === 'crac' || s === 'crah' || s === 'in_row' || s === 'ahu') return '#0ea5e9';
      if (s === 'chiller') return '#0284c7';
      if (s === 'chilled_water_pump' || s === 'ac_pump') return '#0369a1';
      if (s === 'cdu') return '#38bdf8';
      return '#0ea5e9';
    }

    /** Shared canvas texture: ColdAisle logo (snowflake) for unit tops */
    var coolingLogoTex = null;
    var coolingLogoWaiters = [];
    function ensureCoolingLogo(cb) {
      if (coolingLogoTex) {
        cb(coolingLogoTex);
        return;
      }
      coolingLogoWaiters.push(cb);
      if (coolingLogoWaiters.length > 1) return;
      function finish(tex) {
        coolingLogoTex = tex;
        coolingLogoWaiters.forEach(function (fn) {
          try { fn(tex); } catch (e) { /* ignore */ }
        });
        coolingLogoWaiters = [];
      }
      function paintFromImg(img) {
        var c = document.createElement('canvas');
        c.width = 256;
        c.height = 256;
        var ctx = c.getContext('2d');
        ctx.clearRect(0, 0, 256, 256);
        if (img) {
          drawCover(ctx, img, 8, 8, 240, 240);
        } else {
          // Fallback 6-arm snowflake if logo asset fails to load
          ctx.translate(128, 128);
          ctx.strokeStyle = '#38bdf8';
          ctx.lineWidth = 10;
          ctx.lineCap = 'round';
          for (var a = 0; a < 6; a++) {
            ctx.save();
            ctx.rotate((a * Math.PI) / 3);
            ctx.beginPath();
            ctx.moveTo(0, -16);
            ctx.lineTo(0, -88);
            ctx.moveTo(0, -88);
            ctx.lineTo(-14, -72);
            ctx.moveTo(0, -88);
            ctx.lineTo(14, -72);
            ctx.stroke();
            ctx.restore();
          }
        }
        var tex = new THREE.CanvasTexture(c);
        tex.needsUpdate = true;
        finish(tex);
      }
      loadImage(logoUrl).then(function (img) {
        if (img || cancelled) {
          paintFromImg(img);
          return;
        }
        var alt = mediaBase()
          ? mediaBase() + '/assets/img/logo-mark.png'
          : 'assets/img/logo-mark.png';
        loadImage(alt).then(paintFromImg);
      });
    }

    floorCooling.forEach(function (cu) {
      var w = mmToM(cu.width_mm) || 1.2;
      var d = mmToM(cu.depth_mm) || 0.9;
      var h = mmToM(cu.height_mm) || 2.0;
      if (h < 0.1) h = 2.0;
      var x = Number(cu.pos_x) || 0;
      var z = Number(cu.pos_y) || 0;
      var rot = (Number(cu.rotation_deg) || 0) * Math.PI / 180;
      var hex = cu.color_hex || coolingDefaultHex(cu.unit_type);
      if (!/^#[0-9A-Fa-f]{6}$/.test(String(hex))) hex = coolingDefaultHex(cu.unit_type);
      var color = new THREE.Color(hex);
      var role = String(cu.unit_role || '').toLowerCase();
      var isStandby = role === 'standby';
      var bodyOp = isStandby ? 0.22 : 0.4;

      var geo = new THREE.BoxGeometry(w, h, d);
      var mat = new THREE.MeshStandardMaterial({
        color: color,
        transparent: true,
        opacity: bodyOp,
        roughness: 0.5,
        metalness: 0.2,
        depthWrite: false,
      });
      var mesh = new THREE.Mesh(geo, mat);
      mesh.position.set(x + w / 2, h / 2, z + d / 2);
      mesh.rotation.y = rot;
      mesh.userData = { cooling: cu };

      var edgeMat = new THREE.LineBasicMaterial({
        color: color,
        transparent: isStandby,
        opacity: isStandby ? 0.55 : 1,
      });
      mesh.add(new THREE.LineSegments(new THREE.EdgesGeometry(geo), edgeMat));

      // Top cap
      var topMat = new THREE.MeshStandardMaterial({
        color: color,
        transparent: true,
        opacity: isStandby ? 0.35 : 0.55,
        roughness: 0.35,
        metalness: 0.25,
      });
      var top = new THREE.Mesh(
        new THREE.PlaneGeometry(w * 0.96, d * 0.96),
        topMat
      );
      top.rotation.x = -Math.PI / 2;
      top.position.set(0, h / 2 + 0.002, 0);
      mesh.add(top);

      // ColdAisle snowflake logo centered on top
      var logoSize = Math.min(w, d) * 0.5;
      if (logoSize < 0.18) logoSize = Math.min(w, d) * 0.72;
      if (logoSize > 0.9) logoSize = 0.9;
      var logoPlane = new THREE.Mesh(
        new THREE.PlaneGeometry(logoSize, logoSize),
        new THREE.MeshBasicMaterial({
          transparent: true,
          opacity: isStandby ? 0.65 : 0.95,
          depthWrite: false,
          color: 0xffffff,
        })
      );
      logoPlane.rotation.x = -Math.PI / 2;
      logoPlane.position.set(0, h / 2 + 0.006, 0);
      mesh.add(logoPlane);
      ensureCoolingLogo(function (tex) {
        if (cancelled || !logoPlane.material) return;
        logoPlane.material.map = tex;
        logoPlane.material.needsUpdate = true;
      });

      // Name label (billboard-ish flat on top, offset toward front edge)
      var canvas = document.createElement('canvas');
      canvas.width = 320;
      canvas.height = 72;
      var ctx = canvas.getContext('2d');
      ctx.fillStyle = 'rgba(8, 25, 42, 0.88)';
      ctx.fillRect(0, 0, 320, 72);
      ctx.fillStyle = '#7dd3fc';
      ctx.font = 'bold 24px Segoe UI, sans-serif';
      ctx.textAlign = 'center';
      var short = coolingTypeShort(cu.unit_type);
      var nm = String(cu.name || 'Cooling').slice(0, 16);
      ctx.fillText(nm, 160, 30);
      ctx.font = '18px Segoe UI, sans-serif';
      ctx.fillStyle = isStandby ? '#94a3b8' : '#bae6fd';
      ctx.fillText(short + (isStandby ? ' · standby' : ''), 160, 54);
      var tex = new THREE.CanvasTexture(canvas);
      var labelW = Math.max(w * 0.92, 0.55);
      var label = new THREE.Mesh(
        new THREE.PlaneGeometry(labelW, labelW * 0.24),
        new THREE.MeshBasicMaterial({ map: tex, transparent: true, depthTest: true })
      );
      // Sit just above the logo toward one edge so both stay readable
      label.position.set(0, h / 2 + 0.012, d * 0.28);
      label.rotation.x = -Math.PI / 2;
      label.userData = { objectLabel: true };
      label.visible = showObjectLabels;
      mesh.add(label);

      rackGroup.add(mesh);
    });

    // Floor UPS (in-row frames) — purple body + soft health glow like cabinets
    floorUps.forEach(function (uu) {
      var w = mmToM(uu.width_mm) || 0.6;
      var d = mmToM(uu.depth_mm) || 1.1;
      var h = mmToM(uu.height_mm) || 2.0;
      if (h < 0.1) h = 2.0;
      var x = Number(uu.pos_x) || 0;
      var z = Number(uu.pos_y) || 0;
      var rot = (Number(uu.rotation_deg) || 0) * Math.PI / 180;
      var hex = uu.color_hex || '#7c3aed';
      if (!/^#[0-9A-Fa-f]{6}$/.test(String(hex))) hex = '#7c3aed';
      var healthSt = normalizeHealthStatus(
        (uu.health_status || (uu.health && uu.health.status) || 'unknown')
      );
      var color = new THREE.Color(hex);
      if (healthSt === 'crit') color.lerp(new THREE.Color(0xef4444), 0.55);
      else if (healthSt === 'warn') color.lerp(new THREE.Color(0xeab308), 0.4);

      var geo = new THREE.BoxGeometry(w, h, d);
      var mat = new THREE.MeshStandardMaterial({
        color: color,
        transparent: true,
        opacity: 0.55,
        roughness: 0.45,
        metalness: 0.35,
        depthWrite: false,
      });
      if (healthSt === 'crit' || healthSt === 'warn') {
        mat.emissive = new THREE.Color(healthSt === 'crit' ? 0xef4444 : 0xeab308);
        mat.emissiveIntensity = healthSt === 'crit' ? 0.32 : 0.18;
        healthPulseMats.push({
          mat: mat,
          prop: 'emissiveIntensity',
          base: healthSt === 'crit' ? 0.25 : 0.12,
          amp: healthSt === 'crit' ? 0.35 : 0.16,
          speed: healthSt === 'crit' ? 2.3 : 1.6,
        });
      }
      var mesh = new THREE.Mesh(geo, mat);
      mesh.position.set(x + w / 2, h / 2, z + d / 2);
      mesh.rotation.y = rot;
      mesh.userData = { ups: uu, health: healthSt };

      // Soft additive shells on warn/crit
      if (healthSt === 'crit' || healthSt === 'warn') {
        var alertHex = healthSt === 'crit' ? 0xef4444 : 0xeab308;
        [1.1, 1.22].forEach(function (scale, si) {
          var shellMat = new THREE.MeshBasicMaterial({
            color: alertHex,
            transparent: true,
            opacity: 0.1 - si * 0.03,
            depthWrite: false,
            blending: THREE.AdditiveBlending,
            side: THREE.DoubleSide,
          });
          healthPulseMats.push({
            mat: shellMat,
            prop: 'opacity',
            base: 0.06 + si * 0.02,
            amp: 0.1,
            speed: healthSt === 'crit' ? 2.2 : 1.5,
          });
          var shell = new THREE.Mesh(
            new THREE.BoxGeometry(w * scale, h * (1 + si * 0.02), d * scale),
            shellMat
          );
          shell.renderOrder = 2 + si;
          mesh.add(shell);
        });
        var floorGlowMat = new THREE.MeshBasicMaterial({
          map: softRadialTexture(alertHex),
          color: 0xffffff,
          transparent: true,
          opacity: 0.45,
          depthWrite: false,
          blending: THREE.AdditiveBlending,
          side: THREE.DoubleSide,
        });
        healthPulseMats.push({
          mat: floorGlowMat,
          prop: 'opacity',
          base: 0.32,
          amp: 0.2,
          speed: 1.8,
        });
        var floorGlow = new THREE.Mesh(
          new THREE.PlaneGeometry(Math.max(w, d) * 2.2, Math.max(w, d) * 2.2),
          floorGlowMat
        );
        floorGlow.rotation.x = -Math.PI / 2;
        floorGlow.position.set(0, -h / 2 + 0.02, 0);
        mesh.add(floorGlow);
      }

      var canvas = document.createElement('canvas');
      canvas.width = 320;
      canvas.height = 72;
      var ctx = canvas.getContext('2d');
      ctx.fillStyle = 'rgba(30, 10, 50, 0.9)';
      ctx.fillRect(0, 0, 320, 72);
      ctx.fillStyle = '#e9d5ff';
      ctx.font = 'bold 22px Segoe UI, sans-serif';
      ctx.textAlign = 'center';
      ctx.fillText(String(uu.name || 'UPS').slice(0, 16), 160, 28);
      ctx.font = '16px Segoe UI, sans-serif';
      ctx.fillStyle = '#c4b5fd';
      var sub = 'UPS';
      if (uu.last_load_pct != null) sub += ' · ' + uu.last_load_pct + '%';
      if (uu.last_battery_pct != null) sub += ' · batt ' + uu.last_battery_pct + '%';
      ctx.fillText(sub.slice(0, 28), 160, 52);
      var tex = new THREE.CanvasTexture(canvas);
      var label = new THREE.Mesh(
        new THREE.PlaneGeometry(Math.max(w * 0.92, 0.55), Math.max(w * 0.92, 0.55) * 0.24),
        new THREE.MeshBasicMaterial({ map: tex, transparent: true })
      );
      label.position.set(0, h / 2 + 0.06, 0);
      label.rotation.x = -Math.PI / 2;
      label.userData = { objectLabel: true };
      label.visible = showObjectLabels;
      mesh.add(label);

      rackGroup.add(mesh);
    });

    // --- Env heat spheres (cabinet-derived or explicit pos) ---
    var heatGroup = new THREE.Group();
    heatGroup.name = 'envHeat';
    heatGroup.visible = heatOverlay;
    scene.add(heatGroup);

    function tempToColor(t) {
      // Soft ASHRAE-ish band (°C): cool blue → green → yellow → red
      var c = new THREE.Color();
      if (t == null || isNaN(t)) {
        c.setHex(0x64748b);
        return c;
      }
      if (t <= 15) c.setHex(0x1d4ed8);
      else if (t <= 18) c.setHex(0x0ea5e9);
      else if (t <= 22) c.setHex(0x22c55e);
      else if (t <= 25) c.setHex(0xa3e635);
      else if (t <= 27) c.setHex(0xfacc15);
      else if (t <= 30) c.setHex(0xf97316);
      else c.setHex(0xef4444);
      return c;
    }

    /** Soft radial alpha texture (center opaque → edge fully transparent). */
    function softGlowTexture(hexColor) {
      var c = document.createElement('canvas');
      c.width = 256;
      c.height = 256;
      var ctx = c.getContext('2d');
      var col = new THREE.Color(hexColor);
      var r0 = Math.round(col.r * 255);
      var g0 = Math.round(col.g * 255);
      var b0 = Math.round(col.b * 255);
      var g = ctx.createRadialGradient(128, 128, 0, 128, 128, 128);
      // Extremely soft falloff: solid-ish core, long feathered edge
      g.addColorStop(0.0, 'rgba(' + r0 + ',' + g0 + ',' + b0 + ',0.55)');
      g.addColorStop(0.15, 'rgba(' + r0 + ',' + g0 + ',' + b0 + ',0.35)');
      g.addColorStop(0.4, 'rgba(' + r0 + ',' + g0 + ',' + b0 + ',0.14)');
      g.addColorStop(0.7, 'rgba(' + r0 + ',' + g0 + ',' + b0 + ',0.04)');
      g.addColorStop(1.0, 'rgba(' + r0 + ',' + g0 + ',' + b0 + ',0)');
      ctx.fillStyle = g;
      ctx.fillRect(0, 0, 256, 256);
      var tex = new THREE.CanvasTexture(c);
      tex.needsUpdate = true;
      return tex;
    }

    var heatCount = 0;
    envSensors.forEach(function (s) {
      var temp = Number(s.temp);
      if (isNaN(temp)) return;
      // Default ~3 ft (half of original 6 ft); server may still send radius_m
      var r = Number(s.radius_m);
      if (isNaN(r) || r <= 0) r = 0.915;
      var x = Number(s.pos_x);
      var z = Number(s.pos_y); // plan Y → scene Z
      var y = Number(s.pos_z);
      if (isNaN(x) || isNaN(z)) return;
      if (isNaN(y)) y = 1.0;
      heatCount++;

      var color = tempToColor(temp);
      var cy = Math.max(0.35, y);
      var glowTex = softGlowTexture(color.getHex());

      // Soft volumetric feel: nested spheres with decreasing size/opacity + feathered map
      // Outer shell is almost invisible at edge (gradient alpha → 0)
      var shells = [
        { scale: 1.0, opacity: 0.55 },
        { scale: 0.72, opacity: 0.4 },
        { scale: 0.42, opacity: 0.35 },
      ];
      shells.forEach(function (shell, si) {
        var geo = new THREE.SphereGeometry(r * shell.scale, 28, 18);
        var mat = new THREE.MeshBasicMaterial({
          map: glowTex,
          color: 0xffffff,
          transparent: true,
          opacity: shell.opacity,
          depthWrite: false,
          depthTest: true,
          side: THREE.DoubleSide,
          blending: THREE.AdditiveBlending,
        });
        var mesh = new THREE.Mesh(geo, mat);
        mesh.position.set(x, cy, z);
        mesh.scale.set(1, 0.7, 1); // slightly flattened
        mesh.userData = { envSensor: s, kind: 'heat', shell: si };
        mesh.renderOrder = 2 + si;
        heatGroup.add(mesh);
      });

      // Small solid core for location cue
      var core = new THREE.Mesh(
        new THREE.SphereGeometry(0.06, 12, 10),
        new THREE.MeshBasicMaterial({
          color: color,
          transparent: true,
          opacity: 0.9,
          depthWrite: false,
        })
      );
      core.position.set(x, cy, z);
      core.userData = { envSensor: s, kind: 'core' };
      core.renderOrder = 5;
      heatGroup.add(core);

      // Temp label
      var lc = document.createElement('canvas');
      lc.width = 256;
      lc.height = 64;
      var lctx = lc.getContext('2d');
      lctx.fillStyle = 'rgba(15, 23, 42, 0.75)';
      lctx.fillRect(0, 0, 256, 64);
      lctx.fillStyle = '#e2e8f0';
      lctx.font = 'bold 22px Segoe UI, sans-serif';
      lctx.textAlign = 'center';
      var tLabel = (Math.round(temp * 10) / 10) + '°';
      if (s.humidity != null && !isNaN(Number(s.humidity))) {
        tLabel += ' / ' + Math.round(Number(s.humidity)) + '%';
      }
      lctx.fillText(tLabel, 128, 28);
      lctx.font = '14px Segoe UI, sans-serif';
      lctx.fillStyle = '#94a3b8';
      lctx.fillText(String(s.name || '').slice(0, 22), 128, 50);
      var ltex = new THREE.CanvasTexture(lc);
      var lab = new THREE.Mesh(
        new THREE.PlaneGeometry(0.5, 0.13),
        new THREE.MeshBasicMaterial({ map: ltex, transparent: true, depthWrite: false })
      );
      lab.position.set(x, cy + 0.18, z);
      lab.rotation.x = -Math.PI / 2;
      lab.userData = { envSensor: s, kind: 'label' };
      lab.renderOrder = 6;
      heatGroup.add(lab);
    });

    if (heatCount > 0 && statusEl) {
      var prev = statusEl.textContent || '';
      if (prev.indexOf('Ready') >= 0 || prev === '' || prev.indexOf('Building') >= 0) {
        // brief note after load
        setTimeout(function () {
          if (!statusEl || cancelled) return;
          statusEl.style.display = '';
          statusEl.textContent = heatCount + ' heat sphere(s)';
          setTimeout(function () {
            if (statusEl && statusEl.parentNode && statusEl.textContent.indexOf('heat') >= 0) {
              statusEl.style.display = 'none';
            }
          }, 2200);
        }, 1200);
      }
    }

    // Cable raceways (ladder / fiber trough / conduit) at path elevation
    var racewayGroup = new THREE.Group();
    racewayGroup.name = 'raceways';
    scene.add(racewayGroup);
    var racewayCount = 0;
    var racewayFadeMeshes = []; // meshes/lines with per-piece materials for cam fade
    var _rwFadeWorld = new THREE.Vector3();
    (cablePaths || []).forEach(function (path) {
      if (!path) return;
      if (path.is_active === 0 || path.is_active === false) return;
      try {
        var rg = buildRacewayGroup(path);
        if (rg) {
          racewayGroup.add(rg);
          racewayCount++;
          rg.traverse(function (obj) {
            if ((obj.isMesh || obj.isLine) && obj.userData && obj.userData.racewayFade) {
              racewayFadeMeshes.push(obj);
            }
          });
        }
      } catch (eRw) {
        // ignore bad path geometry
      }
    });

    /**
     * Connection cable routes (opt-in Show path): elevated tube in media color
     * with speed-colored end spheres. Drawn above raceways so they read clearly.
     */
    var cableRouteGroup = new THREE.Group();
    cableRouteGroup.name = 'cableRoutes';
    scene.add(cableRouteGroup);
    (cableRoutes || []).forEach(function (route) {
      if (!route || !route.geometry || route.geometry.length < 2) return;
      try {
        var elev = 2.85; // slightly above typical overhead tray
        var pts3 = [];
        for (var ri = 0; ri < route.geometry.length; ri++) {
          var gp = route.geometry[ri];
          var gx = Number(gp.x);
          var gz = Number(gp.y);
          if (!isFinite(gx) || !isFinite(gz)) continue;
          // Drop ends slightly toward rack height so they read as cabinet attach
          var gy = elev;
          if (gp.kind === 'cabinet') gy = 1.1;
          pts3.push(new THREE.Vector3(gx, gy, gz));
        }
        if (pts3.length < 2) return;
        var jacket = route.color_hex || '#38bdf8';
        var endCol = route.end_color_hex || '#e2e8f0';
        var curve;
        try {
          curve = new THREE.CatmullRomCurve3(pts3, false, 'catmullrom', 0.15);
        } catch (eC) {
          curve = null;
        }
        if (curve && THREE.TubeGeometry) {
          var tubular = Math.max(16, pts3.length * 6);
          var tubeGeo = new THREE.TubeGeometry(curve, tubular, 0.035, 8, false);
          var tubeMat = new THREE.MeshStandardMaterial({
            color: new THREE.Color(jacket),
            metalness: 0.25,
            roughness: 0.45,
            emissive: new THREE.Color(jacket),
            emissiveIntensity: 0.12,
          });
          var tube = new THREE.Mesh(tubeGeo, tubeMat);
          tube.renderOrder = 8;
          cableRouteGroup.add(tube);
        } else {
          var lineGeo = new THREE.BufferGeometry().setFromPoints(pts3);
          var lineMat = new THREE.LineBasicMaterial({ color: new THREE.Color(jacket), linewidth: 2 });
          cableRouteGroup.add(new THREE.Line(lineGeo, lineMat));
        }
        // End spheres (speed color)
        var sphereGeo = new THREE.SphereGeometry(0.09, 16, 12);
        var endMat = new THREE.MeshStandardMaterial({
          color: new THREE.Color(endCol),
          emissive: new THREE.Color(endCol),
          emissiveIntensity: 0.35,
          metalness: 0.2,
          roughness: 0.4,
        });
        var s0 = new THREE.Mesh(sphereGeo, endMat);
        s0.position.copy(pts3[0]);
        s0.renderOrder = 9;
        cableRouteGroup.add(s0);
        var s1 = new THREE.Mesh(sphereGeo, endMat.clone());
        s1.position.copy(pts3[pts3.length - 1]);
        s1.renderOrder = 9;
        cableRouteGroup.add(s1);
      } catch (eRoute) {
        // ignore bad route
      }
    });

    /**
     * Dynamic camera-proximity fade: pieces closer to the camera go transparent
     * so overhead ladders never obstruct the room as you orbit.
     *
     * Distances are scaled to camera→look-at so the bubble works at normal
     * orbit range (not only when zoomed in tight).
     */
    function updateRacewayCameraFade() {
      if (!racewayCamFade || !racewayFadeMeshes.length) return;
      var camPos = camera.position;
      // Distance from camera to orbit target (room center-ish)
      var dTarget = camPos.distanceTo(target);
      if (!isFinite(dTarget) || dTarget < 1) dTarget = Math.max(radius, 12);
      // Fully ghost inside nearR; fully solid past farR.
      // Defaults reach well past the room center so front-of-scene trays clear at
      // typical wall / dashboard zoom (no need to nose-dive into the aisle).
      var nearR = isFinite(racewayFadeNearOpt)
        ? racewayFadeNearOpt
        : Math.max(8, dTarget * 0.72);
      var farR = isFinite(racewayFadeFarOpt)
        ? racewayFadeFarOpt
        : Math.max(nearR + 10, dTarget * 1.45);
      if (farR <= nearR) farR = nearR + 10;
      for (var i = 0; i < racewayFadeMeshes.length; i++) {
        var obj = racewayFadeMeshes[i];
        if (!obj || !obj.material) continue;
        obj.getWorldPosition(_rwFadeWorld);
        var dist = camPos.distanceTo(_rwFadeWorld);
        // 0 at/inside near sphere → transparent; 1 beyond far → solid
        var solid = smoothstep01(nearR, farR, dist);
        var base = 1;
        var mat = obj.material;
        if (mat.userData && mat.userData.fadeBaseOpacity != null) {
          base = Number(mat.userData.fadeBaseOpacity);
          if (!isFinite(base)) base = 1;
        }
        var op = racewayFadeMinAlpha + (base - racewayFadeMinAlpha) * solid;
        if (op < 0) op = 0;
        if (op > 1) op = 1;
        mat.transparent = true;
        mat.opacity = op;
        // Avoid z-fight / solid blocking when mostly see-through
        mat.depthWrite = op > 0.55;
        // Keep raceways under health glows (glow renderOrder ≥ 20)
        obj.renderOrder = op < 0.85 ? 2 : 0;
      }
    }

    if (!cabinets.length && !floorPdus.length && !floorCooling.length && !racewayCount) {
      var c2 = document.createElement('canvas');
      c2.width = 512;
      c2.height = 128;
      var cx = c2.getContext('2d');
      cx.fillStyle = '#1e293b';
      cx.fillRect(0, 0, 512, 128);
      cx.fillStyle = '#94a3b8';
      cx.font = '22px Segoe UI, sans-serif';
      cx.textAlign = 'center';
      cx.fillText('No cabinets, PDUs, or cooling on floor plan yet', 256, 70);
      var tex2 = new THREE.CanvasTexture(c2);
      var plane = new THREE.Mesh(
        new THREE.PlaneGeometry(8, 2),
        new THREE.MeshBasicMaterial({ map: tex2, transparent: true })
      );
      plane.position.set(fw / 2, 1.5, fd / 2);
      scene.add(plane);
    }

    var isDown = false, lastX = 0, lastY = 0;
    // Orbit camera: theta=yaw, phi=tilt from vertical, radius=distance
    var theta = Number(options.cameraTheta);
    if (!isFinite(theta)) theta = Math.PI / 4;
    var phi = Number(options.cameraPhi);
    if (!isFinite(phi)) phi = Math.PI / 3.2;
    phi = Math.max(0.15, Math.min(Math.PI / 2.1, phi));
    var radius = Number(options.cameraRadius);
    if (!isFinite(radius) || radius <= 0) radius = 28;
    radius = Math.max(5, Math.min(80, radius));
    var target = new THREE.Vector3(fw / 2, 0.5, fd / 2);

    function updateCamera() {
      camera.position.x = target.x + radius * Math.sin(phi) * Math.cos(theta);
      camera.position.y = target.y + radius * Math.cos(phi);
      camera.position.z = target.z + radius * Math.sin(phi) * Math.sin(theta);
      camera.lookAt(target);
    }
    updateCamera();

    if (interactive) {
      renderer.domElement.addEventListener('pointerdown', function (e) {
        isDown = true;
        lastX = e.clientX;
        lastY = e.clientY;
      });
      window.addEventListener('pointerup', function () { isDown = false; });
      window.addEventListener('pointermove', function (e) {
        if (!isDown) return;
        var dx = e.clientX - lastX, dy = e.clientY - lastY;
        lastX = e.clientX;
        lastY = e.clientY;
        theta += dx * 0.005;
        phi = Math.max(0.15, Math.min(Math.PI / 2.1, phi - dy * 0.005));
        updateCamera();
      });
      renderer.domElement.addEventListener('wheel', function (e) {
        e.preventDefault();
        radius = Math.max(5, Math.min(80, radius + e.deltaY * 0.02));
        updateCamera();
      }, { passive: false });
    }

    var animId;
    var healthT0 = performance.now();
    function animate() {
      animId = requestAnimationFrame(animate);
      if (autoRotate && !isDown) {
        theta += autoRotateSpeed;
        updateCamera();
      }
      // Soft breathe: emissive on body + opacity on glow shells / floor bloom
      if (healthPulseMats.length) {
        var t = (performance.now() - healthT0) / 1000;
        for (var hi = 0; hi < healthPulseMats.length; hi++) {
          var hp = healthPulseMats[hi];
          if (!hp.mat) continue;
          var wave = hp.base + hp.amp * (0.5 + 0.5 * Math.sin(t * hp.speed));
          if (hp.prop === 'opacity') {
            hp.mat.opacity = wave;
          } else {
            hp.mat.emissiveIntensity = wave;
          }
        }
      }
      // Raceway near-camera fade sphere (updates every frame while orbiting)
      updateRacewayCameraFade();
      renderer.render(scene, camera);
    }
    animate();

    function onResize() {
      var w = container.clientWidth || width;
      var h = container.clientHeight || height;
      camera.aspect = w / h;
      camera.updateProjectionMatrix();
      renderer.setSize(w, h);
    }
    window.addEventListener('resize', onResize);

    /**
     * Live health update (NOC poll). Accepts array of
     * { cabinet_id, status, health_display_hex } or map id→status.
     */
    function setCabinetHealth(listOrMap) {
      if (!listOrMap) return;
      var map = Object.create(null);
      if (Array.isArray(listOrMap)) {
        listOrMap.forEach(function (row) {
          if (!row) return;
          var id = Number(row.cabinet_id || row.id);
          if (!id) return;
          map[id] = row;
        });
      } else if (typeof listOrMap === 'object') {
        Object.keys(listOrMap).forEach(function (k) {
          var v = listOrMap[k];
          var id = Number(k);
          if (!id) return;
          if (typeof v === 'string') {
            map[id] = { cabinet_id: id, status: v };
          } else if (v && typeof v === 'object') {
            map[id] = v;
          }
        });
      }
      Object.keys(cabinetHealthNodes).forEach(function (idStr) {
        var id = Number(idStr);
        var node = cabinetHealthNodes[id];
        if (!node) return;
        var row = map[id];
        if (!row) return; // leave unchanged if not in payload
        var st = normalizeHealthStatus(
          row.status || row.health_status
            || (row.health && row.health.status) || 'unknown'
        );
        var disp = row.health_display_hex || row.color || null;
        applyCabinetHealthVisual(node, st, disp);
      });
    }

    return {
      scene: scene,
      camera: camera,
      renderer: renderer,
      heatGroup: heatGroup,
      racewayGroup: racewayGroup,
      setHeatOverlay: function (on) {
        heatGroup.visible = !!on;
      },
      setAutoRotate: function (on) {
        autoRotate = !!on;
      },
      /**
       * Orbit camera: phi = tilt from vertical (rad), radius = distance (m).
       * Optional theta = yaw. Values clamped to interactive ranges.
       */
      setCameraView: function (opts) {
        opts = opts || {};
        if (opts.theta != null && isFinite(Number(opts.theta))) {
          theta = Number(opts.theta);
        }
        if (opts.phi != null && isFinite(Number(opts.phi))) {
          phi = Math.max(0.15, Math.min(Math.PI / 2.1, Number(opts.phi)));
        }
        if (opts.radius != null && isFinite(Number(opts.radius))) {
          radius = Math.max(5, Math.min(80, Number(opts.radius)));
        }
        updateCamera();
      },
      getCameraView: function () {
        return { theta: theta, phi: phi, radius: radius };
      },
      setObjectLabels: function (on) {
        showObjectLabels = !!on;
        scene.traverse(function (obj) {
          if (obj && obj.userData && obj.userData.objectLabel) {
            obj.visible = showObjectLabels;
          }
        });
      },
      setRacewaysVisible: function (on) {
        if (racewayGroup) racewayGroup.visible = !!on;
      },
      setRacewayCamFade: function (on) {
        racewayCamFade = !!on;
        if (!racewayCamFade) {
          // Restore solid raceways
          for (var i = 0; i < racewayFadeMeshes.length; i++) {
            var obj = racewayFadeMeshes[i];
            if (!obj || !obj.material) continue;
            var base = (obj.material.userData && obj.material.userData.fadeBaseOpacity != null)
              ? Number(obj.material.userData.fadeBaseOpacity) : 1;
            if (!isFinite(base)) base = 1;
            obj.material.opacity = base;
            obj.material.depthWrite = base > 0.5;
            obj.renderOrder = 0;
          }
        }
      },
      setCabinetHealth: setCabinetHealth,
      dispose: function () {
        cancelled = true;
        cancelAnimationFrame(animId);
        window.removeEventListener('resize', onResize);
        try {
          if (statusEl && statusEl.parentNode) statusEl.parentNode.removeChild(statusEl);
        } catch (e) { /* ignore */ }
        function disposeObj(obj) {
          if (obj.geometry) obj.geometry.dispose();
          if (obj.material) {
            var mats = Array.isArray(obj.material) ? obj.material : [obj.material];
            mats.forEach(function (m) {
              if (!m) return;
              if (m.map) m.map.dispose();
              m.dispose();
            });
          }
        }
        try {
          heatGroup.traverse(disposeObj);
        } catch (e2) { /* ignore */ }
        try {
          racewayGroup.traverse(disposeObj);
        } catch (e3) { /* ignore */ }
        racewayFadeMeshes.length = 0;
        renderer.dispose();
      },
    };
  }

  /**
   * Map UI percents (0–100) ↔ orbit camera used by NOC / settings preview.
   * tilt 0 = top-down, 100 = side-on. zoom 0 = far, 100 = close.
   */
  function cameraFromPercents(tiltPct, zoomPct) {
    var t = Math.max(0, Math.min(100, Number(tiltPct)));
    var z = Math.max(0, Math.min(100, Number(zoomPct)));
    if (!isFinite(t)) t = 63;
    if (!isFinite(z)) z = 72;
    var phiMin = 0.18;
    var phiMax = 1.45;
    var rFar = 80;
    var rNear = 8;
    return {
      phi: phiMin + (phiMax - phiMin) * (t / 100),
      radius: rFar - (rFar - rNear) * (z / 100),
      tiltPct: t,
      zoomPct: z,
    };
  }

  function percentsFromCamera(phi, radius) {
    var phiMin = 0.18;
    var phiMax = 1.45;
    var rFar = 80;
    var rNear = 8;
    var p = Number(phi);
    var r = Number(radius);
    if (!isFinite(p)) p = Math.PI / 3.2;
    if (!isFinite(r)) r = 28;
    var tilt = ((p - phiMin) / (phiMax - phiMin)) * 100;
    var zoom = ((rFar - r) / (rFar - rNear)) * 100;
    return {
      tiltPct: Math.round(Math.max(0, Math.min(100, tilt))),
      zoomPct: Math.round(Math.max(0, Math.min(100, zoom))),
    };
  }

  global.ColdAisle3D = {
    mount: mount,
    cameraFromPercents: cameraFromPercents,
    percentsFromCamera: percentsFromCamera,
  };
  // Legacy alias (WinDCIM)
  global.WinDCIM3D = global.ColdAisle3D;
})(typeof window !== 'undefined' ? window : this);
