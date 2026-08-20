/* ColdAisle field service worker — network-first HTML, cache shell + last cabinet view. */
/* eslint-disable no-restricted-globals */
const CACHE = 'coldaisle-field-v2';
const SHELL = [
  'assets/css/app.css',
  'assets/css/tech.css',
  'assets/js/app.js',
  'assets/img/logo.svg',
  'assets/img/favicon.svg',
  'pages/tech.php',
];

self.addEventListener('install', function (event) {
  event.waitUntil(
    caches.open(CACHE).then(function (cache) {
      return cache.addAll(SHELL.map(function (p) {
        return new Request(p, { credentials: 'same-origin' });
      })).catch(function () { /* partial shell is fine */ });
    }).then(function () { return self.skipWaiting(); })
  );
});

self.addEventListener('activate', function (event) {
  event.waitUntil(
    caches.keys().then(function (keys) {
      return Promise.all(keys.filter(function (k) { return k !== CACHE; }).map(function (k) {
        return caches.delete(k);
      }));
    }).then(function () { return self.clients.claim(); })
  );
});

function isApi(url) {
  return /\/api\//.test(url.pathname);
}
function isMedia(url) {
  return /\/media\.php$/.test(url.pathname);
}
function isAuthPage(url) {
  return /\/(login|logout|setup)\.php$/.test(url.pathname);
}
function isSettingsPage(url) {
  return /\/pages\/settings\.php$/i.test(url.pathname);
}

self.addEventListener('fetch', function (event) {
  const req = event.request;
  if (req.method !== 'GET') {
    return;
  }
  let url;
  try { url = new URL(req.url); } catch (e) { return; }
  if (url.origin !== self.location.origin) {
    return;
  }
  if (isAuthPage(url) || isApi(url) || isSettingsPage(url)) {
    return;
  }

  if (isMedia(url) || /\.(css|js|svg|png|woff2)$/i.test(url.pathname)) {
    event.respondWith(
      caches.match(req).then(function (hit) {
        const fetchP = fetch(req).then(function (res) {
          if (res && res.ok) {
            const copy = res.clone();
            caches.open(CACHE).then(function (c) { c.put(req, copy); });
          }
          return res;
        });
        return hit || fetchP;
      })
    );
    return;
  }

  // HTML / PHP pages: network first, cache last good copy (cabinet elevations, tech hub)
  event.respondWith(
    fetch(req).then(function (res) {
      if (res && res.ok && (req.mode === 'navigate' || /text\/html/.test(res.headers.get('content-type') || ''))) {
        const copy = res.clone();
        caches.open(CACHE).then(function (c) { c.put(req, copy); });
      }
      return res;
    }).catch(function () {
      return caches.match(req).then(function (hit) {
        return hit || caches.match('pages/tech.php') || new Response(
          '<!DOCTYPE html><html><body style="font-family:system-ui;background:#0f172a;color:#e2e8f0;padding:2rem">'
          + '<h1>Offline</h1><p>ColdAisle cannot reach the server. Open a cabinet you viewed recently — it may still be cached.</p></body></html>',
          { headers: { 'Content-Type': 'text/html; charset=utf-8' } }
        );
      });
    })
  );
});
