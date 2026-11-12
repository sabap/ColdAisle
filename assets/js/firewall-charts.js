/**
 * Firewall detail charts. Series come from stored samples, refreshed once a minute.
 */
(function () {
  'use strict';

  var root = document.getElementById('fwCharts');
  var boot = document.getElementById('fwHistoryBoot');
  if (!root || !boot) return;

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function fmtNum(n, d) {
    if (n == null || isNaN(n)) return '—';
    var s = Number(n).toFixed(d);
    if (s.indexOf('.') !== -1) s = s.replace(/0+$/, '').replace(/\.$/, '');
    return s;
  }

  function fmtInt(n) {
    if (n == null || isNaN(n)) return '—';
    return Math.round(Number(n)).toLocaleString();
  }

  function hasNum(arr) {
    return (arr || []).some(function (v) { return v != null && !isNaN(Number(v)); });
  }

  function chartSvg(series, opts) {
    opts = opts || {};
    var w = 640;
    var h = opts.height || 168;
    var padL = 46, padR = 12, padT = 12, padB = 18;
    var all = [];
    (series || []).forEach(function (s) {
      (s.values || []).forEach(function (v) {
        if (v != null && !isNaN(Number(v))) all.push(Number(v));
      });
    });
    if (!all.length) {
      return '<div class="power-chart-empty">Waiting for the next sample</div>';
    }
    var dataMin = Math.min.apply(null, all);
    var dataMax = Math.max.apply(null, all);
    var min = opts.yMin != null ? opts.yMin : dataMin;
    var max = opts.yMax != null ? opts.yMax : dataMax;
    if (dataMax > max) max = dataMax;
    if (dataMin < min) min = dataMin;
    if (opts.yMin == null) {
      var pad = (max - min) * 0.08 || 1;
      min = Math.max(0, min - pad);
      max = max + pad;
    }
    if (min === max) {
      min = Math.max(0, min - 1);
      max = max + 1;
    }
    var plotW = w - padL - padR;
    var plotH = h - padT - padB;
    var span = max - min || 1;
    function xAt(i, n) {
      if (n <= 1) return padL + plotW / 2;
      return padL + (i / (n - 1)) * plotW;
    }
    function yAt(v) {
      return padT + plotH - ((Number(v) - min) / span) * plotH;
    }
    var grid = '';
    for (var g = 0; g <= 2; g++) {
      var gv = min + (span * g / 2);
      var gy = yAt(gv);
      grid += '<line class="pc-grid" x1="' + padL + '" y1="' + gy.toFixed(1) +
        '" x2="' + (w - padR) + '" y2="' + gy.toFixed(1) + '"/>';
      grid += '<text class="pc-axis" x="' + (padL - 6) + '" y="' + (gy + 3).toFixed(1) +
        '" text-anchor="end">' + esc(fmtNum(gv, gv >= 100 ? 0 : 1)) + '</text>';
    }
    var fills = '';
    var strokes = '';
    var gidBase = 'fwg' + Math.random().toString(36).slice(2, 7);
    var baseY = (padT + plotH).toFixed(1);
    (series || []).forEach(function (s, si) {
      var vals = s.values || [];
      var n = vals.length;
      var color = s.color || '#38bdf8';
      var parts = [];
      var seg = [];
      function pushPart() {
        if (seg.length) parts.push(seg);
        seg = [];
      }
      for (var i = 0; i < n; i++) {
        if (vals[i] == null || isNaN(Number(vals[i]))) {
          if (opts.breakGaps) pushPart();
          continue;
        }
        var x = xAt(i, n);
        var y = yAt(vals[i]);
        if (!opts.breakGaps && n === 1) {
          var x2 = Math.min(padL + plotW, x + Math.max(24, plotW * 0.08));
          parts.push([{ x: x, y: y }, { x: x2, y: y }]);
          seg = [];
          break;
        }
        seg.push({ x: x, y: y });
      }
      pushPart();
      parts.forEach(function (part, pi) {
        if (!part.length) return;
        var draw = part;
        if (part.length === 1) {
          var x3 = Math.min(padL + plotW, part[0].x + Math.max(24, plotW * 0.08));
          draw = [part[0], { x: x3, y: part[0].y }];
        }
        var line = '';
        draw.forEach(function (p, idx) {
          line += (idx ? ' L ' : 'M ') + p.x.toFixed(1) + ' ' + p.y.toFixed(1);
        });
        if (opts.fillEach || si === 0) {
          var gid = gidBase + si + 'p' + pi;
          var area = line + ' L ' + draw[draw.length - 1].x.toFixed(1) + ' ' + baseY +
            ' L ' + draw[0].x.toFixed(1) + ' ' + baseY + ' Z';
          fills += '<defs><linearGradient id="' + gid + '" x1="0" y1="0" x2="0" y2="1">' +
            '<stop offset="0%" stop-color="' + color + '" stop-opacity="0.35"/>' +
            '<stop offset="100%" stop-color="' + color + '" stop-opacity="0.02"/>' +
            '</linearGradient></defs>';
          fills += '<path d="' + area + '" fill="url(#' + gid + ')"/>';
        }
        strokes += '<path class="pc-line" d="' + line + '" fill="none" stroke="' + color +
          '" stroke-width="2.2" stroke-linejoin="round" stroke-linecap="round"/>';
      });
    });
    var paths = fills + strokes;
    return '<svg class="power-chart-svg" viewBox="0 0 ' + w + ' ' + h +
      '" preserveAspectRatio="none" role="img">' + grid + paths + '</svg>';
  }

  function legend(series) {
    var html = '<div class="fw-legend">';
    (series || []).forEach(function (s) {
      if (!hasNum(s.values)) return;
      html += '<span><i style="background:' + esc(s.color || '#38bdf8') + '"></i>' + esc(s.name || '') + '</span>';
    });
    html += '</div>';
    return html;
  }

  function chartCard(label, nowText, series, opts) {
    return '<div class="power-chart power-chart-lg">' +
      '<div class="power-chart-head"><span class="power-chart-label">' + esc(label) + '</span>' +
      '<span class="power-chart-now">' + esc(nowText) + '</span></div>' +
      chartSvg(series, opts) +
      legend(series) +
      '</div>';
  }

  var IN_BLUE = '#38bdf8';
  var OUT_ORANGE = '#fb923c';

  function rateBit(label, util, text, color) {
    var value = util == null || isNaN(util) ? '—' : fmtNum(util, 1) + '%';
    if (text) value += ' · ' + text;
    return '<span style="color:' + color + '">' + esc(label) + ' ' + esc(value) + '</span>';
  }

  function spanChart(label, inVals, outVals) {
    var series = [
      { name: 'Inbound', color: IN_BLUE, values: inVals || [] },
      { name: 'Outbound', color: OUT_ORANGE, values: outVals || [] }
    ];
    return '<div class="power-chart fw-if-span">' +
      '<div class="power-chart-head"><span class="power-chart-label">' + esc(label) + '</span></div>' +
      chartSvg(series, { yMin: 0, yMax: 100, height: 148, fillEach: true }) +
      legend(series) +
      '</div>';
  }

  function ifCard(iface) {
    return '<div class="fw-if-card">' +
      '<div class="fw-if-head"><strong>' + esc(iface.name || 'Interface') + '</strong>' +
      '<span>' + esc(iface.speed_text || '') + '</span></div>' +
      '<div class="fw-if-rates">' +
      rateBit('In', iface.in_util, iface.in_text, IN_BLUE) +
      rateBit('Out', iface.out_util, iface.out_text, OUT_ORANGE) +
      '</div>' +
      '<div class="fw-if-spans">' +
      spanChart('30 minutes', iface.in_series_30m, iface.out_series_30m) +
      spanChart('24 hours', iface.in_series_24h || iface.in_series, iface.out_series_24h || iface.out_series) +
      '</div></div>';
  }

  function paint(fw) {
    if (!fw) {
      root.innerHTML = '<p class="text-muted">Charts appear after the firewall is polled.</p>';
      return;
    }
    var cpuMem = [
      { name: 'CPU', color: '#38bdf8', values: fw.cpu_series || [] },
      { name: 'Memory', color: '#a78bfa', values: fw.memory_series || [] }
    ];
    var html = '<p class="text-muted fw-chart-note">' + esc(fw.note || '') + '</p>';
    html += '<div class="fw-chart-grid">';
    html += chartCard('CPU and memory',
      fmtNum(fw.cpu, 1) + '% CPU · ' + fmtNum(fw.memory, 1) + '% memory',
      cpuMem, { yMin: 0, yMax: 100 });
    html += chartCard('Connections', fmtInt(fw.connections), [
      { name: 'Connections', color: '#34d399', values: fw.connection_series || [] }
    ], {});
    if (hasNum(fw.cps_series)) {
      html += chartCard('Connections per second', fmtInt(fw.cps) + ' /s', [
        { name: 'Per second', color: '#fbbf24', values: fw.cps_series || [] }
      ], {});
    }
    html += '</div>';
    var ifaces = fw.interfaces || [];
    if (!ifaces.length) {
      html += '<p class="text-muted">No up interfaces with a reported speed in the last poll.</p>';
    } else {
      html += '<div class="fw-if-grid">';
      ifaces.forEach(function (iface) { html += ifCard(iface); });
      html += '</div>';
    }
    root.innerHTML = html;
  }

  var initial = null;
  try {
    initial = JSON.parse(boot.textContent || 'null');
  } catch (e) {
    initial = null;
  }
  paint(initial);

  var id = root.getAttribute('data-id');
  var api = root.getAttribute('data-api');
  if (!api || !id) return;
  setInterval(function () {
    fetch(api + '?id=' + encodeURIComponent(id), {
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' }
    }).then(function (r) {
      return r.ok ? r.json() : null;
    }).then(function (body) {
      if (body && body.firewall) paint(body.firewall);
    }).catch(function () {});
  }, 60000);
})();
