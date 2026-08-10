/**
 * ColdAisle — SVG power history charts (no external deps).
 * Gradient area + line, optional outage markers, multi-series phase volts.
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
    if (unit === '%' || unit === 'pct') return n.toFixed(0) + '%';
    if (unit === 'min') return n.toFixed(0) + ' min';
    if (unit === 'state') {
      var labels = { 1: 'low', 2: 'normal', 3: 'near OL', 4: 'overload' };
      var i = Math.round(n);
      return labels[i] != null ? labels[i] + ' (' + i + ')' : String(i);
    }
    return String(n);
  }

  function multiSeriesHasData(obj) {
    if (!obj) return false;
    return ['L1', 'L2', 'L3'].some(function (lab) {
      return (obj[lab] || []).some(function (v) { return v != null && !isNaN(v); });
    });
  }

  function valuesHaveData(arr) {
    return (arr || []).some(function (v) { return v != null && !isNaN(v); });
  }

  function escapeHtml(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function nearestIndex(tArr, iso) {
    if (!tArr || !tArr.length) return -1;
    var ts = Date.parse(iso);
    if (isNaN(ts)) return -1;
    var best = 0, bestD = Infinity;
    for (var i = 0; i < tArr.length; i++) {
      var d = Math.abs(Date.parse(tArr[i]) - ts);
      if (d < bestD) { bestD = d; best = i; }
    }
    return bestD <= 15 * 60 * 1000 ? best : -1; // 15 min tolerance
  }

  /**
   * @param {HTMLElement} el
   * @param {object} opts
   */
  function renderLineChart(el, opts) {
    if (!el) return;
    var t = opts.t || [];
    var values = opts.values || [];
    var seriesExtra = opts.seriesExtra || null; // {L1:[], L2:[], L3:[]} for phase volts
    var outages = opts.outages || [];
    var color = opts.color || '#38bdf8';
    var unit = opts.unit || '';
    var label = opts.label || '';
    var showOutages = opts.showOutages !== false;
    var h = opts.height || (el.classList.contains('power-chart-sm') ? 88 : 180);
    var w = Math.floor(el.clientWidth || el.offsetWidth || (el.parentElement && el.parentElement.clientWidth) || 640);
    if (w < 80) w = 640;

    var outageSig = showOutages && outages.length ? outages.length + ':' + (outages[0] && outages[0].t) : '0';
    var extraSig = seriesExtra ? 'ph' : '';
    if (el._pcW === w && el._pcPainted && el.dataset.ready === '1'
        && el._pcOutageSig === outageSig && el._pcExtraSig === extraSig) {
      return;
    }
    el._pcW = w;
    el._pcOutageSig = outageSig;
    el._pcExtraSig = extraSig;

    var padL = 44, padR = 12, padT = 16, padB = 28;
    var plotW = Math.max(40, w - padL - padR);
    var plotH = Math.max(40, h - padT - padB);

    var nums = values.filter(function (v) { return v != null && !isNaN(v); }).map(Number);
    if (seriesExtra) {
      ['L1', 'L2', 'L3'].forEach(function (lab) {
        (seriesExtra[lab] || []).forEach(function (v) {
          if (v != null && !isNaN(v)) nums.push(Number(v));
        });
      });
    }
    var minV = nums.length ? Math.min.apply(null, nums) : 0;
    var maxV = nums.length ? Math.max.apply(null, nums) : 1;
    if (minV === maxV) {
      minV = minV > 0 ? minV * 0.9 : minV - 1;
      maxV = maxV > 0 ? maxV * 1.1 : maxV + 1;
    }
    if (unit === 'V') {
      minV = Math.max(0, Math.floor(minV / 10) * 10 - 10);
      maxV = Math.ceil(maxV / 10) * 10 + 10;
    } else if (unit === '%' || unit === 'pct') {
      minV = 0;
      maxV = Math.max(100, Math.ceil(maxV / 10) * 10);
    } else if (unit === 'state') {
      // APC load-state enum ~1–4
      minV = 0;
      maxV = 5;
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

    function pathFor(vals) {
      var pathD = '', areaD = '', first = true, firstX = null, lastX = null, lastPt = null;
      var points = [];
      for (var i = 0; i < vals.length; i++) {
        var v = vals[i];
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
        lastPt = points[points.length - 1];
      }
      // One sample: short horizontal segment (not a lone hard-to-see dot)
      if (points.length === 1) {
        var p0 = points[0];
        var x2 = Math.min(padL + plotW, p0.x + Math.max(28, plotW * 0.1));
        pathD = 'M ' + p0.x.toFixed(1) + ' ' + p0.y.toFixed(1) + ' L ' + x2.toFixed(1) + ' ' + p0.y.toFixed(1);
        firstX = p0.x;
        lastX = x2;
      }
      if (points.length && firstX != null && lastX != null) {
        areaD = pathD
          + ' L ' + lastX.toFixed(1) + ' ' + (padT + plotH).toFixed(1)
          + ' L ' + firstX.toFixed(1) + ' ' + (padT + plotH).toFixed(1)
          + ' Z';
      }
      return { pathD: pathD, areaD: areaD, points: points, last: lastPt };
    }

    var main = pathFor(values);
    var metricKey = (el.getAttribute('data-metric') || 'm').replace(/[^a-z0-9_-]/gi, '');
    var gid = 'pg-' + metricKey + '-' + w;
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

    // Outage markers (vertical dashed + triangle)
    var outageSvg = '';
    var outageN = 0;
    if (showOutages && outages.length && t.length) {
      outages.forEach(function (o) {
        var i = nearestIndex(t, o.t);
        if (i < 0) return;
        outageN++;
        var x = xAt(i);
        var title = escapeHtml(o.label || (o.phases || []).join(',') || 'Outage');
        outageSvg += '<g class="pc-outage" data-tip="' + title + '">'
          + '<line x1="' + x.toFixed(1) + '" y1="' + padT + '" x2="' + x.toFixed(1)
          + '" y2="' + (padT + plotH) + '" stroke="#ef4444" stroke-width="1.25"'
          + ' stroke-dasharray="3 3" opacity="0.85"/>'
          + '<polygon points="' + x.toFixed(1) + ',' + (padT + 2) + ' '
          + (x - 5).toFixed(1) + ',' + (padT + 10) + ' '
          + (x + 5).toFixed(1) + ',' + (padT + 10)
          + '" fill="#ef4444" opacity="0.95">'
          + '<title>' + title + '</title></polygon>'
          + '</g>';
      });
    }

    // Extra phase lines (L1/L2/L3)
    var phaseColors = { L1: '#f97316', L2: '#22c55e', L3: '#eab308' };
    var phasePaths = '';
    if (seriesExtra) {
      ['L1', 'L2', 'L3'].forEach(function (lab) {
        var pr = pathFor(seriesExtra[lab] || []);
        if (pr.pathD) {
          phasePaths += '<path class="pc-phase-line" d="' + pr.pathD + '" fill="none" stroke="'
            + phaseColors[lab] + '" stroke-width="1.75" stroke-linejoin="round" opacity="0.9"/>';
        }
      });
    }

    var last = main.last;
    var badge = outageN > 0
      ? '<span class="power-chart-outage-badge" title="Phase outage / low-voltage / overload events">'
        + outageN + ' outage' + (outageN === 1 ? '' : 's') + '</span>'
      : '';
    var phaseLegend = seriesExtra
      ? '<span class="power-chart-phase-legend">'
        + '<i style="background:#f97316"></i>L1 '
        + '<i style="background:#22c55e"></i>L2 '
        + '<i style="background:#eab308"></i>L3</span>'
      : '';

    var head = '<div class="power-chart-head">'
      + '<span class="power-chart-label">' + escapeHtml(label) + ' ' + phaseLegend + badge + '</span>'
      + '<span class="power-chart-now">' + (last ? fmtVal(last.v, unit) : 'No data yet') + '</span>'
      + '</div>';

    var empty = !main.points.length && !seriesExtra
      ? '<div class="power-chart-empty">No history yet — samples appear after SNMP polls.</div>'
      : '';

    var hasGeom = main.points.length || phasePaths;
    var svg = hasGeom ? (
      '<svg class="power-chart-svg" viewBox="0 0 ' + w + ' ' + h + '" width="100%" height="' + h + '" preserveAspectRatio="none">'
      + '<defs><linearGradient id="' + gid + '" x1="0" y1="0" x2="0" y2="1">'
      + '<stop offset="0%" stop-color="' + color + '" stop-opacity="0.35"/>'
      + '<stop offset="100%" stop-color="' + color + '" stop-opacity="0"/>'
      + '</linearGradient></defs>'
      + grid + yLabels + xLabels
      + outageSvg
      + (main.areaD && !seriesExtra ? '<path class="pc-area" d="' + main.areaD + '" fill="url(#' + gid + ')"/>' : '')
      + (main.pathD ? '<path class="pc-line" d="' + main.pathD + '" fill="none" stroke="' + color
        + '" stroke-width="' + (seriesExtra ? '1.25' : '2.25') + '" stroke-linejoin="round" stroke-linecap="round"'
        + (seriesExtra ? ' opacity="0.35"' : '') + '/>' : '')
      + phasePaths
      + (last && !seriesExtra ? '<circle class="pc-dot" cx="' + last.x.toFixed(1) + '" cy="' + last.y.toFixed(1)
        + '" r="3.5" fill="' + color + '"/>' : '')
      + '</svg>'
    ) : '';

    el.innerHTML = head + empty + svg;
    el.dataset.ready = '1';
    el._pcPainted = true;
  }

  function paintFromCache(root) {
    var s = root._pcSeries;
    if (!s) return;
    var outages = root._pcOutages || [];
    var meta = root._pcMeta || {};
    var charts = root.querySelectorAll('[data-metric]');
    charts.forEach(function (c) {
      c.classList.remove('power-chart-loading');
      var metric = c.getAttribute('data-metric') || 'kw';
      var values = s[metric] || [];
      var unit = c.getAttribute('data-unit') || (metric === 'volts' ? 'V' : metric === 'amps' ? 'A' : 'kW');
      var color = c.getAttribute('data-color') || (metric === 'volts' ? '#a78bfa' : '#38bdf8');
      var showOutages = c.getAttribute('data-outages') !== '0';
      var seriesExtra = null;
      var hasData = false;

      if (metric === 'phase_volts') {
        if (s.phase_volts && multiSeriesHasData(s.phase_volts)) {
          seriesExtra = s.phase_volts;
          values = s.phase_volts.L1 || [];
          unit = 'V';
          hasData = true;
        }
      } else if (metric === 'phase_load_state') {
        if (s.phase_load_state && multiSeriesHasData(s.phase_load_state)) {
          seriesExtra = s.phase_load_state;
          values = s.phase_load_state.L1 || [];
          unit = 'state';
          hasData = true;
        }
      } else if (metric === 'volts') {
        values = s.volts || [];
        hasData = valuesHaveData(values);
        // Also hide avg V when meta says no voltage samples
        if (meta.has_avg_volts === false) hasData = false;
      } else if (metric === 'kw' || metric === 'watts') {
        values = s[metric] || s.kw || [];
        hasData = valuesHaveData(values);
      } else if (metric === 'amps') {
        values = s.amps || [];
        hasData = valuesHaveData(values);
        unit = c.getAttribute('data-unit') || 'A';
      } else if (metric === 'load_pct' || metric === 'battery_pct' || metric === 'runtime_min') {
        values = s[metric] || [];
        hasData = valuesHaveData(values);
        if (metric === 'load_pct' || metric === 'battery_pct') {
          unit = c.getAttribute('data-unit') || '%';
        }
        if (metric === 'runtime_min') {
          unit = c.getAttribute('data-unit') || 'min';
        }
      } else {
        hasData = valuesHaveData(values) || (seriesExtra && multiSeriesHasData(seriesExtra));
      }

      // Optional charts: hide when no data (older APCs without phase volts, etc.)
      // Keep primary kw/amps + UPS load_pct visible with empty state
      var hideIfEmpty = c.getAttribute('data-hide-empty') !== '0';
      var keepEmpty = (metric === 'kw' || metric === 'amps' || metric === 'load_pct');
      if (hideIfEmpty && !hasData && !keepEmpty) {
        c.style.display = 'none';
        c.setAttribute('hidden', 'hidden');
        c.dataset.ready = '0';
        return;
      }
      c.style.display = '';
      c.removeAttribute('hidden');

      // Prefer outage markers on usage chart
      if (metric === 'kw' && c.getAttribute('data-outages') == null) {
        showOutages = true;
      }
      renderLineChart(c, {
        t: s.t || [],
        values: values,
        seriesExtra: seriesExtra,
        outages: showOutages ? outages : [],
        showOutages: showOutages,
        label: c.getAttribute('data-label') || metric,
        unit: unit,
        color: color,
        height: parseInt(c.getAttribute('data-height') || '0', 10) || undefined,
      });
    });

    // Optional summary strip
    var sumEl = root.querySelector('[data-outage-summary]');
    if (sumEl) {
      var n = outages.length;
      var phases = (root._pcMeta && root._pcMeta.outage_phases) || [];
      if (n > 0) {
        sumEl.hidden = false;
        sumEl.innerHTML = '<strong>' + n + '</strong> outage event' + (n === 1 ? '' : 's')
          + ' in window'
          + (phases.length ? ' · phases ' + escapeHtml(phases.join(', ')) : '')
          + ' <span class="text-muted">(dead / low voltage / overload)</span>';
      } else {
        sumEl.hidden = true;
        sumEl.innerHTML = '';
      }
    }
  }

  async function mount(root, opts) {
    if (!root) return;
    opts = opts || {};
    if (opts.repaintOnly && root._pcSeries) {
      paintFromCache(root);
      return;
    }
    if (root._pcFetching) return;

    var scope = root.getAttribute('data-scope') || 'site';
    var id = root.getAttribute('data-id') || '';
    var hours = parseInt(root.getAttribute('data-hours') || '24', 10) || 24;
    var from = root.getAttribute('data-from') || '';
    var to = root.getAttribute('data-to') || '';
    var preset = root.getAttribute('data-preset') || '';
    var cacheKey = scope + '|' + id + '|' + hours + '|' + from + '|' + to + '|' + preset;

    if (root._pcSeries && root._pcCacheKey === cacheKey && !opts.force) {
      paintFromCache(root);
      return;
    }

    var base = (window.ColdAisle && window.ColdAisle.baseUrl) || '';
    var url = base.replace(/\/$/, '') + '/api/power_history.php?scope=' + encodeURIComponent(scope)
      + (id ? '&id=' + encodeURIComponent(id) : '');
    if (from && to) {
      url += '&from=' + encodeURIComponent(from) + '&to=' + encodeURIComponent(to);
    } else if (preset) {
      url += '&preset=' + encodeURIComponent(preset);
    } else {
      url += '&hours=' + hours;
    }

    var charts = root.querySelectorAll('[data-metric]');
    if (!root._pcSeries) {
      charts.forEach(function (c) {
        c.classList.add('power-chart-loading');
        c.innerHTML = '<div class="power-chart-empty">Loading…</div>';
        c._pcPainted = false;
        c._pcW = null;
      });
    }

    root._pcFetching = true;
    try {
      var res = await fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
      var data = await res.json();
      if (!data || !data.ok) throw new Error((data && data.error) || 'History unavailable');
      root._pcSeries = data.series || {};
      root._pcOutages = data.outages || [];
      root._pcMeta = data.meta || {};
      root._pcCacheKey = cacheKey;
      charts.forEach(function (c) {
        c._pcW = null;
        c._pcPainted = false;
      });
      paintFromCache(root);
    } catch (e) {
      charts.forEach(function (c) {
        c.classList.remove('power-chart-loading');
        c.innerHTML = '<div class="power-chart-empty">' + escapeHtml(e.message || 'Failed to load') + '</div>';
        c._pcPainted = false;
      });
    } finally {
      root._pcFetching = false;
    }
  }

  function boot() {
    document.querySelectorAll('[data-power-history]').forEach(function (root) {
      mount(root);
    });

    var resizeTimer = null;
    window.addEventListener('resize', function () {
      if (resizeTimer) clearTimeout(resizeTimer);
      resizeTimer = setTimeout(function () {
        document.querySelectorAll('[data-power-history]').forEach(function (root) {
          if (!root._pcSeries) return;
          root.querySelectorAll('[data-metric]').forEach(function (c) {
            c._pcW = null;
            c._pcPainted = false;
          });
          mount(root, { repaintOnly: true });
        });
      }, 250);
    });
  }

  window.ColdAislePowerCharts = { renderLineChart: renderLineChart, mount: mount, boot: boot };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
