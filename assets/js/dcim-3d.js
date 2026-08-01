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

  function mount(container, options) {
    if (!global.THREE) {
      container.innerHTML = '<div class="empty-state"><p>Three.js failed to load.</p></div>';
      return null;
    }

    options = options || {};
    var cabinets = options.cabinets || [];
    var floorPdus = options.pdus || options.floor_pdus || [];
    var envSensors = options.envSensors || options.env_sensors || [];
    var heatOverlay = options.heatOverlay !== false;
    var rooms = options.rooms || [];
    var interactive = options.interactive !== false;
    // 'front' = room overview (default, fast). 'both' = front + rear (floor planner).
    var textureFaces = (options.textureFaces === 'both' || options.textureFaces === 'front')
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

    cabinets.forEach(function (cab) {
      var w = mmToM(cab.width_mm) || 0.6;
      var d = mmToM(cab.depth_mm) || 1.2;
      var h = (Number(cab.u_height) || 42) * uHeightM;
      var x = Number(cab.pos_x) || 0;
      var z = Number(cab.pos_y) || 0;
      var rot = (Number(cab.rotation_deg) || 0) * Math.PI / 180;
      var color = new THREE.Color(cab.color_hex || '#2d3748');

      var geo = new THREE.BoxGeometry(w, h, d);
      var mat = new THREE.MeshStandardMaterial({
        color: color,
        roughness: 0.55,
        metalness: 0.35,
      });
      var mesh = new THREE.Mesh(geo, mat);
      mesh.position.set(x + w / 2, h / 2, z + d / 2);
      mesh.rotation.y = rot;
      mesh.userData = { cabinet: cab };

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
      faceJobs.push({ cab: cab, face: 'front', mat: frontMat });

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

      // Side rails accent
      var railMat = new THREE.MeshStandardMaterial({ color: 0x94a3b8, metalness: 0.7, roughness: 0.3 });
      [-w * 0.48, w * 0.48].forEach(function (rx) {
        var rail = new THREE.Mesh(new THREE.BoxGeometry(0.012, h * 0.98, 0.012), railMat);
        rail.position.set(rx, 0, d / 2 + 0.004);
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
      mesh.add(label);

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

      var geo = new THREE.BoxGeometry(w, h, d);
      var mat = new THREE.MeshStandardMaterial({
        color: color,
        transparent: true,
        opacity: 0.38,
        roughness: 0.55,
        metalness: 0.25,
        depthWrite: false,
      });
      var mesh = new THREE.Mesh(geo, mat);
      mesh.position.set(x + w / 2, h / 2, z + d / 2);
      mesh.rotation.y = rot;
      mesh.userData = { pdu: pdu };

      // Solid wireframe outline in zone color
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

    envSensors.forEach(function (s) {
      var temp = Number(s.temp);
      if (isNaN(temp)) return;
      var r = Number(s.radius_m) || 1.83; // ~6 ft
      var x = Number(s.pos_x);
      var z = Number(s.pos_y); // plan Y → scene Z
      var y = Number(s.pos_z);
      if (isNaN(x) || isNaN(z)) return;
      if (isNaN(y)) y = 1.0;

      var color = tempToColor(temp);
      // Soft sphere — slightly flattened so floor stays readable
      var geo = new THREE.SphereGeometry(r, 24, 16);
      var mat = new THREE.MeshBasicMaterial({
        color: color,
        transparent: true,
        opacity: 0.16,
        depthWrite: false,
        side: THREE.DoubleSide,
      });
      var mesh = new THREE.Mesh(geo, mat);
      mesh.position.set(x, Math.max(0.35, y), z);
      mesh.scale.set(1, 0.72, 1); // elliptical influence volume
      mesh.userData = { envSensor: s, kind: 'heat' };
      mesh.renderOrder = 1;
      heatGroup.add(mesh);

      // Small core marker
      var core = new THREE.Mesh(
        new THREE.SphereGeometry(0.08, 12, 10),
        new THREE.MeshBasicMaterial({
          color: color,
          transparent: true,
          opacity: 0.85,
          depthWrite: false,
        })
      );
      core.position.copy(mesh.position);
      core.userData = { envSensor: s, kind: 'core' };
      heatGroup.add(core);

      // Temp label (billboard-ish, flat on XZ then tilt toward camera is hard without update — flat top label)
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
        new THREE.PlaneGeometry(0.55, 0.14),
        new THREE.MeshBasicMaterial({ map: ltex, transparent: true, depthWrite: false })
      );
      lab.position.set(x, mesh.position.y + 0.22, z);
      lab.rotation.x = -Math.PI / 2;
      lab.userData = { envSensor: s, kind: 'label' };
      heatGroup.add(lab);
    });

    if (!cabinets.length && !floorPdus.length) {
      var c2 = document.createElement('canvas');
      c2.width = 512;
      c2.height = 128;
      var cx = c2.getContext('2d');
      cx.fillStyle = '#1e293b';
      cx.fillRect(0, 0, 512, 128);
      cx.fillStyle = '#94a3b8';
      cx.font = '24px Segoe UI, sans-serif';
      cx.textAlign = 'center';
      cx.fillText('No cabinets or PDUs on floor plan yet', 256, 70);
      var tex2 = new THREE.CanvasTexture(c2);
      var plane = new THREE.Mesh(
        new THREE.PlaneGeometry(8, 2),
        new THREE.MeshBasicMaterial({ map: tex2, transparent: true })
      );
      plane.position.set(fw / 2, 1.5, fd / 2);
      scene.add(plane);
    }

    var isDown = false, lastX = 0, lastY = 0;
    var theta = Math.PI / 4, phi = Math.PI / 3.2, radius = 28;
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
    function animate() {
      animId = requestAnimationFrame(animate);
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

    return {
      scene: scene,
      camera: camera,
      renderer: renderer,
      heatGroup: heatGroup,
      setHeatOverlay: function (on) {
        heatGroup.visible = !!on;
      },
      dispose: function () {
        cancelled = true;
        cancelAnimationFrame(animId);
        window.removeEventListener('resize', onResize);
        try {
          if (statusEl && statusEl.parentNode) statusEl.parentNode.removeChild(statusEl);
        } catch (e) { /* ignore */ }
        try {
          heatGroup.traverse(function (obj) {
            if (obj.geometry) obj.geometry.dispose();
            if (obj.material) {
              if (obj.material.map) obj.material.map.dispose();
              obj.material.dispose();
            }
          });
        } catch (e2) { /* ignore */ }
        renderer.dispose();
      },
    };
  }

  global.ColdAisle3D = { mount: mount };
  // Legacy alias (WinDCIM)
  global.WinDCIM3D = global.ColdAisle3D;
})(typeof window !== 'undefined' ? window : this);
