/**
 * Firewall throughput lanes. Specks fill a glass tube.
 * Inbound stays in the top half, outbound in the bottom half.
 * Density, travel speed, and swirl speed follow the last measured rate.
 * A negative animation delay keeps each speck in place when the wall redraws.
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
    // A quiet link keeps a few sparks. A full link fills its half of the tube.
    return Math.min(120, Math.round(14 + Math.pow(u / 100, 0.5) * 110));
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

  /** Swirls while a speck crosses. Hotter links swirl faster. */
  function spiralTurns(util) {
    return 1.15 + (utilUnit(util) / 100) * 3.4;
  }

  function hashUnit(n) {
    var x = Math.sin(n * 12.9898) * 43758.5453;
    return x - Math.floor(x);
  }

  /** Vertical home of one direction, kept off the centerline and the glass wall. */
  function bandFor(compact, kind) {
    if (compact) {
      return kind === 'in' ? { lo: 5, hi: 15, amp: 2.4 } : { lo: 21, hi: 31, amp: 2.4 };
    }
    return kind === 'in' ? { lo: 6, hi: 19, amp: 3 } : { lo: 27, hi: 40, amp: 3 };
  }

  function readText(kind, util, text) {
    var bits = [];
    if (text) bits.push(String(text));
    if (util != null && isFinite(Number(util))) bits.push(fmtNum(util, 1) + '%');
    return (kind === 'out' ? 'Out ' : 'In ') + (bits.length ? bits.join(' · ') : '—');
  }

  function motes(kind, util, compact) {
    var n = moteCount(util);
    if (!n) return '';
    var baseDur = durationSec(util);
    var turns = spiralTurns(util);
    var color = moteColor(util, kind);
    var band = bandFor(compact, kind);
    var now = Date.now() / 1000;
    var seed = kind === 'out' ? 800 : 0;
    var html = '';
    for (var i = 0; i < n; i++) {
      var h1 = hashUnit(i + 1 + seed);
      var h2 = hashUnit(i + 2 + seed);
      var h3 = hashUnit(i + 3 + seed);
      var h4 = hashUnit(i + 4 + seed);
      var h5 = hashUnit(i + 5 + seed);
      // Same metric speed, with a little scatter so the specks are not one file.
      var dur = baseDur / (0.84 + h1 * 0.32);
      var spin = (dur / turns) * (0.8 + h2 * 0.45);
      var travelDelay = -((now + h3 * dur) % dur);
      var spinDelay = -((now + h4 * spin) % spin);
      var tw = (1.15 - (utilUnit(util) / 100) * 0.6) * (0.55 + h5 * 0.9);
      var twDelay = -((now + hashUnit(i + 6 + seed) * tw) % tw);
      var y = band.lo + hashUnit(i + 7 + seed) * (band.hi - band.lo);
      var amp = band.amp * (0.35 + hashUnit(i + 8 + seed) * 0.65);
      var glow = (0.55 + hashUnit(i + 9 + seed) * 0.45).toFixed(2);
      var lg = hashUnit(i + 10 + seed) > 0.82;
      html += '<span class="fw-lane-runner ' + kind + '" style="animation-duration:' +
        dur.toFixed(2) + 's;animation-delay:' + travelDelay.toFixed(3) + 's">' +
        '<span class="fw-lane-dot' + (lg ? ' lg' : '') + '" style="top:' + y.toFixed(1) +
        'px;--fw-amp:' + amp.toFixed(2) + 'px;--fw-glow:' + glow +
        ';animation-duration:' + spin.toFixed(2) + 's,' + tw.toFixed(2) +
        's;animation-delay:' + spinDelay.toFixed(3) + 's,' + twDelay.toFixed(3) +
        's;background:' + color +
        ';box-shadow:0 0 2px rgba(255,255,255,0.9),0 0 7px ' + color + '"></span></span>';
    }
    return html;
  }

  function fillWidth(util) {
    var u = Number(util);
    if (!isFinite(u) || u <= 0) return '0%';
    return Math.max(0, Math.min(100, u)).toFixed(1) + '%';
  }

  /**
   * @param {object} iface
   * @param {{compact?: boolean}=} opts
   */
  function markup(iface, opts) {
    iface = iface || {};
    opts = opts || {};
    var compact = !!opts.compact;
    var inU = iface.in_util;
    var outU = iface.out_util;
    var inN = Number(inU);
    var outN = Number(outU);
    var heat = 0;
    if (isFinite(inN)) heat = Math.max(heat, inN);
    if (isFinite(outN)) heat = Math.max(heat, outN);
    heat = Math.max(0, Math.min(100, heat)) / 100;
    var inColor = moteColor(inU, 'in');
    var outColor = moteColor(outU, 'out');
    var html = '<div class="fw-lane' + (compact ? ' fw-lane-compact' : ' fw-lane-block') + '">';
    if (compact) {
      html += '<div class="fw-lane-meta"><span class="fw-lane-name">' + esc(iface.name || 'Interface') +
        '</span><span class="fw-lane-speed">' + esc(iface.speed_text || '') + '</span></div>';
    }
    html += '<div class="fw-lane-track' + (heat >= 0.7 ? ' is-hot' : '') +
      '" style="--fw-heat:' + heat.toFixed(3) + '" aria-hidden="true">' +
      '<span class="fw-lane-fill in" style="width:' + fillWidth(inU) + ';background:' + inColor + '"></span>' +
      '<span class="fw-lane-fill out" style="width:' + fillWidth(outU) + ';background:' + outColor + '"></span>' +
      '<span class="fw-lane-sheen"></span>' +
      motes('in', inU, compact) +
      motes('out', outU, compact) +
      '</div>';
    html += '<div class="fw-lane-read">' +
      '<span class="fw-lane-stat in" style="color:' + inColor + '"><i></i>' +
      esc(readText('in', inU, iface.in_text)) + '</span>' +
      '<span class="fw-lane-stat out" style="color:' + outColor + '"><i></i>' +
      esc(readText('out', outU, iface.out_text)) + '</span>' +
      '</div></div>';
    return html;
  }

  window.ColdAisleFirewallLanes = { markup: markup };
})();
