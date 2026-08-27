
const staticDevCoffee = "dev-coffee-site-v1"
const assets = [];

self.addEventListener("install", installEvent => {

    installEvent.waitUntil(
        caches.open(staticDevCoffee).then(cache => {
            cache.addAll(assets)
        })
    )
})

self.addEventListener("fetch", fetchEvent => {
    if (fetchEvent.request.method !== "GET" || fetchEvent.request.mode === "no-cors") {
        return; // Let the browser handle it
    }
    fetchEvent.respondWith(
        caches.match(fetchEvent.request).then(res => {
            return res || fetch(fetchEvent.request)
        })
    )
})



