const CACHE_PREFIX = 'mytube-offline-';
const CACHE_NAME = `${CACHE_PREFIX}v1`;
const OFFLINE_PAGE = '/offline.html';

self.addEventListener('install', (event) => {
    event.waitUntil((async () => {
        const cache = await caches.open(CACHE_NAME);
        await cache.add(new Request(OFFLINE_PAGE, { cache: 'reload' }));
        await self.skipWaiting();
    })());
});

self.addEventListener('activate', (event) => {
    event.waitUntil((async () => {
        const keys = await caches.keys();
        await Promise.all(keys
            .filter((key) => key.startsWith(CACHE_PREFIX) && key !== CACHE_NAME)
            .map((key) => caches.delete(key)));
        await self.clients.claim();
    })());
});

self.addEventListener('fetch', (event) => {
    const { request } = event;
    const url = new URL(request.url);
    if (request.method !== 'GET' || request.mode !== 'navigate' ||
        url.origin !== self.location.origin || url.pathname.startsWith('/api/')) {
        return;
    }

    event.respondWith((async () => {
        let response;
        try {
            response = await fetch(request);
            if (![502, 503, 504].includes(response.status)) return response;
        } catch {
            // The backend can be unreachable even while the device is online.
        }

        const cache = await caches.open(CACHE_NAME);
        return (await cache.match(OFFLINE_PAGE)) ?? response ?? Response.error();
    })());
});
