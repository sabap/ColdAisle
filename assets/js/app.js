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

      if (opts.body && typeof opts.body === 'object' && !(opts.body instanceof FormData)) {
        opts.body = JSON.stringify(opts.body);
      }

      const url = path.startsWith('http') ? path : (baseUrl.replace(/\/$/, '') + '/' + path.replace(/^\//, ''));
      const res = await fetch(url, opts);
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
          msg += ' — check storage/logs/app.log and snmp_discover_last.txt on the server';
        }
        const err = new Error(msg);
        err.status = res.status;
        err.data = data;
        throw err;
      }
      return data;
    },

    toast: function (message, type) {
      type = type || 'info';
      let host = document.getElementById('toast-host');
      if (!host) {
        host = document.createElement('div');
        host.id = 'toast-host';
        host.style.cssText = 'position:fixed;right:1rem;bottom:1rem;z-index:9999;display:flex;flex-direction:column;gap:.5rem;';
        document.body.appendChild(host);
      }
      const el = document.createElement('div');
      el.className = 'alert alert-' + type;
      el.style.minWidth = '220px';
      el.textContent = message;
      host.appendChild(el);
      setTimeout(() => el.remove(), 3500);
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
      // Keep body lock if another modal is still open
      var anyOpen = document.querySelector(
        '.modal-overlay:not([hidden]), .app-modal:not([hidden]), .modal:not([hidden])'
      );
      if (!anyOpen) {
        document.body.classList.remove('modal-open');
      }
    },
    initModals: function (root) {
      root = root || document;
      root.querySelectorAll('[data-modal-open]').forEach(function (btn) {
        if (btn._caModalBound) return;
        btn._caModalBound = true;
        btn.addEventListener('click', function (e) {
          e.preventDefault();
          var id = btn.getAttribute('data-modal-open');
          if (id) {
            api.openModal(id);
          }
        });
      });
      root.querySelectorAll('[data-modal-close]').forEach(function (btn) {
        if (btn._caModalCloseBound) return;
        btn._caModalCloseBound = true;
        btn.addEventListener('click', function (e) {
          e.preventDefault();
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
