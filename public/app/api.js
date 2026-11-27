// Verbindung zum Server, dazu der Offline-Speicher (IndexedDB):
//  - Lernpakete werden nach jedem Laden abgelegt und dienen ohne Netz als Ersatz.
//  - Bewertungen gehen zuerst in die Warteschlange und werden gebündelt gesendet (jede hat eine cid,
//    doppelt Gesendetes speichert der Server nur einmal).

const BASIS = "api.php";
let zustandHoerer = [];
export let online = navigator.onLine;

export function beiNetzwechsel(fn) {
  zustandHoerer.push(fn);
}
function netz(w) {
  if (online === w) return;
  online = w;
  zustandHoerer.forEach((f) => f(w));
  if (w) warteschlangeSenden();
}
addEventListener("online", () => netz(true));
addEventListener("offline", () => netz(false));

export class ApiFehler extends Error {
  constructor(text, status) {
    super(text);
    this.status = status;
  }
}

export async function holen(route, params = {}) {
  const q = new URLSearchParams({ r: route, ...params });
  let r;
  try {
    r = await fetch(`${BASIS}?${q}`, { credentials: "same-origin", headers: { Accept: "application/json" } });
  } catch (err) {
    netz(false);
    throw new ApiFehler("Keine Verbindung zum Server.", 0);
  }
  netz(true);
  return antwort(r);
}

export async function senden(route, daten = {}) {
  let r;
  try {
    r = await fetch(`${BASIS}?r=${encodeURIComponent(route)}`, {
      method: "POST",
      credentials: "same-origin",
      headers: { "Content-Type": "application/json", "X-Karten": "1", Accept: "application/json" },
      body: JSON.stringify(daten),
    });
  } catch (err) {
    netz(false);
    throw new ApiFehler("Keine Verbindung zum Server.", 0);
  }
  netz(true);
  return antwort(r);
}

export async function hochladen(datei, nachweis = "") {
  const fd = new FormData();
  fd.append("datei", datei);
  if (nachweis) fd.append("nachweis", nachweis);
  const r = await fetch(`${BASIS}?r=medien_hochladen`, { method: "POST", credentials: "same-origin", headers: { "X-Karten": "1" }, body: fd });
  return antwort(r);
}

async function antwort(r) {
  // Abgelaufene svetho-Anmeldung: nginx leitet auf die Anmeldeseite um
  if (r.redirected && !r.url.includes(BASIS)) {
    location.href = r.url;
    throw new ApiFehler("Bitte neu anmelden.", 401);
  }
  let d;
  try {
    d = await r.json();
  } catch {
    throw new ApiFehler(`Unerwartete Antwort vom Server (${r.status}).`, r.status);
  }
  if (!r.ok || d.ok === false) throw new ApiFehler(d.fehler || `Fehler ${r.status}`, r.status);
  return d;
}

/* ---------- IndexedDB ---------- */

let dbVersprechen = null;
function idb() {
  if (dbVersprechen) return dbVersprechen;
  dbVersprechen = new Promise((ok, nein) => {
    const req = indexedDB.open("karteikarten", 1);
    req.onupgradeneeded = () => {
      const db = req.result;
      db.createObjectStore("pakete");
      db.createObjectStore("warteschlange", { keyPath: "cid" });
    };
    req.onsuccess = () => ok(req.result);
    req.onerror = () => nein(req.error);
  });
  return dbVersprechen;
}
async function tx(store, modus, fn) {
  try {
    const db = await idb();
    return await new Promise((ok, nein) => {
      const t = db.transaction(store, modus);
      const s = t.objectStore(store);
      const r = fn(s);
      t.oncomplete = () => ok(r?.result);
      t.onerror = () => nein(t.error);
    });
  } catch (err) {
    console.warn("IndexedDB nicht verfügbar", err);
    return undefined;
  }
}

/** Lernpaket holen; ohne Netz aus dem Gerätespeicher (mit Hinweis „offline“) */
export async function lernpaket(art, id) {
  const schluessel = `${art}:${id}`;
  try {
    const p = await holen("lernpaket", { [art]: id });
    tx("pakete", "readwrite", (s) => s.put({ ...p, gespeichert: Date.now() }, schluessel));
    return p;
  } catch (err) {
    if (err.status !== 0) throw err;
    const p = await tx("pakete", "readonly", (s) => s.get(schluessel));
    if (!p) throw new ApiFehler("Ohne Verbindung und ohne gespeichertes Lernpaket. Öffne das Deck einmal mit Netz, dann geht's auch offline.", 0);
    // Bewertungen aus der Warteschlange einrechnen, damit Offline-Fortschritt erhalten bleibt
    const offen = (await tx("warteschlange", "readonly", (s) => s.getAll())) || [];
    for (const w of offen) if (p.karten.some((k) => k.id === w.karte)) p.zustand[w.karte] = { ...(p.zustand[w.karte] || {}), ...w.nach };
    return { ...p, offline: true };
  }
}

/** Bewertung merken und (wenn möglich) gleich senden */
export async function bewertungMerken(w) {
  await tx("warteschlange", "readwrite", (s) => s.put(w));
  // Gespeichertes Paket mitziehen, damit ein Neuladen ohne Netz den Stand kennt
  warteschlangeSenden();
}

let sendetGerade = false;
export async function warteschlangeSenden() {
  if (sendetGerade || !navigator.onLine) return;
  sendetGerade = true;
  try {
    const offen = (await tx("warteschlange", "readonly", (s) => s.getAll())) || [];
    for (let i = 0; i < offen.length; i += 100) {
      const teil = offen.slice(i, i + 100);
      await senden("wiederholungen", { liste: teil });
      await tx("warteschlange", "readwrite", (s) => teil.forEach((w) => s.delete(w.cid)));
    }
  } catch (err) {
    if (err.status && err.status !== 0) console.warn("Senden der Bewertungen fehlgeschlagen", err);
  } finally {
    sendetGerade = false;
  }
}

export async function warteschlangeLaenge() {
  return ((await tx("warteschlange", "readonly", (s) => s.count())) || 0);
}

setInterval(warteschlangeSenden, 30000);

/** Bewertung zurücknehmen: noch in der Warteschlange → einfach entfernen, sonst beim Server rückgängig machen */
export async function bewertungZuruecknehmen(cid, vorher) {
  const offen = await tx("warteschlange", "readonly", (s) => s.get(cid));
  if (offen) {
    await tx("warteschlange", "readwrite", (s) => s.delete(cid));
    return;
  }
  await senden("wiederholung_zurueck", { cid, vorher });
}
