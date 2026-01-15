// sw.js — estável p/ subpasta /farmafixed
const VERSION = 'ff-v4';

self.addEventListener('install', (e) => e.waitUntil(self.skipWaiting()));
self.addEventListener('activate', (e) => {
  e.waitUntil(
    caches.keys().then(keys =>
      Promise.all(keys.filter(k => k !== VERSION).map(k => caches.delete(k)))
    ).then(() => self.clients.claim())
  );
});

// Network-first para GET same-origin dentro do escopo /farmafixed
self.addEventListener('fetch', (event) => {
  const req = event.request;
  const url = new URL(req.url);

  // Apenas GET + mesma origem
  if (req.method !== 'GET' || url.origin !== self.location.origin) return;

  event.respondWith(
    caches.open(VERSION).then(async (cache) => {
      try {
        const net = await fetch(req);
        cache.put(req, net.clone()).catch(()=>{});
        return net;
      } catch (_) {
        const cached = await cache.match(req);
        return cached || Response.error();
      }
    })
  );
});
