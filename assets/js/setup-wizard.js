/**
 * ColdAisle first-login setup wizard.
 */
(function () {
  'use strict';

  var overlay = null;
  var payload = null;
  var busy = false;
  var autosaveTimer = null;

  function apiUrl() {
    var base = (window.ColdAisle && ColdAisle.baseUrl) || '';
    return (base.replace(/\/$/, '') + '/api/setup_wizard.php');
  }

  function csrf() {
    return (window.ColdAisle && ColdAisle.csrf) || '';
  }

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function request(body) {
    body = body || {};
    if (!body._csrf) body._csrf = csrf();
    return fetch(apiUrl(), {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-CSRF-Token': csrf()
      },
      body: JSON.stringify(body)
    }).then(function (res) {
      return res.text().then(function (text) {
        var data;
        try {
          data = text ? JSON.parse(text) : {};
        } catch (e) {
          throw new Error(res.ok
            ? 'The server returned a non-JSON response.'
            : ('Request failed (' + res.status + ').'));
        }
        if (typeof data !== 'object' || data === null) {
          data = { ok: false, error: 'Unexpected response.' };
        }
        data._http = res.status;
        if (!res.ok && data.ok === undefined) {
          data.ok = false;
          data.error = data.error || ('Request failed (' + res.status + ').');
        }
        return data;
      });
    });
  }

  function load(step) {
    var q = step ? ('?step=' + encodeURIComponent(step)) : '';
    return fetch(apiUrl() + q, {
      credentials: 'same-origin',
      headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf() }
    }).then(function (res) { return res.json(); });
  }

  function collectFields() {
    if (!overlay) return {};
    var out = {};
    overlay.querySelectorAll('[data-wiz-field]').forEach(function (el) {
      var name = el.getAttribute('data-wiz-field');
      if (!name) return;
      if (el.type === 'checkbox') {
        out[name] = el.checked ? '1' : '';
      } else {
        out[name] = el.value;
      }
    });
    return out;
  }

  function fieldHtml(f) {
    var name = f.name;
    var val = f.value == null ? '' : String(f.value);
    var req = f.required ? ' required' : '';
    var full = (f.type === 'checkbox' || f.type === 'timezone' || name === 'org_name'
      || name === 'ldaps_base_dn' || name === 'ldaps_bind_dn'
      || name === 'mail_host' || name === 'mail_from_email' || name === 'mail_test_to'
      || name === 'site_name' || name === 'dc_name' || name === 'room_name'
      || name === 'length_units')
      ? ' is-full' : '';

    if (f.type === 'hidden') {
      return '<input type="hidden" data-wiz-field="' + esc(name) + '" value="' + esc(val) + '">';
    }

    if (f.type === 'checkbox') {
      return '<label class="setup-wizard-check">'
        + '<input type="checkbox" data-wiz-field="' + esc(name) + '"' + (val === '1' ? ' checked' : '') + '>'
        + '<span>' + esc(f.label) + '</span></label>';
    }

    var input = '';
    if (f.type === 'select') {
      input = '<select class="form-control" data-wiz-field="' + esc(name) + '"' + req + '>';
      (f.options || []).forEach(function (opt) {
        input += '<option value="' + esc(opt.value) + '"'
          + (String(opt.value) === val ? ' selected' : '') + '>'
          + esc(opt.label) + '</option>';
      });
      input += '</select>';
    } else if (f.type === 'timezone') {
      input = '<input class="form-control" list="setup_wizard_tz" data-wiz-field="' + esc(name) + '" value="'
        + esc(val) + '" placeholder="America/New_York"' + req + '>';
    } else {
      var t = f.type === 'password' || f.type === 'email' || f.type === 'number' ? f.type : 'text';
      input = '<input class="form-control" type="' + t + '" data-wiz-field="' + esc(name) + '" value="'
        + esc(val) + '"'
        + (f.placeholder ? ' placeholder="' + esc(f.placeholder) + '"' : '')
        + (f.step ? ' step="' + esc(f.step) + '"' : '')
        + (f.min != null ? ' min="' + esc(f.min) + '"' : '')
        + (f.max != null ? ' max="' + esc(f.max) + '"' : '')
        + req + '>';
    }
    return '<div class="setup-wizard-field' + full + '"><label>' + esc(f.label) + '</label>'
      + input
      + (f.hint ? '<span class="hint">' + esc(f.hint) + '</span>' : '')
      + '</div>';
  }

  function render() {
    if (!overlay || !payload || !payload.step) return;
    var step = payload.step;
    var prog = payload.progress || { index: 1, total: 1 };
    var dots = (payload.steps || []).map(function (s) {
      var cls = 'setup-wizard-dot';
      if (s.current) cls += ' is-current';
      else if (s.skipped) cls += ' is-skipped';
      else if (s.done) cls += ' is-done';
      return '<span class="' + cls + '" title="' + esc(s.title) + '"></span>';
    }).join('');

    var fields = (step.fields || []).map(fieldHtml).join('');
    var tzList = '';
    if (step.timezones && step.timezones.length) {
      tzList = '<datalist id="setup_wizard_tz">'
        + step.timezones.map(function (z) { return '<option value="' + esc(z) + '">'; }).join('')
        + '</datalist>';
    }
    var guides = (step.guides || []).map(function (g) {
      return '<details><summary>' + esc(g.title) + '</summary><pre>' + esc(g.body) + '</pre></details>';
    }).join('');

    var tests = '';
    if (step.tests && step.tests.length) {
      tests = '<div class="setup-wizard-test">'
        + '<div class="setup-wizard-test-actions">'
        + step.tests.map(function (t) {
          return '<button type="button" class="btn btn-secondary" data-wiz-test="' + esc(t.id) + '">'
            + esc(t.label) + '</button>';
        }).join('')
        + '</div><div class="setup-wizard-result" id="setupWizardTestOut" hidden></div></div>';
    }

    var extra = '';
    if (step.id === 'welcome') {
      extra = '<p class="setup-wizard-blurb" style="margin-top:.25rem">'
        + 'You can stop anytime. Sign in again and the wizard resumes on the last saved step. '
        + 'Skip the whole wizard if you prefer to use Settings instead.</p>';
    }
    if (step.id === 'finish' && step.checklist && step.checklist.length) {
      extra += '<ol class="setup-wizard-next">'
        + step.checklist.map(function (c, i) {
          var cls = 'setup-wizard-next-item' + (c.primary ? ' is-primary' : '');
          return '<li class="' + cls + '">'
            + '<div class="setup-wizard-next-copy">'
            + '<strong>' + (i + 1) + '. ' + esc(c.label) + '</strong>'
            + (c.detail ? '<span>' + esc(c.detail) + '</span>' : '')
            + '</div>'
            + '<button type="button" class="btn ' + (c.primary ? 'btn-primary' : 'btn-secondary') + '" data-wiz-goto="'
            + esc(c.href || 'index.php') + '">Close wizard and open</button>'
            + '</li>';
        }).join('')
        + '</ol>';
    }

    var first = prog.index <= 1;
    var last = step.id === 'finish';
    var skippable = !!step.skippable;

    overlay.querySelector('[data-wiz-dots]').innerHTML = dots;
    overlay.querySelector('[data-wiz-meta]').innerHTML =
      '<span>Step ' + prog.index + ' of ' + prog.total + '</span><span>' + esc(step.kicker || '') + '</span>';
    overlay.querySelector('[data-wiz-title]').textContent = step.title;
    overlay.querySelector('[data-wiz-body]').innerHTML =
      (step.blurb ? '<p class="setup-wizard-blurb">' + esc(step.blurb) + '</p>' : '')
      + extra
      + (fields ? '<div class="setup-wizard-fields">' + fields + '</div>' : '')
      + tzList
      + (guides ? '<div class="setup-wizard-guides">' + guides + '</div>' : '')
      + tests;
    overlay.querySelector('[data-wiz-error]').hidden = true;
    overlay.querySelector('[data-wiz-error]').textContent = '';

    var prevBtn = overlay.querySelector('[data-wiz-prev]');
    var nextBtn = overlay.querySelector('[data-wiz-next]');
    var skipBtn = overlay.querySelector('[data-wiz-skip-step]');
    prevBtn.disabled = first;
    nextBtn.disabled = false;
    nextBtn.textContent = last ? 'Close and go to dashboard' : 'Next';
    skipBtn.hidden = !skippable;
    skipBtn.textContent = step.skip_label || 'Skip';
    bindFloorUnitLabels();
  }

  function bindFloorUnitLabels() {
    if (!overlay || !payload || !payload.step || payload.step.id !== 'floor') return;
    var sel = overlay.querySelector('[data-wiz-field="length_units"]');
    var wIn = overlay.querySelector('[data-wiz-field="floor_width"]');
    var dIn = overlay.querySelector('[data-wiz-field="floor_depth"]');
    if (!sel || !wIn || !dIn) return;

    function labelFor(input, kind, abbr, word) {
      var wrap = input.closest('.setup-wizard-field');
      if (!wrap) return;
      var lab = wrap.querySelector('label');
      var hint = wrap.querySelector('.hint');
      if (lab) {
        lab.textContent = kind === 'width'
          ? ('Width, left → right (' + word + ')')
          : ('Depth, front → back (' + word + ')');
      }
      if (hint) hint.textContent = 'Enter this number in ' + abbr + '.';
    }

    var origW = overlay.querySelector('[data-wiz-field="floor_width_m_orig"]');
    var origD = overlay.querySelector('[data-wiz-field="floor_depth_m_orig"]');
    var dirty = false;

    function displayFromMeters(m, imperial) {
      return imperial ? (m / 0.3048) : m;
    }

    function markDirty() {
      dirty = true;
    }

    function sync(fromUnitChange) {
      var imperial = sel.value === 'imperial';
      var abbr = imperial ? 'ft' : 'm';
      var word = imperial ? 'feet' : 'meters';
      labelFor(wIn, 'width', abbr, word);
      labelFor(dIn, 'depth', abbr, word);
      wIn.step = '0.01';
      dIn.step = '0.01';

      // Unit toggle is display-only unless the user typed a new size.
      // Always re-project from stored meters so 10.06 m never becomes 12.19 m.
      if (fromUnitChange || !dirty) {
        var wM = origW && origW.value !== '' ? parseFloat(origW.value) : NaN;
        var dM = origD && origD.value !== '' ? parseFloat(origD.value) : NaN;
        if (isFinite(wM) && wM > 0) wIn.value = displayFromMeters(wM, imperial).toFixed(imperial ? 4 : 2);
        if (isFinite(dM) && dM > 0) dIn.value = displayFromMeters(dM, imperial).toFixed(imperial ? 4 : 2);
      }

      var w = parseFloat(wIn.value);
      var d = parseFloat(dIn.value);
      var box = overlay.querySelector('[data-wiz-unit-preview]');
      if (!box) {
        box = document.createElement('p');
        box.className = 'setup-wizard-unit-preview';
        box.setAttribute('data-wiz-unit-preview', '');
        var fields = overlay.querySelector('.setup-wizard-fields');
        if (fields && fields.parentNode) fields.parentNode.insertBefore(box, fields.nextSibling);
      }
      if (!isFinite(w) || !isFinite(d) || w <= 0 || d <= 0) {
        box.textContent = 'These numbers are the current hall size. Leave them unless you mean to resize.';
        return;
      }
      if (imperial) {
        box.textContent = w.toFixed(4) + ' × ' + d.toFixed(4) + ' ft  =  '
          + (w * 0.3048).toFixed(2) + ' × ' + (d * 0.3048).toFixed(2)
          + ' m stored (floor plan always uses meters).';
      } else {
        box.textContent = w.toFixed(2) + ' × ' + d.toFixed(2) + ' m stored'
          + '  (about ' + (w / 0.3048).toFixed(2) + ' × ' + (d / 0.3048).toFixed(2) + ' ft).';
      }
    }

    sel.addEventListener('change', function () { sync(true); });
    wIn.addEventListener('input', function () { markDirty(); sync(false); });
    dIn.addEventListener('input', function () { markDirty(); sync(false); });
    sync(false);
  }

  function setError(msg) {
    var el = overlay && overlay.querySelector('[data-wiz-error]');
    if (!el) return;
    if (!msg) {
      el.hidden = true;
      el.textContent = '';
      return;
    }
    el.hidden = false;
    el.textContent = msg;
  }

  function setBusy(on) {
    busy = !!on;
    if (!overlay) return;
    overlay.querySelectorAll('button').forEach(function (b) {
      if (b.getAttribute('data-wiz-skip-all')) return;
      if (on) b.setAttribute('data-was-disabled', b.disabled ? '1' : '0');
      if (on) b.disabled = true;
      else if (b.getAttribute('data-was-disabled') !== '1') b.disabled = false;
    });
    var skipAll = overlay.querySelector('[data-wiz-skip-all]');
    if (skipAll) skipAll.disabled = !!on;
  }

  function applyPayload(data) {
    if (data && data.payload) {
      payload = data.payload;
    } else if (data && data.step) {
      payload = data;
    }
    render();
  }

  function finishAndGo(href) {
    if (busy || !payload || !payload.step) return;
    var fields = collectFields();
    fields.goto = href || 'index.php';
    setBusy(true);
    setError('');
    request({
      action: 'complete',
      step: payload.step.id,
      fields: fields
    }).then(function (data) {
      setBusy(false);
      if (!data.ok) {
        setError(data.error || 'Could not finish the wizard.');
        return;
      }
      close(true);
      var dest = data.redirect || href || 'index.php';
      var base = (window.ColdAisle && ColdAisle.baseUrl) || '';
      window.location.href = base.replace(/\/$/, '') + '/' + String(dest).replace(/^\//, '');
    }).catch(function (err) {
      setBusy(false);
      setError(err && err.message ? err.message : 'Network error.');
    });
  }

  function act(action) {
    if (busy || !payload || !payload.step) return;
    setBusy(true);
    setError('');
    request({
      action: action,
      step: payload.step.id,
      fields: collectFields()
    }).then(function (data) {
      setBusy(false);
      if (!data.ok) {
        setError(data.error || 'Could not save this step.');
        if (data.payload) applyPayload(data);
        return;
      }
      if (data.closed) {
        close(true);
        if (data.redirect) {
          var base = (window.ColdAisle && ColdAisle.baseUrl) || '';
          window.location.href = base.replace(/\/$/, '') + '/' + String(data.redirect).replace(/^\//, '');
        }
        return;
      }
      applyPayload(data);
    }).catch(function (err) {
      setBusy(false);
      setError(err && err.message ? err.message : 'Network error.');
    });
  }

  function runTest(id) {
    if (busy) return;
    var out = overlay.querySelector('#setupWizardTestOut');
    if (out) {
      out.hidden = false;
      out.className = 'setup-wizard-result';
      out.textContent = 'Testing…';
    }
    request({
      action: 'test',
      test: id,
      fields: collectFields()
    }).then(function (data) {
      if (!out) return;
      out.className = 'setup-wizard-result ' + (data.ok ? 'is-ok' : 'is-bad');
      var html = '<strong>' + esc(data.summary || (data.ok ? 'OK' : 'Failed')) + '</strong>';
      if (data.steps && data.steps.length) {
        html += '<ul>' + data.steps.map(function (s) {
          return '<li>' + (s.ok ? '✓ ' : '✕ ') + esc(s.name) + ' — ' + esc(s.detail || '') + '</li>';
        }).join('') + '</ul>';
      }
      out.innerHTML = html;
    }).catch(function (err) {
      if (out) {
        out.className = 'setup-wizard-result is-bad';
        out.textContent = err && err.message ? err.message : 'Test failed.';
      }
    });
  }

  function scheduleSave() {
    if (autosaveTimer) clearTimeout(autosaveTimer);
    autosaveTimer = setTimeout(function () {
      if (!payload || !payload.step || payload.step.id === 'welcome' || payload.step.id === 'finish') return;
      request({
        action: 'save',
        step: payload.step.id,
        fields: collectFields()
      }).catch(function () { /* ignore autosave errors */ });
    }, 1200);
  }

  function openWith(data) {
    payload = data;
    ensureDom();
    render();
    overlay.hidden = false;
    overlay.setAttribute('aria-hidden', 'false');
    document.body.classList.add('modal-open');
  }

  function close(done) {
    if (!overlay) return;
    overlay.hidden = true;
    overlay.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('modal-open');
    if (done && window.ColdAisle) {
      ColdAisle.setupWizardAuto = false;
    }
  }

  function ensureDom() {
    if (overlay) return;
    overlay = document.createElement('div');
    overlay.id = 'setupWizard';
    overlay.className = 'modal-overlay modal-overlay-glass setup-wizard-overlay';
    overlay.hidden = true;
    overlay.setAttribute('aria-hidden', 'true');
    overlay.innerHTML =
      '<div class="setup-wizard-panel" role="dialog" aria-modal="true" aria-labelledby="setupWizardTitle">'
      + '<div class="setup-wizard-head">'
      + '  <div class="setup-wizard-brand">'
      + '    <img src="' + esc((window.ColdAisle && ColdAisle.baseUrl ? ColdAisle.baseUrl.replace(/\/$/, '') + '/' : '') + 'assets/img/logo.svg') + '" alt="">'
      + '    <div><div class="setup-wizard-kicker">Quick start</div>'
      + '    <h2 id="setupWizardTitle" data-wiz-title>Welcome</h2></div>'
      + '  </div>'
      + '  <div class="setup-wizard-track" data-wiz-dots></div>'
      + '  <div class="setup-wizard-meta" data-wiz-meta></div>'
      + '</div>'
      + '<p class="setup-wizard-error" data-wiz-error hidden></p>'
      + '<div class="setup-wizard-body" data-wiz-body></div>'
      + '<div class="setup-wizard-foot">'
      + '  <button type="button" class="btn btn-secondary" data-wiz-skip-all>Skip the wizard</button>'
      + '  <span class="spacer"></span>'
      + '  <button type="button" class="btn btn-secondary" data-wiz-prev>Previous</button>'
      + '  <button type="button" class="btn btn-secondary" data-wiz-skip-step hidden>Skip</button>'
      + '  <button type="button" class="btn btn-primary" data-wiz-next>Next</button>'
      + '  <div class="setup-wizard-save-hint">Progress is saved as you go. Closing the browser resumes this step next login.</div>'
      + '</div></div>';
    document.body.appendChild(overlay);

    overlay.addEventListener('click', function (e) {
      var t = e.target && e.target.nodeType === 1 ? e.target : e.target && e.target.parentElement;
      if (!t || !t.closest) return;
      if (t.closest('[data-wiz-next]')) {
        e.preventDefault();
        if (payload && payload.step && payload.step.id === 'finish') {
          finishAndGo('index.php');
        } else {
          act('next');
        }
        return;
      }
      var goBtn = t.closest('[data-wiz-goto]');
      if (goBtn) {
        e.preventDefault();
        finishAndGo(goBtn.getAttribute('data-wiz-goto') || 'index.php');
        return;
      }
      if (t.closest('[data-wiz-prev]')) {
        e.preventDefault();
        act('prev');
        return;
      }
      if (t.closest('[data-wiz-skip-step]')) {
        e.preventDefault();
        act('skip_step');
        return;
      }
      if (t.closest('[data-wiz-skip-all]')) {
        e.preventDefault();
        if (confirm('Skip the setup wizard?\n\nIt will not open again on login. You can relaunch it from Settings.')) {
          act('skip_wizard');
        }
        return;
      }
      var testBtn = t.closest('[data-wiz-test]');
      if (testBtn) {
        e.preventDefault();
        runTest(testBtn.getAttribute('data-wiz-test') || '');
      }
    });
    overlay.addEventListener('input', scheduleSave);
    overlay.addEventListener('change', scheduleSave);
    document.addEventListener('keydown', function (e) {
      if (!overlay || overlay.hidden) return;
      if (e.key === 'Escape') {
        e.preventDefault();
        // Do not close without saving — persist draft
        act('save');
      }
    });
  }

  function launchFromSettings() {
    var risk = window.ColdAisle && ColdAisle.setupWizardRisk;
    if (risk && risk.warn) {
      var msg = (risk.message || 'This can overwrite existing settings.')
        + '\n\nContinue and open the wizard?';
      if (!confirm(msg)) return;
    }
    request({ action: 'launch' }).then(function (data) {
      if (data.error && !data.step && !data.payload) {
        alert(data.error);
        return;
      }
      openWith(data.payload || data);
    }).catch(function (err) {
      alert(err && err.message ? err.message : 'Could not open the wizard.');
    });
  }

  window.ColdAisle = window.ColdAisle || {};
  ColdAisle.openSetupWizard = function () {
    load().then(openWith).catch(function (err) {
      alert(err && err.message ? err.message : 'Could not load the wizard.');
    });
  };
  ColdAisle.launchSetupWizard = launchFromSettings;

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-setup-wizard-launch]').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        launchFromSettings();
      });
    });
    if (window.ColdAisle && ColdAisle.setupWizardAuto) {
      load().then(openWith).catch(function () { /* stay silent */ });
    }
  });
})();
