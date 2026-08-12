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
  var nocShowLabels = cfg.showLabels !== false;
  var nocShowRaceways = cfg.showRaceways !== false;
  var nocAutoRotate = cfg.autoRotate !== false;
  var nocClearedTtlSec = Number(cfg.clearedAlertTtlSec);
  if (!isFinite(nocClearedTtlSec)) nocClearedTtlSec = 120;
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
    var uh = data.ups_history || {};
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
    var snmpStale = power.snmp_stale != null ? power.snmp_stale : 0;
    var snmpMon = power.snmp_monitored != null ? power.snmp_monitored : 0;
    html += card(snmpStale > 0 ? 'warn' : 'accent', 'Site power', fmtNum(power.kw != null ? power.kw : m.power_kw, 1) + '<span class="unit">kW</span>',
      fmtNum(power.pdu_polled != null ? power.pdu_polled : m.pdus, 0) + ' polled · ' +
      (snmpMon > 0 ? (snmpStale + ' SNMP stale · ') : '') +
      fmtNum(m.pdus, 0) + ' PDU(s)');
    var upsO = data.ups || {};
    var upsCls = (upsO.on_battery > 0 || upsO.health_crit > 0) ? 'crit'
      : (upsO.health_warn > 0 ? 'warn' : (upsO.units > 0 ? 'ok' : ''));
    html += card(upsCls, 'UPS', fmtNum(upsO.units != null ? upsO.units : m.ups_units, 0),
      (upsO.online != null ? fmtNum(upsO.online, 0) + ' online' : 'Inventory') +
      (upsO.on_battery > 0 ? ' · ' + fmtNum(upsO.on_battery, 0) + ' battery' : '') +
      (upsO.avg_load_pct != null ? ' · load ' + fmtNum(upsO.avg_load_pct, 0) + '%' : '') +
      (upsO.est_kw != null ? ' · ~' + fmtNum(upsO.est_kw, 1) + ' kW' : ''));
    html += card('', 'Cooling units', fmtNum(m.cooling_units, 0), 'Air & pumps');
    html += card(envCls, 'Env status',
      fmtNum(env.ok, 0) + ' <span class="unit">ok</span>',
      fmtNum(env.warn, 0) + ' warn · ' + fmtNum(env.crit, 0) + ' crit');
    html += card(env.crit > 0 ? 'crit' : '', 'Env critical', fmtNum(env.crit, 0), 'Threshold breaches');
    html += card(m.open_disposals > 0 ? 'warn' : '', 'Open disposals', fmtNum(m.open_disposals, 0), 'Lifecycle');
    html += card('', 'Sites / DCs', fmtNum(m.sites, 0) + ' / ' + fmtNum(m.datacenters, 0), 'Topology');
    html += card('', 'Env sensors', fmtNum(m.env_sensors, 0), (env.stale || 0) + ' stale (&gt;1h)');

    // Dual mini trends: PDU facility kW + UPS load %
    html += '<div class="noc-card wide">' +
      '<div class="label">Power poll · 24h</div>' +
      '<div class="noc-dual-charts">' +
      '<div class="noc-dual-col">' +
      '<div class="noc-dual-cap">PDU facility kW</div>' +
      '<div class="noc-chart-wrap noc-chart-mini">' + sparklineSvg(hist.kw || [], '#38bdf8') + '</div>' +
      '<div class="noc-chart-meta">' +
      '<span>Now <strong>' + fmtNum(power.kw != null ? power.kw : m.power_kw, 1) + ' kW</strong></span>' +
      (power.kw_max_24h != null
        ? '<span>Peak <strong>' + fmtNum(power.kw_max_24h, 1) + ' kW</strong></span>'
        : '') +
      '</div></div>' +
      '<div class="noc-dual-col">' +
      '<div class="noc-dual-cap">UPS load %</div>' +
      '<div class="noc-chart-wrap noc-chart-mini">' + sparklineSvg(uh.load_pct || [], '#a78bfa') + '</div>' +
      '<div class="noc-chart-meta">' +
      '<span>Avg <strong>' + (upsO.avg_load_pct != null ? fmtNum(upsO.avg_load_pct, 0) + '%' : '—') + '</strong></span>' +
      (upsO.est_kw != null
        ? '<span>Est <strong>' + fmtNum(upsO.est_kw, 1) + ' kW</strong></span>'
        : '') +
      '</div></div></div></div>';

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
          (z.ups_avg_load != null ? '<span class="ut">UPS ' + fmtNum(z.ups_avg_load, 0) + '%</span>' : '') +
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
    var uh = data.ups_history || {};
    var ups = data.ups || {};
    var kw = power.kw != null ? power.kw : m.power_kw;
    var upsCls = (ups.on_battery > 0 || ups.health_crit > 0) ? 'crit'
      : (ups.health_warn > 0 ? 'warn' : (ups.units > 0 ? 'ok' : ''));
    var html = '<div class="noc-metrics">';
    html += card('accent', 'PDU load', fmtNum(kw, 1) + '<span class="unit">kW</span>',
      fmtNum(power.pdu_polled != null ? power.pdu_polled : 0, 0) + ' polled · ' +
      (power.snmp_stale != null && power.snmp_stale > 0
        ? (fmtNum(power.snmp_stale, 0) + ' SNMP stale · ')
        : '') +
      fmtNum(m.pdus, 0) + ' PDU(s)' +
      (power.pdu_amps != null ? ' · ' + fmtNum(power.pdu_amps, 0) + ' A sum' : ''));
    html += card('', '24h average', fmtNum(power.kw_avg_24h, 1) + '<span class="unit">kW</span>', 'PDU site total');
    html += card('', '24h peak', fmtNum(power.kw_max_24h, 1) + '<span class="unit">kW</span>', 'Max bucket');
    html += card('', '24h floor', fmtNum(power.kw_min_24h, 1) + '<span class="unit">kW</span>', 'Min bucket');
    html += card(upsCls, 'UPS units', fmtNum(ups.units != null ? ups.units : m.ups_units, 0),
      fmtNum(ups.online, 0) + ' online · ' + fmtNum(ups.on_battery, 0) + ' on battery' +
      (ups.bypass > 0 ? ' · ' + fmtNum(ups.bypass, 0) + ' bypass' : ''));
    html += card(upsCls, 'UPS load',
      (ups.avg_load_pct != null ? fmtNum(ups.avg_load_pct, 0) + '<span class="unit">%</span>' : '—'),
      (ups.max_load_pct != null ? 'max ' + fmtNum(ups.max_load_pct, 0) + '%' : 'Average of polled units') +
      (ups.polled != null ? ' · ' + fmtNum(ups.polled, 0) + ' polled' : ''));
    html += card(
      (ups.min_battery_pct != null && ups.min_battery_pct < 50) ? 'warn' : '',
      'UPS battery',
      (ups.min_battery_pct != null ? fmtNum(ups.min_battery_pct, 0) + '<span class="unit">%</span>' : '—'),
      ups.avg_battery_pct != null ? 'min · avg ' + fmtNum(ups.avg_battery_pct, 0) + '%' : 'Lowest unit'
    );
    html += card('', 'UPS est. output',
      (ups.est_kw != null ? fmtNum(ups.est_kw, 1) + '<span class="unit">kW</span>' : '—'),
      fmtNum(ups.rated_kva, 0) + ' kVA rated · ' + fmtNum(ups.snmp_on, 0) + ' SNMP');

    html += '<div class="noc-card wide">' +
      '<div class="label">PDU facility power · 24h</div>' +
      '<div class="noc-chart-wrap">' + sparklineSvg(hist.kw || [], '#38bdf8') + '</div>' +
      '<div class="noc-chart-meta">' +
      '<span>Now <strong>' + fmtNum(kw, 1) + ' kW</strong></span>' +
      (power.kw_max_24h != null ? '<span>Peak <strong>' + fmtNum(power.kw_max_24h, 1) + ' kW</strong></span>' : '') +
      (power.last_poll_at
        ? '<span>Last poll <strong>' + esc(String(power.last_poll_at).replace('T', ' ').slice(0, 19)) + '</strong></span>'
        : '') +
      '</div></div>';

    html += '<div class="noc-card wide">' +
      '<div class="label">UPS load · 24h</div>' +
      '<div class="noc-dual-charts">' +
      '<div class="noc-dual-col">' +
      '<div class="noc-dual-cap">Load %</div>' +
      '<div class="noc-chart-wrap noc-chart-mini">' + sparklineSvg(uh.load_pct || [], '#a78bfa') + '</div>' +
      '</div>' +
      '<div class="noc-dual-col">' +
      '<div class="noc-dual-cap">Est. output kW</div>' +
      '<div class="noc-chart-wrap noc-chart-mini">' + sparklineSvg(uh.kw || [], '#c4b5fd') + '</div>' +
      '</div></div>' +
      '<div class="noc-chart-meta">' +
      '<span>Avg load <strong>' + (ups.avg_load_pct != null ? fmtNum(ups.avg_load_pct, 0) + '%' : '—') + '</strong></span>' +
      (ups.est_kw != null ? '<span>Est now <strong>' + fmtNum(ups.est_kw, 1) + ' kW</strong></span>' : '') +
      (ups.last_poll_at
        ? '<span>UPS poll <strong>' + esc(String(ups.last_poll_at).replace('T', ' ').slice(0, 19)) + '</strong></span>'
        : '') +
      '</div></div>';

    var topPdus = power.top_pdus || [];
    if (topPdus.length) {
      html += '<div class="noc-card wide" style="margin-top:.5rem">' +
        '<div class="label">Top PDU loads</div><div class="noc-list">';
      topPdus.forEach(function (p) {
        html += '<div class="noc-list-row">' +
          '<div><strong>' + esc(p.name) + '</strong>' +
          '<div class="muted">' + esc(p.zone_name || '') +
          (p.amps != null ? ' · ' + fmtNum(p.amps, 1) + ' A' : '') +
          '</div></div>' +
          '<div style="text-align:right"><strong>' + fmtNum(p.kw, 2) + '</strong> kW</div></div>';
      });
      html += '</div></div>';
    }

    var list = ups.list || [];
    if (list.length) {
      html += '<div class="noc-card wide" style="margin-top:.5rem">' +
        '<div class="label">UPS units · live SNMP</div><div class="noc-list">';
      list.forEach(function (u) {
        var bits = [];
        if (u.load_pct != null) bits.push('<strong>' + fmtNum(u.load_pct, 0) + '%</strong> load');
        if (u.battery_pct != null) bits.push(fmtNum(u.battery_pct, 0) + '% batt');
        if (u.est_kw != null) bits.push('~' + fmtNum(u.est_kw, 1) + ' kW');
        if (u.output_voltage != null) bits.push(fmtNum(u.output_voltage, 0) + ' V out');
        if (u.input_voltage != null) bits.push(fmtNum(u.input_voltage, 0) + ' V in');
        if (u.output_current != null) bits.push(fmtNum(u.output_current, 1) + ' A');
        if (u.output_freq != null) bits.push(fmtNum(u.output_freq, 1) + ' Hz');
        if (u.runtime_min != null) bits.push(fmtNum(u.runtime_min, 0) + ' min');
        html += '<div class="noc-list-row">' +
          '<div><strong>' + esc(u.name) + '</strong>' +
          '<div class="muted">' + esc(u.output_status || u.status || '') +
          (u.model ? ' · ' + esc(u.model) : '') +
          (u.zone_name ? ' · ' + esc(u.zone_name) : '') +
          '</div></div>' +
          '<div style="text-align:right;max-width:55%">' +
          (bits.length ? bits.join(' · ') : '—') +
          ' ' + badge(u.health) +
          '</div></div>';
      });
      html += '</div></div>';
    }
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
        (z.ups_count != null ? ' · ' + fmtNum(z.ups_count, 0) + ' UPS' : '') +
        (z.voltage ? ' · ' + fmtNum(z.voltage, 0) + ' V' : '') +
        '</div>' +
        '<div class="zn-kw">' + fmtNum(z.kw, 1) + ' <span class="unit">kW</span>' +
        (z.ups_avg_load != null ? ' <span class="unit">· UPS ' + fmtNum(z.ups_avg_load, 0) + '%</span>' : '') +
        '</div>';
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

  function alertChipHtml(a, isNew) {
    if (!a) {
      return '<div class="noc-alert-chip is-empty" aria-hidden="true"></div>';
    }
    var id = Number(a.id) || 0;
    var sev = (a.severity || 'info').toLowerCase();
    if (sev !== 'crit' && sev !== 'warn' && sev !== 'ok') sev = 'info';
    var cleared = !!(a.is_cleared || a.alert_state === 'cleared' || sev === 'ok');
    var stateLabel = cleared
      ? (a.alert_state_label || 'Cleared')
      : (sev === 'info' ? '' : (a.alert_state_label || 'Active'));
    var chipClass = 'noc-alert-chip sev-' + sev
      + (cleared ? ' is-cleared' : (sev === 'info' ? '' : ' is-active'))
      + (isNew ? ' is-new' : '');
    var marker = cleared
      ? '<span class="noc-alert-check" title="Cleared" aria-label="Cleared">✓</span>'
      : '<span class="noc-alert-pulse" aria-hidden="true"></span>';
    return '<div class="' + chipClass + '" data-id="' + id + '">' +
      marker +
      '<div class="noc-alert-body">' +
      '<div class="noc-alert-title">' + esc(a.title || 'Alert') +
      (stateLabel
        ? ' <span class="noc-alert-state' + (cleared ? ' state-cleared' : ' state-active') + '">' +
          esc(stateLabel) + '</span>'
        : '') +
      '</div>' +
      (a.message ? '<p class="noc-alert-msg">' + esc(a.message) + '</p>' : '') +
      '<p class="noc-alert-when">' + esc(formatAlertWhen(a.created_at)) +
      (cleared && a.cleared_at ? ' · cleared' : '') +
      '</p>' +
      '</div></div>';
  }

  /**
   * Recent alerts under 3D: fixed 2×3 grid (6 slots), no scroll.
   * Newest top-left, then right, then next row; older fall off.
   */
  function renderRecentAlerts(data) {
    var host = $('nocAlertsGlass');
    var list = $('nocAlertsList');
    var countEl = $('nocAlertsCount');
    if (!host || !list) return;
    var items = Array.isArray(data.recent_alerts) ? data.recent_alerts : [];
    // Newest first (id DESC)
    var sorted = items.slice().sort(function (a, b) {
      return (Number(b.id) || 0) - (Number(a.id) || 0);
    });
    sorted = sorted.slice(0, ALERT_SLOTS);

    if (!sorted.length) {
      host.hidden = true;
      list.innerHTML = '';
      if (countEl) countEl.textContent = '';
      return;
    }

    host.hidden = false;
    if (countEl) countEl.textContent = String(sorted.length);

    var html = '';
    for (var i = 0; i < ALERT_SLOTS; i++) {
      var a = sorted[i] || null;
      var id = a ? (Number(a.id) || 0) : 0;
      var isNew = !!(a && alertsBootstrapped && id > 0 && !knownAlertIds[id]);
      html += alertChipHtml(a, isNew);
      if (id > 0) knownAlertIds[id] = true;
    }
    list.innerHTML = html;
    alertsBootstrapped = true;
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
    if (nocCfg.cleared_alert_ttl_sec != null) {
      nocClearedTtlSec = Number(nocCfg.cleared_alert_ttl_sec);
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
        view3d = ColdAisle3D.mount(el, {
          cabinets: cabinets,
          pdus: pdus,
          cooling: cooling,
          ups: ups,
          rooms: rooms,
          envSensors: envSensors,
          cablePaths: nocShowRaceways ? cablePaths : [],
          showRaceways: nocShowRaceways,
          showObjectLabels: nocShowLabels,
          logoUrl: logoUrl,
          heatOverlay: envSensors.length > 0,
          interactive: false,
          autoRotate: nocAutoRotate,
          autoRotateSpeed: 0.0025,
          textureFaces: 'none',
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
    setStatus(null, 'Refreshing…');
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
        setStatus(true, 'Live · every ' + Math.round(pollMs / 1000) + 's');
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
  poll(true);
  setInterval(function () { poll(false); }, pollMs);
  panelTimer = setInterval(nextPanel, panelRotateMs);
})();
