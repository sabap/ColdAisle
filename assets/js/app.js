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
        const err = new Error((data && data.error) || res.statusText || 'Request failed');
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
  });

  window.ColdAisle = api;
  // Legacy aliases during rebrand from WinDCIM
  window.WINDCIM = api;
  window.WinDCIM = api;

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
  });
})();
