// Temari service worker — push notifications only. Deliberately no `fetch`/cache
// handler: the app is all-dynamic (Inertia), so caching pages would serve stale
// data and fight the no-prefetch stance. Its one job is to turn a pushed payload
// into a native notification and route the tap.

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
