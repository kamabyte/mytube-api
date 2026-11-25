const CACHE_PREFIX = 'mytube-offline-';
const CACHE_NAME = `${CACHE_PREFIX}v9`;
const OFFLINE_PAGE = '/offline.html';
const DEVICE_LIBRARY = '/on-device';
const PWA_ASSETS = [
    '/site.webmanifest',
    '/icons/icon-192.png',
    '/icons/icon-512.png',
    '/apple-touch-icon.png',
    '/favicon.ico',
];
const BUILD_ASSETS = '/build/assets/';
const BUILD_MANIFEST = '/build/manifest.json';
const DEVICE_ENTRY = 'resources/js/offline-app.tsx';
const NAVIGATION_TIMEOUT = 4000;

async function cacheDeviceLibrary(cache, response) {
    if (!response.ok) throw new Error('Offline library is unavailable');

    const build = await fetch(BUILD_MANIFEST, { cache: 'reload' });
    if (!build.ok) throw new Error('Build manifest is unavailable');
    const [html, manifest] = await Promise.all([response.clone().text(), build.json()]);
    const assets = new Set();
    for (const [, value] of html.matchAll(/\b(?:src|href)="([^"]+)"/g)) {
        const asset = new URL(value, self.location.origin);
        // Laravel may render absolute HTTP asset URLs behind the HTTPS proxy.
        if (asset.host === self.location.host && asset.pathname.startsWith(BUILD_ASSETS)) {
            assets.add(asset.pathname + asset.search);
        }
    }

    const entry = manifest[DEVICE_ENTRY];
    if (!entry || !assets.has(`/build/${entry.file}`)) throw new Error('Offline library does not match the build manifest');

    const visited = new Set();
    function includeChunk(name) {
        if (visited.has(name)) return;
        visited.add(name);
        const chunk = manifest[name];
        for (const file of [chunk.file, ...(chunk.css ?? []), ...(chunk.assets ?? [])]) {
            assets.add(`/build/${file}`);
        }
        for (const dependency of [...(chunk.imports ?? []), ...(chunk.dynamicImports ?? [])]) {
            includeChunk(dependency);
        }
    }
    includeChunk(DEVICE_ENTRY);
    includeChunk('resources/css/app.css');

    const missing = await Promise.all([...assets].map(async (asset) => await cache.match(asset) ? null : asset));
    await cache.addAll(missing.filter(Boolean));
    await cache.put(DEVICE_LIBRARY, response);
}

self.addEventListener('install', (event) => {
    event.waitUntil((async () => {
        const cache = await caches.open(CACHE_NAME);
        await cache.addAll([OFFLINE_PAGE, ...PWA_ASSETS].map((path) => new Request(path, { cache: 'reload' })));
        await cacheDeviceLibrary(cache, await fetch(DEVICE_LIBRARY, { cache: 'reload' }));
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
    if (request.method !== 'GET' || url.origin !== self.location.origin) return;

    if (url.pathname.startsWith(BUILD_ASSETS) || PWA_ASSETS.includes(url.pathname)) {
        event.respondWith((async () => (await caches.match(request)) ?? fetch(request))());
        return;
    }

    if (request.mode !== 'navigate' || url.pathname.startsWith('/api/')) return;

    event.respondWith((async () => {
        const cache = await caches.open(CACHE_NAME);
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), NAVIGATION_TIMEOUT);
        let response;
        let reachable = false;
        try {
            response = await fetch(request, { signal: controller.signal });
            // A proxy can reject requests from outside the home network. Preserve
            // ordinary authorization errors from the app if the health check succeeds.
            if (response.status === 403) {
                const health = await fetch('/up', {
                    method: 'HEAD', cache: 'no-store', redirect: 'error', signal: controller.signal,
                });
                reachable = health.ok;
            } else {
                reachable = ![502, 503, 504].includes(response.status);
            }
        } catch {
            // The backend can be unreachable even while the device is online.
        } finally {
            clearTimeout(timeout);
        }

        if (reachable) {
            if (url.pathname === DEVICE_LIBRARY && response.ok) {
                try {
                    await cacheDeviceLibrary(cache, response.clone());
                } catch {
                    // A rebuild may invalidate its assets. Keep the previous complete shell.
                }
            }
            return response;
        }

        return (await cache.match(DEVICE_LIBRARY)) ?? (await cache.match(OFFLINE_PAGE)) ?? response ?? Response.error();
    })());
});
