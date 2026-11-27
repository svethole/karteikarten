// Lernansicht: Warteschlange wie Anki (fällige Lernschritte → Wiederholungen → neue Karten, gemischt),
// FSRS im Browser, Bewertungen über die Offline-Warteschlange. Tasten: Leertaste/Enter, 1–4, U (rückgängig).
import { lernpaket, bewertungMerken, bewertungZuruecknehmen, senden } from "../api.js";
import { $, e, ICON, meldung, zahl, mischen } from "../ui.js";
import { karteHTML, karteBinden } from "../karte.js";
import { planen, bewerten, abstandText, tagesbeginn, TAG, MIN, FSRS } from "../fsrs.js";
import { stopp } from "../medien.js";
import { notizOeffnen } from "../notizdialog.js";
import { APP } from "../main.js";

let L = null; // Zustand der Sitzung
let tastenHoerer = null;

export function verlassen() {
  if (tastenHoerer) removeEventListener("keydown", tastenHoerer);
  tastenHoerer = null;
  stopp();
  L = null;
}

export async function zeigen(app, [art, id]) {
  const paket = await lernpaket(art, id);
  FSRS.ret = paket.ziel || FSRS.ret;
  // Neue Karten: Reihenfolge der Quelle, gemischt oder zufällig
  const karten = paket.grenzen.mischen === "zufall" ? mischen(paket.karten) : paket.karten;
  L = {
    app, paket, karten, zustand: { ...paket.zustand },
    titel: paket.deck?.name || paket.projekt.name,
    zurueck: `#/projekt/${paket.projekt.id}`,
    sitzung: { neu: 0, wdh: 0, fertig: 0, neueNotizen: new Set(), noten: [0, 0, 0, 0, 0], start: Date.now(), extraNeu: 0 },
    aktuell: null, hinten: false, antwort: {}, gezeigtUm: 0, verlauf: [],
  };
  tastenHoerer = tasten;
  addEventListener("keydown", tastenHoerer);
  weiter();
}

/** Zählung und nächste Karte (wie FC.next in den Trainern, mit Tagesgrenzen) */
function stapel() {
  const jetzt = Date.now(), ende = tagesbeginn(jetzt) + TAG;
  const lernJetzt = [], wdh = [], lernSpaeter = [], neu = [];
  for (const k of L.karten) {
    const s = L.zustand[k.id];
    if (s?.pausiert || (s?.zurueck && s.zurueck > jetzt)) continue;
    if (!s || !s.st) { neu.push(k); continue; }
    if (s.st === "learn" || s.st === "relearn") { if (s.due <= jetzt) lernJetzt.push([k, s]); else if (s.due < ende) lernSpaeter.push([k, s]); }
    else if (s.due < ende) wdh.push([k, s]);
  }
  const g = L.paket.grenzen, h = L.paket.heute, ses = L.sitzung;
  const neuRest = Math.max(0, g.neu + ses.extraNeu - h.neu - ses.neu);
  const wdhRest = Math.max(0, g.wdh - h.wdh - ses.wdh);
  // Geschwister (andere Karten derselben Notiz) nicht am selben Tag als neu zeigen
  const neuFrei = neu.filter((k) => !ses.neueNotizen.has(k.notiz));
  return { jetzt, lernJetzt, wdh: wdh.slice(0, wdhRest), lernSpaeter, neu: neuFrei.slice(0, neuRest), neuGesamt: neuRest };
}

function naechste() {
  const s = stapel();
  if (s.lernJetzt.length) { s.lernJetzt.sort((a, b) => a[1].due - b[1].due); return { karte: s.lernJetzt[0][0], art: "l", s }; }
  const modus = L.paket.grenzen.mischen;
  const neuZuerst = s.neu.length && (!s.wdh.length || (modus !== "reihenfolge" && L.sitzung.fertig % 4 === 3));
  if (s.wdh.length && !neuZuerst) { s.wdh.sort((a, b) => a[1].due - b[1].due); return { karte: s.wdh[0][0], art: "f", s }; }
  if (s.neu.length) return { karte: s.neu[0], art: "n", s };
  // Vorauslernen: Lernschritte der nächsten 20 Minuten
  const bald = s.lernSpaeter.filter(([, z]) => z.due - s.jetzt < 20 * MIN).sort((a, b) => a[1].due - b[1].due);
  if (bald.length) return { karte: bald[0][0], art: "l", s };
  return { karte: null, s };
}

function zaehlerHTML(s, art) {
  const n = Math.min(s.neu.length, s.neuGesamt), l = s.lernJetzt.length + s.lernSpaeter.filter(([, z]) => z.due - s.jetzt < 20 * MIN).length, f = s.wdh.length;
  return `<span class="zz"><span class="n ${art === "n" ? "aktiv" : ""}" title="neu">${zahl(n)}</span><span class="l ${art === "l" ? "aktiv" : ""}" title="lernend">${zahl(l)}</span><span class="f ${art === "f" ? "aktiv" : ""}" title="fällig">${zahl(f)}</span></span>`;
}

function weiter() {
  stopp();
  const { karte, art, s } = naechste();
  L.aktuell = karte ? { karte, art } : null;
  L.hinten = false;
  L.antwort = {};
  L.gezeigtUm = Date.now();
  if (!karte) return fertig(s);
  zeichnen(s);
}

function kopfHTML(s) {
  return `<div class="lernkopf">
      <a class="knopf leise rund" href="${L.zurueck}" aria-label="Zurück zum Projekt">${ICON.zurueck}</a>
      <span class="titel">${e(L.titel)}${L.paket.offline ? ' <span class="chip">offline – wird später abgeglichen</span>' : ""}</span>
      ${s ? zaehlerHTML(s, L.aktuell?.art) : ""}
    </div>`;
}

function zeichnen(s = stapel()) {
  const { karte } = L.aktuell;
  const notiz = L.paket.notizen[karte.notiz];
  const typ = L.paket.typen[notiz.typ];
  const st = L.zustand[karte.id];
  const html = karteHTML({ typ, notiz, ord: karte.ord, hinten: L.hinten, zustand: L.antwort, kategorien: L.paket.kategorien, decknamen: L.paket.decknamen,
    sprache: L.paket.projekt.sprache, genusEffekte: APP.einstellungen.genus_effekte !== false });
  let unten;
  if (!L.hinten) {
    unten = `<button class="knopf haupt aufdecken" data-aufdecken>Antwort zeigen</button>`;
  } else {
    const plan = planen(st, Date.now());
    const empfohlen = L.antwort.richtig === false ? 1 : 3;
    unten = `<div class="bewertung">${[[1, "Nochmal"], [2, "Schwer"], [3, "Gut"], [4, "Einfach"]].map(([g, n]) =>
      `<button class="g${g}" data-note="${g}" ${g === empfohlen ? "data-empfohlen" : ""}>${n}<small>${abstandText(plan[g])}</small></button>`).join("")}</div>`;
  }
  L.app.innerHTML = `<div class="lernen">${kopfHTML(s)}
    <div data-karte>${html}</div>
    ${unten}
    <div class="lernfuss">
      <span class="zeile">
        <button class="knopf klein leise" data-zurueck ${L.verlauf.length ? "" : "disabled"} title="Rückgängig (U)">↶ Rückgängig</button>
        <button class="knopf klein leise" data-notiz title="Karte ansehen, Zusätze, Bearbeiten">${ICON.stift} Karte</button>
      </span>
      <span class="zeile">
        <button class="knopf klein leise" data-aktion="markieren" title="Markieren">${ICON.stern}${st?.markiert ? " markiert" : ""}</button>
        <button class="knopf klein leise" data-aktion="zurueckstellen" title="Auf morgen verschieben">Morgen</button>
        <button class="knopf klein leise" data-aktion="pausieren" title="Karte pausieren">${ICON.pause}</button>
      </span>
    </div>
    <p class="tasten"><kbd>Leertaste</kbd> ${L.hinten ? "Gut" : "aufdecken"}${L.hinten ? " · <kbd>1</kbd>–<kbd>4</kbd> bewerten" : ""} · <kbd>U</kbd> rückgängig</p>
  </div>`;
  const kEl = $("[data-karte]", L.app);
  karteBinden(kEl, {
    autoTon: APP.einstellungen.sprachausgabe_auto !== false,
    beiEingabe: (text) => { L.antwort.eingabe = text; aufdecken(); },
    beiAuswahl: (opt, richtig, sofort) => {
      L.antwort.gewaehlt = opt;
      L.antwort.richtig = richtig;
      if (sofort || !L.hinten) aufdecken();
    },
  });
  wischen(kEl);
  L.app.onclick = klick;
}

function aufdecken() {
  if (!L?.aktuell || L.hinten) return;
  const ein = $("[data-eingabe]", L.app);
  if (ein && L.antwort.eingabe == null) L.antwort.eingabe = ein.value;
  L.hinten = true;
  zeichnen();
  // Falsche Antwort in Eingabe/Auswahl: „Nochmal“ vorschlagen
  $("[data-empfohlen]", L.app)?.focus({ preventScroll: true });
}

async function bewerte(g) {
  if (!L?.aktuell || !L.hinten) return;
  const { karte, art } = L.aktuell;
  const jetzt = Date.now();
  const vorher = L.zustand[karte.id] ? { ...L.zustand[karte.id] } : null;
  const nach = bewerten(vorher?.st ? vorher : null, g, jetzt);
  const cid = `${APP.ich?.benutzer || "x"}-${karte.id}-${jetzt}-${Math.random().toString(36).slice(2, 8)}`;
  L.zustand[karte.id] = { ...(vorher || {}), ...nach };
  const ses = L.sitzung;
  if (!vorher?.st) { ses.neu++; ses.neueNotizen.add(karte.notiz); } else if (art === "f") ses.wdh++;
  ses.fertig++;
  ses.noten[g]++;
  L.verlauf.push({ karte, art, vorher, cid, warNeu: !vorher?.st });
  if (L.verlauf.length > 20) L.verlauf.shift();
  bewertungMerken({ cid, karte: karte.id, zeit: jetzt, note: g, nach, dauer: Math.min(60000, jetzt - L.gezeigtUm) });
  weiter();
}

async function rueckgaengig() {
  const v = L?.verlauf.pop();
  if (!v) return;
  if (v.vorher) L.zustand[v.karte.id] = v.vorher; else delete L.zustand[v.karte.id];
  const ses = L.sitzung;
  ses.fertig = Math.max(0, ses.fertig - 1);
  if (v.warNeu) { ses.neu--; ses.neueNotizen.delete(v.karte.notiz); } else if (v.art === "f") ses.wdh--;
  try { await bewertungZuruecknehmen(v.cid, v.vorher); } catch (err) { meldung("Rückgängig nur auf diesem Gerät: " + err.message, "fehler"); }
  L.aktuell = { karte: v.karte, art: v.art };
  L.hinten = false;
  L.antwort = {};
  L.gezeigtUm = Date.now();
  zeichnen();
}

async function klick(ev) {
  if (ev.target.closest("[data-aufdecken]")) return aufdecken();
  const n = ev.target.closest("[data-note]");
  if (n) return bewerte(Number(n.dataset.note));
  if (ev.target.closest("[data-zurueck]")) return rueckgaengig();
  if (ev.target.closest("[data-notiz]")) {
    const k = L.aktuell?.karte;
    if (k) notizOeffnen(k.notiz, { ord: k.ord });
    return;
  }
  const a = ev.target.closest("[data-aktion]");
  if (a && L.aktuell) {
    const k = L.aktuell.karte, aktion = a.dataset.aktion;
    try {
      await senden("karte_aktion", { karte: k.id, aktion });
      const z = (L.zustand[k.id] = { ...(L.zustand[k.id] || {}) });
      if (aktion === "markieren") { z.markiert = z.markiert ? 0 : 1; meldung(z.markiert ? "Markiert ★" : "Markierung entfernt."); zeichnen(); }
      if (aktion === "pausieren") { z.pausiert = 1; meldung("Karte pausiert."); weiter(); }
      if (aktion === "zurueckstellen") { z.zurueck = tagesbeginn(Date.now()) + TAG; meldung("Kommt morgen wieder."); weiter(); }
    } catch (err) { meldung(err.message, "fehler"); }
  }
  if (ev.target.closest("[data-mehr-neu]")) { L.sitzung.extraNeu += 10; weiter(); }
}

function tasten(ev) {
  if (!L || ev.target.closest("input, textarea, select, dialog") || ev.metaKey || ev.ctrlKey || ev.altKey) return;
  if (ev.key === " " || ev.key === "Enter") {
    if (!L.aktuell) return;
    ev.preventDefault();
    return L.hinten ? bewerte(3) : aufdecken();
  }
  if (/^[1-4]$/.test(ev.key) && L.hinten) { ev.preventDefault(); return bewerte(Number(ev.key)); }
  if (ev.key === "u" || ev.key === "U") return rueckgaengig();
}

/** Am Handy: nach dem Aufdecken nach links wischen = Nochmal, nach rechts = Gut */
function wischen(el) {
  let x0 = null, y0 = 0;
  el.addEventListener("pointerdown", (ev) => { if (ev.pointerType === "touch") { x0 = ev.clientX; y0 = ev.clientY; } });
  el.addEventListener("pointerup", (ev) => {
    if (x0 === null) return;
    const dx = ev.clientX - x0, dy = ev.clientY - y0;
    x0 = null;
    if (Math.abs(dx) < 90 || Math.abs(dy) > 60) return;
    if (!L.hinten) return aufdecken();
    bewerte(dx < 0 ? 1 : 3);
  });
}

function fertig(s) {
  const ses = L.sitzung;
  const min = Math.max(1, Math.round((Date.now() - ses.start) / 60000));
  const spaeter = s.lernSpaeter.length;
  const neuDa = L.karten.some((k) => !L.zustand[k.id]?.st && !L.zustand[k.id]?.pausiert);
  L.app.innerHTML = `<div class="lernen">${kopfHTML(null)}
    <div class="karte fertig">
      <div class="gross-emoji">${ses.fertig ? "🎉" : "🌿"}</div>
      <h2>${ses.fertig ? "Geschafft!" : "Nichts mehr fällig"}</h2>
      ${ses.fertig ? `<p>${zahl(ses.fertig)} Karten in ${min} ${min === 1 ? "Minute" : "Minuten"}${ses.neu ? `, davon ${zahl(ses.neu)} neue` : ""}.</p>
        <p class="leise klein">Nochmal ${ses.noten[1]} · Schwer ${ses.noten[2]} · Gut ${ses.noten[3]} · Einfach ${ses.noten[4]}</p>` : ""}
      <p class="leise">${spaeter ? `${spaeter} ${spaeter === 1 ? "Karte kommt" : "Karten kommen"} heute noch einmal – schau später vorbei.` : "Für heute ist hier alles erledigt."}</p>
      <div class="zeile" style="justify-content:center;margin-top:10px">
        <a class="knopf haupt" href="#/">Zur Übersicht</a>
        <a class="knopf" href="${L.zurueck}">Zum Projekt</a>
        ${neuDa ? '<button class="knopf" data-mehr-neu>10 weitere neue Karten</button>' : ""}
        ${L.verlauf.length ? '<button class="knopf leise" data-zurueck>↶ Letzte rückgängig</button>' : ""}
      </div>
    </div></div>`;
  L.app.onclick = klick;
}
