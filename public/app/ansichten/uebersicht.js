// Übersicht: Begrüßung, Tagesstand, Projektkacheln (per Ziehen sortierbar), neues Projekt.
import { holen, senden } from "../api.js";
import { $, $$, e, flagge, ICON, zahl, vorWann, meldung } from "../ui.js";
import { url } from "../medien.js";
import { APP } from "../main.js";

function gruss() {
  const h = new Date().getHours();
  if (h < 5) return "Gute Nacht";
  if (h < 11) return "Guten Morgen";
  if (h < 14) return "Mahlzeit";
  if (h < 18) return "Servus";
  return "Guten Abend";
}

function ring(anteil, farbe = "#fff") {
  const r = 22, u = 2 * Math.PI * r, p = Math.max(0, Math.min(1, anteil || 0));
  return `<svg class="ring" viewBox="0 0 54 54" aria-label="${Math.round(p * 100)} % gelernt" role="img">
    <circle cx="27" cy="27" r="${r}" fill="rgba(0,0,0,.25)" stroke="rgba(255,255,255,.28)" stroke-width="5"/>
    <circle cx="27" cy="27" r="${r}" fill="none" stroke="${farbe}" stroke-width="5" stroke-linecap="round" stroke-dasharray="${u}" stroke-dashoffset="${u * (1 - p)}" transform="rotate(-90 27 27)"/>
    <text x="27" y="31.5" text-anchor="middle">${Math.round(p * 100)}%</text></svg>`;
}

function kachel(p) {
  const z = p.zahlen;
  const faellig = z.lernen + z.faellig_heute;
  const neu = z.neu_heute;
  const nichts = faellig + neu === 0;
  const bild = url(p.bild);
  const symbol = p.flagge ? flagge(p.flagge) : p.symbol ? `<span class="symbol">${e(p.symbol)}</span>` : "";
  const info = p.quelle
    ? `abgeglichen ${vorWann(p.abgleich)}${p.abgleich_info?.neu ? ` · ${zahl(p.abgleich_info.neu)} neu` : ""}`
    : `${zahl(z.gesamt)} Karten`;
  return `<article class="kachel" style="--farbe:${e(p.farbe)}" data-id="${p.id}" draggable="true">
      <div class="bild" style="${bild ? `background-image:url('${e(bild)}')` : ""}"></div>
      <a class="flaeche" href="#/projekt/${p.id}" aria-label="${e(p.name)} öffnen"></a>
      <div class="oben">
        <div class="zahlen">${p.serie > 1 ? `<span class="pille" title="Tage am Stück">🔥 ${p.serie}</span>` : ""}</div>
        ${z.gesamt ? ring(z.gelernt / z.gesamt) : ""}
      </div>
      <div class="unten">
        <h2>${symbol}${e(p.name)}</h2>
        <p class="beschr">${e(p.beschreibung)}</p>
        <div class="zahlen">
          ${faellig ? `<span class="pille wichtig">${zahl(faellig)} fällig</span>` : ""}
          ${neu ? `<span class="pille">${zahl(neu)} neu</span>` : ""}
          ${nichts && z.gesamt ? `<span class="pille">✓ Für heute fertig</span>` : ""}
          ${!z.gesamt ? `<span class="pille">${p.quelle ? "Noch keine Karten freigeschaltet" : "Noch leer"}</span>` : ""}
        </div>
        <div class="fuss">
          <span class="info">${e(info)}</span>
          <a class="knopf klein lern" href="#/lernen/projekt/${p.id}" ${nichts ? 'aria-disabled="true" tabindex="-1" style="opacity:.55;pointer-events:none"' : ""}>${ICON.spielen} Lernen</a>
        </div>
      </div>
    </article>`;
}

export async function zeigen(app) {
  const d = await holen("uebersicht");
  const reihenfolge = d.reihenfolge || [];
  const rang = (id) => { const i = reihenfolge.indexOf(id); return i < 0 ? 1000 + id : i; };
  const projekte = [...d.projekte].sort((a, b) => rang(a.id) - rang(b.id));
  const faellig = projekte.reduce((s, p) => s + p.zahlen.lernen + p.zahlen.faellig_heute, 0);
  const neu = projekte.reduce((s, p) => s + p.zahlen.neu_heute, 0);
  const name = APP.ich?.benutzer ? `, ${APP.ich.benutzer}` : "";
  const nachweise = projekte.filter((p) => p.bild_nachweis).map((p) => `${e(p.name)}: ${e(p.bild_nachweis)}`);

  app.innerHTML = `
    <section class="gruss">
      <div>
        <h1>${gruss()}${e(name)}!</h1>
        <div class="unter">${faellig + neu ? `Heute stehen <b>${zahl(faellig)}</b> Wiederholungen und <b>${zahl(neu)}</b> neue Karten an.` : "Für heute ist alles erledigt. Schön! 🌿"}</div>
      </div>
      <div class="tagesband">
        <div class="zahlkarte"><b>${zahl(d.heute)}</b><span>heute gelernt</span></div>
        <div class="zahlkarte"><b>${d.serie}</b><span>${d.serie === 1 ? "Tag" : "Tage"} am Stück</span></div>
      </div>
    </section>
    <section class="kacheln" id="kacheln">
      ${projekte.map(kachel).join("")}
      <a class="kachel neu-kachel" href="#/projekt/neu"><div><div class="plus">+</div><b>Neues Projekt</b><p class="leise klein" style="margin:4px 0 0">Eigene Karteikästen mit Decks, Kategorien und Tags</p></div></a>
    </section>
    ${nachweise.length ? `<p class="nachweis">Bilder: ${nachweise.join(" · ")}</p>` : ""}`;

  sortierbar($("#kacheln"));
}

/** Kacheln per Ziehen (Maus) oder Alt+Pfeil (Tastatur) umsortieren; Reihenfolge wird gespeichert */
function sortierbar(raster) {
  let gezogen = null;
  const speichern = () => {
    const ids = $$(".kachel[data-id]", raster).map((k) => Number(k.dataset.id));
    APP.einstellungen.kachel_reihenfolge = ids;
    senden("einstellungen_speichern", { kachel_reihenfolge: ids }).catch(() => meldung("Reihenfolge konnte nicht gespeichert werden.", "fehler"));
  };
  raster.addEventListener("dragstart", (ev) => {
    gezogen = ev.target.closest(".kachel[data-id]");
    if (!gezogen) return;
    gezogen.classList.add("ziehen");
    ev.dataTransfer.effectAllowed = "move";
  });
  raster.addEventListener("dragover", (ev) => {
    const ziel = ev.target.closest(".kachel[data-id]");
    if (!gezogen || !ziel || ziel === gezogen) return;
    ev.preventDefault();
    const r = ziel.getBoundingClientRect();
    const davor = ev.clientX < r.left + r.width / 2;
    ziel.parentNode.insertBefore(gezogen, davor ? ziel : ziel.nextSibling);
  });
  raster.addEventListener("dragend", () => {
    if (!gezogen) return;
    gezogen.classList.remove("ziehen");
    gezogen = null;
    speichern();
  });
  raster.addEventListener("keydown", (ev) => {
    const k = ev.target.closest(".kachel[data-id]");
    if (!k || !ev.altKey || !["ArrowLeft", "ArrowRight"].includes(ev.key)) return;
    ev.preventDefault();
    if (ev.key === "ArrowLeft" && k.previousElementSibling) k.parentNode.insertBefore(k, k.previousElementSibling);
    if (ev.key === "ArrowRight" && k.nextElementSibling?.dataset.id) k.parentNode.insertBefore(k.nextElementSibling, k);
    ev.target.focus();
    speichern();
  });
}
