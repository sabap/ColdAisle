/**
 * WinDCIM — shared front-end helpers
 */
(function () {
  'use strict';

  const csrf = (window.WINDCIM && window.WINDCIM.csrf) ||
    (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
  const baseUrl = (window.WINDCIM && window.WINDCIM.baseUrl) || '';

  window.WinDCIM = Object.assign(window.WINDCIM || {}, {
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
  });

  // Sidebar toggle
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
  });
})();
