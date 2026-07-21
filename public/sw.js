// Temari service worker: push notifications, plus an offline fallback page.
//
// It caches exactly ONE thing — the standalone /offline.html — and nothing else.
// In particular it does NOT precache the JS/CSS bundle: those are content-hashed
// and already served `Cache-Control: public, max-age=31536000, immutable`
// (docker/Caddyfile), so the HTTP cache covers a returning user with no network
// requests at all. Duplicating that in a second cache would buy nothing and add
// a way to serve a stale bundle after a deploy.
//
// It also does NOT cache navigations or Inertia responses. The HTML document
// carries a per-request CSRF token and fresh page props, and the app is
// all-dynamic, so a cached page would be actively wrong. Navigation is strictly
// network-first: the cached page is only ever reached when the network fails,
// which is also why nothing here can go stale.

// Bump this whenever /offline.html changes. `install` only re-fetches the page
// when the browser sees a byte-different sw.js, and `activate` deletes every
// cache whose key is not this one — so a new key is what actually evicts the
// stale copy from installed apps. Left at v1, the status-bar fix below would
// never reach anyone who already had the app installed.
const OFFLINE_CACHE = 'temari-offline-v2';
const OFFLINE_URL = '/offline.html';

self.addEventListener('install', (event) => {
    // `reload` so an install never re-uses an HTTP-cached copy of the page.
    event.waitUntil(
        caches
            .open(OFFLINE_CACHE)
            .then((cache) => cache.add(new Request(OFFLINE_URL, { cache: 'reload' })))
            // A failed pre-cache must not abort the install: push notifications
            // are the more important job and should still work.
            .catch(() => undefined)
            .then(() => self.skipWaiting()),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches
            .keys()
            .then((keys) => Promise.all(keys.filter((key) => key !== OFFLINE_CACHE).map((key) => caches.delete(key))))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    // Navigations only. Assets and XHR/fetch are left entirely alone so the
    // browser's own caching and Inertia's requests behave exactly as before.
    if (event.request.mode !== 'navigate') {
        return;
    }

    event.respondWith(
        fetch(event.request).catch(async () => {
            const cached = await caches.match(OFFLINE_URL);

            // If even the offline page is missing, fall through to the browser's
            // own error rather than returning a confusing empty response.
            return cached ?? Response.error();
        }),
    );
});

self.addEventListener('push', (event) => {
    if (!event.data) {
        return;
    }

    let payload;
    try {
        payload = event.data.json();
    } catch {
        payload = { title: 'Temari', body: event.data.text() };
    }

    event.waitUntil(
        self.registration.showNotification(payload.title || 'Temari', {
            body: payload.body || '',
            icon: payload.icon || '/icon-192.png',
            badge: '/icon-192.png',
            data: payload.data || {},
        }),
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    // Same-origin guard: only ever navigate to an app URL, never an off-origin or
    // non-http target a future/hostile payload might carry.
    let url = '/';
    try {
        const resolved = new URL(event.notification.data?.url || '/', self.location.origin);
        if (resolved.origin === self.location.origin) {
            url = resolved.href;
        }
    } catch {
        url = '/';
    }

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windowClients) => {
            for (const client of windowClients) {
                if (new URL(client.url).origin === self.location.origin && 'focus' in client) {
                    return client.navigate(url).then(() => client.focus());
                }
            }
            return self.clients.openWindow(url);
        }),
    );
});
