// Blood Arena — Service Worker
// PWA offline caching + push notification support

const CACHE_NAME = 'blood-arena-v1';

const APP_SHELL = [
    '/',
    '/?manifest=1',
    '/?badge_icon=1',
    '/assets/icon.png',
    '/assets/logo.png',
    '/assets/logo1.png'
];

// ── Install: pre-cache app shell ─────────────────────────
self.addEventListener('install', function(event) {
    event.waitUntil(
        caches.open(CACHE_NAME).then(function(cache) {
            return cache.addAll(APP_SHELL);
        })
    );
});

// ── Activate: remove old caches ──────────────────────────
self.addEventListener('activate', function(event) {
    event.waitUntil(
        caches.keys().then(function(keys) {
            return Promise.all(
                keys.filter(function(key) { return key !== CACHE_NAME; })
                    .map(function(key) { return caches.delete(key); })
            );
        }).then(function() {
            return self.clients.claim();
        })
    );
});

// ── Fetch: cache-first for assets, network-first for pages ─
self.addEventListener('fetch', function(event) {
    var req = event.request;

    // Only handle GET requests
    if (req.method !== 'GET') return;

    var url = new URL(req.url);

    // Static assets — cache first, fallback to network
    if (url.pathname.startsWith('/assets/') ||
        url.pathname === '/sw.js' ||
        (url.pathname === '/' && (url.search === '?manifest=1' || url.search === '?badge_icon=1'))) {
        event.respondWith(
            caches.match(req).then(function(cached) {
                return cached || fetch(req).then(function(response) {
                    if (response && response.status === 200 && response.type !== 'opaque') {
                        var clone = response.clone();
                        caches.open(CACHE_NAME).then(function(cache) { cache.put(req, clone); });
                    }
                    return response;
                });
            })
        );
        return;
    }

    // Main page — network first, fallback to cache
    if (url.pathname === '/' || url.pathname === '/index.php') {
        event.respondWith(
            fetch(req).then(function(response) {
                if (response && response.status === 200) {
                    var clone = response.clone();
                    caches.open(CACHE_NAME).then(function(cache) { cache.put(req, clone); });
                }
                return response;
            }).catch(function() {
                return caches.match('/');
            })
        );
        return;
    }
});

// ── Message: handle SKIP_WAITING from page ───────────────
self.addEventListener('message', function(event) {
    if (event.data && event.data.type === 'SKIP_WAITING') {
        self.skipWaiting();
    }
});

// ── Push: show notification ───────────────────────────────
self.addEventListener('push', function(event) {
    var data = {};
    if (event.data) {
        try { data = event.data.json(); } catch(e) { data = { title: 'Blood Arena', body: event.data.text() }; }
    }
    var title   = data.title || '🩸 Blood Arena';
    var options = {
        body:             data.body    || 'নতুন Emergency Blood Request!',
        icon:             data.icon    || '/?badge_icon=1',
        badge:            data.badge   || '/?badge_icon=1',
        tag:              data.tag     || 'blood-arena-push',
        vibrate:          [100, 50, 100],
        requireInteraction: false,
        data:             { url: data.url || '/' }
    };
    event.waitUntil(self.registration.showNotification(title, options));
});

// ── Notification click: open app ─────────────────────────
self.addEventListener('notificationclick', function(event) {
    event.notification.close();
    var target = (event.notification.data && event.notification.data.url) ? event.notification.data.url : '/';
    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function(clients) {
            for (var i = 0; i < clients.length; i++) {
                var c = clients[i];
                if (c.url.indexOf(self.location.origin) === 0 && 'focus' in c) {
                    return c.focus();
                }
            }
            if (self.clients.openWindow) {
                return self.clients.openWindow(target);
            }
        })
    );
});
