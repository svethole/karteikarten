// Fenster zu einer Notiz: Karten ansehen (Vorder-/Rückseite), Zusätze bearbeiten (Eselsbrücke, Bild, eigene Tags),
// Karten pausieren/markieren/zurücksetzen; bei eigenen Projekten Sprung in den Editor.
import { holen, senden } from "./api.js";
import { $, $$, e, dialog, ICON, meldung, inTagen, bestaetigen } from "./ui.js";
import { karteHTML, karteBinden } from "./karte.js";
import { bildWaehlen } from "./bildwahl.js";
import { url } from "./medien.js";
import { APP, gehe } from "./main.js";

const ZUSTAND = { null: "neu", learn: "lernt", relearn: "wiederholt", review: "gelernt" };

export async function notizOeffnen(notizId, { ord = null, nachAenderung } = {}) {
  const d = await holen("notiz", { id: notizId });
  const { notiz, typ, karten, darf_bearbeiten } = d;
  let aktiv = ord ?? karten[0]?.ord ?? 0;
  let hinten = false;
  const vorlName = (o) => (typ.art === "normal" ? typ.vorlagen[o]?.name : typ.art === "luecke" ? `Lücke ${o}` : `Maske ${o}`) || `Karte ${o}`;
  const z = notiz.zusatz;
  let bild = z.bild;
  const geaendert = { wert: false };

  await dialog({
    titel: "Karte",
    breit: true,
    inhalt: `
      <div class="zeile">${karten.map((k) => `<button class="knopf klein" data-ord="${k.ord}">${e(vorlName(k.ord))}</button>`).join("")}
        <span style="flex:1"></span><button class="knopf klein" data-umdrehen>Umdrehen</button></div>
      <div data-vorschau></div>
      <div data-kartenstand class="zeile klein leise"></div>
      <details class="karte-box" ${z.notiz || z.bild || z.tags ? "open" : ""}>
        <summary style="cursor:pointer;font-weight:600">Meine Zusätze (Eselsbrücke, Bild, Tags)</summary>
        <div style="display:grid;gap:10px;margin-top:10px">
          <label class="feld">Eselsbrücke / Notiz (Markdown)<textarea class="eingabe" data-z-notiz rows="3">${e(z.notiz)}</textarea></label>
          <div class="bildfeld"><img data-z-bildvorschau src="${e(url(bild))}" alt="" ${bild ? "" : "hidden"}>
            <button class="knopf klein" data-z-bild>${ICON.bild} ${bild ? "Bild ändern" : "Eigenes Bild"}</button>
            <button class="knopf klein leise" data-z-bild-weg ${bild ? "" : "hidden"}>Entfernen</button></div>
          <label class="feld">Eigene Tags (durch Leerzeichen getrennt)<input class="eingabe" data-z-tags value="${e(z.tags)}"></label>
          <div class="zeile ende"><button class="knopf haupt klein" data-z-speichern>Zusätze speichern</button></div>
        </div>
      </details>`,
    fuss: `${darf_bearbeiten ? `<button class="knopf" data-bearbeiten>${ICON.stift} Bearbeiten</button>` : ""}
      <button class="knopf" data-aktion="markieren">${ICON.stern} Markieren</button>
      <button class="knopf" data-aktion="pausieren">${ICON.pause} Pausieren</button>
      <button class="knopf" data-aktion="fortsetzen">Fortsetzen</button>
      <button class="knopf gefahr" data-aktion="zuruecksetzen">Zurücksetzen</button>`,
    beimOeffnen: (fenster, zu) => {
      const zeichne = () => {
        $("[data-vorschau]", fenster).innerHTML = karteHTML({ typ, notiz: { ...notiz, zusatz: { notiz: $("[data-z-notiz]", fenster).value, bild, tags: z.tags } }, ord: aktiv, hinten, kategorien: d.kategorien, decknamen: {}, genusEffekte: APP.einstellungen.genus_effekte });
        karteBinden($("[data-vorschau]", fenster), { autoTon: false });
        $$("[data-ord]", fenster).forEach((b) => b.classList.toggle("haupt", Number(b.dataset.ord) === aktiv));
        const k = karten.find((x) => x.ord === aktiv);
        $("[data-kartenstand]", fenster).innerHTML = k ? `<span class="chip">${e(ZUSTAND[k.st] || "neu")}</span>${k.faellig ? `<span>fällig ${e(inTagen(k.faellig, APP.ich?.tagesbeginn || Date.now()))}</span>` : ""}<span>${k.wdh || 0} Wiederholungen, ${k.fehler || 0}× vergessen</span>${Number(k.pausiert) ? '<span class="chip">pausiert</span>' : ""}${Number(k.markiert) ? '<span class="chip">★ markiert</span>' : ""}` : "";
      };
      zeichne();
      fenster.addEventListener("click", async (ev) => {
        const o = ev.target.closest("[data-ord]");
        if (o) { aktiv = Number(o.dataset.ord); hinten = false; zeichne(); }
        if (ev.target.closest("[data-umdrehen]")) { hinten = !hinten; zeichne(); }
        if (ev.target.closest("[data-bearbeiten]")) { zu(); gehe(`/notiz/${notizId}`); }
        if (ev.target.closest("[data-z-bild]")) {
          const v = await bildWaehlen({ titel: "Eigenes Bild zur Karte" });
          if (v) { bild = v; $("[data-z-bildvorschau]", fenster).src = url(v); $("[data-z-bildvorschau]", fenster).hidden = false; $("[data-z-bild-weg]", fenster).hidden = false; zeichne(); }
        }
        if (ev.target.closest("[data-z-bild-weg]")) { bild = ""; $("[data-z-bildvorschau]", fenster).hidden = true; ev.target.hidden = true; zeichne(); }
        if (ev.target.closest("[data-z-speichern]")) {
          try {
            await senden("zusatz_speichern", { notiz: notizId, notiz_text: $("[data-z-notiz]", fenster).value, bild, tags: $("[data-z-tags]", fenster).value });
            z.tags = $("[data-z-tags]", fenster).value;
            geaendert.wert = true;
            meldung("Zusätze gespeichert.");
          } catch (err) { meldung(err.message, "fehler"); }
        }
        const a = ev.target.closest("[data-aktion]");
        if (a) {
          if (a.dataset.aktion === "zuruecksetzen" && !(await bestaetigen("Lernstand dieser Karten zurücksetzen? Sie gelten dann wieder als neu.", { ja: "Zurücksetzen", gefahr: true }))) return;
          try {
            await senden("karte_aktion", { notiz: notizId, aktion: a.dataset.aktion });
            geaendert.wert = true;
            const neu = await holen("notiz", { id: notizId });
            karten.splice(0, karten.length, ...neu.karten);
            zeichne();
            meldung({ markieren: "Markierung umgeschaltet.", pausieren: "Pausiert.", fortsetzen: "Läuft wieder.", zuruecksetzen: "Zurückgesetzt." }[a.dataset.aktion]);
          } catch (err) { meldung(err.message, "fehler"); }
        }
      });
      $("[data-z-notiz]", fenster).addEventListener("input", () => hinten && zeichne());
    },
  });
  if (geaendert.wert) nachAenderung?.();
}
