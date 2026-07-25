/**
 * ColdAisle — SVG power history charts (no external deps).
 * Full-bleed gradient area + line for 24h load / voltage.
 */
(function () {
  'use strict';

  function pad(n) { return n < 10 ? '0' + n : String(n); }

  function fmtTime(iso) {
    var d = new Date(iso);
    if (isNaN(d.getTime())) return '';
    return pad(d.getHours()) + ':' + pad(d.getMinutes());
  }

  function fmtVal(v, unit) {
    if (v == null || isNaN(v)) return '—';
    var n = Number(v);
    if (unit === 'kW') return (Math.abs(n) >= 10 ? n.toFixed(1) : n.toFixed(2)) + ' kW';
    if (unit === 'V') return n.toFixed(0) + ' V';
    if (unit === 'A') return n.toFixed(1) + ' A';
    return String(n);
  }

  /**
   * @param {HTMLElement} el
   * @param {{t:string[], values:(number|null)[], label:string, unit:string, color?:string, fill?:string}} opts
   */
  function renderLineChart(el, opts) {
    if (!el) return;
    var t = opts.t || [];
    var values = opts.values || [];
    var color = opts.color || '#38bdf8';
    var fill = opts.fill || 'rgba(56,189,248,0.18)';
    var unit = opts.unit || '';
    var label = opts.label || '';
    var h = opts.height || (el.classList.contains('power-chart-sm') ? 88 : 180);
    var w = el.clientWidth || el.offsetWidth || 640;
    if (w < 120) w = 640;

    var padL = 44, padR = 12, padT = 16, padB = 28;
    var plotW = Math.max(40, w - padL - padR);
    var plotH = Math.max(40, h - padT - padB);

    var nums = values.filter(function (v) { return v != null && !isNaN(v); }).map(Number);
    var minV = nums.length ? Math.min.apply(null, nums) : 0;
    var maxV = nums.length ? Math.max.apply(null, nums) : 1;
    if (minV === maxV) {
      minV = minV > 0 ? minV * 0.9 : minV - 1;
      maxV = maxV > 0 ? maxV * 1.1 : maxV + 1;
    }
    // Voltage: pad to nice band
    if (unit === 'V') {
      minV = Math.max(0, Math.floor(minV / 10) * 10 - 10);
      maxV = Math.ceil(maxV / 10) * 10 + 10;
    } else {
      minV = Math.max(0, minV * 0.92);
      maxV = maxV * 1.08 || 1;
    }
    var span = maxV - minV || 1;

    function xAt(i) {
      if (t.length <= 1) return padL + plotW / 2;
      return padL + (i / (t.length - 1)) * plotW;
    }
    function yAt(v) {
      return padT + plotH - ((Number(v) - minV) / span) * plotH;
    }

    var points = [];
    var pathD = '';
    var areaD = '';
    var first = true;
    var firstX = null, lastX = null;
    for (var i = 0; i < values.length; i++) {
      var v = values[i];
      if (v == null || isNaN(v)) continue;
      var x = xAt(i), y = yAt(v);
      points.push({ x: x, y: y, v: Number(v), t: t[i] });
      if (first) {
        pathD = 'M ' + x.toFixed(1) + ' ' + y.toFixed(1);
        firstX = x;
        first = false;
      } else {
        pathD += ' L ' + x.toFixed(1) + ' ' + y.toFixed(1);
      }
      lastX = x;
    }
    if (points.length && firstX != null && lastX != null) {
      areaD = pathD
        + ' L ' + lastX.toFixed(1) + ' ' + (padT + plotH).toFixed(1)
        + ' L ' + firstX.toFixed(1) + ' ' + (padT + plotH).toFixed(1)
        + ' Z';
    }

    var gid = 'pg-' + Math.random().toString(36).slice(2, 9);
    var yTicks = 4;
    var grid = '';
    var yLabels = '';
    for (var g = 0; g <= yTicks; g++) {
      var gv = minV + (span * g) / yTicks;
      var gy = yAt(gv);
      grid += '<line class="pc-grid" x1="' + padL + '" y1="' + gy.toFixed(1)
        + '" x2="' + (padL + plotW) + '" y2="' + gy.toFixed(1) + '"/>';
      var lab = unit === 'kW' ? gv.toFixed(gv >= 10 ? 0 : 1)
        : unit === 'V' ? String(Math.round(gv))
        : gv.toFixed(1);
      yLabels += '<text class="pc-axis" x="' + (padL - 6) + '" y="' + (gy + 3).toFixed(1)
        + '" text-anchor="end">' + lab + '</text>';
    }

    // X labels: ~6 ticks
    var xLabels = '';
    if (t.length) {
      var steps = Math.min(6, t.length);
      for (var xi = 0; xi < steps; xi++) {
        var idx = steps === 1 ? 0 : Math.round((xi / (steps - 1)) * (t.length - 1));
        var xx = xAt(idx);
        xLabels += '<text class="pc-axis" x="' + xx.toFixed(1) + '" y="' + (h - 8)
          + '" text-anchor="middle">' + fmtTime(t[idx]) + '</text>';
      }
    }

    var last = points.length ? points[points.length - 1] : null;
    var head = '<div class="power-chart-head">'
      + '<span class="power-chart-label">' + escapeHtml(label) + '</span>'
      + '<span class="power-chart-now">' + (last ? fmtVal(last.v, unit) : 'No data yet') + '</span>'
      + '</div>';

    var empty = !points.length
      ? '<div class="power-chart-empty">No history yet — samples appear after SNMP polls.</div>'
      : '';

    var svg = points.length ? (
      '<svg class="power-chart-svg" viewBox="0 0 ' + w + ' ' + h + '" width="100%" height="' + h + '" preserveAspectRatio="none">'
      + '<defs><linearGradient id="' + gid + '" x1="0" y1="0" x2="0" y2="1">'
      + '<stop offset="0%" stop-color="' + color + '" stop-opacity="0.35"/>'
      + '<stop offset="100%" stop-color="' + color + '" stop-opacity="0"/>'
      + '</linearGradient></defs>'
      + grid + yLabels + xLabels
      + (areaD ? '<path class="pc-area" d="' + areaD + '" fill="url(#' + gid + ')"/>' : '')
      + (pathD ? '<path class="pc-line" d="' + pathD + '" fill="none" stroke="' + color
        + '" stroke-width="2.25" stroke-linejoin="round" stroke-linecap="round"/>' : '')
      + (last ? '<circle class="pc-dot" cx="' + last.x.toFixed(1) + '" cy="' + last.y.toFixed(1)
        + '" r="3.5" fill="' + color + '"/>' : '')
      + '</svg>'
    ) : '';

    el.innerHTML = head + empty + svg;
    el.dataset.ready = '1';
  }

  function escapeHtml(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  /**
   * Load history API and paint one or more charts under a root.
   * data-scope, data-id, data-hours on root or each chart.
   */
  async function mount(root) {
    if (!root) return;
    var scope = root.getAttribute('data-scope') || 'site';
    var id = root.getAttribute('data-id') || '';
    var hours = parseInt(root.getAttribute('data-hours') || '24', 10) || 24;
    var base = (window.ColdAisle && window.ColdAisle.baseUrl) || '';
    var url = base.replace(/\/$/, '') + '/api/power_history.php?scope=' + encodeURIComponent(scope)
      + '&hours=' + hours + (id ? '&id=' + encodeURIComponent(id) : '');

    var charts = root.querySelectorAll('[data-metric]');
    charts.forEach(function (c) {
      c.classList.add('power-chart-loading');
      c.innerHTML = '<div class="power-chart-empty">Loading…</div>';
    });

    try {
      var res = await fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
      var data = await res.json();
      if (!data || !data.ok) throw new Error((data && data.error) || 'History unavailable');
      var s = data.series || {};
      charts.forEach(function (c) {
        c.classList.remove('power-chart-loading');
        var metric = c.getAttribute('data-metric') || 'kw';
        var values = s[metric] || s.kw || [];
        var unit = c.getAttribute('data-unit') || (metric === 'volts' ? 'V' : metric === 'amps' ? 'A' : 'kW');
        var color = c.getAttribute('data-color') || (metric === 'volts' ? '#a78bfa' : '#38bdf8');
        renderLineChart(c, {
          t: s.t || [],
          values: values,
          label: c.getAttribute('data-label') || metric,
          unit: unit,
          color: color,
          height: parseInt(c.getAttribute('data-height') || '0', 10) || undefined,
        });
      });
    } catch (e) {
      charts.forEach(function (c) {
        c.classList.remove('power-chart-loading');
        c.innerHTML = '<div class="power-chart-empty">' + escapeHtml(e.message || 'Failed to load') + '</div>';
      });
    }
  }

  function boot() {
    document.querySelectorAll('[data-power-history]').forEach(function (root) {
      mount(root);
    });
    var ro;
    if (typeof ResizeObserver !== 'undefined') {
      ro = new ResizeObserver(function () {
        document.querySelectorAll('[data-power-history]').forEach(function (root) {
          if (root._pcTimer) clearTimeout(root._pcTimer);
          root._pcTimer = setTimeout(function () { mount(root); }, 200);
        });
      });
      document.querySelectorAll('[data-power-history]').forEach(function (r) { ro.observe(r); });
    }
  }

  window.ColdAislePowerCharts = { renderLineChart: renderLineChart, mount: mount, boot: boot };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
