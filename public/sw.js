const STATIC_CACHE = 'up-static-v3'
const API_CACHE = 'up-api-v1'
const OFFLINE_URL = '/offline.html'

// Assets to pre-cache on install
const PRECACHE_ASSETS = [
    OFFLINE_URL,
]

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(STATIC_CACHE)
            .then(cache => cache.addAll(PRECACHE_ASSETS))
            .then(() => self.skipWaiting())
    )
})

self.addEventListener('activate', (event) => {
    const validCaches = [STATIC_CACHE, API_CACHE]
    event.waitUntil(
        caches.keys()
            .then(names => Promise.all(
                names
                    .filter(name => !validCaches.includes(name))
                    .map(name => caches.delete(name))
            ))
            .then(() => self.clients.claim())
    )
})

// Network-first with timeout helper
function networkFirstWithTimeout(request, cacheName, timeoutMs = 5000) {
    return new Promise((resolve) => {
        let settled = false

        const timeoutId = setTimeout(() => {
            if (settled) return
            settled = true
            caches.match(request).then(cached => {
                if (cached) {
                    resolve(cached)
                } else {
                    // No cache — let the network promise resolve when it can
                }
            })
        }, timeoutMs)

        fetch(request.clone())
            .then(response => {
                clearTimeout(timeoutId)
                if (!settled) {
                    settled = true
                    if (response.ok) {
                        const clone = response.clone()
                        caches.open(cacheName).then(cache => cache.put(request, clone))
                    }
                    resolve(response)
                } else if (response.ok) {
                    // Timeout already fired but update cache in background
                    const clone = response.clone()
                    caches.open(cacheName).then(cache => cache.put(request, clone))
                }
            })
            .catch(() => {
                clearTimeout(timeoutId)
                if (!settled) {
                    settled = true
                    caches.match(request).then(cached => {
                        if (cached) {
                            resolve(cached)
                        } else {
                            resolve(new Response('Network error', { status: 503 }))
                        }
                    })
                }
            })
    })
}

self.addEventListener('fetch', (event) => {
    if (event.request.method !== 'GET') return

    const url = new URL(event.request.url)
    if (url.origin !== location.origin) return

    // Static assets — cache-first
    if (/\.(css|js|png|jpg|jpeg|gif|svg|woff2?|ttf|ico)$/i.test(url.pathname) || url.pathname.startsWith('/build/')) {
        event.respondWith(
            caches.match(event.request).then(cached =>
                cached || fetch(event.request).then(response => {
                    if (response.ok) {
                        const clone = response.clone()
                        caches.open(STATIC_CACHE).then(cache => cache.put(event.request, clone))
                    }
                    return response
                })
            )
        )
        return
    }

    // Inertia requests — network-first with 5s timeout, cache fallback
    if (event.request.headers.get('X-Inertia')) {
        event.respondWith(networkFirstWithTimeout(event.request, API_CACHE, 5000))
        return
    }

    // API requests — network-first with 5s timeout, cache fallback
    if (url.pathname.startsWith('/api/')) {
        event.respondWith(networkFirstWithTimeout(event.request, API_CACHE, 5000))
        return
    }

    // Navigation requests (HTML) — network-first, fallback to cached, ultimate fallback to offline page
    if (event.request.mode === 'navigate') {
        event.respondWith(
            fetch(event.request)
                .then(response => {
                    if (response.ok) {
                        const clone = response.clone()
                        caches.open(API_CACHE).then(cache => cache.put(event.request, clone))
                    }
                    return response
                })
                .catch(() =>
                    caches.match(event.request).then(cached =>
                        cached || caches.match(OFFLINE_URL)
                    )
                )
        )
        return
    }
})

// Push notifications
self.addEventListener('push', (event) => {
    let data = { title: 'Up', body: 'New notification', data: {} }
    if (event.data) {
        try { data = { ...data, ...event.data.json() } } catch (e) { data.body = event.data.text() }
    }
    event.waitUntil(
        self.registration.showNotification(data.title, {
            body: data.body,
            icon: '/icons/icon-192.png',
            tag: 'up-notification',
            data: data.data,
            vibrate: [100, 50, 100],
        })
    )
})

self.addEventListener('notificationclick', (event) => {
    event.notification.close()
    const url = event.notification.data?.url || '/monitors'
    event.waitUntil(
        clients.matchAll({ type: 'window' }).then(wins => {
            for (const win of wins) {
                if (new URL(win.url).origin === location.origin) {
                    win.focus()
                    return win.navigate(url)
                }
            }
            return clients.openWindow(url)
        })
    )
})
