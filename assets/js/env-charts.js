/**
 * ColdAisle — env sensor history charts (SVG, no external deps).
 */
(function () {
  'use strict';

  function pad(n) { return n < 10 ? '0' + n : String(n); }

  function fmtTime(iso) {
    var d = new Date(iso);
    if (isNaN(d.getTime())) return '';
    return pad(d.getHours()) + ':' + pad(d.getMinutes());
  }

  function hasData(arr) {
    return (arr || []).some(function (v) { return v != null && !isNaN(v); });
  }

  function render(el, opts) {
    if (!el) return;
    var t = opts.t || [];
    var values = opts.values || [];
    var color = opts.color || '#38bdf8';
    var unit = opts.unit || '';
    var label = opts.label || '';
    var h = opts.height || 160;
    var w = Math.floor(el.clientWidth || el.offsetWidth || 640);
    if (w < 80) w = 640;

    if (!hasData(values) || t.length < 1) {
      el.innerHTML = '<p class="text-muted" style="padding:.75rem;margin:0">No history yet for this window.</p>';
      el.dataset.ready = '0';
      return;
    }

    var padL = 44, padR = 12, padT = 14, padB = 26;
    var plotW = Math.max(40, w - padL - padR);
    var plotH = Math.max(40, h - padT - padB);
    var nums = values.filter(function (v) { return v != null && !isNaN(v); }).map(Number);
    var minV = Math.min.apply(null, nums);
    var maxV = Math.max.apply(null, nums);
    if (minV === maxV) {
      minV -= 1;
      maxV += 1;
    }
    // Padding on range
    var padY = (maxV - minV) * 0.08 || 0.5;
    minV -= padY;
    maxV += padY;

    function xAt(i) {
      if (t.length <= 1) return padL + plotW / 2;
      return padL + (i / (t.length - 1)) * plotW;
    }
    function yAt(v) {
      return padT + (1 - (v - minV) / (maxV - minV)) * plotH;
    }

    var pts = [];
    for (var i = 0; i < values.length; i++) {
      if (values[i] == null || isNaN(values[i])) continue;
      pts.push({ i: i, x: xAt(i), y: yAt(Number(values[i])), v: Number(values[i]) });
    }
    if (!pts.length) {
      el.innerHTML = '<p class="text-muted" style="padding:.75rem;margin:0">No numeric points.</p>';
      return;
    }

    var line = '';
    var area = '';
    pts.forEach(function (p, n) {
      line += (n ? ' L ' : 'M ') + p.x.toFixed(1) + ' ' + p.y.toFixed(1);
    });
    area = line + ' L ' + pts[pts.length - 1].x.toFixed(1) + ' ' + (padT + plotH)
      + ' L ' + pts[0].x.toFixed(1) + ' ' + (padT + plotH) + ' Z';

    var yTicks = [];
    for (var ti = 0; ti < 4; ti++) {
      var tv = minV + (maxV - minV) * (ti / 3);
      yTicks.push({ y: yAt(tv), label: tv.toFixed(tv >= 10 || tv <= -10 ? 0 : 1) });
    }
    var xTicks = [];
    var step = Math.max(1, Math.floor(t.length / 5));
    for (var xi = 0; xi < t.length; xi += step) {
      xTicks.push({ x: xAt(xi), label: fmtTime(t[xi]) });
    }

    var gid = 'eg' + Math.random().toString(36).slice(2, 8);
    var svg = '<svg width="' + w + '" height="' + h + '" class="env-chart-svg" role="img" aria-label="'
      + String(label).replace(/"/g, '') + '">';
    svg += '<defs><linearGradient id="' + gid + '" x1="0" y1="0" x2="0" y2="1">'
      + '<stop offset="0%" stop-color="' + color + '" stop-opacity="0.35"/>'
      + '<stop offset="100%" stop-color="' + color + '" stop-opacity="0.02"/>'
      + '</linearGradient></defs>';
    svg += '<rect x="0" y="0" width="' + w + '" height="' + h + '" fill="transparent"/>';
    yTicks.forEach(function (yt) {
      svg += '<line x1="' + padL + '" y1="' + yt.y.toFixed(1) + '" x2="' + (padL + plotW)
        + '" y2="' + yt.y.toFixed(1) + '" stroke="rgba(148,163,184,.15)"/>';
      svg += '<text x="' + (padL - 6) + '" y="' + (yt.y + 3).toFixed(1)
        + '" text-anchor="end" fill="#94a3b8" font-size="10">' + yt.label + '</text>';
    });
    xTicks.forEach(function (xt) {
      svg += '<text x="' + xt.x.toFixed(1) + '" y="' + (h - 8)
        + '" text-anchor="middle" fill="#94a3b8" font-size="10">' + xt.label + '</text>';
    });
    svg += '<path d="' + area + '" fill="url(#' + gid + ')"/>';
    svg += '<path d="' + line + '" fill="none" stroke="' + color + '" stroke-width="2"/>';
    var last = pts[pts.length - 1];
    svg += '<circle cx="' + last.x.toFixed(1) + '" cy="' + last.y.toFixed(1)
      + '" r="3.5" fill="' + color + '"/>';
    svg += '</svg>';
    el.innerHTML = svg;
    el.dataset.ready = '1';
  }

  function mountRoot(root) {
    if (!root || root._envChartBound) return;
    root._envChartBound = true;
    var sensorId = root.getAttribute('data-id') || root.dataset.id;
    var hours = root.getAttribute('data-hours') || '24';
    var base = (window.ColdAisle && ColdAisle.baseUrl) || '';
    var url = base.replace(/\/$/, '') + '/api/env_history.php?sensor_id='
      + encodeURIComponent(sensorId) + '&hours=' + encodeURIComponent(hours);

    var tempEl = root.querySelector('[data-env-series="temp"]');
    var humEl = root.querySelector('[data-env-series="humidity"]');
    var statusEl = root.querySelector('[data-env-chart-status]');

    function load() {
      if (statusEl) statusEl.textContent = 'Loading…';
      fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
        .then(function (res) {
          if (!res.ok || !res.j || !res.j.ok) {
            if (statusEl) statusEl.textContent = (res.j && res.j.error) || 'Failed to load history';
            return;
          }
          var j = res.j;
          if (statusEl) {
            statusEl.textContent = (j.points || 0) + ' point(s) · last ' + (j.hours || hours) + 'h';
          }
          if (tempEl) {
            render(tempEl, {
              t: j.t,
              values: j.temp,
              color: '#38bdf8',
              unit: j.unit || '°C',
              label: 'Temperature',
              height: 170,
            });
          }
          if (humEl) {
            var wrap = humEl.closest('[data-env-hum-wrap]');
            if (hasData(j.humidity)) {
              if (wrap) wrap.hidden = false;
              render(humEl, {
                t: j.t,
                values: j.humidity,
                color: '#34d399',
                unit: '%RH',
                label: 'Humidity',
                height: 150,
              });
            } else if (wrap) {
              wrap.hidden = true;
            }
          }
        })
        .catch(function () {
          if (statusEl) statusEl.textContent = 'Failed to load history';
        });
    }

    load();
    window.addEventListener('resize', function () {
      if (root._envResizeT) clearTimeout(root._envResizeT);
      root._envResizeT = setTimeout(load, 200);
    });
  }

  function boot() {
    document.querySelectorAll('[data-env-history]').forEach(mountRoot);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }

  window.ColdAisleEnvCharts = { mount: mountRoot, render: render };
})();
