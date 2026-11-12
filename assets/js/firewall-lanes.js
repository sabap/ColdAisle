/**
 * Firewall throughput charts. Each plot is split: inbound on top, outbound below.
 * The shaded area under each line carries flow specks.
 * Speck density and travel speed follow the latest sample.
 * A negative animation delay keeps specks in place when the wall redraws.
 */
(function () {
  'use strict';

  var IN_COLOR = '#22d3ee';
  var OUT_COLOR = '#fb923c';
  var HOT_COLOR = '#f87171';

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function fmtNum(n, d) {
    if (n == null || isNaN(n)) return '';
    return Number(n).toFixed(d).replace(/\.?0+$/, '');
  }

  function clamp01(n) {
    return Math.max(0, Math.min(1, n));
  }

  function mixHex(a, b, t) {
    t = clamp01(t);
    function ch(h, i) { return parseInt(h.substr(i, 2), 16); }
    function hex(n) { var s = Math.round(n).toString(16); return s.length < 2 ? '0' + s : s; }
    return '#' +
      hex(ch(a, 1) + (ch(b, 1) - ch(a, 1)) * t) +
      hex(ch(a, 3) + (ch(b, 3) - ch(a, 3)) * t) +
      hex(ch(a, 5) + (ch(b, 5) - ch(a, 5)) * t);
  }

  function heatT(util) {
    var u = Number(util);
    if (!isFinite(u) || u <= 70) return 0;
    if (u >= 92) return 1;
    return (u - 70) / 22;
  }

  function moteColor(util, kind) {
    var base = kind === 'out' ? OUT_COLOR : IN_COLOR;
    return mixHex(base, HOT_COLOR, heatT(util));
  }

  function moteCount(util) {
    var u = utilUnit(util);
    if (u <= 0) return 0;
    // A quiet link keeps a few sparks. A full link fills the area under the line.
    return Math.min(80, Math.round(8 + Math.pow(u / 100, 0.5) * 52));
  }

  function utilUnit(util) {
    var u = Number(util);
    if (!isFinite(u) || u < 0) u = 0;
    if (u > 100) u = 100;
    return u;
  }

  function durationSec(util) {
    return 6.4 - (utilUnit(util) / 100) * 4.8;
  }

  var flowSeq = 0;

  function hashUnit(n) {
    var x = Math.sin(n * 12.9898) * 43758.5453;
    return x - Math.floor(x);
  }

  function readText(kind, util, text) {
    var bits = [];
    if (text) bits.push(String(text));
    if (util != null && isFinite(Number(util))) bits.push(fmtNum(util, 1) + '%');
    return (kind === 'out' ? 'Out ' : 'In ') + (bits.length ? bits.join(' · ') : '—');
  }

  function hasPoint(vals) {
    return (vals || []).some(function (v) { return v != null && !isNaN(Number(v)); });
  }

  // Each half is its own 0–100 scale. The gap around 50 is the split.
  var BAND = {
    in: { top: 7, base: 46 },
    out: { top: 54, base: 93 }
  };

  function scaleOf(vals) {
    var max = 100;
    (vals || []).forEach(function (v) {
      if (v != null && !isNaN(Number(v)) && Number(v) > max) max = Number(v);
    });
    return { min: 0, max: max };
  }

  function yOf(v, scale, band) {
    var span = (scale.max - scale.min) || 1;
    var t = (Number(v) - scale.min) / span;
    if (t < 0) t = 0;
    if (t > 1) t = 1;
    return band.base - t * (band.base - band.top);
  }

  function xOf(i, n) {
    if (n <= 1) return 50;
    return (i / (n - 1)) * 100;
  }

  function meanFill(vals, yMax) {
    var sum = 0;
    var n = 0;
    (vals || []).forEach(function (v) {
      if (v == null || isNaN(Number(v))) return;
      sum += Math.max(0, Number(v));
      n++;
    });
    if (!n || yMax <= 0) return 1;
    return Math.max(0.18, Math.min(1, (sum / n) / yMax));
  }

  function axisMax(scale) {
    return fmtNum(scale.max, scale.max >= 100 ? 0 : 1);
  }

  // A filled ribbon, not an SVG stroke. A stretched viewBox turns a stroke into a hairline.
  function ribbonPath(vals, scale, band, half) {
    var n = (vals || []).length;
    if (!n) return '';
    var lo = band === BAND.out ? 52 : 2;
    var hi = band === BAND.out ? 98 : 48;
    function clampY(y) {
      if (y < lo) return lo;
      if (y > hi) return hi;
      return y;
    }
    function poly(seg) {
      if (!seg.length) return '';
      var up = [];
      var dn = [];
      for (var i = 0; i < seg.length; i++) {
        up.push(seg[i].x.toFixed(2) + ' ' + clampY(seg[i].y - half).toFixed(2));
        dn.push(seg[i].x.toFixed(2) + ' ' + clampY(seg[i].y + half).toFixed(2));
      }
      return 'M ' + up[0] + ' L ' + up.slice(1).join(' L ') +
        ' L ' + dn.reverse().join(' L ') + ' Z ';
    }
    function build(points) {
      return poly(points);
    }
    if (n === 1 && vals[0] != null && !isNaN(Number(vals[0]))) {
      var y0 = yOf(vals[0], scale, band);
      return build([{ x: 4, y: y0 }, { x: 96, y: y0 }]);
    }
    var d = '';
    var seg = [];
    function flush() {
      if (seg.length === 1) {
        d += build([{ x: Math.max(0, seg[0].x - 1.2), y: seg[0].y }, { x: Math.min(100, seg[0].x + 1.2), y: seg[0].y }]);
      } else if (seg.length) {
        d += build(seg);
      }
      seg = [];
    }
    for (var i = 0; i < n; i++) {
      if (vals[i] == null || isNaN(Number(vals[i]))) {
        flush();
        continue;
      }
      seg.push({ x: xOf(i, n), y: yOf(vals[i], scale, band) });
    }
    flush();
    return d.trim();
  }

  function areaPath(vals, scale, band) {
    var n = (vals || []).length;
    var floor = band.base.toFixed(2);
    if (!n) return '';
    if (n === 1 && vals[0] != null && !isNaN(Number(vals[0]))) {
      var y = yOf(vals[0], scale, band).toFixed(2);
      return 'M 4 ' + y + ' L 96 ' + y + ' L 96 ' + floor + ' L 4 ' + floor + ' Z';
    }
    var d = '';
    var seg = [];
    function flush() {
      if (!seg.length) return;
      d += 'M ' + seg[0];
      for (var i = 1; i < seg.length; i++) d += ' L ' + seg[i];
      var last = seg[seg.length - 1].split(' ');
      var first = seg[0].split(' ');
      d += ' L ' + last[0] + ' ' + floor + ' L ' + first[0] + ' ' + floor + ' Z ';
      seg = [];
    }
    for (var i = 0; i < n; i++) {
      if (vals[i] == null || isNaN(Number(vals[i]))) {
        flush();
        continue;
      }
      seg.push(xOf(i, n).toFixed(2) + ' ' + yOf(vals[i], scale, band).toFixed(2));
    }
    flush();
    return d.trim();
  }

  function clipPolygon(vals, scale, band) {
    var n = (vals || []).length;
    if (!hasPoint(vals)) return '';
    var floor = band.base.toFixed(2);
    if (n === 1) {
      var y1 = yOf(vals[0], scale, band).toFixed(2);
      return 'polygon(4% ' + y1 + '%, 96% ' + y1 + '%, 96% ' + floor + '%, 4% ' + floor + '%)';
    }
    var top = [];
    for (var i = 0; i < n; i++) {
      var y = (vals[i] == null || isNaN(Number(vals[i]))) ? band.base : yOf(vals[i], scale, band);
      top.push(xOf(i, n).toFixed(2) + '% ' + y.toFixed(2) + '%');
    }
    return 'polygon(' + top.join(', ') + ', 100% ' + floor + '%, 0% ' + floor + '%)';
  }

  function specks(kind, util, vals, scale, compact) {
    var target = moteCount(util);
    if (!target) return '';
    // Visible count tracks the latest sample. Spawn extra so clipping to a short line still leaves that field.
    var visibleCap = compact ? 28 : 48;
    var spawnCap = compact ? 120 : 180;
    target = Math.min(target, visibleCap);
    var spawn = Math.min(spawnCap, Math.max(target, Math.round(target / meanFill(vals, scale.max))));
    var baseDur = durationSec(util);
    var color = moteColor(util, kind);
    var now = Date.now() / 1000;
    var seed = kind === 'out' ? 900 : 0;
    var html = '';
    for (var i = 0; i < spawn; i++) {
      var h1 = hashUnit(i + 1 + seed);
      var h2 = hashUnit(i + 2 + seed);
      var h3 = hashUnit(i + 3 + seed);
      var dur = baseDur / (0.84 + h1 * 0.32);
      var delay = -((now + h2 * dur) % dur);
      var band = BAND[kind] || BAND.in;
      var y = (band.top + 1.5 + h3 * (band.base - band.top - 3)).toFixed(1);
      var tw = (1.2 - (utilUnit(util) / 100) * 0.55) * (0.7 + hashUnit(i + 4 + seed) * 0.8);
      html += '<span class="fw-flow-runner ' + kind + '" style="top:' + y +
        '%;animation-duration:' + dur.toFixed(2) + 's;animation-delay:' + delay.toFixed(3) + 's">' +
        '<span class="fw-flow-dot" style="background:' + color +
        ';--fw-tw:' + tw.toFixed(2) + 's;--fw-tw-delay:' + (-((now + hashUnit(i + 5 + seed) * tw) % tw)).toFixed(3) +
        's;box-shadow:0 0 2px rgba(255,255,255,0.85),0 0 6px ' + color + '"></span></span>';
    }
    return html;
  }

  function bandGuides(band) {
    var mid = (band.top + band.base) / 2;
    return '<line class="fw-flow-grid" x1="0" y1="' + band.top.toFixed(2) + '" x2="100" y2="' + band.top.toFixed(2) + '"/>' +
      '<line class="fw-flow-grid" x1="0" y1="' + mid.toFixed(2) + '" x2="100" y2="' + mid.toFixed(2) + '"/>' +
      '<line class="fw-flow-grid" x1="0" y1="' + band.base.toFixed(2) + '" x2="100" y2="' + band.base.toFixed(2) + '"/>';
  }

  function strokePair(vals, scale, band, color) {
    var body = ribbonPath(vals, scale, band, 5.2);
    var core = ribbonPath(vals, scale, band, 2.1);
    if (!body) return '';
    return '<path class="fw-flow-stroke" d="' + body + '" fill="' + color + '"/>' +
      '<path class="fw-flow-core" d="' + core + '" fill="#f8fafc"/>';
  }

  function chartHtml(label, inVals, outVals, iface, compact) {
    var inScale = scaleOf(inVals);
    var outScale = scaleOf(outVals);
    var gid = 'fwf' + (++flowSeq);
    var inArea = areaPath(inVals, inScale, BAND.in);
    var outArea = areaPath(outVals, outScale, BAND.out);
    var inStroke = moteColor(iface.in_util, 'in');
    var outStroke = moteColor(iface.out_util, 'out');
    var ready = hasPoint(inVals) || hasPoint(outVals);
    var svg = '';
    if (ready) {
      svg = '<svg class="fw-flow-svg" viewBox="0 0 100 100" preserveAspectRatio="none" role="img" aria-label="' +
        esc(label + ' inbound above, outbound below') + '">' +
        '<defs>' +
        '<linearGradient id="' + gid + 'in" x1="0" y1="0" x2="0" y2="1">' +
        '<stop offset="0%" stop-color="' + inStroke + '" stop-opacity="0.14"/>' +
        '<stop offset="100%" stop-color="' + inStroke + '" stop-opacity="0.03"/>' +
        '</linearGradient>' +
        '<linearGradient id="' + gid + 'out" x1="0" y1="0" x2="0" y2="1">' +
        '<stop offset="0%" stop-color="' + outStroke + '" stop-opacity="0.14"/>' +
        '<stop offset="100%" stop-color="' + outStroke + '" stop-opacity="0.03"/>' +
        '</linearGradient></defs>' +
        bandGuides(BAND.in) +
        bandGuides(BAND.out) +
        '<line class="fw-flow-split" x1="0" y1="50" x2="100" y2="50"/>' +
        (inArea ? '<path d="' + inArea + '" fill="url(#' + gid + 'in)"/>' : '') +
        (outArea ? '<path d="' + outArea + '" fill="url(#' + gid + 'out)"/>' : '') +
        strokePair(inVals, inScale, BAND.in, inStroke) +
        strokePair(outVals, outScale, BAND.out, outStroke) +
        '</svg>';
    }
    var inClip = clipPolygon(inVals, inScale, BAND.in);
    var outClip = clipPolygon(outVals, outScale, BAND.out);
    var html = '<div class="fw-flow-col"><div class="fw-flow-label">' + esc(label) + '</div>' +
      '<div class="fw-flow-stage"><div class="fw-flow-y">' +
      '<span class="fw-flow-y-band in"><span>' + esc(axisMax(inScale)) + '</span><span>0</span></span>' +
      '<span class="fw-flow-y-band out"><span>' + esc(axisMax(outScale)) + '</span><span>0</span></span>' +
      '</div><div class="fw-flow-plot">' +
      (ready ? svg : '<div class="fw-flow-empty">Waiting for samples</div>');
    if (inClip) {
      html += '<div class="fw-flow-layer" style="clip-path:' + inClip + '">' +
        specks('in', iface.in_util, inVals, inScale, compact) + '</div>';
    }
    if (outClip) {
      html += '<div class="fw-flow-layer" style="clip-path:' + outClip + '">' +
        specks('out', iface.out_util, outVals, outScale, compact) + '</div>';
    }
    html += '<div class="fw-flow-glass"></div></div></div></div>';
    return html;
  }

  /**
   * @param {object} iface
   * @param {{compact?: boolean}=} opts
   */
  function markup(iface, opts) {
    iface = iface || {};
    opts = opts || {};
    var compact = !!opts.compact;
    var inColor = moteColor(iface.in_util, 'in');
    var outColor = moteColor(iface.out_util, 'out');
    var spans = [
      {
        label: '30 min',
        inn: iface.in_series_30m || [],
        out: iface.out_series_30m || []
      },
      {
        label: '24 hours',
        inn: iface.in_series_24h || iface.in_series || [],
        out: iface.out_series_24h || iface.out_series || []
      }
    ];
    var html = '<div class="fw-flow' + (compact ? ' is-compact' : '') + '">';
    if (compact) {
      html += '<div class="fw-flow-meta"><span class="fw-flow-name">' + esc(iface.name || 'Interface') +
        '</span><span class="fw-flow-speed">' + esc(iface.speed_text || '') + '</span></div>';
    }
    html += '<div class="fw-flow-read">' +
      '<span class="fw-flow-stat" style="color:' + inColor + '"><i></i>' +
      esc(readText('in', iface.in_util, iface.in_text)) + '</span>' +
      '<span class="fw-flow-stat" style="color:' + outColor + '"><i></i>' +
      esc(readText('out', iface.out_util, iface.out_text)) + '</span></div>';
    html += '<div class="fw-flow-pair">';
    spans.forEach(function (sp) {
      html += chartHtml(sp.label, sp.inn, sp.out, iface, compact);
    });
    html += '</div></div>';
    return html;
  }

  window.ColdAisleFirewallLanes = { markup: markup };
})();
