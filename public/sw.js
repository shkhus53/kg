// KG Attendance service worker.
//
// Scope is still narrow: this app writes attendance state to a server
// database on every action, and every screen shows live counters
// (Scheduled/Present/Pending/Extra) that must never be served stale. So:
//
//   - Navigations (HTML pages) and any non-GET request: NEVER served from
//     cache when the network succeeds. Authenticated, server-rendered pages
//     are never treated as offline-authoritative data.
//   - Only same-origin, versioned build assets under /build/ (hashed
//     filenames from Vite) and the icon files are cache-first, since a
//     hashed filename changes whenever its content changes.
//
// Phase 2 addition: real offline attendance now exists, backed entirely by
// IndexedDB (see resources/js/offline.js), not by caching pages. The one
// thing this worker adds is a navigation FALLBACK: if a page navigation
// fails outright (genuinely offline, browser/app just restarted, no prior
// in-memory app state), we serve one static, non-authenticated,
// non-session-specific shell (/offline.html) instead of the browser's bare
// "No internet" error — that shell reads the same IndexedDB data and lets
// the operator keep working. It is never a cached copy of a real page.

const STATIC_CACHE = 'kg-static-v3';

const CACHEABLE_PREFIXES = ['/build/', '/icons/'];
const OFFLINE_FALLBACK = '/offline.html';

function isCacheable(url) {
    if (url.origin !== self.location.origin) {
        return false;
    }

    return CACHEABLE_PREFIXES.some((prefix) => url.pathname.startsWith(prefix));
}

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(STATIC_CACHE).then((cache) => cache.add(OFFLINE_FALLBACK)));
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

    if (request.mode === 'navigate') {
        // Try the network first — a real page load always wins when
        // reachable. Only on outright network failure (truly offline) do we
        // fall back, and only to the static generic shell, never to a
        // cached copy of the real page.
        event.respondWith(
            fetch(request).catch(() => caches.match(OFFLINE_FALLBACK))
        );
        return;
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
