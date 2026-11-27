// Karten-Editor: Notiztyp, Deck, Kategorie, Tags, Felder (Text, Markdown mit Knopfleiste, Bild, Audio, Masken)
// und Live-Vorschau aller Karten der Notiz. „Speichern & nächste“ behält Deck, Typ und Tags.
import { holen, senden, hochladen } from "../api.js";
import { $, $$, e, ICON, meldung, bestaetigen } from "../ui.js";
import { karteHTML, karteBinden } from "../karte.js";
import { bildWaehlen } from "../bildwahl.js";
import { url, spiele } from "../medien.js";
import { APP, gehe } from "../main.js";

let N = null;

function merken(pid, w) {
  try { localStorage.setItem("kk-editor-" + pid, JSON.stringify(w)); } catch {}
}
function gemerkt(pid) {
  try { return JSON.parse(localStorage.getItem("kk-editor-" + pid) || "{}"); } catch { return {}; }
}

function deckListe(baum, tiefe = 0, aus = []) {
  for (const d of baum) { aus.push({ id: d.id, name: d.name, tiefe }); deckListe(d.kinder, tiefe + 1, aus); }
  return aus;
}

export async function zeigen(app, [id], param) {
  let notiz = null, projektId = Number(param.projekt) || 0;
  if (id !== "neu") {
    const d = await holen("notiz", { id });
    if (!d.darf_bearbeiten) throw new Error("Importierte Karten bekommen ihren Inhalt aus der Quelle. Eigene Ergänzungen gehen über „Meine Zusätze“.");
    notiz = d.notiz;
    projektId = notiz.projekt;
  }
  if (!projektId && param.deck) {
    // Projekt zum Deck suchen
    const u = await holen("uebersicht");
    for (const p of u.projekte) {
      const pd = await holen("projekt", { id: p.id });
      if (deckListe(pd.decks).some((d) => d.id === Number(param.deck))) { projektId = p.id; break; }
    }
  }
  const P = await holen("projekt", { id: projektId });
  if (!P.projekt.darf_bearbeiten) throw new Error("In eingebauten Projekten legst Du keine Karten an.");
  const decks = deckListe(P.decks);
  const typen = P.typen.filter((t) => !/^(its|sprache)-/.test(t.eingebaut || "") || notiz?.typ === t.id);
  const g = gemerkt(projektId);
  const typId = notiz?.typ || Number(param.typ) || (typen.some((t) => t.id === g.typ) ? g.typ : typen[0]?.id);
  N = {
    app, P, decks, typen, notiz,
    deck: notiz?.deck || Number(param.deck) || (decks.some((d) => d.id === g.deck) ? g.deck : decks[0]?.id),
    typ: null, felder: { ...(notiz?.felder || {}) }, kategorie: notiz?.kategorie ?? g.kategorie ?? null,
    tags: notiz?.tags ?? g.tags ?? "", vorschauOrd: null, hinten: false,
  };
  await typLaden(typId);
  zeichnen();
}

async function typLaden(id) {
  const d = await holen("notiztyp", { id });
  N.typ = d.typ;
  N.vorschauOrd = null;
}

function feldHTML(f) {
  const w = N.felder[f.name] ?? "";
  const id = `f-${f.name.replace(/\W/g, "_")}`;
  const kopf = `<span>${e(f.name)}</span>`;
  if (f.art === "bild") {
    return `<div class="feld">${kopf}<div class="bildfeld" data-bildfeld="${e(f.name)}">
      ${w ? `<img src="${e(url(w))}" alt="">` : ""}<button type="button" class="knopf klein" data-bild-waehlen="${e(f.name)}">${ICON.bild} ${w ? "Ändern" : "Bild wählen"}</button>
      ${w ? `<button type="button" class="knopf klein leise" data-bild-weg="${e(f.name)}">Entfernen</button>` : ""}</div></div>`;
  }
  if (f.art === "audio") {
    return `<div class="feld">${kopf}<div class="zeile">
      <label class="knopf klein">${ICON.hochladen} Tondatei<input type="file" accept="audio/*" data-audio-feld="${e(f.name)}" hidden></label>
      ${w ? `<button type="button" class="knopf klein" data-audio-spielen="${e(w)}">${ICON.lautsprecher} Anhören</button><button type="button" class="knopf klein leise" data-audio-weg="${e(f.name)}">Entfernen</button>` : '<span class="leise klein">keine</span>'}</div></div>`;
  }
  if (f.art === "masken") {
    const bildFeld = N.typ.felder.find((x) => x.art === "bild")?.name || "Bild";
    const bild = N.felder[bildFeld];
    let masken = [];
    try { masken = JSON.parse(w || "[]"); } catch {}
    return `<div class="feld">${kopf}${bild ? `<p class="klein leise" style="margin:0">Rechtecke aufziehen – jede Maske wird eine Karte. Antippen wählt eine Maske; Beschriftung und Gruppe darunter (gleiche Gruppe = eine gemeinsame Karte).</p>
      <div class="maskeneditor" data-masken="${e(f.name)}"><img src="${e(url(bild))}" alt="" draggable="false">${masken.map((m, i) => `<div class="maske" data-i="${i}" style="left:${m.x}%;top:${m.y}%;width:${m.w}%;height:${m.h}%">${e(m.text || i + 1)}</div>`).join("")}</div>
      <div class="zeile" data-masken-wahl hidden><input class="eingabe" data-m-text placeholder="Beschriftung (Lösung)" style="flex:1"><input class="eingabe" data-m-gruppe placeholder="Gruppe" style="width:90px" inputmode="numeric"><button type="button" class="knopf klein gefahr" data-m-weg>${ICON.papierkorb}</button></div>`
      : '<p class="leise klein" style="margin:0">Wähle zuerst ein Bild.</p>'}</div>`;
  }
  const istLuecke = N.typ.art === "luecke" && N.typ.vorlagen[0]?.vorne?.some((b) => b.t === "luecke" && b.feld === f.name);
  const leiste = f.art === "md" ? `<div class="werkzeugleiste" data-leiste="${id}">
      <button type="button" data-fmt="**" title="Fett"><b>B</b></button><button type="button" data-fmt="*" title="Kursiv"><i>I</i></button>
      <button type="button" data-fmt="liste" title="Aufzählung">•</button><button type="button" data-fmt="tabelle" title="Tabelle">▦</button>
      ${istLuecke ? '<button type="button" data-fmt="luecke" title="Markierten Text zur Lücke machen (Strg+Umschalt+C)" style="color:var(--akzent)">[…] Lücke</button>' : ""}</div>` : "";
  const lang = f.art === "md" || w.length > 60 || f.name.match(/Antwort|Rückseite|Notiz|Text|Extra|Erklärung|Falsch/);
  return `<label class="feld" for="${id}">${kopf}${leiste}
    ${lang ? `<textarea class="eingabe" id="${id}" data-feld="${e(f.name)}" rows="${f.art === "md" ? 4 : 2}">${e(w)}</textarea>` : `<input class="eingabe" id="${id}" data-feld="${e(f.name)}" value="${e(w)}">`}
    ${f.name === "Falsch" ? '<span class="klein leise">Eine falsche Antwort pro Zeile.</span>' : ""}</label>`;
}

function zeichnen() {
  const { P, typ } = N;
  N.app.innerHTML = `
    <div class="zeile" style="margin-bottom:12px"><a class="knopf leise rund" href="#/projekt/${P.projekt.id}" aria-label="Zurück">${ICON.zurueck}</a>
      <h1 style="margin:0">${N.notiz ? "Karte bearbeiten" : "Neue Karte"}</h1><span class="leise">in ${e(P.projekt.name)}</span></div>
    <div class="editor">
      <form class="karte-box" data-form style="display:grid;gap:12px">
        <div class="spalten">
          <label class="feld">Notiztyp<select class="eingabe" data-typ>${N.typen.map((t) => `<option value="${t.id}" ${t.id === typ.id ? "selected" : ""}>${e(t.name)}</option>`).join("")}</select></label>
          <label class="feld">Deck<select class="eingabe" data-deck>${N.decks.map((d) => `<option value="${d.id}" ${d.id === N.deck ? "selected" : ""}>${"– ".repeat(d.tiefe)}${e(d.name)}</option>`).join("")}</select></label>
          ${P.kategorien.length ? `<label class="feld">Kategorie<select class="eingabe" data-kategorie><option value="">keine</option>${P.kategorien.map((k) => `<option value="${k.id}" ${k.id === N.kategorie ? "selected" : ""}>${e(k.symbol)} ${e(k.name)}</option>`).join("")}</select></label>` : ""}
        </div>
        ${typ.felder.map(feldHTML).join("")}
        <label class="feld">Tags<input class="eingabe" data-tags value="${e(N.tags)}" placeholder="z. B. pharmakologie wichtig" list="tagliste">
          <datalist id="tagliste">${Object.keys(P.tags).map((t) => `<option value="${e(t)}">`).join("")}</datalist></label>
        ${APP.ich?.ki ? `<div class="zeile"><input class="eingabe" data-ki-hinweis placeholder="Wunsch an Claude (optional), z. B. „mit Beispielsatz“" style="flex:1"><button type="button" class="knopf" data-ki>${ICON.funke} Claude fragen</button></div>` : ""}
        <div class="zeile ende">
          ${N.notiz ? `<button type="button" class="knopf gefahr" data-loeschen>${ICON.papierkorb} Löschen</button><span style="flex:1"></span>` : ""}
          ${N.notiz ? "" : '<button type="submit" class="knopf" data-und-weiter>Speichern &amp; nächste</button>'}
          <button type="submit" class="knopf haupt">Speichern</button>
        </div>
        <p class="klein leise" style="margin:0"><kbd>Strg</kbd>+<kbd>Enter</kbd> speichert${N.notiz ? "" : " und legt die nächste Karte an"}.</p>
      </form>
      <aside class="vorschau-paar" data-vorschau></aside>
    </div>`;
  vorschau();
  binden();
}

function vorschau() {
  const ziel = $("[data-vorschau]", N.app);
  const { typ } = N;
  const notiz = { id: N.notiz?.id || 0, felder: N.felder, tags: N.tags, kategorie: N.kategorie, deck: N.deck, zusatz: {} };
  const ords = ordsBerechnen(typ, N.felder);
  if (N.vorschauOrd == null || !ords.includes(N.vorschauOrd)) N.vorschauOrd = ords[0] ?? 0;
  const name = (o) => (typ.art === "normal" ? typ.vorlagen[o]?.name : typ.art === "luecke" ? `Lücke ${o}` : `Maske ${o}`);
  const kats = Object.fromEntries(N.P.kategorien.map((k) => [k.id, k]));
  ziel.innerHTML = `<div class="zeile" style="justify-content:space-between"><b>Vorschau</b><span class="klein leise">${ords.length} ${ords.length === 1 ? "Karte" : "Karten"}</span></div>
    ${ords.length > 1 ? `<div class="zeile">${ords.map((o) => `<button type="button" class="knopf klein ${o === N.vorschauOrd ? "haupt" : ""}" data-v-ord="${o}">${e(name(o))}</button>`).join("")}</div>` : ""}
    ${ords.length ? `<div class="klein leise">Vorderseite</div>${karteHTML({ typ, notiz, ord: N.vorschauOrd, hinten: false, kategorien: kats, decknamen: {}, sprache: N.P.projekt.sprache })}
      <div class="klein leise">Rückseite</div>${karteHTML({ typ, notiz, ord: N.vorschauOrd, hinten: true, kategorien: kats, decknamen: {}, sprache: N.P.projekt.sprache })}`
      : `<p class="leer">${typ.art === "luecke" ? "Markiere Text und klick auf „[…] Lücke“." : typ.art === "verdeckung" ? "Zieh Masken auf das Bild." : "Füll die Felder aus."}</p>`}`;
  $$(".karte", ziel).forEach((k) => karteBinden(k, { autoTon: false }));
}

/** Gleiche Regeln wie karten_ords() auf dem Server */
function ordsBerechnen(typ, felder) {
  const w = (n) => String(felder[n] ?? "").trim();
  if (typ.art === "luecke") {
    const feld = typ.vorlagen[0]?.vorne?.find((b) => b.t === "luecke")?.feld || "Text";
    return [...new Set([...w(feld).matchAll(/\{\{c(\d+)::/g)].map((m) => Number(m[1])))].sort((a, b) => a - b);
  }
  if (typ.art === "verdeckung") {
    const feld = typ.vorlagen[0]?.vorne?.find((b) => b.t === "verdeckung")?.masken || "Masken";
    let m = [];
    try { m = JSON.parse(w(feld) || "[]"); } catch {}
    return [...new Set(m.map((x, i) => (x.gruppe !== "" && x.gruppe != null ? Number(x.gruppe) : i + 1)))].sort((a, b) => a - b);
  }
  return typ.vorlagen.map((v, i) => (v.bedingung && !w(v.bedingung) ? null : i)).filter((x) => x !== null);
}

function binden() {
  const form = $("[data-form]", N.app);
  let warte;
  form.addEventListener("input", (ev) => {
    const f = ev.target.closest("[data-feld]");
    if (f) N.felder[f.dataset.feld] = f.value;
    if (ev.target.matches("[data-tags]")) N.tags = ev.target.value;
    clearTimeout(warte);
    warte = setTimeout(vorschau, 150);
  });
  form.addEventListener("change", async (ev) => {
    if (ev.target.matches("[data-typ]")) {
      // Werte gleichnamiger Felder bleiben erhalten
      await typLaden(Number(ev.target.value));
      zeichnen();
    }
    if (ev.target.matches("[data-deck]")) N.deck = Number(ev.target.value);
    if (ev.target.matches("[data-kategorie]")) N.kategorie = ev.target.value ? Number(ev.target.value) : null;
    const a = ev.target.closest("[data-audio-feld]");
    if (a?.files[0]) {
      try { const r = await hochladen(a.files[0]); N.felder[a.dataset.audioFeld] = r.verweis; zeichnen(); } catch (err) { meldung(err.message, "fehler"); }
    }
  });
  form.addEventListener("click", async (ev) => {
    const fmt = ev.target.closest("[data-fmt]");
    if (fmt) formatieren(fmt);
    const bw = ev.target.closest("[data-bild-waehlen]");
    if (bw) {
      const suche = Object.values(N.felder).find((x) => x && !String(x).startsWith("medien:")) || "";
      const v = await bildWaehlen({ suche: String(suche).slice(0, 60) });
      if (v) { N.felder[bw.dataset.bildWaehlen] = v; zeichnen(); }
    }
    const bweg = ev.target.closest("[data-bild-weg]");
    if (bweg) { N.felder[bweg.dataset.bildWeg] = ""; zeichnen(); }
    const as = ev.target.closest("[data-audio-spielen]");
    if (as) spiele(as.dataset.audioSpielen).catch(() => meldung("Ton lässt sich nicht abspielen.", "fehler"));
    const aw = ev.target.closest("[data-audio-weg]");
    if (aw) { N.felder[aw.dataset.audioWeg] = ""; zeichnen(); }
    if (ev.target.closest("[data-loeschen]")) {
      if (!(await bestaetigen("Diese Notiz mit allen ihren Karten löschen?", { ja: "Löschen", gefahr: true }))) return;
      await senden("notiz_loeschen", { id: N.notiz.id });
      gehe(`/projekt/${N.P.projekt.id}/karten`);
    }
    if (ev.target.closest("[data-ki]")) kiFragen(ev.target.closest("[data-ki]"));
  });
  $("[data-vorschau]", N.app).addEventListener("click", (ev) => {
    const o = ev.target.closest("[data-v-ord]");
    if (o) { N.vorschauOrd = Number(o.dataset.vOrd); vorschau(); }
  });
  let weiterNach = false;
  $("[data-und-weiter]", form)?.addEventListener("click", () => (weiterNach = true));
  form.addEventListener("keydown", (ev) => {
    if ((ev.ctrlKey || ev.metaKey) && ev.key === "Enter") { ev.preventDefault(); weiterNach = !N.notiz; form.requestSubmit(); }
    if ((ev.ctrlKey || ev.metaKey) && ev.shiftKey && (ev.key === "C" || ev.key === "c")) {
      const b = $('[data-fmt="luecke"]', form);
      if (b) { ev.preventDefault(); formatieren(b); }
    }
  });
  form.addEventListener("submit", async (ev) => {
    ev.preventDefault();
    const nachher = weiterNach;
    weiterNach = false;
    try {
      const r = await senden("notiz_speichern", { id: N.notiz?.id, deck: N.deck, typ: N.typ.id, felder: N.felder, kategorie: N.kategorie, tags: N.tags });
      merken(N.P.projekt.id, { typ: N.typ.id, deck: N.deck, kategorie: N.kategorie, tags: N.tags });
      meldung(N.notiz ? "Gespeichert." : `Angelegt – ${r.karten} ${r.karten === 1 ? "Karte" : "Karten"}.`);
      if (nachher) {
        N.felder = {};
        zeichnen();
        $("[data-feld]", N.app)?.focus();
      } else gehe(`/projekt/${N.P.projekt.id}/karten`);
    } catch (err) { meldung(err.message, "fehler"); }
  });
  maskenBinden();
}

function formatieren(knopf) {
  const ta = document.getElementById(knopf.closest("[data-leiste]").dataset.leiste);
  const [a, b] = [ta.selectionStart, ta.selectionEnd];
  const sel = ta.value.slice(a, b);
  let neu = sel, art = knopf.dataset.fmt;
  if (art === "**" || art === "*") neu = `${art}${sel || "Text"}${art}`;
  if (art === "liste") neu = (sel || "Punkt").split("\n").map((z) => `- ${z.replace(/^- /, "")}`).join("\n");
  if (art === "tabelle") neu = `${sel ? sel + "\n\n" : ""}| Spalte 1 | Spalte 2 |\n| --- | --- |\n| … | … |`;
  if (art === "luecke") {
    const n = Math.max(0, ...[...ta.value.matchAll(/\{\{c(\d+)::/g)].map((m) => Number(m[1]))) + 1;
    neu = `{{c${n}::${sel || "Antwort"}}}`;
  }
  ta.setRangeText(neu, a, b, "end");
  ta.focus();
  ta.dispatchEvent(new Event("input", { bubbles: true }));
}

/** Masken aufziehen, verschieben, beschriften (Koordinaten in % des Bildes) */
function maskenBinden() {
  const ed = $("[data-masken]", N.app);
  if (!ed) return;
  const feld = ed.dataset.masken;
  let masken = [];
  try { masken = JSON.parse(N.felder[feld] || "[]"); } catch {}
  let gewaehlt = null, aktion = null;
  const wahl = $("[data-masken-wahl]", N.app);
  const sichern = () => { N.felder[feld] = JSON.stringify(masken); vorschau(); };
  const zeichneMasken = () => {
    $$(".maske", ed).forEach((m) => m.remove());
    masken.forEach((m, i) => ed.insertAdjacentHTML("beforeend", `<div class="maske ${i === gewaehlt ? "gewaehlt" : ""}" data-i="${i}" style="left:${m.x}%;top:${m.y}%;width:${m.w}%;height:${m.h}%">${e(m.text || (m.gruppe !== "" && m.gruppe != null ? "G" + m.gruppe : i + 1))}</div>`));
    wahl.hidden = gewaehlt == null;
    if (gewaehlt != null) { $("[data-m-text]", wahl).value = masken[gewaehlt].text || ""; $("[data-m-gruppe]", wahl).value = masken[gewaehlt].gruppe ?? ""; }
  };
  const pos = (ev) => {
    const r = $("img", ed).getBoundingClientRect();
    return { x: Math.max(0, Math.min(100, ((ev.clientX - r.left) / r.width) * 100)), y: Math.max(0, Math.min(100, ((ev.clientY - r.top) / r.height) * 100)) };
  };
  ed.addEventListener("pointerdown", (ev) => {
    ev.preventDefault();
    ed.setPointerCapture(ev.pointerId);
    const p = pos(ev);
    const m = ev.target.closest(".maske");
    if (m) { gewaehlt = Number(m.dataset.i); aktion = { art: "schieben", start: p, ur: { ...masken[gewaehlt] } }; }
    else { masken.push({ x: p.x, y: p.y, w: 0, h: 0, text: "", gruppe: "" }); gewaehlt = masken.length - 1; aktion = { art: "ziehen", start: p }; }
    zeichneMasken();
  });
  ed.addEventListener("pointermove", (ev) => {
    if (!aktion) return;
    const p = pos(ev), m = masken[gewaehlt];
    if (aktion.art === "ziehen") {
      m.x = Math.min(p.x, aktion.start.x); m.y = Math.min(p.y, aktion.start.y);
      m.w = Math.abs(p.x - aktion.start.x); m.h = Math.abs(p.y - aktion.start.y);
    } else {
      m.x = Math.max(0, Math.min(100 - m.w, aktion.ur.x + p.x - aktion.start.x));
      m.y = Math.max(0, Math.min(100 - m.h, aktion.ur.y + p.y - aktion.start.y));
    }
    const el = $(`.maske[data-i="${gewaehlt}"]`, ed);
    Object.assign(el.style, { left: m.x + "%", top: m.y + "%", width: m.w + "%", height: m.h + "%" });
  });
  ed.addEventListener("pointerup", () => {
    if (!aktion) return;
    const m = masken[gewaehlt];
    if (aktion.art === "ziehen" && (m.w < 1.5 || m.h < 1.5)) { masken.splice(gewaehlt, 1); gewaehlt = null; }
    else for (const k of ["x", "y", "w", "h"]) m[k] = Math.round(m[k] * 100) / 100;
    aktion = null;
    zeichneMasken();
    sichern();
  });
  wahl.addEventListener("input", () => {
    if (gewaehlt == null) return;
    masken[gewaehlt].text = $("[data-m-text]", wahl).value;
    const g = $("[data-m-gruppe]", wahl).value.trim();
    masken[gewaehlt].gruppe = g === "" ? "" : Number(g) || "";
    zeichneMasken();
    sichern();
  });
  $("[data-m-weg]", wahl).addEventListener("click", () => { masken.splice(gewaehlt, 1); gewaehlt = null; zeichneMasken(); sichern(); });
}

async function kiFragen(knopf) {
  knopf.disabled = true;
  const alt = knopf.innerHTML;
  knopf.innerHTML = `${ICON.funke} Claude denkt nach …`;
  try {
    const r = await senden("ki_vorschlag", { typ: N.typ.id, projekt: N.P.projekt.id, felder: N.felder, hinweis: $("[data-ki-hinweis]", N.app)?.value || "" });
    const n = Object.keys(r.felder).length;
    Object.assign(N.felder, r.felder);
    zeichnen();
    meldung(n ? `Claude hat ${n} ${n === 1 ? "Feld" : "Felder"} vorgeschlagen – bitte prüfen. (${r.verbraucht}/${r.limit} diesen Monat)` : "Claude hatte keine Vorschläge.");
  } catch (err) {
    meldung(err.message, "fehler");
    knopf.disabled = false;
    knopf.innerHTML = alt;
  }
}
