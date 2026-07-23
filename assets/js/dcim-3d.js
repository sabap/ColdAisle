/**
 * WinDCIM — Three.js rectangular rack / floor visualization
 * Textures front/rear faces with device template images stacked by U position.
 */
(function (global) {
  'use strict';

  function mmToM(mm) { return (Number(mm) || 0) / 1000; }

  function mediaBase() {
    var b = (global.WINDCIM && global.WINDCIM.baseUrl) || (global.WinDCIM && global.WinDCIM.baseUrl) || '';
    return String(b).replace(/\/$/, '');
  }

  function absUrl(url) {
    if (!url) return '';
    if (/^https?:\/\//i.test(url)) return url;
    var base = mediaBase();
    if (url.charAt(0) === '/') return (base ? base.replace(/^(https?:\/\/[^/]+).*/i, '$1') : '') + url;
    return (base ? base + '/' : '') + url.replace(/^\//, '');
  }

  function loadImage(url) {
    return new Promise(function (resolve) {
      if (!url) {
        resolve(null);
        return;
      }
      var img = new Image();
      // Same-origin media.php (session cookie) — do not set crossOrigin or canvas taints / auth fails
      img.onload = function () { resolve(img); };
      img.onerror = function () { resolve(null); };
      img.src = absUrl(url);
    });
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

  /**
   * Build a canvas texture for one rack face (19:1.75×U aspect).
   * Y maps U1 at bottom of texture (matches rack elevation).
   */
  function buildFaceTexture(cab, face) {
    var units = Math.max(1, Number(cab.u_height) || 42);
    // Scale so U height has reasonable texels; width follows 19/1.75 ratio
    var pxPerU = 32;
    var h = Math.max(64, Math.round(units * pxPerU));
    var w = Math.max(64, Math.round(h * (19 / (units * 1.75))));

    var canvas = document.createElement('canvas');
    canvas.width = w;
    canvas.height = h;
    var ctx = canvas.getContext('2d');

    // Empty rack bay
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
    // Side rails
    ctx.fillStyle = '#94a3b8';
    ctx.fillRect(0, 0, Math.max(2, w * 0.03), h);
    ctx.fillRect(w - Math.max(2, w * 0.03), 0, Math.max(2, w * 0.03), h);

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
        var d = row.d;
        var pos = Math.max(1, Number(d.position_u) || 1);
        var uh = Math.max(1, Number(d.u_height) || 1);
        // Bottom of device in U space from floor of rack
        var bottomU = pos - 1;
        var topU = pos - 1 + uh;
        // Canvas Y increases downward; U1 at bottom of canvas
        var yTop = h - (topU / units) * h;
        var yBot = h - (bottomU / units) * h;
        var dh = Math.max(1, yBot - yTop);

        if (row.img) {
          drawCover(ctx, row.img, 0, yTop, w, dh);
        } else {
          ctx.fillStyle = typeColor(d.device_type);
          ctx.fillRect(0, yTop, w, dh);
          ctx.strokeStyle = 'rgba(15,23,42,0.8)';
          ctx.strokeRect(0.5, yTop + 0.5, w - 1, dh - 1);
          ctx.fillStyle = '#e2e8f0';
          ctx.font = 'bold ' + Math.max(10, Math.min(16, dh * 0.35)) + 'px Segoe UI,sans-serif';
          ctx.textAlign = 'center';
          ctx.textBaseline = 'middle';
          ctx.fillText(String(d.label || '').slice(0, 14), w / 2, yTop + dh / 2);
        }
        // Hairline between devices
        ctx.strokeStyle = 'rgba(148,163,184,0.45)';
        ctx.beginPath();
        ctx.moveTo(0, yTop);
        ctx.lineTo(w, yTop);
        ctx.stroke();
      });

      var tex = new THREE.CanvasTexture(canvas);
      tex.needsUpdate = true;
      tex.minFilter = THREE.LinearFilter;
      tex.magFilter = THREE.LinearFilter;
      if (THREE.sRGBEncoding !== undefined) {
        tex.encoding = THREE.sRGBEncoding;
      }
      return tex;
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
    var rooms = options.rooms || [];
    var interactive = options.interactive !== false;

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

      // Front / rear face planes — textured async with device images
      var faceW = w * 0.98;
      var faceH = h * 0.98;
      var frontGeo = new THREE.PlaneGeometry(faceW, faceH);
      var frontMat = new THREE.MeshStandardMaterial({
        color: 0x1e293b,
        roughness: 0.45,
        metalness: 0.15,
      });
      var front = new THREE.Mesh(frontGeo, frontMat);
      front.position.set(0, 0, d / 2 + 0.003);
      mesh.add(front);

      var rearMat = new THREE.MeshStandardMaterial({
        color: 0x1e293b,
        roughness: 0.45,
        metalness: 0.15,
      });
      var rear = new THREE.Mesh(frontGeo.clone(), rearMat);
      rear.position.set(0, 0, -d / 2 - 0.003);
      rear.rotation.y = Math.PI;
      mesh.add(rear);

      // Load device textures onto faces
      buildFaceTexture(cab, 'front').then(function (tex) {
        if (!tex) return;
        frontMat.map = tex;
        frontMat.color.setHex(0xffffff);
        frontMat.needsUpdate = true;
      });
      buildFaceTexture(cab, 'rear').then(function (tex) {
        if (!tex) return;
        rearMat.map = tex;
        rearMat.color.setHex(0xffffff);
        rearMat.needsUpdate = true;
      });

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
      dispose: function () {
        cancelAnimationFrame(animId);
        window.removeEventListener('resize', onResize);
        renderer.dispose();
      },
    };
  }

  global.WinDCIM3D = { mount: mount };
})(typeof window !== 'undefined' ? window : this);
