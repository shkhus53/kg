// KG Attendance service worker.
//
// Scope is intentionally narrow: this app writes attendance state to a
// server database on every action, and every screen shows live counters
// (Scheduled/Present/Pending/Extra) that must never be served stale. So:
//
//   - Navigations (HTML pages) and any non-GET request: NEVER cached.
//     Always go to the network. If the network fails, the browser's own
//     offline error is shown — we do not fabricate an offline attendance
//     flow (there isn't one; see Phase 10 spec, offline attendance is
//     explicitly out of scope).
//   - Only same-origin, versioned build assets under /build/ (hashed
//     filenames from Vite) and the icon files are cache-first, since a
//     hashed filename changes whenever its content changes.
//
// This exists purely to satisfy PWA installability (a registered service
// worker with a fetch handler); it is not an offline-attendance system.

const STATIC_CACHE = 'kg-static-v1';

const CACHEABLE_PREFIXES = ['/build/', '/icons/'];

function isCacheable(url) {
    if (url.origin !== self.location.origin) {
        return false;
    }

    return CACHEABLE_PREFIXES.some((prefix) => url.pathname.startsWith(prefix));
}

self.addEventListener('install', (event) => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) =>
            Promise.all(keys.filter((key) => key !== STATIC_CACHE).map((key) => caches.delete(key)))
        )
    );
    self.clients.claim();
});

self.addEventListener('fetch', (event) => {
    const request = event.request;

    if (request.method !== 'GET') {
        return; // never intercept mutations (attendance marks, session close, imports, etc.)
    }

    const url = new URL(request.url);

    if (!isCacheable(url)) {
        return; // let the browser handle it: network-only, nothing cached, nothing stale
    }

    event.respondWith(
        caches.open(STATIC_CACHE).then((cache) =>
            cache.match(request).then((cached) => {
                if (cached) {
                    return cached;
                }

                return fetch(request).then((response) => {
                    if (response.ok) {
                        cache.put(request, response.clone());
                    }

                    return response;
                });
            })
        )
    );
});
