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
    var x = Number(n);
    return x.toFixed(d).replace(/\.?0+$/, '');
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
    var paths = '';
    var gidBase = 'fwg' + Math.random().toString(36).slice(2, 7);
    (series || []).forEach(function (s, si) {
      var vals = s.values || [];
      var n = vals.length;
      var line = '';
      var first = true;
      var firstX = 0;
      var lastX = 0;
      var count = 0;
      for (var i = 0; i < n; i++) {
        if (vals[i] == null || isNaN(Number(vals[i]))) continue;
        var x = xAt(i, n);
        var y = yAt(vals[i]);
        if (n === 1) {
          var x2 = Math.min(padL + plotW, x + Math.max(24, plotW * 0.08));
          line = 'M ' + x.toFixed(1) + ' ' + y.toFixed(1) + ' L ' + x2.toFixed(1) + ' ' + y.toFixed(1);
          firstX = x;
          lastX = x2;
          count = 1;
          break;
        }
        line += (first ? 'M ' : ' L ') + x.toFixed(1) + ' ' + y.toFixed(1);
        if (first) firstX = x;
        lastX = x;
        first = false;
        count++;
      }
      if (!line || count < 1) return;
      var color = s.color || '#38bdf8';
      if (si === 0) {
        var gid = gidBase + si;
        var area = line + ' L ' + lastX.toFixed(1) + ' ' + (padT + plotH).toFixed(1) +
          ' L ' + firstX.toFixed(1) + ' ' + (padT + plotH).toFixed(1) + ' Z';
        paths += '<defs><linearGradient id="' + gid + '" x1="0" y1="0" x2="0" y2="1">' +
          '<stop offset="0%" stop-color="' + color + '" stop-opacity="0.35"/>' +
          '<stop offset="100%" stop-color="' + color + '" stop-opacity="0.02"/>' +
          '</linearGradient></defs>';
        paths += '<path d="' + area + '" fill="url(#' + gid + ')"/>';
      }
      paths += '<path class="pc-line" d="' + line + '" fill="none" stroke="' + color +
        '" stroke-width="2.2" stroke-linejoin="round" stroke-linecap="round"/>';
    });
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

  function bar(label, util, text, kind) {
    var hot = util != null && !isNaN(util) && Number(util) >= 70;
    var width = util == null || isNaN(util) ? 0 : Math.max(0, Math.min(100, Number(util)));
    var value = util == null || isNaN(util) ? '—' : fmtNum(util, 1) + '%';
    if (text) value += ' · ' + text;
    return '<div class="fw-util-row"><span class="fw-util-k">' + esc(label) + '</span>' +
      '<div class="fw-util-bar ' + kind + (hot ? ' hot' : '') + '"><i style="width:' + width.toFixed(1) + '%"></i></div>' +
      '<span class="fw-util-v">' + esc(value) + '</span></div>';
  }

  function ifCard(iface) {
    var series = [
      { name: 'In', color: '#22d3ee', values: iface.in_series || [] },
      { name: 'Out', color: '#fb923c', values: iface.out_series || [] }
    ];
    return '<div class="fw-if-card">' +
      '<div class="fw-if-head"><strong>' + esc(iface.name || 'Interface') + '</strong>' +
      '<span>' + esc(iface.speed_text || '') + '</span></div>' +
      bar('In', iface.in_util, iface.in_text, 'in') +
      bar('Out', iface.out_util, iface.out_text, 'out') +
      '<div class="fw-if-spark">' + chartSvg(series, { yMin: 0, yMax: 100, height: 96 }) + '</div>' +
      legend(series) +
      '</div>';
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
