const MONGOYIA_PWA_CACHE = 'mongoyia-pwa-shell-v1';
const MONGOYIA_PWA_ASSETS = [
  '/',
  '/manifest.webmanifest',
  '/pwa-offline.html',
  '/pwa-icon.svg',
  '/pwa-maskable.svg'
];

self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(MONGOYIA_PWA_CACHE)
      .then(cache => cache.addAll(MONGOYIA_PWA_ASSETS))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys()
      .then(keys => Promise.all(keys
        .filter(key => key !== MONGOYIA_PWA_CACHE)
        .map(key => caches.delete(key))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', event => {
  const request = event.request;
  if (request.method !== 'GET') {
    return;
  }

  const url = new URL(request.url);
  if (url.origin !== self.location.origin) {
    return;
  }

  if (request.mode === 'navigate') {
    event.respondWith(
      fetch(request)
        .then(response => {
          const copy = response.clone();
          caches.open(MONGOYIA_PWA_CACHE).then(cache => cache.put(request, copy));
          return response;
        })
        .catch(() => caches.match(request).then(cached => cached || caches.match('/pwa-offline.html')))
    );
    return;
  }

  event.respondWith(
    caches.match(request)
      .then(cached => cached || fetch(request).then(response => {
        if (!response || response.status !== 200 || response.type !== 'basic') {
          return response;
        }
        const copy = response.clone();
        caches.open(MONGOYIA_PWA_CACHE).then(cache => cache.put(request, copy));
        return response;
      }))
  );
});
