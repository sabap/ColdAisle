/**
 * Experimental overlay hall views (dashboard + NOC lab).
 * Does not replace production dashboard or NOC scripts.
 */
(function () {
  'use strict';

  var cfg = window.ColdAisleLab || {};
  var view3d = null;
  var lastPayload = null;
  var panel = 'overview';
  var cam = 'orbit';

  function $(id) { return document.getElementById(id); }

  function fmt(n, d) {
    if (n == null || n === '' || !isFinite(Number(n))) return '—';
    return Number(n).toFixed(d == null ? 1 : d);
  }

  function loadScript(url) {
    return new Promise(function (resolve, reject) {
      var s = document.createElement('script');
      s.src = url;
      s.async = true;
      s.onload = function () { resolve(); };
      s.onerror = function () { reject(new Error('script ' + url)); };
      document.head.appendChild(s);
    });
  }

  function tickClock() {
    var el = $('labClock');
    if (!el) return;
    var now = new Date();
    el.textContent = now.toISOString().slice(11, 19) + ' UTC';
  }

  function setPanel(id) {
    panel = id;
    document.querySelectorAll('.lab-rail-btn[data-panel]').forEach(function (b) {
      b.classList.toggle('is-active', b.getAttribute('data-panel') === id);
    });
    paintInspector(lastPayload);
    paintChips(lastPayload);
  }

  function paintChips(data) {
    var box = $('labChips');
    if (!box || !data) return;
    var m = data.metrics || {};
    var p = data.power || {};
    var c = data.cooling || {};
    var env = data.env || {};
    var unit = (window.ColdAisle && ColdAisle.tempSymbol) || '°C';
    var chips = [];
    if (panel === 'thermal') {
      chips = [
        { k: 'Cold aisle', v: fmt(c.avg_cold_aisle) + unit, s: 'env sensors' },
        { k: 'Hot aisle', v: fmt(c.avg_hot_aisle) + unit, s: 'env sensors' },
        { k: 'Supply', v: fmt(c.avg_supply) + unit, s: 'CRAH / CRAC' },
        { k: 'Return', v: fmt(c.avg_return) + unit, s: 'CRAH / CRAC' },
        { k: 'Env crit', v: String(env.crit || 0), s: 'sensors over limit', alert: (env.crit || 0) > 0 }
      ];
    } else if (panel === 'power') {
      chips = [
        { k: 'IT load', v: fmt(p.kw, 2) + ' kW', s: (p.pdu_polled || 0) + ' PDU polled' },
        { k: 'Amps', v: p.pdu_amps != null ? fmt(p.pdu_amps, 0) + ' A' : '—', s: 'site rollup' },
        { k: 'UPS', v: String(m.ups_units || 0), s: 'active units' },
        { k: 'SNMP stale', v: String(p.snmp_stale || 0), s: 'of ' + (p.snmp_monitored || 0), alert: (p.snmp_stale || 0) > 0 }
      ];
    } else if (panel === 'network') {
      chips = [
        { k: 'Cabinets', v: String(m.cabinets || 0), s: 'on hall' },
        { k: 'Devices', v: String(m.devices || 0), s: 'active' },
        { k: 'U used', v: fmt(m.u_pct, 0) + '%', s: (m.u_used || 0) + ' / ' + (m.u_total || 0) + ' U' }
      ];
    } else {
      var alerts = (data.alerts || data.recent_alerts || []).filter(function (a) {
        return a && (a.sev === 'crit' || a.sev === 'warn') && !a.cleared && !a.is_cleared;
      });
      chips = [
        { k: 'IT load', v: fmt(p.kw, 2) + ' kW', s: 'PDU site rollup' },
        { k: 'Inlet', v: fmt(c.avg_cold_aisle || c.avg_supply) + unit, s: 'cold aisle / supply' },
        { k: 'U fill', v: fmt(m.u_pct, 0) + '%', s: 'cabinet space' },
        { k: 'Alerts', v: String(alerts.length), s: 'open warn/crit', alert: alerts.length > 0 }
      ];
    }
    box.innerHTML = chips.map(function (ch) {
      return '<div class="lab-chip' + (ch.alert ? ' is-alert' : '') + '">'
        + '<div class="k">' + esc(ch.k) + '</div>'
        + '<div class="v">' + esc(ch.v) + '</div>'
        + '<div class="s">' + esc(ch.s || '') + '</div></div>';
    }).join('');
  }

  function ringSvg(pct, label) {
    var p = Math.max(0, Math.min(100, Number(pct) || 0));
    var r = 26;
    var c = 2 * Math.PI * r;
    var dash = (p / 100) * c;
    return '<div class="lab-gauge"><svg width="72" height="72" viewBox="0 0 72 72">'
      + '<circle cx="36" cy="36" r="' + r + '" fill="none" stroke="#041820" stroke-width="5"/>'
      + '<circle cx="36" cy="36" r="' + r + '" fill="none" stroke="#00e5ff" stroke-width="5" '
      + 'stroke-linecap="round" stroke-dasharray="' + dash.toFixed(1) + ' ' + c.toFixed(1) + '" '
      + 'transform="rotate(-90 36 36)"/>'
      + '<text x="36" y="40" text-anchor="middle" fill="#00e5ff" font-size="12" font-family="Orbitron,sans-serif">'
      + Math.round(p) + '</text></svg><div class="k">' + esc(label) + '</div></div>';
  }

  function gaugeRow(data) {
    var m = (data && data.metrics) || {};
    var p = (data && data.power) || {};
    var u = Number(m.u_pct) || 0;
    var kw = Number(p.kw) || 0;
    var kwPct = Math.max(0, Math.min(100, kw * 8));
    var env = (data && data.env) || {};
    var envN = (env.ok || 0) + (env.warn || 0) + (env.crit || 0) + (env.unknown || 0);
    var envOk = envN ? (100 * (env.ok || 0) / envN) : 0;
    return '<div class="lab-gauges">' + ringSvg(u, 'U fill') + ringSvg(kwPct, 'Load') + ringSvg(envOk, 'Env') + '</div>';
  }

  function paintInspector(data) {
    var box = $('labInspectorBody');
    if (!box || !data) return;
    var m = data.metrics || {};
    var p = data.power || {};
    var c = data.cooling || {};
    var env = data.env || {};
    var title = $('labInspectorTitle');
    if (title) {
      title.textContent = panel === 'thermal' ? 'Thermal' : panel === 'power' ? 'Power' : panel === 'network' ? 'Inventory' : 'Hall';
    }
    var rows = [];
    if (panel === 'thermal') {
      rows = [
        ['Units', String(m.cooling_units || 0)],
        ['Primary live', String(c.live_primary || '—')],
        ['Standby live', String(c.live_standby || '—')],
        ['Env warn', String(env.warn || 0)],
        ['Env crit', String(env.crit || 0)],
        ['Stale', String(env.stale || 0)]
      ];
    } else if (panel === 'power') {
      rows = [
        ['kW', fmt(p.kw, 2)],
        ['PDUs', String(m.pdus || 0)],
        ['Polled', String(p.pdu_polled || 0)],
        ['UPS', String(m.ups_units || 0)],
        ['24h avg kW', fmt(p.kw_avg_24h, 2)]
      ];
    } else if (panel === 'network') {
      rows = [
        ['Sites', String(m.sites || 0)],
        ['Rooms', String(m.rooms || 0)],
        ['Cabinets', String(m.cabinets || 0)],
        ['Devices', String(m.devices || 0)],
        ['U %', fmt(m.u_pct, 1)]
      ];
    } else {
      rows = [
        ['Cabinets', String(m.cabinets || 0)],
        ['Devices', String(m.devices || 0)],
        ['kW', fmt(p.kw, 2)],
        ['Cooling', String(m.cooling_units || 0)],
        ['Sensors', String(m.env_sensors || 0)]
      ];
    }
    var html = gaugeRow(data) + '<div class="lab-stat-grid">';
    rows.forEach(function (r) {
      html += '<div class="lab-stat"><div class="k">' + esc(r[0]) + '</div><div class="v">' + esc(r[1]) + '</div></div>';
    });
    html += '</div>';
    var list = (panel === 'thermal' ? (c.list || []) : panel === 'power' ? (p.top_pdus || []) : [])
      .slice(0, 8);
    if (list.length) {
      html += '<div class="lab-bars">';
      list.forEach(function (row) {
        var name = row.name || '—';
        var pct = 0;
        var sub = '';
        if (panel === 'thermal') {
          sub = (row.supply != null ? ('sup ' + row.supply) : '') + (row.live_role ? (' · ' + row.live_role) : '');
        } else {
          var w = Number(row.last_poll_watts || row.watts || 0);
          pct = Math.max(0, Math.min(100, w / 100));
          sub = fmt(w / 1000, 2) + ' kW';
        }
        html += '<div class="lab-bar-row"><div class="k"><span>' + esc(name) + '</span><span>' + esc(sub) + '</span></div>'
          + '<div class="lab-bar"><i style="width:' + pct + '%"></i></div></div>';
      });
      html += '</div>';
    }
    box.innerHTML = html;
  }

  function paintStream(data) {
    var box = $('labStreamList');
    if (!box) return;
    var alerts = data.alerts || data.recent_alerts || [];
    if (!alerts.length) {
      box.innerHTML = '<div class="lab-ev"><span></span><span class="sev-info">INFO</span><span>No recent site alerts.</span></div>';
      return;
    }
    box.innerHTML = alerts.slice(0, 14).map(function (a) {
      var sev = (a.sev || a.severity || 'info');
      var t = String(a.created_at || a.at || '').replace('T', ' ').slice(11, 19);
      var msg = a.title || a.message || '';
      return '<div class="lab-ev"><span>' + esc(t) + '</span><span class="sev-' + esc(sev) + '">'
        + esc(String(sev).toUpperCase()) + '</span><span>' + esc(msg) + '</span></div>';
    }).join('');
  }

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }

  function applyCam(name) {
    cam = name;
    document.querySelectorAll('.lab-cam button').forEach(function (b) {
      b.classList.toggle('is-active', b.getAttribute('data-cam') === name);
    });
    if (!view3d) return;
    if (name === 'aisle' && typeof view3d.setMode === 'function') {
      try { view3d.setMode('walk'); } catch (e) { /* orbit fallback */ }
    }
    if (name !== 'aisle' && typeof view3d.setMode === 'function') {
      try { view3d.setMode('orbit'); } catch (e2) { /* ignore */ }
    }
    if (typeof view3d.setCameraView !== 'function') return;
    if (name === 'plan') {
      view3d.setCameraView({ phi: 0.22, radius: 42, theta: Math.PI / 4 });
    } else if (name === 'orbit') {
      view3d.setCameraView({ phi: Math.PI / 3.2, radius: 28, theta: 0.7 });
    } else if (name === 'reset') {
      view3d.setCameraView({ phi: Math.PI / 3.2, radius: 28, theta: 0.7 });
      cam = 'orbit';
      document.querySelectorAll('.lab-cam button').forEach(function (b) {
        b.classList.toggle('is-active', b.getAttribute('data-cam') === 'orbit');
      });
    }
  }

  function mount3d(scene) {
    var el = $('lab3d');
    if (!el || !window.ColdAisle3D) return;
    if (view3d && typeof view3d.dispose === 'function') {
      try { view3d.dispose(); } catch (e) { /* ignore */ }
    }
    view3d = ColdAisle3D.mount(el, {
      cabinets: scene.cabinets || [],
      pdus: scene.pdus || [],
      cooling: scene.cooling || scene.cooling_units || [],
      ups: scene.ups || scene.ups_units || [],
      rooms: scene.rooms || [],
      envSensors: scene.env_sensors || scene.envSensors || [],
      airflowAnchors: scene.airflow_anchors || [],
      airflowOverlay: true,
      cablePaths: scene.cable_paths || scene.cablePaths || [],
      showRaceways: true,
      showObjectLabels: true,
      logoUrl: scene.logo_url || cfg.logoUrl || '',
      heatOverlay: true,
      interactive: true,
      walkEnabled: true,
      autoRotate: cfg.autoRotate !== false,
      autoRotateSpeed: 0.0018,
      textureFaces: 'none',
      look: 'lab',
      cameraPhi: Math.PI / 3.2,
      cameraRadius: 28
    });
    if (scene.cabinet_health && view3d.setCabinetHealth) {
      view3d.setCabinetHealth(scene.cabinet_health);
    }
    applyCam(cam);
  }

  function ingest(data) {
    lastPayload = data || {};
    paintChips(lastPayload);
    paintInspector(lastPayload);
    paintStream(lastPayload);
    if (data && data.scene && !view3d) {
      mount3d(data.scene);
    } else if (data && data.cabinet_health && view3d && view3d.setCabinetHealth) {
      view3d.setCabinetHealth(data.cabinet_health);
    }
  }

  var wantScene = true;

  function poll() {
    var url = cfg.apiUrl;
    if (!url) return;
    if (!wantScene) {
      url = url.replace('scene=1', 'scene=0');
    }
    fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        ingest(data);
        if (data && data.scene) wantScene = false;
      })
      .catch(function () { /* keep last paint */ });
  }

  function bind() {
    document.querySelectorAll('.lab-rail-btn[data-panel]').forEach(function (b) {
      b.addEventListener('click', function () { setPanel(b.getAttribute('data-panel')); });
    });
    document.querySelectorAll('.lab-cam button').forEach(function (b) {
      b.addEventListener('click', function () { applyCam(b.getAttribute('data-cam')); });
    });
  }

  function start() {
    bind();
    tickClock();
    setInterval(tickClock, 1000);
    var threeUrl = cfg.threeUrl || 'https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js';
    var dcimUrl = cfg.dcim3dUrl;
    loadScript(threeUrl)
      .then(function () { return dcimUrl ? loadScript(dcimUrl) : null; })
      .then(function () {
        poll();
        setInterval(poll, cfg.pollMs || 20000);
      })
      .catch(function () {
        poll();
      });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
})();
