/**
 * ColdAisle NOC wall — metrics, rotating panels, sparkline, spinning 3D.
 */
(function () {
  'use strict';

  var cfg = window.ColdAisleNoc || {};
  var apiBase = cfg.apiUrl || 'api/noc.php';
  var pollMs = Math.max(5000, Number(cfg.pollMs) || 20000);
  var panelRotateMs = Math.max(5000, Number(cfg.panelRotateMs) || 20000);
  var sceneReloadMs = Math.max(60000, Number(cfg.sceneReloadMs) || 300000);
  /** If no successful poll within 2× expected interval, mark the live dot red (TV lockup cue). */
  var staleAfterMs = Math.max(pollMs * 2, 60000);
  var lastPollSuccessAt = 0;
  var statusMode = 'wait'; // ok | wait | err
  var nocShowLabels = cfg.showLabels !== false;
  var nocShowRaceways = cfg.showRaceways !== false;
  var nocAutoRotate = cfg.autoRotate !== false;
  var nocClearedTtlSec = Number(cfg.clearedAlertTtlSec);
  if (!isFinite(nocClearedTtlSec)) nocClearedTtlSec = 120;
  var nocCamTiltPct = Number(cfg.camTiltPct);
  var nocCamZoomPct = Number(cfg.camZoomPct);
  if (!isFinite(nocCamTiltPct)) nocCamTiltPct = 63;
  if (!isFinite(nocCamZoomPct)) nocCamZoomPct = 72;

  function nocCameraOpts() {
    if (window.ColdAisle3D && typeof ColdAisle3D.cameraFromPercents === 'function') {
      return ColdAisle3D.cameraFromPercents(nocCamTiltPct, nocCamZoomPct);
    }
    return { phi: Math.PI / 3.2, radius: 28 };
  }
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
  var ALERT_SLOTS = 6; // 2 columns × 3 rows

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

  /**
   * Footer live indicator. Label is "Live" when connected; dot color carries state:
   * green = fresh data, amber = refreshing, red = failed or stale (no update).
   */
  function setStatus(ok, msg) {
    var dot = $('nocDot');
    var st = $('nocStatus');
    if (ok === true) {
      statusMode = 'ok';
      lastPollSuccessAt = Date.now();
    } else if (ok === false) {
      statusMode = 'err';
    } else {
      statusMode = 'wait';
    }
    if (dot) {
      dot.className = 'noc-status-dot ' + (
        statusMode === 'ok' ? '' : statusMode === 'err' ? 'err' : 'wait'
      );
      dot.title = statusMode === 'ok'
        ? 'Receiving updates'
        : statusMode === 'wait'
          ? 'Updating…'
          : 'No recent update — check this TV / network';
    }
    if (st) {
      // Keep the wall label simple; never show panel-rotate intervals here
      if (msg === 'Offline' || msg === 'Connecting…') {
        st.textContent = msg;
      } else if (statusMode === 'err' && lastPollSuccessAt > 0) {
        st.textContent = 'Stale';
      } else if (statusMode === 'err') {
        st.textContent = msg || 'Offline';
      } else {
        st.textContent = 'Live';
      }
    }
  }

  function checkPollWatchdog() {
    if (reloadingForUpdate || hidden) return;
    if (!lastPollSuccessAt) return;
    if (Date.now() - lastPollSuccessAt < staleAfterMs) return;
    // Stuck / frozen wall: expected poll never completed
    var dot = $('nocDot');
    var st = $('nocStatus');
    statusMode = 'err';
    if (dot) {
      dot.className = 'noc-status-dot err';
      dot.title = 'No update for ' + Math.round(staleAfterMs / 1000) +
        's+ (expected every ' + Math.round(pollMs / 1000) + 's)';
    }
    if (st) st.textContent = 'Stale';
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

  // Fixed caps so growth never forces TV scrollbars
  var CAP = { zones: 6, pdus: 5, ups: 5, sensors: 5, cooling: 5, zoneStrip: 6 };

  function moreNote(shown, total, noun) {
    var extra = Math.max(0, (Number(total) || 0) - (Number(shown) || 0));
    if (extra < 1) return '';
    return '<div class="noc-more">+' + extra + ' more ' + (noun || 'items') + '</div>';
  }

  function heroStat(cls, label, valueHtml, hint) {
    return '<div class="noc-hero-stat ' + (cls || '') + '">' +
      '<div class="hs-label">' + esc(label) + '</div>' +
      '<div class="hs-value">' + valueHtml + '</div>' +
      (hint ? '<div class="hs-hint">' + hint + '</div>' : '') +
      '</div>';
  }

  function rankBars(rows, opts) {
    opts = opts || {};
    var maxV = 0;
    rows.forEach(function (r) {
      var v = Number(r.value) || 0;
      if (v > maxV) maxV = v;
    });
    if (maxV <= 0) maxV = 1;
    var html = '<div class="noc-rank-bars">';
    rows.forEach(function (r, i) {
      var v = Number(r.value) || 0;
      var pct = Math.min(100, Math.max(4, (v / maxV) * 100));
      var hot = r.hot || (opts.hotAt != null && v >= opts.hotAt);
      html += '<div class="noc-rank-row' + (hot ? ' hot' : '') + '">' +
        '<div class="rr-idx">' + (i + 1) + '</div>' +
        '<div class="rr-body">' +
        '<div class="rr-top"><span class="rr-name">' + esc(r.name || '—') + '</span>' +
        '<span class="rr-val">' + (r.display != null ? r.display : fmtNum(v, 1)) + '</span></div>' +
        (r.sub ? '<div class="rr-sub">' + r.sub + '</div>' : '') +
        '<div class="rr-track"><i style="width:' + pct + '%"></i></div>' +
        '</div></div>';
    });
    html += '</div>';
    return html;
  }

  function renderOverview(data) {
    var m = data.metrics || {};
    var env = data.env || {};
    var power = data.power || {};
    var hist = data.power_history || {};
    var uh = data.ups_history || {};
    var zones = data.zones || [];
    var upsO = data.ups || {};
    var envCls = (env.crit > 0) ? 'crit' : (env.warn > 0) ? 'warn' : 'ok';
    var upsCls = (upsO.on_battery > 0 || upsO.health_crit > 0) ? 'crit'
      : (upsO.health_warn > 0 ? 'warn' : (upsO.units > 0 ? 'ok' : ''));
    var kw = power.kw != null ? power.kw : m.power_kw;
    var snmpStale = power.snmp_stale != null ? power.snmp_stale : 0;

    var html = '<div class="noc-panel-frame noc-frame-overview">';
    html += '<div class="noc-hero-row">';
    html += heroStat('accent', 'Site power',
      fmtNum(kw, 1) + '<span class="unit">kW</span>',
      (snmpStale > 0 ? snmpStale + ' SNMP stale · ' : '') +
      fmtNum(power.pdu_polled != null ? power.pdu_polled : m.pdus, 0) + ' PDU polled');
    html += heroStat('', 'Cabinets / devices',
      fmtNum(m.cabinets, 0) + '<span class="unit"> / </span>' + fmtNum(m.devices, 0),
      fmtNum(m.rooms, 0) + ' rooms · U ' + fmtNum(m.u_pct, 0) + '%');
    html += heroStat(upsCls, 'UPS',
      (upsO.avg_load_pct != null ? fmtNum(upsO.avg_load_pct, 0) + '<span class="unit">%</span>' : fmtNum(upsO.units, 0)),
      (upsO.units != null ? fmtNum(upsO.units, 0) + ' units' : '') +
      (upsO.on_battery > 0 ? ' · ' + fmtNum(upsO.on_battery, 0) + ' battery' : ' · online'));
    html += heroStat(envCls, 'Environment',
      fmtNum(env.crit, 0) + '<span class="unit"> crit</span>',
      fmtNum(env.warn, 0) + ' warn · ' + fmtNum(env.ok, 0) + ' ok · ' +
      fmtNum(m.cooling_units, 0) + ' cooling');
    html += '</div>';

    html += '<div class="noc-viz-row">';
    html += '<div class="noc-viz-panel">' +
      '<div class="noc-viz-title">Facility PDU · 24h</div>' +
      '<div class="noc-chart-wrap noc-chart-hero">' + sparklineSvg(hist.kw || [], '#38bdf8') + '</div>' +
      '<div class="noc-chart-meta">' +
      '<span>Now <strong>' + fmtNum(kw, 1) + ' kW</strong></span>' +
      (power.kw_max_24h != null ? '<span>Peak <strong>' + fmtNum(power.kw_max_24h, 1) + '</strong></span>' : '') +
      '</div></div>';
    html += '<div class="noc-viz-panel">' +
      '<div class="noc-viz-title">UPS load · 24h</div>' +
      '<div class="noc-chart-wrap noc-chart-hero">' + sparklineSvg(uh.load_pct || [], '#a78bfa') + '</div>' +
      '<div class="noc-chart-meta">' +
      '<span>Avg <strong>' + (upsO.avg_load_pct != null ? fmtNum(upsO.avg_load_pct, 0) + '%' : '—') + '</strong></span>' +
      (upsO.est_kw != null ? '<span>Est <strong>' + fmtNum(upsO.est_kw, 1) + ' kW</strong></span>' : '') +
      '</div></div>';
    html += '</div>';

    var zShow = zones.slice(0, CAP.zoneStrip);
    html += '<div class="noc-foot-strip">';
    html += '<div class="noc-foot-label">Power zones</div>';
    if (!zShow.length) {
      html += '<div class="noc-empty-inline">No zones configured</div>';
    } else {
      html += '<div class="noc-zone-strip">';
      zShow.forEach(function (z) {
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
      html += '</div>';
      html += moreNote(zShow.length, zones.length, 'zones');
    }
    html += '</div></div>';
    return html;
  }

  function renderPower(data) {
    var m = data.metrics || {};
    var power = data.power || {};
    var hist = data.power_history || {};
    var uh = data.ups_history || {};
    var ups = data.ups || {};
    var kw = power.kw != null ? power.kw : m.power_kw;
    var upsCls = (ups.on_battery > 0 || ups.health_crit > 0) ? 'crit'
      : (ups.health_warn > 0 ? 'warn' : (ups.units > 0 ? 'ok' : ''));
    var topPdus = (power.top_pdus || []).slice(0, CAP.pdus);
    var upsList = (ups.list || []).slice(0, CAP.ups);
    var maxPdu = 0;
    topPdus.forEach(function (p) {
      if ((Number(p.kw) || 0) > maxPdu) maxPdu = Number(p.kw) || 0;
    });

    var html = '<div class="noc-panel-frame noc-frame-power">';
    html += '<div class="noc-hero-row noc-hero-3">';
    html += heroStat('accent', 'PDU load now',
      fmtNum(kw, 1) + '<span class="unit">kW</span>',
      'avg ' + fmtNum(power.kw_avg_24h, 1) + ' · peak ' + fmtNum(power.kw_max_24h, 1));
    html += heroStat(upsCls, 'UPS load',
      (ups.avg_load_pct != null ? fmtNum(ups.avg_load_pct, 0) + '<span class="unit">%</span>' : '—'),
      fmtNum(ups.online, 0) + ' online · ' + fmtNum(ups.on_battery, 0) + ' battery');
    html += heroStat(
      (ups.min_battery_pct != null && ups.min_battery_pct < 50) ? 'warn' : '',
      'Lowest battery',
      (ups.min_battery_pct != null ? fmtNum(ups.min_battery_pct, 0) + '<span class="unit">%</span>' : '—'),
      ups.est_kw != null ? '~' + fmtNum(ups.est_kw, 1) + ' kW est. output' : 'Across UPS fleet'
    );
    html += '</div>';

    html += '<div class="noc-viz-row noc-viz-single">';
    html += '<div class="noc-viz-panel">' +
      '<div class="noc-viz-title">Facility power · 24h</div>' +
      '<div class="noc-chart-wrap noc-chart-hero">' + sparklineSvg(hist.kw || [], '#38bdf8') + '</div>' +
      '</div>';
    html += '<div class="noc-viz-panel noc-viz-side">' +
      '<div class="noc-viz-title">UPS load · 24h</div>' +
      '<div class="noc-chart-wrap noc-chart-side">' + sparklineSvg(uh.load_pct || [], '#a78bfa') + '</div>' +
      '</div></div>';

    html += '<div class="noc-split-row">';
    html += '<div class="noc-split-pane">' +
      '<div class="noc-section-label">Top PDU loads</div>';
    if (!topPdus.length) {
      html += '<div class="noc-empty-inline">No PDU samples</div>';
    } else {
      html += rankBars(topPdus.map(function (p) {
        return {
          name: p.name,
          value: p.kw,
          display: fmtNum(p.kw, 2) + ' kW',
          sub: esc(p.zone_name || '') + (p.amps != null ? ' · ' + fmtNum(p.amps, 1) + ' A' : ''),
          hot: maxPdu > 0 && (Number(p.kw) || 0) >= maxPdu * 0.85,
        };
      }));
      html += moreNote(topPdus.length, (power.top_pdus || []).length, 'PDUs');
    }
    html += '</div>';

    html += '<div class="noc-split-pane">' +
      '<div class="noc-section-label">UPS fleet</div>';
    if (!upsList.length) {
      html += '<div class="noc-empty-inline">No UPS telemetry</div>';
    } else {
      html += '<div class="noc-pill-grid">';
      upsList.forEach(function (u) {
        var h = (u.health || 'unknown').toLowerCase();
        html += '<div class="noc-pill ' + (h === 'crit' || h === 'warn' || h === 'ok' ? h : '') + '">' +
          '<div class="np-name">' + esc(u.name) + '</div>' +
          '<div class="np-val">' +
          (u.load_pct != null ? fmtNum(u.load_pct, 0) + '%' : '—') +
          '</div>' +
          '<div class="np-meta">' +
          (u.battery_pct != null ? fmtNum(u.battery_pct, 0) + '% batt' : '') +
          (u.est_kw != null ? ' · ' + fmtNum(u.est_kw, 1) + ' kW' : '') +
          '</div>' + badge(u.health) +
          '</div>';
      });
      html += '</div>';
      html += moreNote(upsList.length, (ups.list || []).length, 'UPS');
    }
    html += '</div></div></div>';
    return html;
  }

  function renderZones(data) {
    var zones = data.zones || [];
    if (!zones.length) {
      return '<div class="noc-panel-frame noc-frame-empty"><div class="noc-empty">No power zones defined yet. Add zones under Power → Zones.</div></div>';
    }
    var shown = zones.slice(0, CAP.zones);
    var html = '<div class="noc-panel-frame noc-frame-zones">';
    html += '<div class="noc-section-head">' +
      '<span class="noc-section-label">Power zones</span>' +
      '<span class="noc-section-count">Top ' + shown.length +
      (zones.length > shown.length ? ' of ' + zones.length : '') + '</span></div>';
    html += '<div class="noc-zone-grid noc-zone-grid-fixed">';
    shown.forEach(function (z) {
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
        ' · ' + fmtNum(z.pdu_count, 0) + ' PDU' +
        (z.ups_count != null ? ' · ' + fmtNum(z.ups_count, 0) + ' UPS' : '') +
        '</div>' +
        '<div class="zn-kw">' + fmtNum(z.kw, 1) + ' <span class="unit">kW</span>' +
        (z.ups_avg_load != null ? ' <span class="unit">· UPS ' + fmtNum(z.ups_avg_load, 0) + '%</span>' : '') +
        '</div>';
      if (bar != null) {
        html += '<div class="zn-bar"><i style="width:' + bar + '%"></i></div>' +
          '<div class="zn-util">' + fmtNum(util, 0) + '% of ' + fmtNum(z.max_kw, 1) + ' kW</div>';
      } else {
        html += '<div class="zn-util">No max kW set</div>';
      }
      html += '</div>';
    });
    html += '</div>';
    html += moreNote(shown.length, zones.length, 'zones');
    html += '</div>';
    return html;
  }

  function renderCooling(data) {
    var c = data.cooling || {};
    var env = data.env || {};
    var hot = (data.hot_sensors || []).slice(0, CAP.sensors);
    var units = (c.list || []).slice(0, CAP.cooling);
    var envCls = (env.crit > 0) ? 'crit' : (env.warn > 0) ? 'warn' : 'ok';

    var html = '<div class="noc-panel-frame noc-frame-cooling">';
    html += '<div class="noc-hero-row noc-hero-3">';
    html += heroStat('accent', 'Cooling units',
      fmtNum(c.units, 0),
      fmtNum(c.primary, 0) + ' primary · ' + fmtNum(c.standby, 0) + ' standby');
    html += heroStat('', 'Rated capacity',
      fmtNum(c.rated_kw, 1) + '<span class="unit">kW</span>',
      fmtNum(c.snmp_on, 0) + ' with SNMP');
    html += heroStat(envCls, 'Env sensors',
      fmtNum(env.crit, 0) + '<span class="unit"> crit</span>',
      fmtNum(env.warn, 0) + ' warn · ' + fmtNum(env.ok, 0) + ' ok · ' + fmtNum(env.stale, 0) + ' stale');
    html += '</div>';

    html += '<div class="noc-split-row noc-split-cool">';
    html += '<div class="noc-split-pane noc-split-wide">' +
      '<div class="noc-section-label">Warmest sensors</div>';
    if (!hot.length) {
      html += '<div class="noc-empty-inline">No temperature readings yet</div>';
    } else {
      html += '<div class="noc-temp-grid">';
      hot.forEach(function (s) {
        var st = (s.status || 'unknown').toLowerCase();
        html += '<div class="noc-temp-tile ' + (st === 'crit' || st === 'warn' || st === 'ok' ? st : '') + '">' +
          '<div class="tt-name">' + esc(s.name) + '</div>' +
          '<div class="tt-val">' + fmtNum(s.value, 1) +
          '<span class="unit">' + esc(s.unit || '°') + '</span></div>' +
          (s.humidity != null ? '<div class="tt-rh">' + fmtNum(s.humidity, 0) + '%RH</div>' : '') +
          badge(s.status) +
          '</div>';
      });
      html += '</div>';
      html += moreNote(hot.length, (data.hot_sensors || []).length, 'sensors');
    }
    html += '</div>';

    html += '<div class="noc-split-pane">' +
      '<div class="noc-section-label">Air units</div>';
    if (!units.length) {
      html += '<div class="noc-empty-inline">No cooling units listed</div>';
    } else {
      html += '<div class="noc-pill-grid noc-pill-stack">';
      units.forEach(function (u) {
        html += '<div class="noc-pill">' +
          '<div class="np-name">' + esc(u.name) + '</div>' +
          '<div class="np-meta">' + esc(u.type || '') +
          (u.role ? ' · ' + esc(u.role) : '') +
          (u.status ? ' · ' + esc(u.status) : '') +
          '</div>' +
          '<div class="np-val small">' +
          (u.rated_kw != null ? fmtNum(u.rated_kw, 1) + ' kW' : '—') +
          (u.snmp ? ' · SNMP' : '') +
          '</div></div>';
      });
      html += '</div>';
      html += moreNote(units.length, (c.list || []).length, 'units');
    }
    html += '</div></div></div>';
    return html;
  }

  var knownAlertIds = Object.create(null);
  var alertsBootstrapped = false;

  function formatAlertWhen(iso) {
    if (!iso) return '';
    try {
      var d = new Date(String(iso).indexOf('T') >= 0 ? iso : String(iso).replace(' ', 'T') + 'Z');
      if (isNaN(d.getTime())) {
        d = new Date(iso);
      }
      if (isNaN(d.getTime())) return String(iso).slice(0, 19);
      return d.toLocaleString(undefined, {
        month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit',
      });
    } catch (e) {
      return String(iso).slice(0, 19);
    }
  }

  /** Live alert chip elements (id → DOM) — patched on poll, no full re-render flash. */
  var alertChipEls = Object.create(null);
  var alertOrderIds = [];
  var alertAnimLock = false;

  function chipSignature(a) {
    if (!a) return '';
    return [
      Number(a.id) || 0,
      a.severity || '',
      a.is_cleared ? 1 : 0,
      a.alert_state || '',
      a.title || '',
      a.message || '',
      a.cleared_at || '',
      a.created_at || '',
    ].join('\u0001');
  }

  function alertMeta(a) {
    var sev = String(a.severity || 'info').toLowerCase();
    if (sev !== 'crit' && sev !== 'warn' && sev !== 'ok') sev = 'info';
    var cleared = !!(a.is_cleared || a.alert_state === 'cleared' || sev === 'ok');
    var stateLabel = cleared
      ? (a.alert_state_label || 'Cleared')
      : (sev === 'info' ? '' : (a.alert_state_label || 'Active'));
    return { sev: sev, cleared: cleared, stateLabel: stateLabel };
  }

  function fillChipEl(el, a) {
    var id = Number(a.id) || 0;
    var m = alertMeta(a);
    var sig = chipSignature(a);
    if (el.dataset.sig === sig) return false;
    el.dataset.sig = sig;
    el.dataset.id = String(id);
    el.className = 'noc-alert-chip sev-' + m.sev
      + (m.cleared ? ' is-cleared' : (m.sev === 'info' ? '' : ' is-active'));
    el.removeAttribute('aria-hidden');

    var markerHtml = m.cleared
      ? '<span class="noc-alert-check" title="Cleared" aria-label="Cleared">✓</span>'
      : '<span class="noc-alert-pulse" aria-hidden="true"></span>';
    var stateHtml = m.stateLabel
      ? ' <span class="noc-alert-state' + (m.cleared ? ' state-cleared' : ' state-active') + '">' +
        esc(m.stateLabel) + '</span>'
      : '';
    el.innerHTML =
      markerHtml +
      '<div class="noc-alert-body">' +
      '<div class="noc-alert-title">' + esc(a.title || 'Alert') + stateHtml + '</div>' +
      (a.message ? '<p class="noc-alert-msg">' + esc(a.message) + '</p>' : '') +
      '<p class="noc-alert-when">' + esc(formatAlertWhen(a.created_at)) +
      (m.cleared && a.cleared_at ? ' · cleared' : '') +
      '</p></div>';
    return true;
  }

  function createChipEl(a) {
    var el = document.createElement('div');
    fillChipEl(el, a);
    return el;
  }

  function idsEqual(a, b) {
    if (a.length !== b.length) return false;
    for (var i = 0; i < a.length; i++) {
      if (Number(a[i]) !== Number(b[i])) return false;
    }
    return true;
  }

  /**
   * Recent alerts under 3D: fixed 2×3 grid (6 slots), no scroll.
   * Polls patch content in place (no flash). New alerts FLIP-slide others over/down.
   */
  function renderRecentAlerts(data) {
    var host = $('nocAlertsGlass');
    var list = $('nocAlertsList');
    var countEl = $('nocAlertsCount');
    if (!host || !list) return;

    var items = Array.isArray(data.recent_alerts) ? data.recent_alerts : [];
    var sorted = items.slice().sort(function (a, b) {
      return (Number(b.id) || 0) - (Number(a.id) || 0);
    }).slice(0, ALERT_SLOTS);

    var newIds = sorted.map(function (a) { return Number(a.id) || 0; }).filter(function (id) {
      return id > 0;
    });
    var byId = Object.create(null);
    sorted.forEach(function (a) {
      var id = Number(a.id) || 0;
      if (id) byId[id] = a;
    });

    if (!newIds.length) {
      if (!host.hidden) {
        // Soft clear without hard flash
        Object.keys(alertChipEls).forEach(function (k) {
          var el = alertChipEls[k];
          if (el && el.parentNode) el.parentNode.removeChild(el);
          delete alertChipEls[k];
        });
        list.innerHTML = '';
        alertOrderIds = [];
        host.hidden = true;
        // Free vertical space for 3D on 1080p
        try { window.dispatchEvent(new Event('resize')); } catch (eHide) { /* ignore */ }
      }
      if (countEl) countEl.textContent = '';
      alertsBootstrapped = true;
      return;
    }

    var wasHidden = !!host.hidden;
    host.hidden = false;
    if (wasHidden) {
      try { window.dispatchEvent(new Event('resize')); } catch (eShow) { /* ignore */ }
    }
    if (countEl) countEl.textContent = String(newIds.length);

    // First paint: place quietly, no enter animation
    if (!alertsBootstrapped) {
      list.innerHTML = '';
      alertChipEls = Object.create(null);
      newIds.forEach(function (id) {
        var el = createChipEl(byId[id]);
        alertChipEls[id] = el;
        list.appendChild(el);
        knownAlertIds[id] = true;
      });
      // Pad empty slots for stable grid footprint
      for (var e = newIds.length; e < ALERT_SLOTS; e++) {
        var empty = document.createElement('div');
        empty.className = 'noc-alert-chip is-empty';
        empty.setAttribute('aria-hidden', 'true');
        empty.dataset.empty = '1';
        list.appendChild(empty);
      }
      alertOrderIds = newIds.slice();
      alertsBootstrapped = true;
      return;
    }

    // Same order: patch signatures only (cleared/status/time) — no DOM thrash
    if (idsEqual(alertOrderIds, newIds)) {
      newIds.forEach(function (id) {
        var el = alertChipEls[id];
        if (el) fillChipEl(el, byId[id]);
      });
      return;
    }

    if (alertAnimLock) {
      // Coalesce: still patch what we can; full layout next free frame
      newIds.forEach(function (id) {
        if (alertChipEls[id]) fillChipEl(alertChipEls[id], byId[id]);
      });
    }

    // FLIP: record first positions of existing chips
    var firstRects = Object.create(null);
    alertOrderIds.forEach(function (id) {
      var el = alertChipEls[id];
      if (el && el.getBoundingClientRect) {
        firstRects[id] = el.getBoundingClientRect();
      }
    });

    var newIdSet = Object.create(null);
    newIds.forEach(function (id) { newIdSet[id] = true; });

    // Chips falling off the grid
    var leaving = [];
    alertOrderIds.forEach(function (id) {
      if (!newIdSet[id] && alertChipEls[id]) {
        leaving.push({ id: id, el: alertChipEls[id], rect: firstRects[id] });
      }
    });

    // Ensure elements exist / updated
    var entering = [];
    newIds.forEach(function (id) {
      if (!alertChipEls[id]) {
        alertChipEls[id] = createChipEl(byId[id]);
        entering.push(id);
        knownAlertIds[id] = true;
      } else {
        fillChipEl(alertChipEls[id], byId[id]);
      }
    });

    // Rebuild list order (chips only + empty pads)
    list.innerHTML = '';
    newIds.forEach(function (id) {
      list.appendChild(alertChipEls[id]);
    });
    for (var p = newIds.length; p < ALERT_SLOTS; p++) {
      var pad = document.createElement('div');
      pad.className = 'noc-alert-chip is-empty';
      pad.setAttribute('aria-hidden', 'true');
      pad.dataset.empty = '1';
      list.appendChild(pad);
    }

    // Invert: put movers back under old pixels
    newIds.forEach(function (id) {
      var el = alertChipEls[id];
      var first = firstRects[id];
      if (!el) return;
      if (entering.indexOf(id) >= 0) {
        el.classList.add('noc-alert-enter');
        el.style.transition = 'none';
        el.style.opacity = '0';
        el.style.transform = 'translate(-28%, -18%) scale(0.92)';
        return;
      }
      if (!first) return;
      var last = el.getBoundingClientRect();
      var dx = first.left - last.left;
      var dy = first.top - last.top;
      if (Math.abs(dx) < 0.5 && Math.abs(dy) < 0.5) return;
      el.classList.add('noc-alert-moving');
      el.style.transition = 'none';
      el.style.transform = 'translate(' + dx + 'px,' + dy + 'px)';
    });

    // Play leave animations (absolute clone so grid layout stays clean)
    leaving.forEach(function (item) {
      var el = item.el;
      var rect = item.rect;
      delete alertChipEls[item.id];
      if (!rect || !list.parentNode) return;
      var ghost = el.cloneNode(true);
      ghost.classList.add('noc-alert-leave');
      ghost.style.position = 'fixed';
      ghost.style.left = rect.left + 'px';
      ghost.style.top = rect.top + 'px';
      ghost.style.width = rect.width + 'px';
      ghost.style.height = rect.height + 'px';
      ghost.style.margin = '0';
      ghost.style.zIndex = '20';
      ghost.style.pointerEvents = 'none';
      document.body.appendChild(ghost);
      requestAnimationFrame(function () {
        ghost.classList.add('noc-alert-leave-active');
      });
      setTimeout(function () {
        if (ghost.parentNode) ghost.parentNode.removeChild(ghost);
      }, 420);
    });

    alertOrderIds = newIds.slice();
    alertAnimLock = true;

    // Play: slide to new grid cells + enter new card
    requestAnimationFrame(function () {
      requestAnimationFrame(function () {
        newIds.forEach(function (id) {
          var el = alertChipEls[id];
          if (!el) return;
          el.style.transition = '';
          el.style.transform = '';
          el.style.opacity = '';
          if (entering.indexOf(id) >= 0) {
            el.classList.add('noc-alert-enter-active');
            el.classList.remove('noc-alert-enter');
          }
          el.classList.remove('noc-alert-moving');
        });
        setTimeout(function () {
          newIds.forEach(function (id) {
            var el = alertChipEls[id];
            if (!el) return;
            el.classList.remove('noc-alert-enter-active');
          });
          alertAnimLock = false;
        }, 480);
      });
    });
  }

  function applyNocDisplaySettings(nocCfg) {
    if (!nocCfg || typeof nocCfg !== 'object') return;
    var prevRaceways = nocShowRaceways;
    var prevPanel = panelRotateMs;
    if (typeof nocCfg.show_labels === 'boolean') nocShowLabels = nocCfg.show_labels;
    if (typeof nocCfg.show_raceways === 'boolean') nocShowRaceways = nocCfg.show_raceways;
    if (typeof nocCfg.auto_rotate === 'boolean') nocAutoRotate = nocCfg.auto_rotate;
    if (nocCfg.panel_rotate_ms != null) {
      var ms = Number(nocCfg.panel_rotate_ms);
      if (isFinite(ms) && ms >= 5000) panelRotateMs = ms;
    } else if (nocCfg.panel_rotate_sec != null) {
      var sec = Number(nocCfg.panel_rotate_sec);
      if (isFinite(sec) && sec >= 5) panelRotateMs = sec * 1000;
    }
    // Keep stale watchdog at 2× data poll interval (not panel slide timer)
    staleAfterMs = Math.max(pollMs * 2, 60000);
    if (nocCfg.cleared_alert_ttl_sec != null) {
      nocClearedTtlSec = Number(nocCfg.cleared_alert_ttl_sec);
    }
    if (nocCfg.cam_tilt_pct != null) {
      nocCamTiltPct = Math.max(0, Math.min(100, Number(nocCfg.cam_tilt_pct)));
    }
    if (nocCfg.cam_zoom_pct != null) {
      nocCamZoomPct = Math.max(0, Math.min(100, Number(nocCfg.cam_zoom_pct)));
    }
    // Raceways on after off needs geometry reload (mount used empty list)
    if (prevRaceways !== nocShowRaceways) {
      sceneLoadedAt = 0;
    }
    if (view3d) {
      if (typeof view3d.setObjectLabels === 'function') {
        view3d.setObjectLabels(nocShowLabels);
      }
      if (typeof view3d.setRacewaysVisible === 'function') {
        view3d.setRacewaysVisible(nocShowRaceways);
      }
      if (typeof view3d.setAutoRotate === 'function') {
        view3d.setAutoRotate(nocAutoRotate);
      }
      if (typeof view3d.setCameraView === 'function') {
        var cam = nocCameraOpts();
        view3d.setCameraView({ phi: cam.phi, radius: cam.radius });
      }
    }
    // Restart panel timer if interval changed
    if (panelTimer && prevPanel !== panelRotateMs) {
      clearInterval(panelTimer);
      panelTimer = setInterval(nextPanel, panelRotateMs);
      restartRotateProgress();
    }
    var hint = $('nocPanelHint');
    if (hint) {
      hint.textContent = 'Panel every ' + Math.round(panelRotateMs / 1000) + 's'
        + (nocAutoRotate ? ' · 3D orbit on' : ' · 3D orbit off');
    }
  }

  function renderAll(data) {
    lastData = data;
    if (data.noc) applyNocDisplaySettings(data.noc);
    var o = $('panel-overview');
    var p = $('panel-power');
    var z = $('panel-zones');
    var c = $('panel-cooling');
    if (o) o.innerHTML = renderOverview(data);
    if (p) p.innerHTML = renderPower(data);
    if (z) z.innerHTML = renderZones(data);
    if (c) c.innerHTML = renderCooling(data);
    renderRecentAlerts(data);

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

  /**
   * Apply cabinet alert colors to the live 3D view (warn/crit yellow/red).
   * Safe to call before mount finishes — no-ops until view3d exists.
   */
  function applyNocCabinetHealth(health) {
    if (!health || !view3d || typeof view3d.setCabinetHealth !== 'function') return;
    try {
      view3d.setCabinetHealth(health);
    } catch (eH) { /* ignore */ }
  }

  /**
   * @param {object} scene
   * @param {Array|object|null} healthSnapshot top-level cabinet_health from same poll
   */
  function mountScene(scene, healthSnapshot) {
    var el = $('noc3d');
    if (!el || !scene) return;
    var cabinets = scene.cabinets || [];
    var pdus = scene.pdus || [];
    var cooling = scene.cooling || scene.cooling_units || [];
    var ups = scene.ups || scene.ups_units || [];
    var rooms = scene.rooms || [];
    var envSensors = scene.env_sensors || scene.envSensors || [];
    var airflowAnchors = scene.airflow_anchors || scene.airflowAnchors || [];
    var cablePaths = scene.cable_paths || scene.cablePaths || [];
    var logoUrl = scene.logo_url || cfg.logoUrl || '';
    // Prefer explicit snapshot; fall back to health embedded on scene
    var health = healthSnapshot || scene.cabinet_health || null;

    function start() {
      if (!window.ColdAisle3D) {
        el.innerHTML = '<div style="padding:1rem;color:#94a3b8">3D module unavailable</div>';
        return;
      }
      if (view3d && typeof view3d.dispose === 'function') {
        try { view3d.dispose(); } catch (e) { /* ignore */ }
        view3d = null;
      }
      try {
        var cam0 = nocCameraOpts();
        view3d = ColdAisle3D.mount(el, {
          cabinets: cabinets,
          pdus: pdus,
          cooling: cooling,
          ups: ups,
          rooms: rooms,
          envSensors: envSensors,
          airflowAnchors: airflowAnchors,
          airflowOverlay: airflowAnchors.length > 0,
          airflowColor: 'blue',
          cablePaths: nocShowRaceways ? cablePaths : [],
          showRaceways: nocShowRaceways,
          showObjectLabels: nocShowLabels,
          logoUrl: logoUrl,
          heatOverlay: envSensors.length > 0,
          interactive: false,
          walkEnabled: false,
          autoRotate: nocAutoRotate,
          autoRotateSpeed: 0.0025,
          textureFaces: 'none',
          cameraPhi: cam0.phi,
          cameraRadius: cam0.radius,
        });
      } catch (eMount) {
        view3d = null;
        el.innerHTML = '<div style="padding:1rem;color:#94a3b8">3D mount failed</div>';
        return;
      }
      sceneLoadedAt = Date.now();
      // Always re-apply after mount (async script load used to skip the poll-time apply)
      applyNocCabinetHealth(health);
      if (view3d) {
        if (typeof view3d.setObjectLabels === 'function') view3d.setObjectLabels(nocShowLabels);
        if (typeof view3d.setRacewaysVisible === 'function') view3d.setRacewaysVisible(nocShowRaceways);
        if (typeof view3d.setAutoRotate === 'function') view3d.setAutoRotate(nocAutoRotate);
      }
      // Reflow after flex layout (3D stage + alert grid)
      try {
        window.dispatchEvent(new Event('resize'));
      } catch (eR) { /* ignore */ }
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
    setStatus(null, 'Live'); // amber “updating” pulse; label stays Live
    fetch(apiUrl(needScene), {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
      cache: 'no-store',
    })
      .then(function (r) {
        return r.text().then(function (text) {
          var j = null;
          var parseErr = null;
          try {
            j = text ? JSON.parse(text) : null;
          } catch (e) {
            parseErr = e;
          }
          return { ok: r.ok, status: r.status, j: j, text: text, parseErr: parseErr };
        });
      })
      .then(function (res) {
        if (reloadingForUpdate) return;
        if (res.parseErr || !res.j) {
          var snippet = (res.text || '').replace(/\s+/g, ' ').trim().slice(0, 120);
          showError(
            'NOC API returned invalid JSON (HTTP ' + res.status + ')' +
            (snippet ? ': ' + snippet : '')
          );
          setStatus(false, 'Bad response');
          return;
        }
        if (!res.ok || !res.j.ok) {
          var err = (res.j && res.j.error) || ('HTTP ' + res.status);
          showError(err);
          setStatus(false, 'Update failed');
          return;
        }
        if (checkAppVersion(res.j)) return;
        showError('');
        renderAll(res.j);
        if (needScene && res.j.scene) {
          // Pass cabinet_health so colors apply after mount (incl. async THREE load)
          mountScene(res.j.scene, res.j.cabinet_health || null);
        } else {
          // Scene geometry kept; refresh alert tints every poll
          applyNocCabinetHealth(res.j.cabinet_health);
        }
        setStatus(true, 'Live');
      })
      .catch(function (err) {
        if (reloadingForUpdate) return;
        showError('Network error loading NOC data' + (err && err.message ? ' (' + err.message + ')' : ''));
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
  setStatus(null, 'Connecting…');
  poll(true);
  setInterval(function () { poll(false); }, pollMs);
  // Watchdog: if polls stop (frozen TV / hung tab), turn the live dot red
  setInterval(checkPollWatchdog, 5000);
  panelTimer = setInterval(nextPanel, panelRotateMs);
})();
