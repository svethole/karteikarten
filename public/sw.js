// Service Worker: Oberfläche offline verfügbar machen. Die Daten (Lernpakete, Bewertungen) liegen in IndexedDB (app/api.js).
// Strategie: Oberfläche „erst Netz, dann Zwischenspeicher“ (Änderungen kommen sofort an), Bilder/Töne „erst Zwischenspeicher“.
const VERSION = "kk-1";
const OBERFLAECHE = [
  "./", "index.html", "style.css", "manifest.webmanifest", "icons/icon.svg", "icons/icon-192.png",
  "app/main.js", "app/api.js", "app/ui.js", "app/fsrs.js", "app/md.js", "app/medien.js", "app/karte.js", "app/bildwahl.js", "app/notizdialog.js",
  "app/ansichten/uebersicht.js", "app/ansichten/projekt.js", "app/ansichten/projekt-bearbeiten.js", "app/ansichten/lernen.js",
  "app/ansichten/notiz.js", "app/ansichten/designer.js", "app/ansichten/statistik.js", "app/ansichten/einstellungen.js",
  "schriften/inter-latin-wght-normal.woff2", "schriften/inter-latin-ext-wght-normal.woff2",
  "schriften/fraunces-latin-wght-normal.woff2", "schriften/fraunces-latin-ext-wght-normal.woff2",
];

self.addEventListener("install", (ev) => {
  ev.waitUntil(caches.open(VERSION).then((c) => c.addAll(OBERFLAECHE)).then(() => self.skipWaiting()));
});

self.addEventListener("activate", (ev) => {
  ev.waitUntil(caches.keys().then((k) => Promise.all(k.filter((x) => x !== VERSION && x !== "kk-medien").map((x) => caches.delete(x)))).then(() => self.clients.claim()));
});

self.addEventListener("fetch", (ev) => {
  const r = ev.request;
  if (r.method !== "GET") return;
  const u = new URL(r.url);
  if (u.origin !== location.origin) return;
  // Bilder, Töne, Sprachausgabe: erst Zwischenspeicher (ändern sich nicht)
  if (u.pathname.endsWith("api.php") && /^(medien|quelle|tts)$/.test(u.searchParams.get("r") || "")) {
    ev.respondWith(caches.open("kk-medien").then(async (c) => {
      const treffer = await c.match(r);
      if (treffer) return treffer;
      const antwort = await fetch(r);
      if (antwort.ok && antwort.status === 200) c.put(r, antwort.clone());
      return antwort;
    }));
    return;
  }
  if (u.pathname.endsWith("api.php")) return; // übrige API: immer Netz (Ersatz regelt app/api.js)
  ev.respondWith(fetch(r).then((antwort) => {
    if (antwort.ok && !antwort.redirected) caches.open(VERSION).then((c) => c.put(r, antwort.clone()));
    return antwort;
  }).catch(() => caches.match(r).then((x) => x || caches.match("index.html"))));
});
