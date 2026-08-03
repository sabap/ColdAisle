/**
 * ColdAisle NOC wall — poll metrics, soft-update DOM, spin 3D scene.
 */
(function () {
  'use strict';

  var cfg = window.ColdAisleNoc || {};
  var apiBase = cfg.apiUrl || 'api/noc.php';
  var pollMs = Math.max(5000, Number(cfg.pollMs) || 20000);
  var sceneReloadMs = Math.max(60000, Number(cfg.sceneReloadMs) || 300000);
  var view3d = null;
  var sceneLoadedAt = 0;
  var hidden = false;

  function $(id) { return document.getElementById(id); }

  function fmtNum(n, d) {
    if (n == null || isNaN(n)) return '—';
    var x = Number(n);
    if (d === 0) return String(Math.round(x));
    return x.toFixed(d).replace(/\.?0+$/, '');
  }

  function setClock() {
    var el = $('nocClock');
    if (!el) return;
    var now = new Date();
    el.textContent = now.toLocaleString(undefined, {
      weekday: 'short',
      year: 'numeric',
      month: 'short',
      day: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
      second: '2-digit',
    });
  }

  function setStatus(ok, msg) {
    var dot = $('nocDot');
    var st = $('nocStatus');
    if (dot) {
      dot.className = 'noc-status-dot ' + (ok === true ? '' : ok === false ? 'err' : 'wait');
    }
    if (st) st.textContent = msg || '';
  }

  function showError(msg) {
    var el = $('nocError');
    if (!el) return;
    if (!msg) {
      el.classList.remove('show');
      el.textContent = '';
      return;
    }
    el.textContent = msg;
    el.classList.add('show');
  }

  function card(cls, label, valueHtml, hint) {
    return '<div class="noc-card ' + (cls || '') + '">' +
      '<div class="label">' + label + '</div>' +
      '<div class="value">' + valueHtml + '</div>' +
      (hint ? '<div class="hint">' + hint + '</div>' : '') +
      '</div>';
  }

  function renderMetrics(data) {
    var m = data.metrics || {};
    var env = data.env || {};
    var power = data.power || {};
    var host = $('nocMetrics');
    if (!host) return;

    var envCls = (env.crit > 0) ? 'crit' : (env.warn > 0) ? 'warn' : 'ok';
    var html = '';
    html += card('accent', 'Cabinets', fmtNum(m.cabinets, 0), fmtNum(m.rooms, 0) + ' room(s)');
    html += card('', 'Devices', fmtNum(m.devices, 0), 'Active inventory');
    html += card('', 'U utilization', fmtNum(m.u_pct, 1) + '<span class="unit">%</span>',
      fmtNum(m.u_used, 0) + ' / ' + fmtNum(m.u_total, 0) + ' U');
    html += card('accent', 'Site power', fmtNum(power.kw != null ? power.kw : m.power_kw, 1) + '<span class="unit">kW</span>',
      fmtNum(m.pdus, 0) + ' PDU(s)');
    html += card('', 'PDUs', fmtNum(m.pdus, 0), power.last_poll_at
      ? 'Last poll ' + String(power.last_poll_at).replace('T', ' ').slice(0, 19)
      : 'No poll yet');
    html += card('', 'Cooling units', fmtNum(m.cooling_units, 0), 'Air & pumps');
    html += card('', 'Env sensors', fmtNum(m.env_sensors, 0),
      (env.stale || 0) + ' stale (&gt;1h)');
    html += card(envCls, 'Env status',
      fmtNum(env.ok, 0) + ' <span class="unit">ok</span>',
      fmtNum(env.warn, 0) + ' warn · ' + fmtNum(env.crit, 0) + ' crit');
    html += card(env.crit > 0 ? 'crit' : '', 'Env critical', fmtNum(env.crit, 0), 'Threshold breaches');
    html += card(m.open_disposals > 0 ? 'warn' : '', 'Open disposals', fmtNum(m.open_disposals, 0), 'Lifecycle');
    html += card('', 'Sites / DCs', fmtNum(m.sites, 0) + ' / ' + fmtNum(m.datacenters, 0), 'Topology');
    host.innerHTML = html;

    var upd = $('nocUpdated');
    if (upd) {
      upd.textContent = 'Last update: ' + (data.updated_at
        ? new Date(data.updated_at).toLocaleString()
        : new Date().toLocaleString());
    }
  }

  function loadScript(src) {
    return new Promise(function (resolve, reject) {
      var s = document.createElement('script');
      s.src = src;
      s.async = true;
      s.onload = function () { resolve(); };
      s.onerror = function () { reject(new Error('Failed to load ' + src)); };
      document.head.appendChild(s);
    });
  }

  function mountScene(scene) {
    var el = $('noc3d');
    if (!el || !scene) return;
    var cabinets = scene.cabinets || [];
    var pdus = scene.pdus || [];
    var rooms = scene.rooms || [];
    var envSensors = scene.env_sensors || scene.envSensors || [];

    function start() {
      if (!window.ColdAisle3D) {
        el.innerHTML = '<div style="padding:1rem;color:#94a3b8">3D module unavailable</div>';
        return;
      }
      if (view3d && typeof view3d.dispose === 'function') {
        try { view3d.dispose(); } catch (e) { /* ignore */ }
        view3d = null;
      }
      view3d = ColdAisle3D.mount(el, {
        cabinets: cabinets,
        pdus: pdus,
        rooms: rooms,
        envSensors: envSensors,
        heatOverlay: envSensors.length > 0,
        interactive: false,
        autoRotate: true,
        autoRotateSpeed: 0.0025,
        textureFaces: 'none',
      });
      sceneLoadedAt = Date.now();
    }

    if (window.THREE && window.ColdAisle3D) {
      start();
      return;
    }
    var threeUrl = cfg.threeUrl || 'https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js';
    var app3d = cfg.dcim3dUrl || 'assets/js/dcim-3d.js';
    loadScript(threeUrl)
      .then(function () { return loadScript(app3d); })
      .then(start)
      .catch(function () {
        el.innerHTML = '<div style="padding:1rem;color:#94a3b8">3D view could not load (CDN blocked?)</div>';
      });
  }

  function apiUrl(withScene) {
    var u = apiBase;
    var sep = u.indexOf('?') >= 0 ? '&' : '?';
    if (withScene) u += sep + 'scene=1';
    return u;
  }

  function poll(forceScene) {
    if (hidden) return;
    var needScene = forceScene || !view3d || (Date.now() - sceneLoadedAt > sceneReloadMs);
    setStatus(null, 'Refreshing…');
    fetch(apiUrl(needScene), {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    })
      .then(function (r) {
        return r.json().then(function (j) {
          return { ok: r.ok, status: r.status, j: j };
        });
      })
      .then(function (res) {
        if (!res.ok || !res.j || !res.j.ok) {
          var err = (res.j && res.j.error) || ('HTTP ' + res.status);
          showError(err);
          setStatus(false, 'Update failed');
          return;
        }
        showError('');
        renderMetrics(res.j);
        if (needScene && res.j.scene) {
          mountScene(res.j.scene);
        }
        setStatus(true, 'Live · every ' + Math.round(pollMs / 1000) + 's');
      })
      .catch(function () {
        showError('Network error loading NOC data');
        setStatus(false, 'Offline');
      });
  }

  document.addEventListener('visibilitychange', function () {
    hidden = document.hidden;
    if (!hidden) poll(false);
  });

  setClock();
  setInterval(setClock, 1000);
  poll(true);
  setInterval(function () { poll(false); }, pollMs);
})();
