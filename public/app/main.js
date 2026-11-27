// Einstieg: Anmeldestand laden, Kopfzeile, Router (#/…), Hell/Dunkel, Service Worker.
import { holen, online, beiNetzwechsel, warteschlangeSenden, warteschlangeLaenge } from "./api.js";
import { $, e, ICON, MARKE, meldung, ladeAnzeige } from "./ui.js";
import { FSRS } from "./fsrs.js";

export const APP = { ich: null, einstellungen: {} };

const ROUTEN = [
  [/^\/?$/, () => import("./ansichten/uebersicht.js"), "start"],
  [/^\/projekt\/neu$/, () => import("./ansichten/projekt-bearbeiten.js"), "start"],
  [/^\/projekt\/(\d+)(?:\/(decks|karten|statistik|einstellungen))?$/, () => import("./ansichten/projekt.js"), "start"],
  [/^\/lernen\/(deck|projekt)\/(\d+)$/, () => import("./ansichten/lernen.js"), "lernen"],
  [/^\/notiz\/(neu|\d+)$/, () => import("./ansichten/notiz.js"), "start"],
  [/^\/designer\/(neu|\d+)$/, () => import("./ansichten/designer.js"), "start"],
  [/^\/statistik$/, () => import("./ansichten/statistik.js"), "statistik"],
  [/^\/einstellungen$/, () => import("./ansichten/einstellungen.js"), "einstellungen"],
];

let aktuelleAnsicht = null;

export function gehe(pfad) {
  location.hash = "#" + pfad;
}

/** Abfrageteil der Adresse (#/notiz/neu?deck=3) */
export function parameter() {
  const q = location.hash.split("?")[1] || "";
  return Object.fromEntries(new URLSearchParams(q));
}

function kopf(aktiv) {
  $("#kopf").innerHTML = `
    <a class="marke" href="#/">${MARKE}<span>Karteikarten</span></a>
    <span class="netz ${online ? "" : "offline"}" id="netz">${ICON.wolke} offline</span>
    <nav>
      <a href="#/" ${aktiv === "start" ? 'aria-current="page"' : ""}>${ICON.haus}<span class="text">Übersicht</span></a>
      <a href="#/statistik" ${aktiv === "statistik" ? 'aria-current="page"' : ""}>${ICON.statistik}<span class="text">Statistik</span></a>
      <a href="#/einstellungen" ${aktiv === "einstellungen" ? 'aria-current="page"' : ""}>${ICON.zahnrad}<span class="text">Einstellungen</span></a>
    </nav>`;
}

async function zeigen() {
  const pfad = location.hash.replace(/^#/, "").split("?")[0] || "/";
  const app = $("#app");
  aktuelleAnsicht?.verlassen?.();
  aktuelleAnsicht = null;
  for (const [muster, laden, nav] of ROUTEN) {
    const m = pfad.match(muster);
    if (!m) continue;
    kopf(nav);
    document.body.dataset.ansicht = nav;
    app.innerHTML = ladeAnzeige();
    try {
      const modul = await laden();
      aktuelleAnsicht = modul;
      await modul.zeigen(app, m.slice(1), parameter());
    } catch (err) {
      console.error(err);
      app.innerHTML = `<div class="leer"><h2>Hoppla</h2><p>${e(err.message || "Das hat nicht geklappt.")}</p><p><a class="knopf" href="#/">Zur Übersicht</a></p></div>`;
    }
    $(".blatt")?.remove();
    window.scrollTo(0, 0);
    return;
  }
  gehe("/");
}

export function designSetzen(d) {
  if (d === "hell" || d === "dunkel") document.documentElement.dataset.design = d;
  else delete document.documentElement.dataset.design;
  try { localStorage.setItem("kk-design", d); } catch {}
}

async function start() {
  try {
    APP.ich = await holen("ich");
    APP.einstellungen = APP.ich.einstellungen;
    FSRS.ret = APP.einstellungen.ziel || 0.9;
    designSetzen(APP.einstellungen.design);
  } catch (err) {
    // Ohne Netz trotzdem starten (Lernen geht mit gespeicherten Lernpaketen)
    APP.ich = { benutzer: "", admin: false, einstellungen: {} };
    try { APP.einstellungen = JSON.parse(localStorage.getItem("kk-einstellungen") || "{}"); } catch { APP.einstellungen = {}; }
  }
  try { localStorage.setItem("kk-einstellungen", JSON.stringify(APP.einstellungen)); } catch {}
  addEventListener("hashchange", zeigen);
  beiNetzwechsel(async (w) => {
    $("#netz")?.classList.toggle("offline", !w);
    if (w && (await warteschlangeLaenge())) meldung("Wieder online – Fortschritt wird abgeglichen.");
  });
  zeigen();
  warteschlangeSenden();
  if ("serviceWorker" in navigator && location.protocol === "https:") navigator.serviceWorker.register("sw.js").catch(() => {});
}

start();
