// Projektseite: Banner, Reiter Decks (Baum mit Zählern), Karten (Liste mit Filtern), Statistik, Einstellungen.
import { holen, senden } from "../api.js";
import { $, $$, e, flagge, ICON, zahl, vorWann, meldung, bestaetigen, eingabeDialog, dialog, inTagen, datum } from "../ui.js";
import { url } from "../medien.js";
import { klartext } from "../md.js";
import { kartenTitel } from "../karte.js";
import { notizOeffnen } from "../notizdialog.js";
import { statistikHTML } from "./statistik.js";
import { einstellungenHTML, einstellungenBinden } from "./projekt-bearbeiten.js";
import { APP, gehe } from "../main.js";

let P = null; // Projektdaten der aktuellen Seite

const zugeklappt = (() => {
  try { return new Set(JSON.parse(localStorage.getItem("kk-zu") || "[]")); } catch { return new Set(); }
})();
function klappMerken() {
  try { localStorage.setItem("kk-zu", JSON.stringify([...zugeklappt])); } catch {}
}

export async function zeigen(app, [id, reiter = "decks"], param) {
  P = await holen("projekt", { id });
  const p = P.projekt;
  const s = P.summe;
  const faellig = s.lernen + s.faellig_heute;
  const bild = url(p.bild);
  app.innerHTML = `
    <section class="banner" style="--farbe:${e(p.farbe)};${bild ? `background-image:url('${e(bild)}')` : ""}">
      <h1>${p.flagge ? flagge(p.flagge) : p.symbol ? `<span class="symbol">${e(p.symbol)}</span>` : ""}${e(p.name)}</h1>
      ${p.beschreibung ? `<p>${e(p.beschreibung)}</p>` : ""}
      <div class="zahlen">
        ${faellig ? `<span class="chip" style="background:#fff;color:#1a1d22">${zahl(faellig)} fällig</span>` : ""}
        ${s.neu_heute ? `<span class="chip" style="background:rgba(255,255,255,.2);color:#fff">${zahl(s.neu_heute)} neu</span>` : ""}
        <span class="chip" style="background:rgba(255,255,255,.2);color:#fff">${zahl(s.gesamt)} Karten · ${zahl(s.gelernt)} gelernt</span>
        ${P.serie > 1 ? `<span class="chip" style="background:rgba(255,255,255,.2);color:#fff">🔥 ${P.serie} Tage</span>` : ""}
      </div>
      <div class="zeile" style="margin-top:14px">
        <a class="knopf haupt" href="#/lernen/projekt/${p.id}" ${faellig + s.neu_heute ? "" : 'aria-disabled="true" style="opacity:.6;pointer-events:none"'}>${ICON.spielen} Alles lernen</a>
        ${p.darf_bearbeiten ? `<a class="knopf" href="#/notiz/neu?projekt=${p.id}">${ICON.plus} Neue Karte</a>` : `<button class="knopf" data-abgleich>${ICON.sync} Jetzt abgleichen</button>`}
      </div>
    </section>
    <nav class="reiter">
      ${[["decks", "Decks"], ["karten", "Karten"], ["statistik", "Statistik"], ["einstellungen", "Einstellungen"]]
        .map(([k, n]) => `<a href="#/projekt/${p.id}/${k}${k === "karten" && param.deck ? `?deck=${param.deck}` : ""}" ${k === reiter ? 'aria-current="page"' : ""}>${n}</a>`).join("")}
    </nav>
    <section id="reiter"></section>`;

  $("[data-abgleich]", app)?.addEventListener("click", (ev) => abgleichen(ev.currentTarget));
  const ziel = $("#reiter", app);
  if (reiter === "decks") decksZeigen(ziel);
  if (reiter === "karten") await kartenZeigen(ziel, param);
  if (reiter === "statistik") ziel.innerHTML = statistikHTML(await holen("statistik", { projekt: p.id }));
  if (reiter === "einstellungen") {
    ziel.innerHTML = einstellungenHTML(P);
    einstellungenBinden(ziel, P);
  }
}

async function abgleichen(knopf) {
  knopf.disabled = true;
  knopf.innerHTML = `${ICON.sync} Gleiche ab …`;
  try {
    const r = await senden("abgleich", { projekt: P.projekt.id });
    const x = r.ergebnis;
    meldung(x.uebersprungen ? "Gerade eben erst abgeglichen." : `Abgeglichen: ${x.neu} neu, ${x.geaendert} geändert, ${x.entfernt} entfernt.`);
    window.dispatchEvent(new HashChangeEvent("hashchange"));
  } catch (err) {
    meldung(err.message, "fehler");
    knopf.disabled = false;
    knopf.innerHTML = `${ICON.sync} Jetzt abgleichen`;
  }
}

/* ---------- Decks ---------- */

function zaehler(z) {
  const zelle = (n, k) => `<span class="${n ? k : "null"}" title="${{ n: "neu", l: "lernend", f: "fällig" }[k]}">${zahl(n)}</span>`;
  return `<span class="zz">${zelle(z.neu_heute, "n")}${zelle(z.lernen, "l")}${zelle(z.faellig_heute, "f")}</span>`;
}

function deckZeile(d, eigen) {
  const kinder = d.kinder.length > 0;
  const zu = zugeklappt.has(d.id);
  const gesperrt = d.summe.gesamt === 0 && /^lektion:/.test(d.schluessel || "");
  const lernbar = d.summe.lernen + d.summe.faellig_heute + d.summe.neu_heute > 0;
  return `<li>
    <div class="deck ${gesperrt ? "gesperrt" : ""}" data-deck="${d.id}">
      ${kinder ? `<button class="auf" data-auf="${d.id}" aria-expanded="${!zu}" aria-label="${zu ? "Aufklappen" : "Zuklappen"}">${ICON.pfeil}</button>` : "<span></span>"}
      <span class="name">${gesperrt ? `<span title="Lektion im Trainer noch nicht abgeschlossen" style="display:inline-flex;width:14px;vertical-align:-2px;margin-right:4px">${ICON.schloss}</span>` : ""}
        ${lernbar ? `<a href="#/lernen/deck/${d.id}">${e(d.name)}</a>` : `<a href="#/projekt/${P.projekt.id}/karten?deck=${d.id}">${e(d.name)}</a>`}</span>
      ${zaehler(d.summe)}
      <span class="aktionen">
        ${lernbar ? `<a class="knopf klein rund leise" href="#/lernen/deck/${d.id}" title="Lernen" aria-label="${e(d.name)} lernen">${ICON.spielen}</a>` : ""}
        ${eigen ? `<button class="knopf klein rund leise" data-deck-menue="${d.id}" title="Deck bearbeiten" aria-label="${e(d.name)} bearbeiten">${ICON.stift}</button>` : ""}
      </span>
    </div>
    ${kinder && !zu ? `<ul>${d.kinder.map((k) => deckZeile(k, eigen)).join("")}</ul>` : ""}
  </li>`;
}

function fortschrittBox(s) {
  const g = Math.max(1, s.gesamt);
  return `<div class="karte-box"><h3>Fortschritt</h3>
    <div class="balken" style="height:12px;margin:6px 0 8px"><span style="width:${(s.sicher / g) * 100}%;background:var(--gut)"></span><span style="width:${((s.gelernt - s.sicher) / g) * 100}%;background:var(--schwer)"></span></div>
    <p class="klein leise" style="margin:0">${zahl(s.sicher)} sicher · ${zahl(s.gelernt - s.sicher)} in Arbeit · ${zahl(s.gesamt - s.gelernt)} neu${s.pausiert ? ` · ${zahl(s.pausiert)} pausiert` : ""}</p>
    <p class="klein leise" style="margin:6px 0 0">Heute: ${zahl(s.heute_neu)} neue, ${zahl(s.heute_wdh)} Wiederholungen</p></div>`;
}

function decksZeigen(ziel) {
  decksZeichnen(ziel);
  // Ereignisse einmal am Container (onclick ersetzt beim erneuten Aufruf, damit nichts doppelt läuft)
  ziel.onclick = async (ev) => {
    const auf = ev.target.closest("[data-auf]");
    if (auf) {
      const id = Number(auf.dataset.auf);
      zugeklappt.has(id) ? zugeklappt.delete(id) : zugeklappt.add(id);
      klappMerken();
      decksZeichnen(ziel);
      return;
    }
    if (ev.target.closest("[data-neues-deck]")) {
      const name = await eingabeDialog("Neues Deck", { feld: "Name des Decks" });
      if (name) await deckSpeichern({ name });
    }
    const m = ev.target.closest("[data-deck-menue]");
    if (m) deckMenue(Number(m.dataset.deckMenue));
  };
}

function decksZeichnen(ziel) {
  const eigen = P.projekt.darf_bearbeiten;
  const kat = P.kategorien;
  const tags = Object.entries(P.tags).slice(0, 60);
  const p = P.projekt;
  ziel.innerHTML = `<div class="zweispaltig">
    <div>
      <div class="karte-box">
        <div class="zeile" style="justify-content:space-between;margin-bottom:6px">
          <h3 style="margin:0">Decks</h3>
          <span class="klein leise"><span style="color:var(--neu-farbe)">neu</span> · <span style="color:var(--lernen-farbe)">lernend</span> · <span style="color:var(--faellig-farbe)">fällig</span></span>
        </div>
        ${P.decks.length ? `<ul class="baum">${P.decks.map((d) => deckZeile(d, eigen)).join("")}</ul>` : '<p class="leer">Noch keine Decks.</p>'}
        ${eigen ? `<div class="zeile" style="margin-top:10px"><button class="knopf klein" data-neues-deck>${ICON.plus} Deck</button></div>` : ""}
        ${p.quelle && p.quelle !== "its" ? `<p class="klein leise" style="margin:10px 0 0">${ICON.schloss.replace("<svg", '<svg style="width:13px;vertical-align:-2px"')} Lektionen werden freigeschaltet, sobald Du sie im Trainer abschließt.</p>` : ""}
      </div>
    </div>
    <aside>
      ${fortschrittBox(P.summe)}
      ${kat.length || eigen ? `<div class="karte-box"><h3>Kategorien</h3><div class="zeile">${kat.map((k) => `<a class="chip" style="color:${e(k.farbe)};text-decoration:none" href="#/projekt/${p.id}/karten?kategorie=${k.id}"><span class="punkt"></span>${e(k.symbol)} ${e(k.name)}</a>`).join("") || '<span class="leise klein">Noch keine.</span>'}</div>
        ${eigen ? `<p style="margin:10px 0 0"><a class="klein" href="#/projekt/${p.id}/einstellungen">Kategorien verwalten</a></p>` : ""}</div>` : ""}
      ${tags.length ? `<div class="karte-box"><h3>Tags</h3><div class="zeile">${tags.map(([t, n]) => `<a class="chip" style="text-decoration:none" href="#/projekt/${p.id}/karten?tag=${encodeURIComponent(t)}">#${e(t)} <span class="leise">${n}</span></a>`).join("")}</div></div>` : ""}
      ${p.quelle ? `<div class="karte-box"><h3>Abgleich</h3><p class="klein" style="margin:0">Zuletzt ${e(vorWann(p.abgleich))}${p.abgleich_info?.stand ? ` · Stand der Quelle ${e(p.abgleich_info.stand)}` : ""}</p>
        ${P.abgleich_log.slice(0, 4).map((l) => `<p class="klein leise" style="margin:4px 0 0">${e(datum(l.zeit))}: ${l.meldung === "ok" ? `${l.neu} neu, ${l.geaendert} geändert, ${l.entfernt} entfernt` : e(l.meldung)}</p>`).join("")}</div>` : ""}
    </aside></div>`;
}

async function deckSpeichern(daten) {
  try {
    await senden("deck_speichern", { projekt: P.projekt.id, ...daten });
    window.dispatchEvent(new HashChangeEvent("hashchange"));
  } catch (err) {
    meldung(err.message, "fehler");
  }
}

function alleDecks(liste = P.decks, tiefe = 0, aus = []) {
  for (const d of liste) { aus.push({ ...d, tiefe }); alleDecks(d.kinder, tiefe + 1, aus); }
  return aus;
}

async function deckMenue(id) {
  const decks = alleDecks();
  const d = decks.find((x) => x.id === id);
  await dialog({
    titel: "Deck bearbeiten",
    inhalt: `<label class="feld">Name<input class="eingabe" data-name value="${e(d.name)}"></label>
      <label class="feld">Liegt in<select class="eingabe" data-eltern><option value="">(oberste Ebene)</option>
        ${decks.filter((x) => x.id !== id).map((x) => `<option value="${x.id}" ${x.id === d.eltern ? "selected" : ""}>${"– ".repeat(x.tiefe)}${e(x.name)}</option>`).join("")}</select></label>
      <label class="feld">Beschreibung<textarea class="eingabe" data-beschr rows="2">${e(d.beschreibung || "")}</textarea></label>
      <div class="zeile"><a class="knopf klein" href="#/notiz/neu?deck=${id}">${ICON.plus} Karte in dieses Deck</a><button class="knopf klein" data-unter>${ICON.plus} Unterdeck</button></div>`,
    fuss: `<button class="knopf gefahr" data-loeschen>${ICON.papierkorb} Löschen</button><span style="flex:1"></span><button class="knopf leise" data-zu>Abbrechen</button><button class="knopf haupt" data-ok>Speichern</button>`,
    beimOeffnen: (f, zu) => {
      $("[data-ok]", f).addEventListener("click", async () => {
        zu();
        await deckSpeichern({ id, name: $("[data-name]", f).value, eltern: $("[data-eltern]", f).value || null, beschreibung: $("[data-beschr]", f).value });
      });
      $("[data-unter]", f).addEventListener("click", async () => {
        zu();
        const name = await eingabeDialog("Neues Unterdeck", { feld: `Name (in „${d.name}“)` });
        if (name) await deckSpeichern({ name, eltern: id });
      });
      $("[data-loeschen]", f).addEventListener("click", async () => {
        zu();
        if (!(await bestaetigen(`Deck „${d.name}“ samt Unterdecks und allen Karten darin löschen?`, { ja: "Löschen", gefahr: true }))) return;
        try { await senden("deck_loeschen", { id }); window.dispatchEvent(new HashChangeEvent("hashchange")); } catch (err) { meldung(err.message, "fehler"); }
      });
    },
  });
}

/* ---------- Karten ---------- */

const ZUSTAND_TEXT = { null: ["neu", "var(--neu-farbe)"], learn: ["lernt", "var(--lernen-farbe)"], relearn: ["wiederholt", "var(--lernen-farbe)"], review: ["gelernt", "var(--gut)"] };

async function kartenZeigen(ziel, param) {
  const f = { suche: param.suche || "", deck: param.deck || "", kategorie: param.kategorie || "", tag: param.tag || "", zustand: param.zustand || "", sortierung: param.sortierung || "", seite: 0 };
  const decks = alleDecks();
  ziel.innerHTML = `
    <form class="filter" data-filter>
      <input class="eingabe" name="suche" placeholder="Suchen …" value="${e(f.suche)}" type="search">
      <select class="eingabe" name="deck"><option value="">Alle Decks</option>${decks.map((d) => `<option value="${d.id}" ${String(d.id) === f.deck ? "selected" : ""}>${"– ".repeat(d.tiefe)}${e(d.name)}</option>`).join("")}</select>
      ${P.kategorien.length ? `<select class="eingabe" name="kategorie"><option value="">Alle Kategorien</option>${P.kategorien.map((k) => `<option value="${k.id}" ${String(k.id) === f.kategorie ? "selected" : ""}>${e(k.name)}</option>`).join("")}</select>` : ""}
      ${Object.keys(P.tags).length ? `<select class="eingabe" name="tag"><option value="">Alle Tags</option>${Object.keys(P.tags).map((t) => `<option ${t === f.tag ? "selected" : ""}>${e(t)}</option>`).join("")}</select>` : ""}
      <select class="eingabe" name="zustand">${[["", "Alle Karten"], ["neu", "Neu"], ["lernen", "Lernend"], ["faellig", "Fällig"], ["gelernt", "Gelernt"], ["schwer", "Oft vergessen"], ["markiert", "Markiert"], ["pausiert", "Pausiert"]]
        .map(([k, n]) => `<option value="${k}" ${k === f.zustand ? "selected" : ""}>${n}</option>`).join("")}</select>
      <select class="eingabe" name="sortierung">${[["", "Reihenfolge"], ["faellig", "Fälligkeit"], ["neu", "Zuletzt angelegt"], ["fehler", "Am öftesten vergessen"]]
        .map(([k, n]) => `<option value="${k}" ${k === f.sortierung ? "selected" : ""}>${n}</option>`).join("")}</select>
    </form>
    <div data-liste></div>`;
  const liste = $("[data-liste]", ziel);
  const laden = async () => {
    liste.innerHTML = '<div class="laden"><div class="kreisel"></div></div>';
    const params = { projekt: P.projekt.id, ...Object.fromEntries(Object.entries(f).filter(([, v]) => v !== "")) };
    const d = await holen("karten", params);
    if (!d.karten.length) { liste.innerHTML = '<p class="leer">Keine Karten gefunden.</p>'; return; }
    const tb = APP.ich?.tagesbeginn || Date.now();
    liste.innerHTML = `<p class="klein leise">${zahl(d.gesamt)} Karten</p>
      <table class="liste"><thead><tr><th>Karte</th><th class="mobil-weg">Deck</th><th>Stand</th><th class="mobil-weg">Fällig</th></tr></thead><tbody>
      ${d.karten.map((k) => {
        const n = d.notizen[k.notiz], typ = d.typen[n.typ];
        const [zt, zf] = ZUSTAND_TEXT[k.st] || ZUSTAND_TEXT.null;
        const vorl = typ.art === "normal" ? (typ.vorlagen.length > 1 ? `<span class="leise klein"> · ${e(typ.vorlagen[k.ord]?.name || "")}</span>` : "")
          : `<span class="leise klein"> · ${typ.art === "luecke" ? "Lücke" : "Maske"} ${k.ord}</span>`;
        const kat = n.kategorie != null ? d.kategorien[n.kategorie] : null;
        return `<tr data-notiz="${k.notiz}" data-ord="${k.ord}"><td><div class="vorschau">${kat ? `<span class="chip" style="color:${e(kat.farbe)};margin-right:4px"><span class="punkt"></span></span>` : ""}${e(klartext(kartenTitel(typ, n, k.ord)).slice(0, 160))}${vorl}</div></td>
          <td class="mobil-weg leise klein">${e(decks.find((x) => x.id === n.deck)?.name || "")}</td>
          <td><span class="chip" style="color:${zf}"><span class="punkt"></span>${zt}</span>${k.markiert ? " ★" : ""}${k.pausiert ? ' <span class="chip">pausiert</span>' : ""}</td>
          <td class="mobil-weg klein">${k.faellig && k.st ? e(inTagen(k.faellig, tb)) : "–"}</td></tr>`;
      }).join("")}</tbody></table>
      <div class="zeile" style="justify-content:center;margin-top:12px">
        ${d.seite > 0 ? '<button class="knopf klein" data-seite="-1">‹ Zurück</button>' : ""}
        <span class="klein leise">Seite ${d.seite + 1} von ${Math.max(1, Math.ceil(d.gesamt / d.pro_seite))}</span>
        ${(d.seite + 1) * d.pro_seite < d.gesamt ? '<button class="knopf klein" data-seite="1">Weiter ›</button>' : ""}</div>`;
  };
  let warte;
  $("[data-filter]", ziel).addEventListener("input", (ev) => {
    const fd = new FormData(ev.currentTarget);
    for (const k of Object.keys(f)) if (fd.has(k)) f[k] = fd.get(k);
    f.seite = 0;
    clearTimeout(warte);
    warte = setTimeout(laden, ev.target.name === "suche" ? 300 : 0);
  });
  $("[data-filter]", ziel).addEventListener("submit", (ev) => ev.preventDefault());
  liste.addEventListener("click", (ev) => {
    const s = ev.target.closest("[data-seite]");
    if (s) { f.seite += Number(s.dataset.seite); laden(); return; }
    const z = ev.target.closest("[data-notiz]");
    if (z) notizOeffnen(Number(z.dataset.notiz), { ord: Number(z.dataset.ord), nachAenderung: laden });
  });
  await laden();
}
