/**
 * Faint specks in the shaded area under a throughput line.
 * Inbound drifts one way, outbound the other. Density follows the latest sample.
 * A negative delay keeps specks in place when a page redraws.
 */
(function () {
  'use strict';

  function hash(n) {
    var x = Math.sin(n * 12.9898) * 43758.5453;
    return x - Math.floor(x);
  }

  function utilUnit(util) {
    var u = Number(util);
    if (!isFinite(u) || u < 0) u = 0;
    if (u > 100) u = 100;
    return u;
  }

  function countFor(util, max) {
    var u = utilUnit(util);
    if (u <= 0) return 0;
    max = max || 14;
    return Math.max(2, Math.min(max, Math.round(2 + Math.pow(u / 100, 0.85) * (max - 2))));
  }

  function durationFor(util) {
    return 16 - (utilUnit(util) / 100) * 8;
  }

  /**
   * @param {Array<{color:string,dir:string,util:number,points:Array<{x:number,y:number}>,baseY:number}>} clips
   * @param {{w:number,h:number,max?:number}} box
   */
  function layers(clips, box) {
    box = box || {};
    var w = box.w || 1;
    var h = box.h || 1;
    var max = box.max || 14;
    var now = Date.now() / 1000;
    var html = '';
    (clips || []).forEach(function (clip, ci) {
      var pts = clip.points || [];
      if (pts.length < 2) return;
      var n = countFor(clip.util, max);
      if (!n) return;
      var peak = pts[0].y;
      pts.forEach(function (p) {
        if (p.y < peak) peak = p.y;
      });
      var base = clip.baseY;
      var poly = pts.map(function (p) {
        return (p.x / w * 100).toFixed(2) + '% ' + (p.y / h * 100).toFixed(2) + '%';
      });
      var last = pts[pts.length - 1];
      var first = pts[0];
      poly.push((last.x / w * 100).toFixed(2) + '% ' + (base / h * 100).toFixed(2) + '%');
      poly.push((first.x / w * 100).toFixed(2) + '% ' + (base / h * 100).toFixed(2) + '%');
      var dir = clip.dir === 'out' ? 'out' : 'in';
      var seed = (dir === 'out' ? 700 : 80) + ci * 37;
      var dur0 = durationFor(clip.util);
      var color = clip.color || (dir === 'out' ? '#fb923c' : '#38bdf8');
      html += '<div class="fw-speck-layer" aria-hidden="true" style="clip-path:polygon(' + poly.join(',') + ')">';
      for (var i = 0; i < n; i++) {
        var h1 = hash(i + 1 + seed);
        var h2 = hash(i + 11 + seed);
        var h3 = hash(i + 23 + seed);
        var dur = dur0 / (0.85 + h1 * 0.3);
        var delay = -((now + h2 * dur) % dur);
        var yPx = base - (base - peak) * (0.08 + h3 * 0.5);
        html += '<span class="fw-speck-runner ' + dir + '" style="top:' + (yPx / h * 100).toFixed(2) +
          '%;animation-duration:' + dur.toFixed(2) + 's;animation-delay:' + delay.toFixed(3) + 's">' +
          '<i style="background:' + color + '"></i></span>';
      }
      html += '</div>';
    });
    return html;
  }

  window.ColdAisleFirewallSpecks = { layers: layers, countFor: countFor };
})();
