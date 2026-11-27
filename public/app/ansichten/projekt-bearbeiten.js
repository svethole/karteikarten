// Neues Projekt anlegen und Projekteinstellungen (Reiter „Einstellungen“ der Projektseite).
import { holen, senden } from "../api.js";
import { $, $$, e, ICON, meldung, bestaetigen, flagge } from "../ui.js";
import { url } from "../medien.js";
import { bildWaehlen } from "../bildwahl.js";
import { APP, gehe } from "../main.js";

const FARBEN = ["#2c6e70", "#2563a8", "#1f7a4d", "#b4533c", "#7c3aed", "#c2410c", "#be185d", "#0e7490", "#4d7c0f", "#475569", "#a16207", "#9f1239"];
const SPRACHEN = [["", "keine (Deutsch)"], ["cs", "Tschechisch"], ["it", "Italienisch"], ["nl", "Niederländisch"], ["en", "Englisch"], ["fr", "Französisch"], ["es", "Spanisch"], ["da", "Dänisch"], ["la", "Latein"]];

function projektFormular(p = {}) {
  const farbe = p.farbe || FARBEN[0];
  return `
    <div class="spalten">
      <label class="feld">Name<input class="eingabe" name="name" value="${e(p.name || "")}" maxlength="80" required></label>
      <label class="feld">Symbol (Emoji)<input class="eingabe" name="symbol" value="${e(p.symbol || "")}" maxlength="8" placeholder="z. B. 🧠"></label>
    </div>
    <label class="feld">Beschreibung<input class="eingabe" name="beschreibung" value="${e(p.beschreibung || "")}" maxlength="300"></label>
    <div class="spalten">
      <div class="feld">Farbe<div class="farbwahl" data-farben>${FARBEN.map((f) => `<button type="button" style="background:${f}" data-farbe="${f}" aria-pressed="${f === farbe}" aria-label="Farbe ${f}"></button>`).join("")}
        <input type="color" name="farbe" value="${e(farbe)}" style="width:34px;height:30px;border:0;background:none;padding:0" aria-label="Eigene Farbe"></div></div>
      <label class="feld">Sprache der Karten (für Sprachausgabe)<select class="eingabe" name="sprache">${SPRACHEN.map(([k, n]) => `<option value="${k}" ${k === (p.sprache || "") ? "selected" : ""}>${n}</option>`).join("")}</select></label>
    </div>
    <div class="feld">Kachelbild
      <div class="zeile"><button type="button" class="knopf" data-bild>${ICON.bild} Bild wählen</button><button type="button" class="knopf leise" data-bild-weg>Kein Bild</button></div>
      <input type="hidden" name="bild" value="${e(p.bild || "")}"><input type="hidden" name="bild_nachweis" value="${e(p.bild_nachweis || "")}">
    </div>
    <div class="kachel" data-vorschau style="--farbe:${e(farbe)};max-width:420px;min-height:200px">
      <div class="bild" style="${p.bild ? `background-image:url('${e(url(p.bild))}')` : ""}"></div>
      <div class="unten" style="margin-top:auto"><h2 data-v-name>${p.flagge ? flagge(p.flagge) : `<span class="symbol">${e(p.symbol || "")}</span>`}${e(p.name || "Neues Projekt")}</h2><p class="beschr" data-v-beschr>${e(p.beschreibung || "")}</p></div>
    </div>`;
}

function formularBinden(form) {
  const v = $("[data-vorschau]", form);
  const aktualisieren = () => {
    const fd = new FormData(form);
    v.style.setProperty("--farbe", fd.get("farbe"));
    $("[data-v-name]", v).innerHTML = `<span class="symbol">${e(fd.get("symbol"))}</span>${e(fd.get("name") || "Neues Projekt")}`;
    $("[data-v-beschr]", v).textContent = fd.get("beschreibung");
    const b = fd.get("bild");
    $(".bild", v).style.backgroundImage = b ? `url('${url(b)}')` : "";
  };
  form.addEventListener("input", aktualisieren);
  form.addEventListener("click", async (ev) => {
    const f = ev.target.closest("[data-farbe]");
    if (f) {
      form.farbe.value = f.dataset.farbe;
      $$("[data-farbe]", form).forEach((b) => b.setAttribute("aria-pressed", b === f));
      aktualisieren();
    }
    if (ev.target.closest("[data-bild]")) {
      const verweis = await bildWaehlen({ titel: "Kachelbild", suche: form.name.value });
      if (verweis) { form.bild.value = verweis; form.bild_nachweis.value = ""; aktualisieren(); }
    }
    if (ev.target.closest("[data-bild-weg]")) { form.bild.value = ""; aktualisieren(); }
  });
}

/** Seite „Neues Projekt“ */
export async function zeigen(app) {
  app.innerHTML = `<h1>Neues Projekt</h1><form class="karte-box" style="display:grid;gap:14px;max-width:760px" data-form>${projektFormular()}
    <div class="zeile ende"><a class="knopf leise" href="#/">Abbrechen</a><button class="knopf haupt">Projekt anlegen</button></div></form>`;
  const form = $("[data-form]", app);
  formularBinden(form);
  form.name.focus();
  form.addEventListener("submit", async (ev) => {
    ev.preventDefault();
    try {
      const r = await senden("projekt_speichern", Object.fromEntries(new FormData(form)));
      meldung("Projekt angelegt. Leg jetzt Deine ersten Karten an!");
      gehe(`/projekt/${r.id}`);
    } catch (err) { meldung(err.message, "fehler"); }
  });
}

/* ---------- Reiter „Einstellungen“ ---------- */

export function einstellungenHTML(P) {
  const p = P.projekt, s = p.einstellungen;
  const eigen = p.darf_bearbeiten;
  return `<div style="display:grid;gap:14px;max-width:860px">
    <form class="karte-box" data-lernen style="display:grid;gap:12px"><h3 style="margin:0">Lernen</h3>
      <div class="spalten">
        <label class="feld">Neue Karten pro Tag<input class="eingabe" type="number" min="0" max="9999" name="neu_pro_tag" value="${s.neu_pro_tag}"></label>
        <label class="feld">Höchstens Wiederholungen pro Tag<input class="eingabe" type="number" min="0" max="99999" name="max_wdh_pro_tag" value="${s.max_wdh_pro_tag}"></label>
        <label class="feld">Neue Karten<select class="eingabe" name="neu_mischen">${[["reihenfolge", "in Reihenfolge"], ["gemischt", "zwischen Wiederholungen"], ["zufall", "zufällig"]].map(([k, n]) => `<option value="${k}" ${k === s.neu_mischen ? "selected" : ""}>${n}</option>`).join("")}</select></label>
      </div>
      <p class="klein leise" style="margin:0">Gilt nur für Dich. Das Tagesbudget für neue Karten teilen sich alle Decks des Projekts.</p>
      <div class="zeile ende"><button class="knopf haupt klein">Speichern</button></div></form>

    ${eigen ? `<form class="karte-box" data-projekt style="display:grid;gap:14px"><h3 style="margin:0">Projekt</h3>${projektFormular(p)}
      <div class="zeile ende"><button class="knopf haupt klein">Speichern</button></div></form>

      <div class="karte-box"><h3>Kategorien</h3><p class="klein leise" style="margin-top:-4px">Jede Karte hat höchstens eine Kategorie – farbig in Listen und auf Wunsch auf der Karte.</p>
        <div data-kategorien style="display:grid;gap:6px">${P.kategorien.map((k) => katZeile(k)).join("")}</div>
        <div class="zeile" style="margin-top:8px"><button class="knopf klein" data-kat-neu>${ICON.plus} Kategorie</button></div></div>` : ""}

    <div class="karte-box"><h3>Notiztypen</h3><p class="klein leise" style="margin-top:-4px">Ein Notiztyp legt die Felder fest und wie Vorder- und Rückseite aussehen. Im Designer ziehst Du Bausteine auf die Karte.</p>
      <div style="display:grid;gap:4px">${P.typen.map((t) => `<div class="zeile" style="justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--rand)">
        <span><b>${e(t.name)}</b> <span class="leise klein">${t.eigen ? "eigener" : "eingebaut"}${t.art !== "normal" ? ` · ${t.art === "luecke" ? "Lückentext" : "Bild-Verdeckung"}` : ""}</span></span>
        <span class="zeile">${t.eigen ? `<a class="knopf klein" href="#/designer/${t.id}">${ICON.stift} Gestalten</a>` : `<a class="knopf klein leise" href="#/designer/${t.id}">Ansehen</a>`}
        ${eigen ? `<a class="knopf klein leise" href="#/designer/neu?projekt=${p.id}&vorlage=${t.id}">${ICON.kopie} Kopie</a>` : ""}</span></div>`).join("")}</div>
      ${eigen ? `<div class="zeile" style="margin-top:10px"><a class="knopf klein" href="#/designer/neu?projekt=${p.id}">${ICON.plus} Neuer Notiztyp</a></div>` : ""}</div>

    <div class="karte-box"><h3>Anki-Austausch</h3>
      <div class="zeile"><a class="knopf klein" href="api.php?r=apkg_export&projekt=${p.id}" download>${ICON.herunter} Als .apkg exportieren</a>
        <a class="knopf klein leise" href="api.php?r=apkg_export&projekt=${p.id}&lernstand=1" download>mit Lernstand</a>
        ${eigen ? `<label class="knopf klein">${ICON.hochladen} .apkg importieren<input type="file" accept=".apkg,.colpkg" data-apkg hidden></label>` : ""}</div>
      ${eigen ? '<p class="klein leise" style="margin:8px 0 0">Import: In Anki beim Exportieren „Unterstützung älterer Anki-Versionen“ anhaken.</p>' : ""}</div>

    ${!eigen && APP.ich?.admin ? `<form class="karte-box" data-admin style="display:grid;gap:12px"><h3 style="margin:0">Admin: Darstellung und Freigaben</h3>
      <div class="zeile"><button type="button" class="knopf klein" data-bild-admin>${ICON.bild} Kachelbild ändern</button>
        <input type="color" name="farbe" value="${e(p.farbe)}" aria-label="Farbe"></div>
      <input type="hidden" name="bild" value="${e(p.bild)}"><label class="feld">Bildnachweis<input class="eingabe" name="bild_nachweis" value="${e(p.bild_nachweis)}"></label>
      <div class="feld">Sichtbar für<div data-freigaben class="zeile"><span class="leise klein">lädt …</span></div></div>
      <p class="klein leise" style="margin:0">Die Sprachkurse enthalten Buchinhalte (nur persönlicher Gebrauch) – gib sie nur Dir selbst frei.</p>
      <div class="zeile ende"><button class="knopf haupt klein">Speichern</button></div></form>` : ""}

    ${eigen ? `<div class="karte-box"><h3>Projekt löschen</h3><p class="klein leise">Alle Decks und Karten verschwinden aus der Übersicht.</p><button class="knopf gefahr klein" data-loeschen>${ICON.papierkorb} Projekt löschen</button></div>` : ""}
  </div>`;
}

function katZeile(k) {
  return `<div class="zeile" data-kat="${k.id}" style="flex-wrap:nowrap"><input type="color" value="${e(k.farbe)}" data-k-farbe aria-label="Farbe">
    <input class="eingabe" data-k-symbol value="${e(k.symbol)}" maxlength="8" style="width:64px" aria-label="Symbol">
    <input class="eingabe" data-k-name value="${e(k.name)}" maxlength="60" aria-label="Name">
    <button class="knopf klein rund leise" data-k-weg aria-label="Kategorie löschen">${ICON.papierkorb}</button></div>`;
}

export function einstellungenBinden(ziel, P) {
  const p = P.projekt;
  const neu = () => window.dispatchEvent(new HashChangeEvent("hashchange"));
  $("[data-lernen]", ziel).addEventListener("submit", async (ev) => {
    ev.preventDefault();
    try {
      await senden("projekt_speichern", { id: p.id, nur_einstellungen: true, einstellungen: Object.fromEntries(new FormData(ev.target)) });
      meldung("Gespeichert.");
    } catch (err) { meldung(err.message, "fehler"); }
  });
  const pf = $("[data-projekt]", ziel);
  if (pf) {
    formularBinden(pf);
    pf.addEventListener("submit", async (ev) => {
      ev.preventDefault();
      try { await senden("projekt_speichern", { id: p.id, ...Object.fromEntries(new FormData(pf)) }); meldung("Gespeichert."); neu(); } catch (err) { meldung(err.message, "fehler"); }
    });
  }
  // Kategorien: Änderungen beim Verlassen eines Felds speichern
  const kats = $("[data-kategorien]", ziel);
  if (kats) {
    const speichern = async (zeile) => {
      try {
        await senden("kategorie_speichern", { projekt: p.id, id: zeile.dataset.kat || undefined, name: $("[data-k-name]", zeile).value, farbe: $("[data-k-farbe]", zeile).value, symbol: $("[data-k-symbol]", zeile).value });
      } catch (err) { meldung(err.message, "fehler"); }
    };
    kats.addEventListener("change", (ev) => speichern(ev.target.closest("[data-kat]")));
    kats.addEventListener("click", async (ev) => {
      const w = ev.target.closest("[data-k-weg]");
      if (!w) return;
      const z = w.closest("[data-kat]");
      if (!(await bestaetigen("Kategorie löschen? Die Karten bleiben, nur ohne Kategorie."))) return;
      await senden("kategorie_loeschen", { id: z.dataset.kat });
      z.remove();
    });
    $("[data-kat-neu]", ziel).addEventListener("click", async () => {
      const farben = ["#0284c7", "#dc2626", "#ea580c", "#7c3aed", "#ca8a04", "#0d9488", "#db2777", "#2563eb", "#65a30d"];
      try {
        const r = await senden("kategorie_speichern", { projekt: p.id, name: `Kategorie ${kats.children.length + 1}`, farbe: farben[kats.children.length % farben.length] });
        kats.insertAdjacentHTML("beforeend", katZeile({ id: r.id, name: `Kategorie ${kats.children.length + 1}`, farbe: farben[kats.children.length % farben.length], symbol: "" }));
        $("[data-k-name]", kats.lastElementChild).select();
      } catch (err) { meldung(err.message, "fehler"); }
    });
  }
  $("[data-apkg]", ziel)?.addEventListener("change", async (ev) => {
    const datei = ev.target.files[0];
    if (!datei) return;
    const fd = new FormData();
    fd.append("datei", datei);
    fd.append("projekt", p.id);
    meldung("Importiere … das kann bei großen Decks etwas dauern.");
    try {
      const r = await fetch("api.php?r=apkg_import", { method: "POST", headers: { "X-Karten": "1" }, body: fd, credentials: "same-origin" }).then((x) => x.json());
      if (!r.ok) throw new Error(r.fehler);
      meldung(`Importiert: ${r.notizen} Notizen, ${r.karten} Karten${r.medien ? `, ${r.medien} Medien` : ""}.`);
      neu();
    } catch (err) { meldung(err.message, "fehler"); }
  });
  $("[data-loeschen]", ziel)?.addEventListener("click", async () => {
    if (!(await bestaetigen(`Projekt „${p.name}“ wirklich löschen?`, { ja: "Löschen", gefahr: true }))) return;
    await senden("projekt_loeschen", { id: p.id });
    gehe("/");
  });
  const af = $("[data-admin]", ziel);
  if (af) {
    holen("benutzer").then((d) => {
      const frei = new Set(P.freigaben || []);
      $("[data-freigaben]", af).innerHTML = [["*", "alle angemeldeten Konten"], ...d.benutzer.map((b) => [b, b])]
        .map(([k, n]) => `<label class="chip" style="cursor:pointer"><input type="checkbox" value="${e(k)}" ${frei.has(k) ? "checked" : ""}> ${e(n)}</label>`).join("");
    });
    $("[data-bild-admin]", af).addEventListener("click", async () => {
      const v = await bildWaehlen({ titel: "Kachelbild", suche: p.name });
      if (v) { af.bild.value = v; af.bild_nachweis.value = ""; meldung("Bild gewählt – noch speichern."); }
    });
    af.addEventListener("submit", async (ev) => {
      ev.preventDefault();
      try {
        await senden("projekt_speichern", { id: p.id, farbe: af.farbe.value, bild: af.bild.value, bild_nachweis: af.bild_nachweis.value });
        await senden("freigaben_setzen", { projekt: p.id, benutzer: $$("[data-freigaben] input:checked", af).map((i) => i.value) });
        meldung("Gespeichert.");
        neu();
      } catch (err) { meldung(err.message, "fehler"); }
    });
  }
}
