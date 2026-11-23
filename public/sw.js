// Service worker: rýchle načítanie aplikácie. Dáta (api.php) sa nikdy necachujú.
const CACHE = 'dm-shell-v1.0.0';
const SHELL = ['./', 'index.html', 'assets/app.css?v=1.0.0', 'assets/app.js?v=1.0.0',
  'assets/icons/icon.svg', 'assets/icons/icon-192.png', 'manifest.webmanifest'];

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(CACHE).then((c) => c.addAll(SHELL)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (e) => {
  e.waitUntil(caches.keys()
    .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
    .then(() => self.clients.claim()));
});

self.addEventListener('fetch', (e) => {
  const url = new URL(e.request.url);
  if (e.request.method !== 'GET' || url.origin !== location.origin) return;
  if (url.pathname.endsWith('api.php') || url.pathname.endsWith('install.php')) return;
  // Sieť najprv, pri výpadku cache (aplikácia sa aspoň zobrazí).
  e.respondWith(
    fetch(e.request)
      .then((res) => {
        if (res.ok) {
          const copy = res.clone();
          caches.open(CACHE).then((c) => c.put(e.request, copy));
        }
        return res;
      })
      .catch(() => caches.match(e.request).then((r) => r || caches.match('index.html')))
  );
});
