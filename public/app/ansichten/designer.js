// Designer für Notiztypen: Bausteine aus der Palette auf Vorder-/Rückseite ziehen (Maus und Touch über Pointer-Events),
// umsortieren, Eigenschaften einstellen; Felder und Kartenvorlagen verwalten; Live-Vorschau mit Beispieldaten.
import { holen, senden } from "../api.js";
import { $, $$, e, ICON, meldung, bestaetigen, eingabeDialog } from "../ui.js";
import { karteHTML, karteBinden } from "../karte.js";
import { bildWaehlen } from "../bildwahl.js";
import { gehe } from "../main.js";

// Palette: Art, Name, Kürzel, Startwerte
const PALETTE = [
  ["Text", [
    ["ueberschrift", "Überschrift", "H", { text: "{{Vorderseite}}", groesse: 2 }],
    ["feld", "Feld", "F", { feld: "", stil: "normal" }],
    ["text", "Text mit Feldern", "T", { text: "Text mit **{{Vorderseite}}**" }],
    ["liste", "Liste", "•", { eintraege: ["Erster Punkt", "Zweiter Punkt"], nummeriert: false }],
    ["kasten", "Merke-Kasten", "!", { art: "merke", titel: "Merke", text: "{{Notiz}}" }],
    ["tabelle", "Tabelle", "▦", { zeilen: [["Spalte A", "Spalte B"], ["…", "…"]], kopf: true }],
  ]],
  ["Medien", [
    ["bild", "Bild", "🖼", { feld: "" }],
    ["audio", "Tondatei", "♪", { feld: "", auto: false }],
    ["sprachausgabe", "Sprachausgabe", "🔊", { feld: "", sprache: "", auto: false }],
  ]],
  ["Abfrage", [
    ["eingabe", "Antwort eintippen", "⌨", { feld: "" }],
    ["auswahl", "Multiple-Choice", "☑", { richtig: "", falsch: "" }],
    ["luecke", "Lückentext", "[…]", { feld: "" }],
    ["verdeckung", "Bild-Verdeckung", "▣", { feld: "", masken: "" }],
  ]],
  ["Sprache", [
    ["sprachtext", "Sprachtext (antippbar)", "Aa", { feld: "", sprachausgabe: true, gross: true }],
    ["genus", "Genus-Effekt", "♂♀", { feld: "" }],
  ]],
  ["Aufbau", [
    ["vorderseite", "Vorderseite wiederholen", "↺", {}],
    ["trenner", "Trennlinie", "—", {}],
    ["abstand", "Abstand", "␣", {}],
    ["tags", "Kategorie, Deck, Tags", "#", { zeige: "alle" }],
  ]],
];
const NAMEN = Object.fromEntries(PALETTE.flatMap(([, l]) => l.map(([t, n, k]) => [t, { n, k }])));
const START = Object.fromEntries(PALETTE.flatMap(([, l]) => l.map(([t, , , s]) => [t, s])));
const SPRACHEN = [["", "aus Feld „Sprache“ / Projekt"], ["cs", "Tschechisch"], ["it", "Italienisch"], ["nl", "Niederländisch"], ["en", "Englisch"], ["fr", "Französisch"], ["es", "Spanisch"], ["de", "Deutsch"], ["da", "Dänisch"]];

let D = null;

export async function zeigen(app, [id], param) {
  let typ;
  if (id === "neu") {
    const pid = Number(param.projekt);
    if (param.vorlage) {
      const v = (await holen("notiztyp", { id: param.vorlage })).typ;
      typ = { ...v, id: null, projekt: pid, name: `${v.name} (Kopie)`, eingebaut: null };
    } else {
      typ = {
        id: null, projekt: pid, name: "Neuer Notiztyp", art: "normal", stil: {},
        felder: [{ name: "Vorderseite", art: "md" }, { name: "Rückseite", art: "md" }, { name: "Notiz", art: "md" }],
        vorlagen: [{ name: "Karte 1", bedingung: "", vorne: [{ t: "feld", feld: "Vorderseite", stil: "gross" }], hinten: [{ t: "vorderseite" }, { t: "trenner" }, { t: "feld", feld: "Rückseite" }, { t: "kasten", art: "info", text: "{{Notiz}}", wenn: "Notiz" }] }],
      };
    }
  } else {
    typ = (await holen("notiztyp", { id })).typ;
  }
  typ.felder = typ.felder.map((f) => ({ ...f, alt: f.name }));
  if (!typ.stil || Array.isArray(typ.stil)) typ.stil = {};
  const projekt = typ.projekt ? (await holen("projekt", { id: typ.projekt })).projekt : null;
  D = { app, typ, projekt, vorlage: 0, gewaehlt: null, nurLesen: !!typ.eingebaut || (projekt && !projekt.darf_bearbeiten), geaendert: false };
  zeichnen();
}

export function verlassen() {
  if (D?.geaendert && !D.gespeichert) meldung("Hinweis: Änderungen am Notiztyp wurden nicht gespeichert.");
  D = null;
}

/* ---------- Beispieldaten für die Vorschau ---------- */

function beispiel() {
  const f = {};
  for (const x of D.typ.felder) {
    if (x.art === "bild") f[x.name] = "bilder/kacheln/nl.webp";
    else if (x.art === "masken") f[x.name] = JSON.stringify([{ x: 20, y: 30, w: 22, h: 16, text: "Mühle", gruppe: "" }, { x: 58, y: 62, w: 26, h: 14, text: "Gracht", gruppe: "" }]);
    else if (x.art === "audio") f[x.name] = "";
    else if (/^genus$/i.test(x.name)) f[x.name] = "f";
    else if (/^sprache$/i.test(x.name)) f[x.name] = D.projekt?.sprache || "it";
    else if (/^falsch$/i.test(x.name)) f[x.name] = "Falsche Antwort 1\nFalsche Antwort 2";
    else if (D.typ.art === "luecke" && D.typ.vorlagen[0].vorne.some((b) => b.t === "luecke" && b.feld === x.name)) f[x.name] = "Die {{c1::Lunge}} nimmt {{c2::Sauerstoff}} auf.";
    else f[x.name] = `‹${x.name}›`;
  }
  return { id: 1, felder: f, tags: "beispiel", kategorie: null, deck: 0, zusatz: {} };
}

function felderNach(arten) {
  return D.typ.felder.filter((f) => !arten || arten.includes(f.art)).map((f) => f.name);
}

/* ---------- Zeichnen ---------- */

function bausteinVorschau(b) {
  const typ = { ...D.typ, art: "normal", vorlagen: [{ vorne: [b.t === "vorderseite" ? { t: "text", text: "*(Vorderseite wird hier wiederholt)*" } : b], hinten: [] }] };
  const n = beispiel();
  const ordnung = D.typ.art === "normal" ? 0 : 1;
  let html = karteHTML({ typ: { ...typ, art: D.typ.art === "normal" ? "normal" : D.typ.art }, notiz: n, ord: ordnung, hinten: false, genusEffekte: false, sprache: D.projekt?.sprache });
  // Nur den Inhalt (ohne Kartenrahmen) zeigen
  html = html.replace(/^<div class="karte[^"]*"[^>]*>/, "").replace(/<\/div>$/, "");
  if (b.t === "genus") html = `<span class="chip">Genus aus Feld „${e(b.feld || "?")}“: Farbe und Effekt am Kartenrand</span>`;
  if (!html.trim() || html.includes('class="leise">(leer)')) html = `<span class="leise klein">${e(NAMEN[b.t]?.n || b.t)}${b.feld ? ` · ${e(b.feld)}` : ""}${b.wenn ? ` · nur wenn „${e(b.wenn)}“ gefüllt` : ""}</span>`;
  return html;
}

function ablageHTML(seite) {
  const v = D.typ.vorlagen[D.vorlage];
  const liste = v[seite] || [];
  return liste.length
    ? liste.map((b, i) => `<div class="baustein ${D.gewaehlt?.seite === seite && D.gewaehlt.i === i ? "gewaehlt" : ""}" data-seite="${seite}" data-i="${i}" tabindex="0">
        <span class="griff" data-griff>${ICON.griff.replace("<svg", '<svg width="16" height="16"')}</span><span class="etikett">${e(NAMEN[b.t]?.n || b.t)}${b.wenn ? " · bedingt" : ""}</span>
        <div class="inhalt">${bausteinVorschau(b)}</div></div>`).join("")
    : `<p class="hinweis-leer">Bausteine hierher ziehen<br><span class="klein">oder in der Palette antippen</span></p>`;
}

function zeichnen() {
  const t = D.typ;
  const lesen = D.nurLesen;
  D.app.innerHTML = `
    <div class="zeile" style="margin-bottom:12px;justify-content:space-between">
      <div class="zeile"><a class="knopf leise rund" href="#/projekt/${t.projekt || ""}/einstellungen" aria-label="Zurück">${ICON.zurueck}</a>
        <h1 style="margin:0">${lesen ? e(t.name) : `<input class="eingabe" data-name value="${e(t.name)}" style="font:600 1.5rem var(--schrift-titel);padding:4px 10px;width:min(420px,70vw)">`}</h1></div>
      <div class="zeile">${lesen ? (D.projekt?.darf_bearbeiten ? `<a class="knopf haupt" href="#/designer/neu?projekt=${t.projekt}&vorlage=${t.id}">${ICON.kopie} Kopie zum Gestalten</a>` : "") + '<span class="chip">eingebaut – nur ansehen</span>'
        : `${t.id ? `<button class="knopf gefahr klein" data-typ-loeschen>${ICON.papierkorb}</button>` : ""}<button class="knopf haupt" data-speichern>Speichern</button>`}</div>
    </div>
    <div class="designer">
      <aside class="palette" ${lesen ? 'style="opacity:.45;pointer-events:none"' : ""}>
        ${PALETTE.map(([g, l]) => `<p class="gruppe">${e(g)}</p>${l.map(([art, n, k]) => `<div class="baustein-vorlage" data-neu="${art}" tabindex="0" role="button" title="Ziehen oder antippen"><span class="ic">${e(k)}</span>${e(n)}</div>`).join("")}`).join("")}
      </aside>
      <section>
        <div class="vorlagen-reiter">
          ${t.art === "normal" ? t.vorlagen.map((v, i) => `<button class="knopf klein" data-vorlage="${i}" aria-pressed="${i === D.vorlage}">${e(v.name)}</button>`).join("") : `<span class="chip">${t.art === "luecke" ? "Eine Karte je Lücke {{c1::…}}" : "Eine Karte je Maske"}</span>`}
          ${t.art === "normal" && !lesen ? `<button class="knopf klein leise" data-vorlage-neu>${ICON.plus} Kartenrichtung</button>` : ""}
        </div>
        <div class="leinwand-paar">
          <div class="leinwand"><h3>Vorderseite</h3><div class="ablage" data-ablage="vorne">${ablageHTML("vorne")}</div></div>
          <div class="leinwand"><h3>Rückseite</h3><div class="ablage" data-ablage="hinten">${ablageHTML("hinten")}</div></div>
        </div>
        <h3 style="margin:22px 0 8px">Vorschau mit Beispieldaten</h3>
        <div class="leinwand-paar" data-gesamtvorschau></div>
      </section>
      <aside class="eigenschaften" data-eigenschaften></aside>
    </div>`;
  gesamtVorschau();
  eigenschaftenZeichnen();
  binden();
}

function gesamtVorschau() {
  const z = $("[data-gesamtvorschau]", D.app);
  const n = beispiel();
  const ord = D.typ.art === "normal" ? D.vorlage : 1;
  z.innerHTML = `<div>${karteHTML({ typ: D.typ, notiz: n, ord, hinten: false, sprache: D.projekt?.sprache })}</div><div>${karteHTML({ typ: D.typ, notiz: n, ord, hinten: true, sprache: D.projekt?.sprache })}</div>`;
  $$(".karte", z).forEach((k) => karteBinden(k, { autoTon: false }));
}

function auswahlFeld(name, wert, felder, { leer = "–", label } = {}) {
  return `<label class="feld">${e(label || name)}<select class="eingabe" data-p="${name}"><option value="">${e(leer)}</option>${felder.map((f) => `<option ${f === wert ? "selected" : ""}>${e(f)}</option>`).join("")}</select></label>`;
}
function textFeld(name, wert, label, mehrzeilig = false) {
  return `<label class="feld">${e(label)}${mehrzeilig ? `<textarea class="eingabe" data-p="${name}" rows="3">${e(wert ?? "")}</textarea>` : `<input class="eingabe" data-p="${name}" value="${e(wert ?? "")}">`}</label>`;
}
function hakenFeld(name, wert, label) {
  return `<label class="zeile klein" style="gap:6px"><input type="checkbox" data-p="${name}" ${wert ? "checked" : ""}> ${e(label)}</label>`;
}

function eigenschaftenZeichnen() {
  const z = $("[data-eigenschaften]", D.app);
  const lesen = D.nurLesen;
  const t = D.typ;
  const v = t.vorlagen[D.vorlage];
  let block = "";
  const g = D.gewaehlt;
  if (g && v[g.seite][g.i]) {
    const b = v[g.seite][g.i];
    const alle = felderNach(), texte = felderNach(["text", "md"]), bilder = felderNach(["bild"]);
    const einfueger = `<div class="klein leise">Felder einfügen: ${alle.map((f) => `<button type="button" class="chip" data-einfuegen="${e(f)}" style="border:0;cursor:pointer">{{${e(f)}}}</button>`).join(" ")}</div>`;
    const teile = {
      ueberschrift: () => textFeld("text", b.text, "Text") + einfueger + `<label class="feld">Größe<select class="eingabe" data-p="groesse">${[1, 2, 3].map((n) => `<option value="${n}" ${Number(b.groesse) === n ? "selected" : ""}>${["groß", "mittel", "klein"][n - 1]}</option>`).join("")}</select></label>`,
      text: () => textFeld("text", b.text, "Text (Markdown)", true) + einfueger,
      feld: () => auswahlFeld("feld", b.feld, alle, { label: "Feld" }) + `<label class="feld">Darstellung<select class="eingabe" data-p="stil">${[["normal", "normal"], ["gross", "groß"], ["klein", "klein"], ["leise", "leise (grau)"]].map(([k, n]) => `<option value="${k}" ${b.stil === k ? "selected" : ""}>${n}</option>`).join("")}</select></label>`,
      liste: () => auswahlFeld("feld", b.feld, texte, { label: "Einträge aus Feld (eine Zeile je Punkt)", leer: "– feste Einträge –" }) + (b.feld ? "" : `<label class="feld">Feste Einträge (eine Zeile je Punkt)<textarea class="eingabe" data-p="eintraege" rows="4">${e((b.eintraege || []).join("\n"))}</textarea></label>`) + hakenFeld("nummeriert", b.nummeriert, "nummeriert"),
      kasten: () => `<label class="feld">Art<select class="eingabe" data-p="art">${[["merke", "Merke"], ["achtung", "Achtung"], ["info", "Info"]].map(([k, n]) => `<option value="${k}" ${b.art === k ? "selected" : ""}>${n}</option>`).join("")}</select></label>` + textFeld("titel", b.titel, "Titel") + textFeld("text", b.text, "Text (Markdown)", true) + einfueger,
      tabelle: () => auswahlFeld("feld", b.feld, texte, { label: "Tabelle aus Feld (Markdown)", leer: "– feste Tabelle –" }) + (b.feld ? "" : `<label class="feld">Zeilen (Zellen mit | trennen)<textarea class="eingabe" data-p="zeilen" rows="4">${e((b.zeilen || []).map((r) => r.join(" | ")).join("\n"))}</textarea></label>${hakenFeld("kopf", b.kopf, "erste Zeile ist Kopf")}`),
      bild: () => auswahlFeld("feld", b.feld, bilder, { label: "Bild aus Feld", leer: "– festes Bild –" }) + (b.feld ? "" : `<button type="button" class="knopf klein" data-festes-bild>${ICON.bild} Festes Bild wählen</button>`) + textFeld("hoehe", b.hoehe, "Höchste Höhe in Pixel (optional)"),
      audio: () => auswahlFeld("feld", b.feld, felderNach(["audio"]), { label: "Tondatei aus Feld" }) + hakenFeld("auto", b.auto, "automatisch abspielen (Rückseite)"),
      sprachausgabe: () => auswahlFeld("feld", b.feld, texte, { label: "Text aus Feld" }) + `<label class="feld">Sprache<select class="eingabe" data-p="sprache">${SPRACHEN.map(([k, n]) => `<option value="${k}" ${b.sprache === k ? "selected" : ""}>${n}</option>`).join("")}</select></label>` + hakenFeld("auto", b.auto, "automatisch vorlesen"),
      eingabe: () => auswahlFeld("feld", b.feld, texte, { label: "Richtige Antwort aus Feld" }) + '<p class="klein leise" style="margin:0">Gehört auf Vorder- und Rückseite: vorne das Eingabefeld, hinten der Vergleich.</p>',
      auswahl: () => auswahlFeld("richtig", b.richtig, texte, { label: "Richtige Antwort aus Feld" }) + auswahlFeld("falsch", b.falsch, texte, { label: "Falsche Antworten aus Feld (eine je Zeile)" }) + auswahlFeld("optionen", b.optionen, texte, { label: "oder: feste Optionen aus Feld (mit | getrennt)" }) + hakenFeld("sofort", b.sofort, "Antippen deckt sofort auf"),
      luecke: () => auswahlFeld("feld", b.feld, texte, { label: "Lückentext aus Feld" }) + '<p class="klein leise" style="margin:0">Lücken im Feld als {{c1::Antwort}} oder {{c1::Antwort::Hinweis}}.</p>',
      verdeckung: () => auswahlFeld("feld", b.feld, bilder, { label: "Bild aus Feld" }) + auswahlFeld("masken", b.masken, felderNach(["masken"]), { label: "Masken aus Feld" }),
      tags: () => `<label class="feld">Zeigen<select class="eingabe" data-p="zeige">${[["alle", "alles"], ["kategorie", "nur Kategorie"], ["deck", "nur Deck"], ["tags", "nur Tags"]].map(([k, n]) => `<option value="${k}" ${b.zeige === k ? "selected" : ""}>${n}</option>`).join("")}</select></label>`,
      sprachtext: () => auswahlFeld("feld", b.feld, texte, { label: "Text aus Feld" }) + `<label class="feld">Sprache<select class="eingabe" data-p="sprache">${SPRACHEN.map(([k, n]) => `<option value="${k}" ${b.sprache === k ? "selected" : ""}>${n}</option>`).join("")}</select></label>` + hakenFeld("sprachausgabe", b.sprachausgabe, "Knöpfe zum Anhören") + hakenFeld("gross", b.gross, "groß") + hakenFeld("auto", b.auto, "automatisch vorlesen"),
      genus: () => auswahlFeld("feld", b.feld, texte, { label: "Genus aus Feld (m, f, n, de, het)" }),
    };
    block = `<div class="karte-box"><div class="zeile" style="justify-content:space-between"><h3 style="margin:0">${e(NAMEN[b.t]?.n || b.t)}</h3>
        ${lesen ? "" : `<span class="zeile"><button class="knopf klein rund leise" data-verschieben="-1" aria-label="Nach oben">↑</button><button class="knopf klein rund leise" data-verschieben="1" aria-label="Nach unten">↓</button><button class="knopf klein rund leise gefahr" data-entfernen aria-label="Baustein entfernen">${ICON.papierkorb}</button></span>`}</div>
      <fieldset data-props style="border:0;padding:0;margin:0;display:grid;gap:10px" ${lesen ? "disabled" : ""}>
        ${teile[b.t]?.() || '<p class="klein leise" style="margin:0">Keine Einstellungen.</p>'}
        ${b.t !== "vorderseite" ? auswahlFeld("wenn", b.wenn, alle, { label: "Nur zeigen, wenn dieses Feld gefüllt ist", leer: "immer zeigen" }) : ""}
      </fieldset></div>`;
  } else {
    block = '<div class="karte-box"><p class="klein leise" style="margin:0">Tipp: Baustein anklicken, um ihn einzustellen. <kbd>Entf</kbd> entfernt, <kbd>Alt</kbd>+<kbd>↑</kbd>/<kbd>↓</kbd> verschiebt.</p></div>';
  }
  const felder = `<div class="karte-box"><h3 style="margin:0">Felder</h3>
    <fieldset class="felderliste" data-felder style="border:0;padding:0;margin:0" ${lesen ? "disabled" : ""}>
      ${t.felder.map((f, i) => `<div class="zeile" data-f="${i}"><input class="eingabe" data-f-name value="${e(f.name)}" aria-label="Feldname">
        <select class="eingabe" data-f-art style="width:110px" aria-label="Feldart">${[["text", "Text"], ["md", "Markdown"], ["bild", "Bild"], ["audio", "Ton"], ["masken", "Masken"]].map(([k, n]) => `<option value="${k}" ${f.art === k ? "selected" : ""}>${n}</option>`).join("")}</select>
        <button type="button" class="knopf klein rund leise" data-f-weg aria-label="Feld entfernen">${ICON.x}</button></div>`).join("")}
      <button type="button" class="knopf klein" data-f-neu>${ICON.plus} Feld</button></fieldset></div>`;
  const typBox = `<div class="karte-box"><h3 style="margin:0">Karte</h3><fieldset data-typprops style="border:0;padding:0;margin:0;display:grid;gap:10px" ${lesen ? "disabled" : ""}>
      ${!t.id ? `<label class="feld">Art<select class="eingabe" data-t="art">${[["normal", "normal (je Vorlage eine Karte)"], ["luecke", "Lückentext (je Lücke eine Karte)"], ["verdeckung", "Bild-Verdeckung (je Maske eine Karte)"]].map(([k, n]) => `<option value="${k}" ${t.art === k ? "selected" : ""}>${n}</option>`).join("")}</select></label>` : ""}
      ${t.art === "normal" ? `<label class="feld">Name dieser Kartenrichtung<input class="eingabe" data-t="vname" value="${e(v.name)}"></label>
        <label class="feld">Karte nur anlegen, wenn Feld gefüllt<select class="eingabe" data-t="bedingung"><option value="">immer</option>${felderNach().map((f) => `<option ${f === v.bedingung ? "selected" : ""}>${e(f)}</option>`).join("")}</select></label>
        ${t.vorlagen.length > 1 ? '<button type="button" class="knopf klein gefahr" data-vorlage-weg>Diese Kartenrichtung entfernen</button>' : ""}` : ""}
      <div class="spalten" style="grid-template-columns:1fr 1fr">
        <label class="feld">Ausrichtung<select class="eingabe" data-s="ausrichtung"><option value="mitte">mittig</option><option value="links" ${t.stil.ausrichtung === "links" ? "selected" : ""}>links</option></select></label>
        <label class="feld">Schrift<select class="eingabe" data-s="schrift">${[["sans", "Inter"], ["serif", "Fraunces"], ["mono", "Mono"]].map(([k, n]) => `<option value="${k}" ${(t.stil.schrift || "sans") === k ? "selected" : ""}>${n}</option>`).join("")}</select></label>
        <label class="feld">Größe<select class="eingabe" data-s="groesse">${[["normal", "normal"], ["klein", "klein"], ["gross", "groß"]].map(([k, n]) => `<option value="${k}" ${(t.stil.groesse || "normal") === k ? "selected" : ""}>${n}</option>`).join("")}</select></label>
        <label class="feld">Hintergrund<span class="zeile"><input type="color" data-s="hintergrund" value="${e(t.stil.hintergrund || "#fffdf9")}"><button type="button" class="knopf klein leise" data-s-weg>Standard</button></span></label>
      </div></fieldset></div>`;
  z.innerHTML = block + felder + typBox;
}

function vorlageAktuell() {
  return D.typ.vorlagen[D.vorlage];
}
function geaendert({ alles = false } = {}) {
  D.geaendert = true;
  if (alles) return zeichnen();
  for (const s of ["vorne", "hinten"]) $(`[data-ablage="${s}"]`, D.app).innerHTML = ablageHTML(s);
  gesamtVorschau();
}

function einfuegen(seite, index, art, block) {
  const liste = vorlageAktuell()[seite];
  const b = block || { t: art, ...structuredClone(START[art] || {}) };
  // Sinnvolle Startfelder: erstes passendes Feld des Typs
  if (!block) {
    const erstes = (arten) => felderNach(arten)[0] || "";
    if ("feld" in b && !b.feld) b.feld = ["bild", "verdeckung"].includes(art) ? erstes(["bild"]) : art === "audio" ? erstes(["audio"]) : erstes(["text", "md"]);
    if (art === "verdeckung" && !b.masken) b.masken = erstes(["masken"]);
    if (art === "auswahl") { b.richtig = felderNach().find((f) => /richtig|antwort/i.test(f)) || erstes(["text", "md"]); b.falsch = felderNach().find((f) => /falsch/i.test(f)) || ""; }
    if (art === "ueberschrift" || art === "text") b.text = b.text.replace("Vorderseite", felderNach(["text", "md"])[0] || "Vorderseite");
    if (art === "kasten") b.text = `{{${felderNach(["text", "md"]).find((f) => /notiz|extra|erkl/i.test(f)) || felderNach(["text", "md"])[0] || "Notiz"}}}`;
  }
  liste.splice(index, 0, b);
  D.gewaehlt = { seite, i: index };
  geaendert();
  eigenschaftenZeichnen();
}

/* ---------- Ziehen und Ablegen (Pointer-Events, funktioniert mit Maus und Finger) ---------- */

function ziehenStarten(ev, quelle) {
  if (D.nurLesen || ev.button > 0) return;
  const start = { x: ev.clientX, y: ev.clientY };
  let schwebend = null, marke = null, ziel = null;
  const touch = ev.pointerType === "touch";
  let bereit = !touch; // Finger: erst nach kurzem Halten, damit Scrollen geht
  const halten = touch ? setTimeout(() => { bereit = true; navigator.vibrate?.(15); }, 260) : null;
  const bewegen = (e2) => {
    if (!bereit) { if (Math.hypot(e2.clientX - start.x, e2.clientY - start.y) > 8) aufraeumen(); return; }
    if (!schwebend) {
      if (Math.hypot(e2.clientX - start.x, e2.clientY - start.y) < 6) return;
      const el = quelle.element;
      schwebend = el.cloneNode(true);
      schwebend.classList.add("schwebend");
      schwebend.style.width = el.getBoundingClientRect().width + "px";
      document.body.append(schwebend);
      if (quelle.seite) el.style.opacity = ".3";
      marke = document.createElement("div");
      marke.className = "einfuegemarke";
    }
    e2.preventDefault();
    schwebend.style.left = e2.clientX + 8 + "px";
    schwebend.style.top = e2.clientY - 14 + "px";
    const unter = document.elementFromPoint(e2.clientX, e2.clientY);
    const ablage = unter?.closest("[data-ablage]");
    $$(".ablage.drueber", D.app).forEach((a) => a !== ablage && a.classList.remove("drueber"));
    if (!ablage) { marke.remove(); ziel = null; return; }
    ablage.classList.add("drueber");
    const kinder = $$(".baustein", ablage).filter((k) => k.style.opacity !== ".3");
    let idx = kinder.length;
    for (let i = 0; i < kinder.length; i++) {
      const r = kinder[i].getBoundingClientRect();
      if (e2.clientY < r.top + r.height / 2) { idx = i; break; }
    }
    if (kinder[idx]) ablage.insertBefore(marke, kinder[idx]); else ablage.append(marke);
    ziel = { seite: ablage.dataset.ablage, idx: kinder[idx] ? Number(kinder[idx].dataset.i) : vorlageAktuell()[ablage.dataset.ablage].length };
  };
  const loslassen = () => {
    const war = !!schwebend;
    aufraeumen();
    if (!war) { if (quelle.art) einfuegen(D.gewaehlt?.seite || "vorne", vorlageAktuell()[D.gewaehlt?.seite || "vorne"].length, quelle.art); return; }
    if (!ziel) { if (quelle.seite) geaendert(); return; }
    if (quelle.art) return einfuegen(ziel.seite, ziel.idx, quelle.art);
    // Verschieben innerhalb oder zwischen den Seiten
    const von = vorlageAktuell()[quelle.seite];
    const [b] = von.splice(quelle.i, 1);
    let idx = ziel.idx;
    if (quelle.seite === ziel.seite && quelle.i < idx) idx--;
    vorlageAktuell()[ziel.seite].splice(idx, 0, b);
    D.gewaehlt = { seite: ziel.seite, i: idx };
    geaendert();
    eigenschaftenZeichnen();
  };
  function aufraeumen() {
    clearTimeout(halten);
    removeEventListener("pointermove", bewegen);
    removeEventListener("pointerup", loslassen);
    removeEventListener("pointercancel", aufraeumen);
    schwebend?.remove();
    marke?.remove();
    $$(".ablage.drueber", D.app).forEach((a) => a.classList.remove("drueber"));
    if (quelle.element) quelle.element.style.opacity = "";
  }
  addEventListener("pointermove", bewegen, { passive: false });
  addEventListener("pointerup", loslassen);
  addEventListener("pointercancel", aufraeumen);
}

function binden() {
  const app = D.app;
  app.onpointerdown = (ev) => {
    const neu = ev.target.closest("[data-neu]");
    if (neu) return ziehenStarten(ev, { art: neu.dataset.neu, element: neu });
    const b = ev.target.closest(".baustein");
    if (b && !D.nurLesen) {
      D.gewaehlt = { seite: b.dataset.seite, i: Number(b.dataset.i) };
      $$(".baustein.gewaehlt", app).forEach((x) => x.classList.remove("gewaehlt"));
      b.classList.add("gewaehlt");
      eigenschaftenZeichnen();
      ziehenStarten(ev, { seite: b.dataset.seite, i: Number(b.dataset.i), element: b });
    } else if (b) {
      D.gewaehlt = { seite: b.dataset.seite, i: Number(b.dataset.i) };
      eigenschaftenZeichnen();
    }
  };
  app.onkeydown = (ev) => {
    const neu = ev.target.closest("[data-neu]");
    if (neu && (ev.key === "Enter" || ev.key === " ")) { ev.preventDefault(); einfuegen(D.gewaehlt?.seite || "vorne", vorlageAktuell()[D.gewaehlt?.seite || "vorne"].length, neu.dataset.neu); }
    const b = ev.target.closest(".baustein");
    if (b && !D.nurLesen) {
      if (ev.key === "Delete" || ev.key === "Backspace") { ev.preventDefault(); entfernen(); }
      if (ev.altKey && (ev.key === "ArrowUp" || ev.key === "ArrowDown")) { ev.preventDefault(); verschieben(ev.key === "ArrowUp" ? -1 : 1); }
    }
  };
  app.onclick = async (ev) => {
    const vl = ev.target.closest("[data-vorlage]");
    if (vl) { D.vorlage = Number(vl.dataset.vorlage); D.gewaehlt = null; zeichnen(); }
    if (ev.target.closest("[data-vorlage-neu]")) {
      const name = await eingabeDialog("Neue Kartenrichtung", { feld: "Name", wert: `Karte ${D.typ.vorlagen.length + 1}` });
      if (name) {
        const erste = D.typ.felder[0]?.name, zweite = D.typ.felder[1]?.name;
        D.typ.vorlagen.push({ name, bedingung: "", vorne: [{ t: "feld", feld: zweite || erste, stil: "gross" }], hinten: [{ t: "vorderseite" }, { t: "trenner" }, { t: "feld", feld: erste }] });
        D.vorlage = D.typ.vorlagen.length - 1;
        D.gewaehlt = null;
        geaendert({ alles: true });
      }
    }
    if (ev.target.closest("[data-vorlage-weg]")) {
      if (!(await bestaetigen(`Kartenrichtung „${vorlageAktuell().name}“ entfernen? Die zugehörigen Karten werden beim Speichern gelöscht.`, { ja: "Entfernen", gefahr: true }))) return;
      D.typ.vorlagen.splice(D.vorlage, 1);
      D.vorlage = 0;
      D.gewaehlt = null;
      geaendert({ alles: true });
    }
    if (ev.target.closest("[data-entfernen]")) entfernen();
    const vs = ev.target.closest("[data-verschieben]");
    if (vs) verschieben(Number(vs.dataset.verschieben));
    const ein = ev.target.closest("[data-einfuegen]");
    if (ein) {
      const feld = $('[data-props] [data-p="text"]', app);
      if (feld) { feld.setRangeText(`{{${ein.dataset.einfuegen}}}`, feld.selectionStart, feld.selectionEnd, "end"); feld.dispatchEvent(new Event("input", { bubbles: true })); feld.focus(); }
    }
    if (ev.target.closest("[data-festes-bild]")) {
      const v = await bildWaehlen();
      if (v) { aktuellerBlock().quelle = v; geaendert(); }
    }
    if (ev.target.closest("[data-f-neu]")) {
      let n = "Feld", i = 1;
      while (D.typ.felder.some((f) => f.name === `${n} ${i}`)) i++;
      D.typ.felder.push({ name: `${n} ${i}`, art: "text", alt: "" });
      geaendert({ alles: true });
    }
    const fw = ev.target.closest("[data-f-weg]");
    if (fw) {
      const i = Number(fw.closest("[data-f]").dataset.f);
      if (D.typ.felder.length <= 1) return meldung("Ein Feld muss bleiben.", "fehler");
      if (D.typ.id && !(await bestaetigen(`Feld „${D.typ.felder[i].name}“ entfernen? Die Inhalte dieses Felds gehen in allen Notizen verloren.`, { ja: "Entfernen", gefahr: true }))) return;
      D.typ.felder.splice(i, 1);
      geaendert({ alles: true });
    }
    if (ev.target.closest("[data-s-weg]")) { delete D.typ.stil.hintergrund; geaendert({ alles: true }); }
    if (ev.target.closest("[data-speichern]")) speichern();
    if (ev.target.closest("[data-typ-loeschen]")) {
      if (!(await bestaetigen(`Notiztyp „${D.typ.name}“ löschen?`, { ja: "Löschen", gefahr: true }))) return;
      try { await senden("notiztyp_loeschen", { id: D.typ.id }); D.gespeichert = true; gehe(`/projekt/${D.typ.projekt}/einstellungen`); } catch (err) { meldung(err.message, "fehler"); }
    }
  };
  app.oninput = (ev) => {
    if (ev.target.matches("[data-name]")) { D.typ.name = ev.target.value; D.geaendert = true; return; }
    const p = ev.target.closest("[data-p]");
    if (p) {
      const b = aktuellerBlock();
      const k = p.dataset.p;
      let w = p.type === "checkbox" ? p.checked : p.value;
      if (k === "eintraege") w = p.value.split("\n");
      if (k === "zeilen") w = p.value.split("\n").filter((z) => z.trim()).map((z) => z.split("|").map((c) => c.trim()));
      if (k === "groesse" && b.t === "ueberschrift") w = Number(w);
      if (w === "" || w === false) delete b[k]; else b[k] = w;
      geaendert();
      if (["feld"].includes(k) && ["liste", "tabelle", "bild"].includes(b.t)) eigenschaftenZeichnen();
      return;
    }
    const f = ev.target.closest("[data-f]");
    if (f) {
      const feld = D.typ.felder[Number(f.dataset.f)];
      if (ev.target.matches("[data-f-name]")) {
        const alt = feld.name, neu = ev.target.value.replace(/[{}]/g, "").trim();
        if (!neu) return;
        feld.name = neu;
        // Feldnamen in allen Bausteinen mitziehen
        for (const v of D.typ.vorlagen) {
          if (v.bedingung === alt) v.bedingung = neu;
          for (const b of [...v.vorne, ...v.hinten]) for (const [k, w] of Object.entries(b)) {
            if (w === alt && ["feld", "richtig", "falsch", "optionen", "masken", "wenn"].includes(k)) b[k] = neu;
            if (typeof w === "string" && ["text"].includes(k)) b[k] = w.split(`{{${alt}}}`).join(`{{${neu}}}`);
          }
        }
      }
      if (ev.target.matches("[data-f-art]")) feld.art = ev.target.value;
      geaendert();
      return;
    }
    const t = ev.target.closest("[data-t]");
    if (t) {
      const k = t.dataset.t;
      if (k === "art") {
        D.typ.art = t.value;
        if (t.value === "luecke") { D.typ.felder = [{ name: "Text", art: "md", alt: "" }, { name: "Extra", art: "md", alt: "" }]; D.typ.vorlagen = [{ name: "Lücke", vorne: [{ t: "luecke", feld: "Text" }], hinten: [{ t: "luecke", feld: "Text" }, { t: "feld", feld: "Extra", stil: "leise", wenn: "Extra" }] }]; }
        if (t.value === "verdeckung") { D.typ.felder = [{ name: "Überschrift", art: "text", alt: "" }, { name: "Bild", art: "bild", alt: "" }, { name: "Masken", art: "masken", alt: "" }, { name: "Extra", art: "md", alt: "" }]; D.typ.vorlagen = [{ name: "Maske", vorne: [{ t: "ueberschrift", text: "{{Überschrift}}", groesse: 2, wenn: "Überschrift" }, { t: "verdeckung", feld: "Bild", masken: "Masken" }], hinten: [{ t: "verdeckung", feld: "Bild", masken: "Masken" }, { t: "feld", feld: "Extra", wenn: "Extra" }] }]; }
        D.vorlage = 0;
        D.gewaehlt = null;
        return geaendert({ alles: true });
      }
      if (k === "vname") { vorlageAktuell().name = t.value; D.geaendert = true; $(`[data-vorlage="${D.vorlage}"]`, D.app).textContent = t.value; return; }
      if (k === "bedingung") { vorlageAktuell().bedingung = t.value; D.geaendert = true; return; }
    }
    const s = ev.target.closest("[data-s]");
    if (s) { D.typ.stil[s.dataset.s] = s.value; geaendert(); }
  };
}

function aktuellerBlock() {
  const g = D.gewaehlt;
  return g ? vorlageAktuell()[g.seite][g.i] : null;
}
function entfernen() {
  const g = D.gewaehlt;
  if (!g) return;
  vorlageAktuell()[g.seite].splice(g.i, 1);
  D.gewaehlt = null;
  geaendert();
  eigenschaftenZeichnen();
}
function verschieben(r) {
  const g = D.gewaehlt;
  if (!g) return;
  const l = vorlageAktuell()[g.seite];
  const j = g.i + r;
  if (j < 0 || j >= l.length) return;
  [l[g.i], l[j]] = [l[j], l[g.i]];
  D.gewaehlt = { seite: g.seite, i: j };
  geaendert();
  $(`.baustein[data-seite="${g.seite}"][data-i="${j}"]`, D.app)?.focus();
}

async function speichern() {
  const t = D.typ;
  try {
    const r = await senden("notiztyp_speichern", { id: t.id || undefined, projekt: t.projekt, name: t.name, art: t.art, felder: t.felder, vorlagen: t.vorlagen, stil: t.stil });
    D.gespeichert = true;
    meldung("Notiztyp gespeichert.");
    if (!t.id) gehe(`/designer/${r.id}`);
    else { t.felder = t.felder.map((f) => ({ ...f, alt: f.name })); D.geaendert = false; D.gespeichert = false; }
  } catch (err) {
    meldung(err.message, "fehler");
  }
}
