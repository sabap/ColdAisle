/**
 * Firewall throughput lanes. Small particles stream at the last measured rate.
 * Density and speed follow that rate. A negative animation delay keeps them
 * in phase when the wall redraws.
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
    var u = Number(util);
    if (!isFinite(u) || u <= 0) return 0;
    if (u > 100) u = 100;
    // A quiet link keeps a few sparks. A full link becomes a dense stream.
    return Math.round(5 + Math.pow(u / 100, 0.7) * 27);
  }

  function durationSec(util) {
    var u = Number(util);
    if (!isFinite(u) || u < 0) u = 0;
    if (u > 100) u = 100;
    return 6.4 - (u / 100) * 4.8;
  }

  function readText(kind, util, text) {
    var bits = [];
    if (text) bits.push(String(text));
    if (util != null && isFinite(Number(util))) bits.push(fmtNum(util, 1) + '%');
    return (kind === 'out' ? 'Out ' : 'In ') + (bits.length ? bits.join(' · ') : '—');
  }

  function motes(kind, util, tops) {
    var n = moteCount(util);
    if (!n) return '';
    var dur = durationSec(util);
    var color = moteColor(util, kind);
    var now = Date.now() / 1000;
    var html = '';
    for (var i = 0; i < n; i++) {
      var delay = -((now + (i / n) * dur) % dur);
      var top = tops[(i * 2) % tops.length];
      var faint = (i % 3) === 0;
      html += '<span class="fw-lane-runner ' + kind + '" style="animation-duration:' +
        dur.toFixed(2) + 's;animation-delay:' + delay.toFixed(3) + 's">' +
        '<span class="fw-lane-dot' + (faint ? ' sm' : '') + '" style="top:' + top +
        'px;background:' + color + ';opacity:' + (faint ? '0.72' : '1') +
        ';box-shadow:0 0 6px ' + color + '"></span></span>';
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
    var topsIn = compact ? [6, 8, 11, 13, 16] : [8, 11, 14, 17, 20];
    var topsOut = compact ? [21, 24, 26, 29, 32] : [27, 30, 33, 36, 40];
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
      motes('in', inU, topsIn) +
      motes('out', outU, topsOut) +
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
