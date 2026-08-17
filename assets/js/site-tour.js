/**
 * ColdAisle guided tour — flag markers on live UI, multi-page, exit anytime.
 */
(function () {
  'use strict';

  var STEPS = [];
  var CHAPTERS = [];
  var index = 0;
  var root = null;
  var busy = false;
  var waitBound = null;
  var KEY = 'ca_site_tour';

  function base() {
    return ((window.ColdAisle && ColdAisle.baseUrl) || '').replace(/\/$/, '');
  }
  function csrf() {
    return (window.ColdAisle && ColdAisle.csrf) || '';
  }
  function apiUrl() {
    return base() + '/api/site_tour.php';
  }
  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }
  function currentPath() {
    var p = (location.pathname || '').replace(/\\/g, '/');
    var b = base();
    if (b) {
      try {
        var bp = new URL(b, location.origin).pathname.replace(/\/$/, '');
        if (bp && p.indexOf(bp) === 0) p = p.slice(bp.length) || '/';
      } catch (e) { /* ignore */ }
    }
    p = p.replace(/^\//, '');
    if (p === '' || p === 'index.php') return 'index.php';
    return p;
  }
  function pathMatches(stepPath) {
    var a = currentPath();
    var b = String(stepPath || '').replace(/^\//, '');
    return a === b || a.endsWith('/' + b);
  }

  function request(body) {
    body = body || {};
    body._csrf = csrf();
    return fetch(apiUrl(), {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-CSRF-Token': csrf()
      },
      body: JSON.stringify(body)
    }).then(function (r) { return r.json(); });
  }

  function persistLocal(on, i) {
    try {
      if (!on) {
        sessionStorage.removeItem(KEY);
        return;
      }
      sessionStorage.setItem(KEY, JSON.stringify({ on: true, i: i }));
    } catch (e) { /* private mode */ }
  }
  function readLocal() {
    try {
      var raw = sessionStorage.getItem(KEY);
      return raw ? JSON.parse(raw) : null;
    } catch (e) {
      return null;
    }
  }

  function ensureRoot() {
    if (root) return root;
    root = document.createElement('div');
    root.className = 'site-tour';
    root.hidden = true;
    root.innerHTML =
      '<div class="site-tour-dim" data-tour-ignore></div>'
      + '<div class="site-tour-hole" data-tour-ignore hidden></div>'
      + '<div class="site-tour-flag" data-tour-ignore>'
      + '  <div class="site-tour-pole" aria-hidden="true"></div>'
      + '  <div class="site-tour-card" role="dialog" aria-modal="true" aria-labelledby="siteTourTitle">'
      + '    <div class="site-tour-kicker" id="siteTourKicker"></div>'
      + '    <div class="site-tour-chapters" id="siteTourChapters"></div>'
      + '    <h3 id="siteTourTitle"></h3>'
      + '    <p id="siteTourBody"></p>'
      + '    <p class="site-tour-wait" id="siteTourWait" hidden></p>'
      + '    <div class="site-tour-actions">'
      + '      <button type="button" class="btn btn-ghost btn-sm" data-tour-exit>Exit tour</button>'
      + '      <span class="site-tour-spacer"></span>'
      + '      <button type="button" class="btn btn-secondary btn-sm" data-tour-prev>Back</button>'
      + '      <button type="button" class="btn btn-ghost btn-sm" data-tour-skip-wait hidden>Skip this</button>'
      + '      <button type="button" class="btn btn-primary btn-sm" data-tour-next>Next</button>'
      + '    </div>'
      + '  </div>'
      + '</div>';
    document.body.appendChild(root);
    root.addEventListener('click', function (e) {
      var t = e.target && e.target.closest ? e.target : null;
      if (!t) return;
      if (t.closest('[data-tour-next]')) { e.preventDefault(); next(); }
      else if (t.closest('[data-tour-prev]')) { e.preventDefault(); prev(); }
      else if (t.closest('[data-tour-exit]')) { e.preventDefault(); exit(); }
      else if (t.closest('[data-tour-skip-wait]')) { e.preventDefault(); clearWait(); next(); }
      else if (t.closest('[data-tour-chapter]')) {
        e.preventDefault();
        var idx = parseInt(t.closest('[data-tour-chapter]').getAttribute('data-tour-chapter'), 10);
        if (!isNaN(idx)) go(idx);
      }
    });
    document.addEventListener('keydown', function (e) {
      if (!root || root.hidden) return;
      if (e.key === 'Escape') { e.preventDefault(); exit(); }
      if (e.key === 'ArrowRight') { e.preventDefault(); if (!waiting()) next(); }
      if (e.key === 'ArrowLeft') { e.preventDefault(); prev(); }
    });
    window.addEventListener('resize', function () {
      if (root && !root.hidden) position();
    });
    return root;
  }

  function targetEl(sel) {
    if (!sel) return null;
    try { return document.querySelector(sel); } catch (e) { return null; }
  }

  function position() {
    if (!root || !STEPS[index]) return;
    var step = STEPS[index];
    var hole = root.querySelector('.site-tour-hole');
    var flag = root.querySelector('.site-tour-flag');
    var el = targetEl(step.target);
    var place = step.place || 'right';

    document.querySelectorAll('.site-tour-lit').forEach(function (n) {
      n.classList.remove('site-tour-lit');
    });

    if (!el || place === 'center') {
      hole.hidden = true;
      flag.className = 'site-tour-flag is-center';
      flag.style.top = '';
      flag.style.left = '';
      return;
    }

    el.classList.add('site-tour-lit');
    try { el.scrollIntoView({ block: 'nearest', inline: 'nearest', behavior: 'smooth' }); } catch (e) { /* ignore */ }
    var r = el.getBoundingClientRect();
    var pad = 8;
    hole.hidden = false;
    hole.style.top = Math.max(0, r.top - pad) + 'px';
    hole.style.left = Math.max(0, r.left - pad) + 'px';
    hole.style.width = Math.min(window.innerWidth, r.width + pad * 2) + 'px';
    hole.style.height = Math.min(window.innerHeight, r.height + pad * 2) + 'px';

    flag.className = 'site-tour-flag is-' + place;
    var fw = flag.offsetWidth || 360;
    var fh = flag.offsetHeight || 220;
    var top = r.top;
    var left = r.right + 16;
    if (place === 'bottom') {
      top = r.bottom + 14;
      left = r.left;
    } else if (place === 'top') {
      top = r.top - fh - 14;
      left = r.left;
    } else if (place === 'left') {
      left = r.left - fw - 16;
      top = r.top;
    }
    if (left + fw > window.innerWidth - 12) left = window.innerWidth - fw - 12;
    if (left < 12) left = 12;
    if (top + fh > window.innerHeight - 12) top = window.innerHeight - fh - 12;
    if (top < 12) top = 12;
    flag.style.top = top + 'px';
    flag.style.left = left + 'px';
  }

  function currentChapter() {
    return (STEPS[index] && STEPS[index].chapter) || '';
  }

  function paintChapters() {
    var box = root.querySelector('#siteTourChapters');
    if (!box) return;
    var cur = currentChapter();
    box.innerHTML = CHAPTERS.map(function (ch) {
      var on = ch.label === cur ? ' is-current' : '';
      return '<button type="button" class="site-tour-chapter' + on + '" data-tour-chapter="'
        + esc(String(ch.index)) + '">' + esc(ch.label) + '</button>';
    }).join('');
  }

  function clearWait() {
    if (waitBound && waitBound.el && waitBound.fn) {
      waitBound.el.removeEventListener('click', waitBound.fn, true);
    }
    waitBound = null;
  }

  function bindWait(step) {
    clearWait();
    if (!step || step.wait !== 'click') return;
    var el = targetEl(step.target);
    if (!el) return;
    var fn = function () {
      clearWait();
      setTimeout(function () { next(true); }, 120);
    };
    el.addEventListener('click', fn, true);
    waitBound = { el: el, fn: fn };
  }

  function waiting() {
    return !!(STEPS[index] && STEPS[index].wait === 'click' && waitBound);
  }

  function paint() {
    ensureRoot();
    var step = STEPS[index];
    if (!step) { hide(); return; }
    root.hidden = false;
    document.body.classList.add('site-tour-open');
    root.querySelector('#siteTourKicker').textContent =
      (step.chapter ? step.chapter + ' · ' : 'Tour · ') + (index + 1) + ' of ' + STEPS.length;
    root.querySelector('#siteTourTitle').textContent = step.title || '';
    root.querySelector('#siteTourBody').textContent = step.body || '';
    paintChapters();
    bindWait(step);
    var waitEl = root.querySelector('#siteTourWait');
    var skipWait = root.querySelector('[data-tour-skip-wait]');
    var nextBtn = root.querySelector('[data-tour-next]');
    var prevBtn = root.querySelector('[data-tour-prev]');
    var isWait = step.wait === 'click';
    if (waitEl) {
      waitEl.hidden = !isWait;
      waitEl.textContent = isWait ? (step.wait_hint || 'Try the highlighted control to continue.') : '';
    }
    if (skipWait) skipWait.hidden = !isWait;
    nextBtn.textContent = index >= STEPS.length - 1 ? 'Finish' : (isWait ? 'Waiting…' : 'Next');
    nextBtn.disabled = isWait && index < STEPS.length - 1;
    prevBtn.disabled = index <= 0;
    requestAnimationFrame(position);
    setTimeout(position, 80);
  }

  function hide() {
    clearWait();
    if (root) root.hidden = true;
    document.body.classList.remove('site-tour-open');
    document.querySelectorAll('.site-tour-lit').forEach(function (n) {
      n.classList.remove('site-tour-lit');
    });
  }

  function go(i) {
    if (busy || !STEPS.length) return;
    i = Math.max(0, Math.min(STEPS.length - 1, i));
    index = i;
    persistLocal(true, i);
    request({ action: 'goto', step: i }).catch(function () { /* keep going */ });
    var step = STEPS[i];
    if (step && step.page && !pathMatches(step.page)) {
      var url = base() + '/' + step.page.replace(/^\//, '');
      url += (url.indexOf('?') >= 0 ? '&' : '?') + 'tour=1';
      location.href = url;
      return;
    }
    paint();
  }

  function next(fromTry) {
    if (waiting() && !fromTry) return;
    if (index >= STEPS.length - 1) {
      finish();
      return;
    }
    go(index + 1);
  }
  function prev() {
    if (index <= 0) return;
    go(index - 1);
  }

  function exit() {
    persistLocal(false, 0);
    hide();
    request({ action: 'exit' }).catch(function () { /* ignore */ });
  }
  function finish() {
    persistLocal(false, 0);
    hide();
    request({ action: 'complete' }).catch(function () { /* ignore */ });
  }

  function applyPayload(data, startAt) {
    STEPS = (data && data.steps) || [];
    CHAPTERS = (data && data.chapters) || [];
    if (!STEPS.length) return;
    var i = typeof startAt === 'number' ? startAt : (data.index || 0);
    if (i < 0 || i >= STEPS.length) i = 0;
    index = i;
    persistLocal(true, i);
    var step = STEPS[i];
    if (step && step.page && !pathMatches(step.page)) {
      var url = base() + '/' + step.page.replace(/^\//, '');
      url += (url.indexOf('?') >= 0 ? '&' : '?') + 'tour=1';
      location.href = url;
      return;
    }
    paint();
  }

  function startFromBeginning() {
    if (window.ColdAisle && ColdAisle.setupWizardAuto) return;
    fetch(apiUrl(), {
      credentials: 'same-origin',
      headers: { Accept: 'application/json', 'X-CSRF-Token': csrf() }
    }).then(function (r) { return r.json(); }).then(function (data) {
      return request({ action: 'start' }).then(function () {
        applyPayload(data, 0);
      });
    }).catch(function (err) {
      alert(err && err.message ? err.message : 'Could not start the tour.');
    });
  }

  function resumeIfNeeded() {
    if (window.ColdAisle && ColdAisle.setupWizardAuto) return;
    if (window.ColdAisle && ColdAisle.techMode) return;
    var q = new URLSearchParams(location.search);
    var local = readLocal();
    var want = q.get('tour') === '1' || (local && local.on) || (window.ColdAisle && ColdAisle.siteTourActive);
    if (!want) return;
    fetch(apiUrl(), {
      credentials: 'same-origin',
      headers: { Accept: 'application/json', 'X-CSRF-Token': csrf() }
    }).then(function (r) { return r.json(); }).then(function (data) {
      var i = local && typeof local.i === 'number' ? local.i : (data.index || 0);
      if (data.state && data.state.status === 'idle' && q.get('tour') === '1') {
        return request({ action: 'start' }).then(function () { applyPayload(data, 0); });
      }
      applyPayload(data, i);
    }).catch(function () { /* stay quiet */ });
  }

  window.ColdAisle = window.ColdAisle || {};
  ColdAisle.startSiteTour = startFromBeginning;

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-site-tour-launch]').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        startFromBeginning();
      });
    });
    resumeIfNeeded();
  });
})();
