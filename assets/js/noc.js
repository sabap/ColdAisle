/**
 * ColdAisle NOC wall — metrics, rotating panels, sparkline, spinning 3D.
 */
(function () {
  'use strict';

  var cfg = window.ColdAisleNoc || {};
  var apiBase = cfg.apiUrl || 'api/noc.php';
  var pollMs = Math.max(5000, Number(cfg.pollMs) || 20000);
  var panelRotateMs = Math.max(8000, Number(cfg.panelRotateMs) || 18000);
  var sceneReloadMs = Math.max(60000, Number(cfg.sceneReloadMs) || 300000);
  /** Version of HTML/JS this tab loaded; API version change triggers full reload */
  var bootVersion = cfg.appVersion ? String(cfg.appVersion) : null;
  var reloadingForUpdate = false;
  var view3d = null;
  var sceneLoadedAt = 0;
  var hidden = false;
  var lastData = null;
  var panels = ['overview', 'power', 'zones', 'cooling'];
  var panelIdx = 0;
  var panelTimer = null;
  var userPinned = false;

  function $(id) { return document.getElementById(id); }

  function esc(s) {
    var d = document.createElement('div');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
  }

  function fmtNum(n, d) {
    if (n == null || isNaN(n)) return '—';
    var x = Number(n);
    if (d === 0) return String(Math.round(x));
    return x.toFixed(d).replace(/\.?0+$/, '');
  }

  function setClock() {
    var el = $('nocClock');
    if (!el) return;
    el.textContent = new Date().toLocaleString(undefined, {
      weekday: 'short', year: 'numeric', month: 'short', day: 'numeric',
      hour: '2-digit', minute: '2-digit', second: '2-digit',
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

  /** SVG area sparkline from kw array */
  function sparklineSvg(kwArr, color) {
    color = color || '#38bdf8';
    var vals = (kwArr || []).map(function (v) {
      return v == null || isNaN(v) ? null : Number(v);
    });
    var nums = vals.filter(function (v) { return v != null; });
    if (!nums.length) {
      return '<div class="noc-empty">No power history yet (poll PDUs to build the chart)</div>';
    }
    var w = 640, h = 140, pad = 8;
    var min = Math.min.apply(null, nums);
    var max = Math.max.apply(null, nums);
    if (min === max) {
      min = Math.max(0, min - 1);
      max = max + 1;
    }
    var padY = (max - min) * 0.08 || 0.5;
    min -= padY;
    max += padY;
    var n = vals.length;
    function xAt(i) {
      if (n <= 1) return w / 2;
      return pad + (i / (n - 1)) * (w - pad * 2);
    }
    function yAt(v) {
      return pad + (1 - (v - min) / (max - min)) * (h - pad * 2);
    }
    var line = '';
    var first = true;
    var pts = [];
    for (var i = 0; i < n; i++) {
      if (vals[i] == null) continue;
      var x = xAt(i), y = yAt(vals[i]);
      pts.push({ x: x, y: y });
      line += (first ? 'M ' : ' L ') + x.toFixed(1) + ' ' + y.toFixed(1);
      first = false;
    }
    if (!pts.length) {
      return '<div class="noc-empty">No numeric power samples</div>';
    }
    var area = line + ' L ' + pts[pts.length - 1].x.toFixed(1) + ' ' + (h - pad) +
      ' L ' + pts[0].x.toFixed(1) + ' ' + (h - pad) + ' Z';
    var gid = 'ng' + Math.random().toString(36).slice(2, 7);
    return '<svg viewBox="0 0 ' + w + ' ' + h + '" preserveAspectRatio="none" role="img" aria-label="Power kW 24h">' +
      '<defs><linearGradient id="' + gid + '" x1="0" y1="0" x2="0" y2="1">' +
      '<stop offset="0%" stop-color="' + color + '" stop-opacity="0.4"/>' +
      '<stop offset="100%" stop-color="' + color + '" stop-opacity="0.02"/>' +
      '</linearGradient></defs>' +
      '<path d="' + area + '" fill="url(#' + gid + ')"/>' +
      '<path d="' + line + '" fill="none" stroke="' + color + '" stroke-width="2.5" stroke-linejoin="round"/>' +
      '<circle cx="' + pts[pts.length - 1].x.toFixed(1) + '" cy="' + pts[pts.length - 1].y.toFixed(1) +
      '" r="4" fill="' + color + '"/>' +
      '</svg>';
  }

  function badge(st) {
    var s = (st || 'unknown').toLowerCase();
    if (s !== 'ok' && s !== 'warn' && s !== 'crit') s = 'unknown';
    return '<span class="noc-badge ' + s + '">' + esc(s) + '</span>';
  }

  function utilBar(pct, hotAt) {
    if (pct == null || isNaN(pct)) return '';
    var p = Math.min(100, Math.max(0, Number(pct)));
    var hot = hotAt != null && p >= hotAt;
    return '<div class="noc-mini-bar' + (hot ? ' hot' : '') + '"><i style="width:' + p + '%"></i></div>';
  }

  function renderOverview(data) {
    var m = data.metrics || {};
    var env = data.env || {};
    var power = data.power || {};
    var hist = data.power_history || {};
    var zones = data.zones || [];
    var envCls = (env.crit > 0) ? 'crit' : (env.warn > 0) ? 'warn' : 'ok';
    var html = '<div class="noc-metrics">';
    html += card('accent', 'Cabinets', fmtNum(m.cabinets, 0), fmtNum(m.rooms, 0) + ' room(s)');
    html += card('', 'Devices', fmtNum(m.devices, 0), 'Active inventory');
    html += '<div class="noc-card">' +
      '<div class="label">U utilization</div>' +
      '<div class="value">' + fmtNum(m.u_pct, 1) + '<span class="unit">%</span></div>' +
      utilBar(m.u_pct, 85) +
      '<div class="hint">' + fmtNum(m.u_used, 0) + ' / ' + fmtNum(m.u_total, 0) + ' U</div></div>';
    html += card('accent', 'Site power', fmtNum(power.kw != null ? power.kw : m.power_kw, 1) + '<span class="unit">kW</span>',
      fmtNum(m.pdus, 0) + ' PDU(s)');
    html += card('', 'Cooling units', fmtNum(m.cooling_units, 0), 'Air & pumps');
    html += card(envCls, 'Env status',
      fmtNum(env.ok, 0) + ' <span class="unit">ok</span>',
      fmtNum(env.warn, 0) + ' warn · ' + fmtNum(env.crit, 0) + ' crit');
    html += card(env.crit > 0 ? 'crit' : '', 'Env critical', fmtNum(env.crit, 0), 'Threshold breaches');
    html += card(m.open_disposals > 0 ? 'warn' : '', 'Open disposals', fmtNum(m.open_disposals, 0), 'Lifecycle');
    html += card('', 'Sites / DCs', fmtNum(m.sites, 0) + ' / ' + fmtNum(m.datacenters, 0), 'Topology');
    html += card('', 'Env sensors', fmtNum(m.env_sensors, 0), (env.stale || 0) + ' stale (&gt;1h)');

    // Mini power trend + zone strip so Overview feels alive on the wall
    html += '<div class="noc-card wide">' +
      '<div class="label">Site power · 24h trend</div>' +
      '<div class="noc-chart-wrap noc-chart-mini">' + sparklineSvg(hist.kw || [], '#38bdf8') + '</div>' +
      '<div class="noc-chart-meta">' +
      '<span>Now <strong>' + fmtNum(power.kw != null ? power.kw : m.power_kw, 1) + ' kW</strong></span>' +
      (power.kw_max_24h != null
        ? '<span>Peak <strong>' + fmtNum(power.kw_max_24h, 1) + ' kW</strong></span>'
        : '') +
      '</div></div>';

    if (zones.length) {
      html += '<div class="noc-card wide"><div class="label">Power zones</div><div class="noc-zone-strip">';
      zones.slice(0, 8).forEach(function (z) {
        var color = z.color_hex || '#38bdf8';
        if (color.charAt(0) !== '#') color = '#' + color;
        var util = z.util_pct;
        html += '<div class="noc-zone-chip' + (util != null && util >= 75 ? ' hot' : '') + '">' +
          '<span class="dot" style="background:' + esc(color) + '"></span>' +
          '<span class="nm">' + esc(z.name) + '</span>' +
          '<span class="kv">' + fmtNum(z.kw, 1) + ' kW</span>' +
          (util != null ? '<span class="ut">' + fmtNum(util, 0) + '%</span>' : '') +
          '</div>';
      });
      html += '</div></div>';
    }
    html += '</div>';
    return html;
  }

  function renderPower(data) {
    var m = data.metrics || {};
    var power = data.power || {};
    var hist = data.power_history || {};
    var kw = power.kw != null ? power.kw : m.power_kw;
    var html = '<div class="noc-metrics">';
    html += card('accent', 'Live load', fmtNum(kw, 1) + '<span class="unit">kW</span>',
      fmtNum(m.pdus, 0) + ' PDU(s)');
    html += card('', '24h average', fmtNum(power.kw_avg_24h, 1) + '<span class="unit">kW</span>', 'Site total');
    html += card('', '24h peak', fmtNum(power.kw_max_24h, 1) + '<span class="unit">kW</span>', 'Max bucket');
    html += card('', '24h floor', fmtNum(power.kw_min_24h, 1) + '<span class="unit">kW</span>', 'Min bucket');
    html += '<div class="noc-card wide">' +
      '<div class="label">Site power · last 24 hours</div>' +
      '<div class="noc-chart-wrap">' + sparklineSvg(hist.kw || [], '#38bdf8') + '</div>' +
      '<div class="noc-chart-meta">' +
      '<span>Points <strong>' + fmtNum(hist.points, 0) + '</strong></span>' +
      (power.last_poll_at
        ? '<span>Last poll <strong>' + esc(String(power.last_poll_at).replace('T', ' ').slice(0, 19)) + '</strong></span>'
        : '') +
      '</div></div>';
    html += '</div>';
    return html;
  }

  function renderZones(data) {
    var zones = data.zones || [];
    if (!zones.length) {
      return '<div class="noc-empty">No power zones defined yet. Add zones under Power → Zones.</div>';
    }
    var html = '<div class="noc-zone-grid">';
    zones.forEach(function (z) {
      var color = z.color_hex || '#38bdf8';
      if (color.charAt(0) !== '#') color = '#' + color;
      var util = z.util_pct;
      var hot = util != null && util >= 75;
      var bar = util != null ? Math.min(100, Math.max(0, util)) : null;
      html += '<div class="noc-zone' + (hot ? ' hot' : '') + '" style="border-left-color:' + esc(color) + '">' +
        '<div class="zn-name">' + esc(z.name) + '</div>' +
        '<div class="zn-meta">' +
        esc(z.dc_name || '') +
        (z.feed_type ? ' · Feed ' + esc(z.feed_type) : '') +
        ' · ' + fmtNum(z.pdu_count, 0) + ' PDU(s)' +
        (z.voltage ? ' · ' + fmtNum(z.voltage, 0) + ' V' : '') +
        '</div>' +
        '<div class="zn-kw">' + fmtNum(z.kw, 1) + ' <span class="unit">kW</span></div>';
      if (bar != null) {
        html += '<div class="zn-bar"><i style="width:' + bar + '%"></i></div>' +
          '<div class="zn-util">' + fmtNum(util, 1) + '% of ' + fmtNum(z.max_kw, 1) + ' kW rated</div>';
      } else {
        html += '<div class="zn-util">No max kW set on zone</div>';
      }
      html += '</div>';
    });
    html += '</div>';
    return html;
  }

  function renderCooling(data) {
    var c = data.cooling || {};
    var env = data.env || {};
    var hot = data.hot_sensors || [];
    var envCls = (env.crit > 0) ? 'crit' : (env.warn > 0) ? 'warn' : 'ok';
    var html = '<div class="noc-metrics">';
    html += card('accent', 'Cooling units', fmtNum(c.units, 0),
      fmtNum(c.primary, 0) + ' primary · ' + fmtNum(c.standby, 0) + ' standby');
    html += card('', 'Rated capacity', fmtNum(c.rated_kw, 1) + '<span class="unit">kW</span>', 'Nameplate sum');
    html += card('', 'SNMP enabled', fmtNum(c.snmp_on, 0), 'Units with SNMP on');
    html += card(envCls, 'Env sensors',
      fmtNum(env.ok, 0) + ' ok',
      fmtNum(env.warn, 0) + ' warn · ' + fmtNum(env.crit, 0) + ' crit · ' + fmtNum(env.stale, 0) + ' stale');
    html += '</div>';

    html += '<div class="noc-card wide" style="margin-top:.75rem">' +
      '<div class="label">Warmest sensors</div>';
    if (!hot.length) {
      html += '<div class="noc-empty">No temperature readings yet</div>';
    } else {
      html += '<div class="noc-list">';
      hot.forEach(function (s) {
        html += '<div class="noc-list-row">' +
          '<div><strong>' + esc(s.name) + '</strong>' +
          (s.humidity != null ? '<div class="muted">' + fmtNum(s.humidity, 0) + '%RH</div>' : '') +
          '</div>' +
          '<div style="text-align:right">' +
          '<strong style="font-size:1.15rem">' + fmtNum(s.value, 1) + '</strong> ' +
          '<span class="muted">' + esc(s.unit || '') + '</span> ' + badge(s.status) +
          '</div></div>';
      });
      html += '</div>';
    }
    html += '</div>';

    var list = c.list || [];
    if (list.length) {
      html += '<div class="noc-card wide" style="margin-top:.75rem">' +
        '<div class="label">Air units</div><div class="noc-list">';
      list.forEach(function (u) {
        html += '<div class="noc-list-row">' +
          '<div><strong>' + esc(u.name) + '</strong>' +
          '<div class="muted">' + esc(u.type || '') +
          (u.role ? ' · ' + esc(u.role) : '') +
          (u.status ? ' · ' + esc(u.status) : '') +
          '</div></div>' +
          '<div class="muted" style="text-align:right">' +
          (u.rated_kw != null ? fmtNum(u.rated_kw, 1) + ' kW' : '—') +
          (u.snmp ? ' · SNMP' : '') +
          '</div></div>';
      });
      html += '</div></div>';
    }
    return html;
  }

  function renderAll(data) {
    lastData = data;
    var o = $('panel-overview');
    var p = $('panel-power');
    var z = $('panel-zones');
    var c = $('panel-cooling');
    if (o) o.innerHTML = renderOverview(data);
    if (p) p.innerHTML = renderPower(data);
    if (z) z.innerHTML = renderZones(data);
    if (c) c.innerHTML = renderCooling(data);

    var upd = $('nocUpdated');
    if (upd) {
      upd.textContent = 'Last update: ' + (data.updated_at
        ? new Date(data.updated_at).toLocaleString()
        : new Date().toLocaleString());
    }
  }

  function restartRotateProgress() {
    var bar = $('nocRotateBar');
    if (!bar) return;
    bar.classList.remove('run');
    // Force reflow so CSS animation restarts
    void bar.offsetWidth;
    if (!userPinned && !hidden) {
      bar.style.animationDuration = panelRotateMs + 'ms';
      bar.classList.add('run');
    }
  }

  function showPanel(name) {
    var idx = panels.indexOf(name);
    if (idx < 0) return;
    panelIdx = idx;
    document.querySelectorAll('.noc-panel').forEach(function (el) {
      el.classList.toggle('active', el.getAttribute('data-panel') === name);
    });
    document.querySelectorAll('.noc-tab').forEach(function (el) {
      el.classList.toggle('active', el.getAttribute('data-panel') === name);
    });
    var hint = $('nocPanelHint');
    if (hint) {
      hint.textContent = userPinned
        ? 'Panel pinned · click tabs to change'
        : 'Panel: ' + name + ' · auto-rotates';
    }
    restartRotateProgress();
  }

  function nextPanel() {
    if (userPinned || hidden) return;
    panelIdx = (panelIdx + 1) % panels.length;
    showPanel(panels[panelIdx]);
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
    var cooling = scene.cooling || scene.cooling_units || [];
    var rooms = scene.rooms || [];
    var envSensors = scene.env_sensors || scene.envSensors || [];
    var logoUrl = scene.logo_url || cfg.logoUrl || '';

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
        cooling: cooling,
        rooms: rooms,
        envSensors: envSensors,
        logoUrl: logoUrl,
        heatOverlay: envSensors.length > 0,
        interactive: false,
        autoRotate: true,
        autoRotateSpeed: 0.0025,
        textureFaces: 'none',
      });
      sceneLoadedAt = Date.now();
      // Health may already be on cabinet rows; also accept top-level snapshot if provided later
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

  /**
   * Wall TVs stay open for days. When operators ship a new ColdAisle build,
   * api/noc.php returns a new App::VERSION — full reload picks up CSS/JS/HTML.
   */
  function checkAppVersion(data) {
    var remote = data && data.version != null ? String(data.version) : '';
    if (!remote) return false;
    if (!bootVersion) {
      bootVersion = remote;
      return false;
    }
    if (remote === bootVersion) return false;
    if (reloadingForUpdate) return true;
    reloadingForUpdate = true;
    setStatus(null, 'App updated to v' + remote + ' — reloading…');
    showError('');
    var verEl = $('nocAppVer');
    if (verEl) {
      verEl.textContent = (data.app || 'ColdAisle') + ' v' + remote + ' (reloading…)';
    }
    // Bust HTML cache while keeping token query args
    try {
      var u = new URL(window.location.href);
      u.searchParams.set('_nocv', remote);
      u.searchParams.set('_t', String(Date.now()));
      setTimeout(function () {
        window.location.replace(u.toString());
      }, 800);
    } catch (e) {
      setTimeout(function () {
        window.location.reload();
      }, 800);
    }
    return true;
  }

  function poll(forceScene) {
    if (hidden || reloadingForUpdate) return;
    var needScene = forceScene || !view3d || (Date.now() - sceneLoadedAt > sceneReloadMs);
    setStatus(null, 'Refreshing…');
    fetch(apiUrl(needScene), {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
      cache: 'no-store',
    })
      .then(function (r) {
        return r.json().then(function (j) {
          return { ok: r.ok, status: r.status, j: j };
        });
      })
      .then(function (res) {
        if (reloadingForUpdate) return;
        if (!res.ok || !res.j || !res.j.ok) {
          var err = (res.j && res.j.error) || ('HTTP ' + res.status);
          showError(err);
          setStatus(false, 'Update failed');
          return;
        }
        if (checkAppVersion(res.j)) return;
        showError('');
        renderAll(res.j);
        if (needScene && res.j.scene) {
          mountScene(res.j.scene);
        }
        // Apply live cabinet health every poll (scene geometry only reloads rarely)
        if (view3d && typeof view3d.setCabinetHealth === 'function' && res.j.cabinet_health) {
          try {
            view3d.setCabinetHealth(res.j.cabinet_health);
          } catch (eH) { /* ignore */ }
        }
        setStatus(true, 'Live · every ' + Math.round(pollMs / 1000) + 's');
      })
      .catch(function () {
        if (reloadingForUpdate) return;
        showError('Network error loading NOC data');
        setStatus(false, 'Offline');
      });
  }

  document.querySelectorAll('.noc-tab').forEach(function (btn) {
    btn.addEventListener('click', function () {
      userPinned = true;
      showPanel(btn.getAttribute('data-panel'));
      // Resume auto-rotate after 2 full cycles of silence
      clearTimeout(window._nocPinT);
      window._nocPinT = setTimeout(function () {
        userPinned = false;
        restartRotateProgress();
      }, panelRotateMs * 2);
    });
  });

  document.addEventListener('visibilitychange', function () {
    hidden = document.hidden;
    if (!hidden) {
      poll(false);
      restartRotateProgress();
    } else {
      var bar = $('nocRotateBar');
      if (bar) bar.classList.remove('run');
    }
  });

  setClock();
  setInterval(setClock, 1000);
  showPanel('overview');
  poll(true);
  setInterval(function () { poll(false); }, pollMs);
  panelTimer = setInterval(nextPanel, panelRotateMs);
})();
