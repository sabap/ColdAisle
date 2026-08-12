/**
 * ColdAisle — shared front-end helpers
 */
(function () {
  'use strict';

  const csrf = (window.ColdAisle && window.ColdAisle.csrf) ||
    (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
  const baseUrl = (window.ColdAisle && window.ColdAisle.baseUrl) || '';

  const api = Object.assign(window.ColdAisle || {}, {
    csrf,
    baseUrl,

    api: async function (path, options = {}) {
      const headers = Object.assign({
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-CSRF-Token': csrf,
      }, options.headers || {});

      // IIS sometimes blocks PUT/DELETE; allow callers to send POST + override
      let method = (options.method || 'GET').toUpperCase();
      if ((method === 'PUT' || method === 'PATCH' || method === 'DELETE') && options.forcePostOverride) {
        headers['X-HTTP-Method-Override'] = method;
        method = 'POST';
      }

      const opts = Object.assign({}, options, {
        method: method,
        headers: headers,
        credentials: 'same-origin',
      });
      delete opts.forcePostOverride;
      const timeoutMs = typeof opts.timeoutMs === 'number' ? opts.timeoutMs : 0;
      delete opts.timeoutMs;

      // Optional client timeout (SNMP poll / long ops) — aborts fetch so UI never hangs forever
      let abortTimer = null;
      let localController = null;
      if (timeoutMs > 0 && !opts.signal && typeof AbortController !== 'undefined') {
        localController = new AbortController();
        opts.signal = localController.signal;
        abortTimer = setTimeout(function () {
          try { localController.abort(); } catch (e) { /* ignore */ }
        }, timeoutMs);
      }

      if (opts.body && typeof opts.body === 'object' && !(opts.body instanceof FormData)) {
        opts.body = JSON.stringify(opts.body);
      }

      const url = path.startsWith('http') ? path : (baseUrl.replace(/\/$/, '') + '/' + path.replace(/^\//, ''));
      let res;
      try {
        res = await fetch(url, opts);
      } catch (fetchErr) {
        if (abortTimer) clearTimeout(abortTimer);
        const aborted = (fetchErr && fetchErr.name === 'AbortError')
          || (localController && localController.signal && localController.signal.aborted)
          || /abort/i.test(String((fetchErr && fetchErr.message) || ''));
        if (aborted) {
          const err = new Error('SNMP poll timed out waiting for the server. The device may be slow or unreachable — try again, or check network/firewall to UDP/161.');
          err.status = 0;
          err.timeout = true;
          err.aborted = true;
          throw err;
        }
        throw fetchErr;
      }
      if (abortTimer) clearTimeout(abortTimer);
      const text = await res.text();
      let data;
      try { data = text ? JSON.parse(text) : {}; } catch (e) { data = { raw: text }; }
      if (!res.ok) {
        let msg = (data && data.error) || '';
        // IIS often returns HTML "Internal Server Error" with no JSON body
        if (!msg && data && data.raw) {
          const raw = String(data.raw).replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
          msg = raw.slice(0, 240) || res.statusText || 'Request failed';
        }
        if (!msg) {
          msg = res.statusText || ('HTTP ' + res.status);
        }
        if (res.status === 500 && (!data || !data.error)) {
          // Blank / HTML 500s are often PHP timeouts on long SNMP polls
          if (!text || /internal server error|maximum execution|timed? ?out/i.test(String(text))) {
            msg = 'Server timed out or failed during SNMP poll (HTTP 500). Try again; if it persists, check storage/logs/app.log.';
          } else {
            msg += ' — check storage/logs/app.log and snmp_discover_last.txt on the server';
          }
        }
        if (res.status === 504 || res.status === 408) {
          msg = msg || 'SNMP poll timed out.';
        }
        const err = new Error(msg);
        err.status = res.status;
        err.data = data;
        err.timeout = res.status === 504 || res.status === 408
          || /timed? ?out/i.test(msg);
        throw err;
      }
      return data;
    },

    toast: function (message, type, opts) {
      type = type || 'info';
      opts = opts || {};
      const cleared = !!(opts.cleared || opts.is_cleared);
      if (cleared && (type === 'error' || type === 'warning' || type === 'danger')) {
        type = 'success';
      }
      let host = document.getElementById('toast-host');
      if (!host) {
        host = document.createElement('div');
        host.id = 'toast-host';
        host.setAttribute('aria-live', 'polite');
        document.body.appendChild(host);
      }
      const el = document.createElement('div');
      el.className = 'ca-toast ca-toast-' + type + (cleared ? ' ca-toast-cleared' : '');
      el.setAttribute('role', 'status');

      let marker;
      if (cleared) {
        marker = document.createElement('span');
        marker.className = 'ca-toast-check';
        marker.setAttribute('aria-label', 'Cleared');
        marker.title = 'Cleared';
        marker.textContent = '✓';
      } else {
        marker = document.createElement('span');
        marker.className = 'health-pulse health-pulse-' + (
          type === 'success' ? 'ok' :
          type === 'warning' ? 'warn' :
          (type === 'error' || type === 'danger') ? 'crit' : 'info'
        );
        marker.setAttribute('aria-hidden', 'true');
      }

      const body = document.createElement('div');
      body.style.flex = '1';
      body.style.minWidth = '0';
      if (opts.title) {
        const t = document.createElement('div');
        t.className = 'ca-toast-title';
        t.textContent = opts.title;
        if (cleared) {
          const badge = document.createElement('span');
          badge.className = 'ca-toast-cleared-badge';
          badge.textContent = 'Cleared';
          t.appendChild(document.createTextNode(' '));
          t.appendChild(badge);
        }
        body.appendChild(t);
      }
      const m = document.createElement('p');
      m.className = opts.title ? 'ca-toast-msg' : 'ca-toast-title';
      m.style.margin = opts.title ? '' : '0';
      m.textContent = message || '';
      body.appendChild(m);

      const close = document.createElement('button');
      close.type = 'button';
      close.className = 'ca-toast-close';
      close.setAttribute('aria-label', 'Dismiss');
      close.innerHTML = '×';
      const dismiss = function () {
        el.classList.add('ca-toast-out');
        setTimeout(function () { el.remove(); }, 240);
      };
      close.addEventListener('click', dismiss);

      el.appendChild(marker);
      el.appendChild(body);
      el.appendChild(close);
      host.appendChild(el);

      const ms = typeof opts.duration === 'number' ? opts.duration : 5200;
      if (ms > 0) {
        setTimeout(dismiss, ms);
      }
      return el;
    },

    /**
     * Poll notifications API and surface new items as live toasts.
     * Uses sessionStorage so a refresh does not re-toast the same IDs.
     */
    initLiveToasts: function () {
      if (window.__caLiveToastsStarted) return;
      window.__caLiveToastsStarted = true;
      const storageKey = 'ca_notif_max_id';
      let sinceId = 0;
      try {
        sinceId = parseInt(sessionStorage.getItem(storageKey) || '0', 10) || 0;
      } catch (e) { sinceId = 0; }

      const seen = Object.create(null);
      let first = true;
      const intervalMs = 25000;

      const tick = async function () {
        try {
          const q = first && sinceId === 0
            ? 'api/notifications.php?limit=8'
            : ('api/notifications.php?since_id=' + encodeURIComponent(String(sinceId)) + '&limit=20');
          const data = await api.api(q);
          if (!data || !data.ok) return;

          // Badge
          const badge = document.querySelector('.notif-badge');
          const unread = typeof data.unread === 'number' ? data.unread : 0;
          if (badge) {
            if (unread > 0) {
              badge.textContent = String(unread);
              badge.hidden = false;
              badge.style.display = '';
            } else {
              badge.hidden = true;
            }
          } else if (unread > 0) {
            const actions = document.querySelector('.topbar-actions');
            if (actions) {
              const a = document.createElement('a');
              a.className = 'notif-badge';
              a.href = (api.baseUrl || '').replace(/\/$/, '') + '/pages/notifications.php';
              a.title = 'Notifications';
              a.textContent = String(unread);
              actions.appendChild(a);
            }
          }

          const items = Array.isArray(data.items) ? data.items : [];
          // First paint with no prior max: set watermark without toasting old backlog
          if (first && sinceId === 0) {
            let max = 0;
            items.forEach(function (it) {
              if (it.id > max) max = it.id;
            });
            // Also respect API max_id if higher
            if ((data.max_id || 0) > max) max = data.max_id;
            sinceId = max;
            try { sessionStorage.setItem(storageKey, String(sinceId)); } catch (e2) {}
            first = false;
            return;
          }
          first = false;

          items.forEach(function (it) {
            if (!it || !it.id || seen[it.id]) return;
            seen[it.id] = true;
            if (it.id <= sinceId) return;
            sinceId = Math.max(sinceId, it.id);
            const msg = it.message || '';
            api.toast(msg || it.title || 'Notification', it.toast_type || 'info', {
              title: it.title || 'Alert',
              duration: 7000,
              cleared: !!(it.is_cleared || it.alert_state === 'cleared'),
            });
          });
          if ((data.max_id || 0) > sinceId) {
            sinceId = data.max_id;
          }
          try { sessionStorage.setItem(storageKey, String(sinceId)); } catch (e3) {}
        } catch (err) {
          // silent — user may lack permission or be offline
        }
      };

      // Delay first poll so page interactive work wins
      setTimeout(tick, 4000);
      setInterval(tick, intervalMs);
    },

    /**
     * Searchable timezone combobox (see includes/timezone_field.php).
     * Call again after injecting new HTML.
     */
    initTimezoneComboboxes: function (root) {
      initTimezoneComboboxes(root || document);
    },

    /**
     * Open/close page modals (id of .modal-overlay / .app-modal / .modal element).
     * Markup: button[data-modal-open="elementId"], [data-modal-close] inside modal.
     */
    openModal: function (id) {
      var el = typeof id === 'string' ? document.getElementById(id) : id;
      if (!el) return;
      el.hidden = false;
      el.removeAttribute('hidden');
      el.style.display = 'flex';
      document.body.classList.add('modal-open');
    },
    closeModal: function (el) {
      if (!el) return;
      if (typeof el === 'string') {
        el = document.getElementById(el);
      }
      if (!el) return;
      el.hidden = true;
      el.setAttribute('hidden', '');
      el.style.display = 'none';
      // Keep body lock if another modal is still open
      var anyOpen = document.querySelector(
        '.modal-overlay:not([hidden]), .app-modal:not([hidden]), .modal:not([hidden])'
      );
      if (!anyOpen) {
        document.body.classList.remove('modal-open');
      }
    },
    /**
     * Animated SNMP poll overlay (device ↔ ColdAisle GET packets).
     * Usage:
     *   ColdAisle.runSnmpPoll({
     *     title: 'Poll PDU', name: 'RA-R1', host: '10.0.0.1',
     *     request: function () { return ColdAisle.api('api/snmp_pdu.php', { method:'POST', body:{...}, timeoutMs: 55000 }); },
     *     onSuccess: function (data) { location.reload(); }
     *   });
     */
    runSnmpPoll: function (opts) {
      opts = opts || {};
      const self = api;
      const title = opts.title || 'SNMP poll';
      const name = opts.name || '';
      const host = opts.host || '';
      const timeoutMs = typeof opts.timeoutMs === 'number' ? opts.timeoutMs : 55000;
      const tips = opts.tips || [
        'Opening SNMP session…',
        'GET sysDescr / identity…',
        'Reading power metrics…',
        'Fetching phase voltages & currents…',
        'Packaging results…',
      ];

      let overlay = document.getElementById('caSnmpPollModal');
      if (!overlay) {
        overlay = document.createElement('div');
        overlay.id = 'caSnmpPollModal';
        overlay.className = 'modal-overlay modal-overlay-glass spoll-overlay';
        overlay.hidden = true;
        overlay.innerHTML =
          '<div class="modal-panel modal-panel-glass spoll-panel" role="dialog" aria-modal="true" aria-labelledby="caSnmpPollTitle">'
          + '  <div class="modal-header">'
          + '    <h2 id="caSnmpPollTitle">SNMP poll</h2>'
          + '    <button type="button" class="modal-close" id="caSnmpPollClose" aria-label="Close" hidden>&times;</button>'
          + '  </div>'
          + '  <div class="modal-body spoll-body">'
          + '    <div class="spoll-anim" id="caSnmpPollAnim" aria-hidden="true">'
          + '      <div class="spoll-node spoll-node-device">'
          + '        <div class="spoll-device">'
          + '          <span class="spoll-led spoll-led-1"></span>'
          + '          <span class="spoll-led spoll-led-2"></span>'
          + '          <span class="spoll-led spoll-led-3"></span>'
          + '          <div class="spoll-antenna"><i></i><i></i><i></i></div>'
          + '        </div>'
          + '        <span class="spoll-node-label" id="caSnmpPollDeviceLabel">Device</span>'
          + '      </div>'
          + '      <div class="spoll-lane" aria-hidden="true">'
          + '        <span class="spoll-pkt spoll-pkt-1">GET</span>'
          + '        <span class="spoll-pkt spoll-pkt-2">.1.3.6</span>'
          + '        <span class="spoll-pkt spoll-pkt-3">kW</span>'
          + '        <span class="spoll-pkt spoll-pkt-4">A</span>'
          + '        <span class="spoll-pkt spoll-pkt-5">V</span>'
          + '      </div>'
          + '      <div class="spoll-node spoll-node-app">'
          + '        <div class="spoll-app">'
          + '          <span class="spoll-app-ring"></span>'
          + '          <span class="spoll-app-core">CA</span>'
          + '        </div>'
          + '        <span class="spoll-node-label">ColdAisle</span>'
          + '      </div>'
          + '    </div>'
          + '    <p class="spoll-status" id="caSnmpPollStatus" aria-live="polite">Polling…</p>'
          + '    <p class="spoll-detail text-muted" id="caSnmpPollDetail"></p>'
          + '  </div>'
          + '  <div class="modal-footer" id="caSnmpPollFooter" hidden>'
          + '    <button type="button" class="btn btn-primary" id="caSnmpPollDone">Close</button>'
          + '  </div>'
          + '</div>';
        document.body.appendChild(overlay);
      }

      const titleEl = document.getElementById('caSnmpPollTitle');
      const statusEl = document.getElementById('caSnmpPollStatus');
      const detailEl = document.getElementById('caSnmpPollDetail');
      const footerEl = document.getElementById('caSnmpPollFooter');
      const closeBtn = document.getElementById('caSnmpPollClose');
      const doneBtn = document.getElementById('caSnmpPollDone');
      const animEl = document.getElementById('caSnmpPollAnim');
      const deviceLabel = document.getElementById('caSnmpPollDeviceLabel');
      const panel = overlay.querySelector('.spoll-panel');

      let tipTimer = null;
      let tipIdx = 0;
      let finished = false;
      let canDismiss = false;

      function setWorkingUi() {
        finished = false;
        canDismiss = false;
        overlay.classList.remove('spoll-state-ok', 'spoll-state-err', 'spoll-state-timeout');
        overlay.classList.add('spoll-state-working');
        if (animEl) animEl.classList.add('spoll-anim-running');
        if (closeBtn) closeBtn.hidden = true;
        if (footerEl) footerEl.hidden = true;
        if (statusEl) statusEl.textContent = tips[0] || 'Polling…';
        if (detailEl) {
          const bits = [];
          if (name) bits.push(name);
          if (host) bits.push(host);
          detailEl.textContent = bits.length ? bits.join(' · ') + ' · UDP/161' : 'UDP/161';
        }
        if (deviceLabel) deviceLabel.textContent = name || host || 'Device';
        if (titleEl) titleEl.textContent = title;
        tipIdx = 0;
        if (tipTimer) clearInterval(tipTimer);
        tipTimer = setInterval(function () {
          if (finished || !statusEl) return;
          tipIdx = (tipIdx + 1) % tips.length;
          statusEl.textContent = tips[tipIdx];
        }, 2200);
      }

      function setDoneUi(kind, message, detail) {
        finished = true;
        canDismiss = true;
        if (tipTimer) { clearInterval(tipTimer); tipTimer = null; }
        overlay.classList.remove('spoll-state-working', 'spoll-state-ok', 'spoll-state-err', 'spoll-state-timeout');
        overlay.classList.add(
          kind === 'ok' ? 'spoll-state-ok'
            : kind === 'timeout' ? 'spoll-state-timeout'
              : 'spoll-state-err'
        );
        if (animEl) animEl.classList.remove('spoll-anim-running');
        if (statusEl) statusEl.textContent = message || (kind === 'ok' ? 'Poll complete' : 'Poll failed');
        if (detailEl && detail) detailEl.textContent = detail;
        if (closeBtn) closeBtn.hidden = false;
        if (footerEl) footerEl.hidden = false;
      }

      function close() {
        if (!canDismiss && !finished) return;
        if (tipTimer) { clearInterval(tipTimer); tipTimer = null; }
        overlay.hidden = true;
        document.body.classList.remove('modal-open');
      }

      function open() {
        setWorkingUi();
        overlay.hidden = false;
        document.body.classList.add('modal-open');
      }

      if (closeBtn && !closeBtn._spollBound) {
        closeBtn._spollBound = true;
        closeBtn.addEventListener('click', close);
      }
      if (doneBtn && !doneBtn._spollBound) {
        doneBtn._spollBound = true;
        doneBtn.addEventListener('click', close);
      }
      if (!overlay._spollBound) {
        overlay._spollBound = true;
        overlay.addEventListener('click', function (e) {
          if (e.target === overlay && canDismiss) close();
        });
        document.addEventListener('keydown', function (e) {
          if (e.key === 'Escape' && overlay && !overlay.hidden && canDismiss) close();
        });
      }
      // Prevent accidental dismiss of panel clicks
      if (panel && !panel._spollBound) {
        panel._spollBound = true;
        panel.addEventListener('click', function (e) { e.stopPropagation(); });
      }

      open();

      const req = typeof opts.request === 'function'
        ? opts.request
        : function () {
          return Promise.reject(new Error('No poll request configured'));
        };

      // Prefer caller's timeoutMs on the request helper; also wrap with our default
      return Promise.resolve()
        .then(function () {
          return req({ timeoutMs: timeoutMs });
        })
        .then(function (data) {
          const msg = (data && data.message) || 'Poll complete';
          setDoneUi('ok', 'Poll complete', msg);
          if (typeof opts.onSuccess === 'function') {
            try { opts.onSuccess(data, { close: close }); } catch (e) { /* ignore */ }
          } else {
            // Default: brief pause so the success state is visible, then reload
            setTimeout(function () {
              if (opts.reload !== false) {
                location.reload();
              } else {
                close();
              }
            }, opts.reloadDelayMs != null ? opts.reloadDelayMs : 900);
          }
          return data;
        })
        .catch(function (err) {
          const timedOut = !!(err && (err.timeout || err.aborted || err.name === 'AbortError'));
          const msg = timedOut
            ? 'SNMP poll timed out'
            : ((err && err.message) || 'Poll failed');
          const detail = timedOut
            ? 'No response within ' + Math.round(timeoutMs / 1000)
              + 's. The agent may be slow, blocked, or offline. You can close this window and try again.'
            : String((err && err.message) || 'Unexpected error');
          setDoneUi(timedOut ? 'timeout' : 'err', msg, detail);
          if (typeof opts.onError === 'function') {
            try { opts.onError(err, { close: close, timedOut: timedOut }); } catch (e) { /* ignore */ }
          }
          // Re-throw only if caller wants — we already handled UI
          return null;
        });
    },

    initModals: function (root) {
      root = root || document;
      root.querySelectorAll('[data-modal-open], [data-ca-modal-open]').forEach(function (btn) {
        if (btn._caModalBound) return;
        btn._caModalBound = true;
        btn.addEventListener('click', function (e) {
          e.preventDefault();
          var id = btn.getAttribute('data-ca-modal-open') || btn.getAttribute('data-modal-open');
          if (id) {
            // Prefer page-local openers (self-contained modals)
            if (typeof window['caOpen_' + id] === 'function') {
              window['caOpen_' + id]();
              return;
            }
            api.openModal(id);
          }
        });
      });
      root.querySelectorAll('[data-modal-close], [data-ca-modal-close]').forEach(function (btn) {
        if (btn._caModalCloseBound) return;
        btn._caModalCloseBound = true;
        btn.addEventListener('click', function (e) {
          e.preventDefault();
          var mid = btn.getAttribute('data-ca-modal-close');
          if (mid && typeof window['caClose_' + mid] === 'function') {
            window['caClose_' + mid]();
            return;
          }
          var modal = btn.closest('.modal-overlay, .app-modal, .modal');
          api.closeModal(modal);
        });
      });
    },
  });

  window.ColdAisle = api;
  // Legacy aliases during rebrand from WinDCIM
  window.WINDCIM = api;
  window.WinDCIM = api;

  // Global modal open/close for data-modal-open / data-modal-close
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      api.initModals();
    });
  } else {
    api.initModals();
  }

  function getTimezoneList() {
    if (Array.isArray(window.ColdAisleTimezoneList) && window.ColdAisleTimezoneList.length) {
      return window.ColdAisleTimezoneList;
    }
    var dataEl = document.getElementById('ca_timezone_data');
    if (dataEl) {
      try {
        var parsed = JSON.parse(dataEl.textContent || '[]');
        if (Array.isArray(parsed) && parsed.length) {
          window.ColdAisleTimezoneList = parsed;
          return parsed;
        }
      } catch (e) { /* ignore */ }
    }
    // Legacy Settings markup (#tz_data)
    dataEl = document.getElementById('tz_data');
    if (dataEl) {
      try {
        var legacy = JSON.parse(dataEl.textContent || '[]');
        if (Array.isArray(legacy) && legacy.length) {
          window.ColdAisleTimezoneList = legacy;
          return legacy;
        }
      } catch (e2) { /* ignore */ }
    }
    return [];
  }

  function bindTimezoneCombobox(box) {
    if (!box || box.getAttribute('data-tz-bound') === '1') return;
    var input = box.querySelector('input[type="text"], input:not([type])');
    var list = box.querySelector('.tz-combobox-list, ul[role="listbox"]');
    if (!input || !list) return;

    var all = getTimezoneList().slice();
    // Ensure current value is in the list for filtering/display
    var cur = (input.value || '').trim();
    if (cur && all.indexOf(cur) === -1) {
      all.unshift(cur);
    }
    if (!all.length) return;

    box.setAttribute('data-tz-bound', '1');
    var active = -1;
    var maxShow = 80;

    function norm(s) {
      return String(s || '').toLowerCase().replace(/_/g, ' ');
    }

    function filter(q) {
      q = norm(q).trim();
      if (!q) return all.slice(0, maxShow);
      var out = [];
      for (var i = 0; i < all.length && out.length < maxShow; i++) {
        var id = all[i];
        var n = norm(id);
        if (n.indexOf(q) !== -1 || id.toLowerCase().indexOf(q) !== -1) {
          out.push(id);
        }
      }
      return out;
    }

    function positionList() {
      // Fixed so the menu is not clipped by modal overflow
      var rect = input.getBoundingClientRect();
      list.style.position = 'fixed';
      list.style.left = Math.max(4, rect.left) + 'px';
      list.style.top = (rect.bottom + 2) + 'px';
      list.style.width = Math.max(rect.width, 12 * 16) + 'px';
      list.style.right = 'auto';
      list.style.zIndex = '4000';
    }

    function render(items) {
      list.innerHTML = '';
      active = -1;
      if (!items.length) {
        var empty = document.createElement('li');
        empty.className = 'tz-empty';
        empty.textContent = 'No matching timezones';
        list.appendChild(empty);
        return;
      }
      items.forEach(function (id, idx) {
        var li = document.createElement('li');
        li.setAttribute('role', 'option');
        li.setAttribute('data-value', id);
        li.textContent = id.replace(/_/g, ' ');
        li.title = id;
        li.addEventListener('mousedown', function (e) {
          e.preventDefault();
          pick(id);
        });
        li.addEventListener('mouseenter', function () {
          setActive(idx);
        });
        list.appendChild(li);
      });
    }

    function openList() {
      positionList();
      list.hidden = false;
      input.setAttribute('aria-expanded', 'true');
    }

    function closeList() {
      list.hidden = true;
      input.setAttribute('aria-expanded', 'false');
      active = -1;
    }

    function setActive(idx) {
      var items = list.querySelectorAll('li[role="option"]');
      items.forEach(function (el, i) {
        el.setAttribute('aria-selected', i === idx ? 'true' : 'false');
      });
      active = idx;
      if (items[idx]) {
        items[idx].scrollIntoView({ block: 'nearest' });
      }
    }

    function pick(id) {
      input.value = id;
      closeList();
      input.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function refresh() {
      render(filter(input.value));
      openList();
      if (list.querySelector('li[role="option"]')) {
        setActive(0);
      }
    }

    input.addEventListener('focus', function () {
      refresh();
    });
    input.addEventListener('input', function () {
      refresh();
    });
    input.addEventListener('keydown', function (e) {
      var items = list.querySelectorAll('li[role="option"]');
      if (e.key === 'ArrowDown') {
        e.preventDefault();
        if (list.hidden) refresh();
        setActive(Math.min(active + 1, items.length - 1));
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        setActive(Math.max(active - 1, 0));
      } else if (e.key === 'Enter') {
        if (!list.hidden && active >= 0 && items[active]) {
          e.preventDefault();
          pick(items[active].getAttribute('data-value'));
        }
      } else if (e.key === 'Escape') {
        closeList();
      }
    });
    input.addEventListener('blur', function () {
      setTimeout(closeList, 150);
    });

    document.addEventListener('click', function (e) {
      if (!box.contains(e.target) && !list.contains(e.target)) closeList();
    });
    window.addEventListener('resize', function () {
      if (!list.hidden) positionList();
    });
    // Reposition while scrolling containers (modals, main content)
    document.addEventListener('scroll', function () {
      if (!list.hidden) positionList();
    }, true);
  }

  function initTimezoneComboboxes(root) {
    root = root || document;
    var nodes = root.querySelectorAll
      ? root.querySelectorAll('[data-tz-combobox], .tz-combobox')
      : [];
    Array.prototype.forEach.call(nodes, bindTimezoneCombobox);
    // Legacy Settings: single #tz_combobox without data attribute
    var legacy = document.getElementById('tz_combobox');
    if (legacy) bindTimezoneCombobox(legacy);
  }

  /**
   * Dev request timer: fill "Browser …" from Navigation Timing.
   * after HTML ≈ responseEnd → loadEventEnd (images, JS, 3D setup).
   */
  function paintDevRequestTimer() {
    var el = document.getElementById('devRequestTimer');
    if (!el) return;
    var browserEl = el.querySelector('.dev-timer-browser');
    if (!browserEl) return;

    var nav = null;
    if (performance.getEntriesByType) {
      var list = performance.getEntriesByType('navigation');
      if (list && list.length) nav = list[0];
    }

    var ttfb = 0;
    var afterHtml = 0;
    var loadMs = 0;
    var dclMs = 0;

    if (nav && typeof nav.responseEnd === 'number') {
      ttfb = Math.max(0, nav.responseStart || 0);
      var respEnd = nav.responseEnd || 0;
      var loadEnd = nav.loadEventEnd > 0 ? nav.loadEventEnd : performance.now();
      var dclEnd = nav.domContentLoadedEventEnd > 0 ? nav.domContentLoadedEventEnd : loadEnd;
      afterHtml = Math.max(0, loadEnd - respEnd);
      loadMs = loadEnd;
      dclMs = dclEnd;
    } else if (performance.timing) {
      var t = performance.timing;
      var navStart = t.navigationStart || 0;
      ttfb = Math.max(0, (t.responseStart || 0) - navStart);
      var respEnd2 = (t.responseEnd || 0) - navStart;
      var loadEnd2 = (t.loadEventEnd || Date.now()) - navStart;
      afterHtml = Math.max(0, loadEnd2 - respEnd2);
      loadMs = loadEnd2;
      dclMs = Math.max(0, (t.domContentLoadedEventEnd || 0) - navStart);
    } else {
      afterHtml = performance.now();
      loadMs = afterHtml;
    }

    function r(n) { return Math.round(n); }

    browserEl.textContent =
      ' · Browser after-HTML ' + r(afterHtml) + 'ms' +
      ' (TTFB ~' + r(ttfb) + 'ms · DCL ' + r(dclMs) + 'ms · load ' + r(loadMs) + 'ms)';

    // Highlight when browser work dominates after the document arrived
    var sql = parseFloat(el.getAttribute('data-sql-ms') || '0') || 0;
    var php = parseFloat(el.getAttribute('data-php-ms') || '0') || 0;
    var server = parseFloat(el.getAttribute('data-total-ms') || '0') || 0;
    el.classList.remove('dev-timer-hot', 'dev-timer-ok');
    if (afterHtml > server * 1.5 && afterHtml > 400) {
      browserEl.classList.add('dev-timer-hot');
    } else if (sql > php && sql > 200) {
      el.querySelector('.dev-timer-server') &&
        el.querySelector('.dev-timer-server').classList.add('dev-timer-hot');
    } else if (server < 200 && afterHtml < 500) {
      el.classList.add('dev-timer-ok');
    }
  }

  /**
   * Settings page: collapsible section cards (default collapsed).
   * Expand all / Collapse all; #hash opens a section; remembers open ids in localStorage.
   */
  function initSettingsCollapsible() {
    var root = document.getElementById('settingsSections');
    if (!root) return;

    var STORAGE_KEY = 'coldaisle.settings.openCards';
    var cards = [];

    function slugify(text) {
      return String(text || '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, 48) || 'section';
    }

    function loadOpenSet() {
      try {
        var raw = localStorage.getItem(STORAGE_KEY);
        if (!raw) return null; // null = no preference yet (use defaults)
        var arr = JSON.parse(raw);
        if (!Array.isArray(arr)) return null;
        var set = Object.create(null);
        arr.forEach(function (id) { set[id] = true; });
        return set;
      } catch (e) {
        return null;
      }
    }

    function saveOpenSet() {
      try {
        var open = cards.filter(function (c) { return c.open; }).map(function (c) { return c.id; });
        localStorage.setItem(STORAGE_KEY, JSON.stringify(open));
      } catch (e) { /* private mode */ }
    }

    function setOpen(card, open, skipSave) {
      card.open = !!open;
      card.el.classList.toggle('is-collapsed', !card.open);
      card.el.classList.toggle('is-expanded', card.open);
      if (card.btn) {
        card.btn.setAttribute('aria-expanded', card.open ? 'true' : 'false');
      }
      if (card.body) {
        card.body.hidden = !card.open;
      }
      if (!skipSave) saveOpenSet();
    }

    function ensureCardId(el, title) {
      if (el.id) return el.id;
      var base = slugify(title);
      var id = base;
      var n = 2;
      while (document.getElementById(id)) {
        id = base + '-' + n;
        n++;
      }
      el.id = id;
      return id;
    }

    root.querySelectorAll(':scope > .card').forEach(function (el) {
      var header = el.querySelector(':scope > .card-header');
      var body = el.querySelector(':scope > .card-body');
      if (!header || !body) return;

      var h2 = header.querySelector('h2');
      var title = h2 ? h2.textContent.trim() : 'Section';
      var id = ensureCardId(el, title);

      // Build accessible toggle control (keep badges / extra header content)
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'settings-card-toggle';
      btn.setAttribute('aria-expanded', 'false');
      btn.setAttribute('aria-controls', id + '-body');
      body.id = body.id || (id + '-body');

      var chevron = document.createElement('span');
      chevron.className = 'settings-card-chevron';
      chevron.setAttribute('aria-hidden', 'true');

      var label = document.createElement('span');
      label.className = 'settings-card-title';
      if (h2) {
        label.appendChild(h2);
      } else {
        label.textContent = title;
      }

      btn.appendChild(chevron);
      btn.appendChild(label);

      // Move remaining header children (badges, etc.) after the toggle
      var extras = document.createElement('div');
      extras.className = 'settings-card-extras';
      while (header.firstChild) {
        extras.appendChild(header.firstChild);
      }
      header.classList.add('settings-card-head');
      header.appendChild(btn);
      if (extras.childNodes.length) {
        header.appendChild(extras);
      }

      var card = { el: el, body: body, btn: btn, id: id, open: false };
      cards.push(card);

      btn.addEventListener('click', function () {
        setOpen(card, !card.open);
      });
    });

    if (!cards.length) return;

    var saved = loadOpenSet();
    var hash = (location.hash || '').replace(/^#/, '');

    cards.forEach(function (card) {
      var shouldOpen = false;
      if (hash && card.id === hash) {
        shouldOpen = true;
      } else if (saved) {
        shouldOpen = !!saved[card.id];
      }
      // Default: all collapsed (saved empty array also stays collapsed)
      setOpen(card, shouldOpen, true);
    });
    saveOpenSet();

    if (hash) {
      var target = document.getElementById(hash);
      if (target && target.classList.contains('card')) {
        // Expand and scroll after layout
        var match = cards.filter(function (c) { return c.id === hash; })[0];
        if (match) setOpen(match, true);
        setTimeout(function () {
          target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }, 50);
      }
    }

    window.addEventListener('hashchange', function () {
      var h = (location.hash || '').replace(/^#/, '');
      if (!h) return;
      cards.forEach(function (card) {
        if (card.id === h) {
          setOpen(card, true);
          card.el.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
      });
    });

    var expandAll = document.getElementById('settingsExpandAll');
    var collapseAll = document.getElementById('settingsCollapseAll');
    if (expandAll) {
      expandAll.addEventListener('click', function () {
        cards.forEach(function (c) { setOpen(c, true, true); });
        saveOpenSet();
      });
    }
    if (collapseAll) {
      collapseAll.addEventListener('click', function () {
        cards.forEach(function (c) { setOpen(c, false, true); });
        saveOpenSet();
      });
    }
  }

  // Sidebar toggle + timezone widgets
  document.addEventListener('DOMContentLoaded', function () {
    const btn = document.getElementById('sidebarToggle');
    const sidebar = document.getElementById('sidebar');
    if (btn) {
      btn.addEventListener('click', function () {
        if (window.innerWidth <= 800 && sidebar) {
          sidebar.classList.toggle('open');
        } else {
          document.body.classList.toggle('sidebar-collapsed');
        }
      });
    }
    initTimezoneComboboxes(document);
    initSettingsCollapsible();
    // First paint of browser metrics at DCL; refine on full load
    paintDevRequestTimer();
  });
  window.addEventListener('load', function () {
    paintDevRequestTimer();
  });
})();
