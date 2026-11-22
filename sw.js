/* Service Worker mínimo — PWA offline shell */
const CACHE = "auditb1-v6";
const ASSETS = [
  "./",
  "./index.html",
  "./manifest.webmanifest",
  "./webapp/css/style.css",
  "./webapp/img/logo.png",
  "./webapp/img/favicon.png"
];

self.addEventListener("install", (event) => {
  event.waitUntil(
    caches.open(CACHE).then((cache) => cache.addAll(ASSETS)).then(() => self.skipWaiting())
  );
});

self.addEventListener("activate", (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)))
    ).then(() => self.clients.claim())
  );
});

self.addEventListener("fetch", (event) => {
  const url = new URL(event.request.url);
  // No cachear API ni uploads
  if (url.pathname.indexOf("/api/") !== -1 || url.pathname.indexOf("/uploads/") !== -1) {
    return;
  }
  if (event.request.method !== "GET") {
    return;
  }
  // Network-first para no servir vistas/JS viejos tras un deploy
  event.respondWith(
    fetch(event.request)
      .then((res) => {
        if (res && res.ok && url.origin === self.location.origin) {
          const clone = res.clone();
          caches.open(CACHE).then((cache) => cache.put(event.request, clone));
        }
        return res;
      })
      .catch(() => caches.match(event.request))
  );
});
