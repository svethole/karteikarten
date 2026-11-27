// Bildauswahl: Hochladen (auch Einfügen aus der Zwischenablage), Bildsuche (Wikimedia Commons / Openverse über den Server)
// oder Adresse. Gibt einen Verweis „medien:<id>“ zurück (oder null bei Abbruch).
import { holen, senden, hochladen } from "./api.js";
import { $, e, dialog, ICON, meldung } from "./ui.js";

export function bildWaehlen({ titel = "Bild wählen", suche = "" } = {}) {
  return dialog({
    titel,
    breit: true,
    inhalt: `
      <div class="zeile">
        <label class="knopf haupt">${ICON.hochladen} Datei hochladen<input type="file" accept="image/*" data-datei hidden></label>
        <span class="leise klein">oder Bild hier einfügen (Strg+V)</span>
      </div>
      <form class="zeile" data-suche>
        <input class="eingabe" name="q" placeholder="Bild suchen …" value="${e(suche)}" style="flex:1 1 220px">
        <select class="eingabe" name="quelle" style="width:auto"><option value="wikimedia">Wikimedia Commons</option><option value="openverse">Openverse</option></select>
        <button class="knopf">Suchen</button>
      </form>
      <div data-treffer class="bildraster"></div>
      <form class="zeile" data-adresse>
        <input class="eingabe" name="url" placeholder="oder Bildadresse einfügen (https://…)" style="flex:1 1 260px">
        <button class="knopf">Übernehmen</button>
      </form>
      <p class="leise klein" style="margin:0">Achte bei fremden Bildern auf die Lizenz – der Bildnachweis wird mitgespeichert.</p>`,
    beimOeffnen: (d, zu) => {
      const fertig = (r) => r && zu(r.verweis);
      $("[data-datei]", d).addEventListener("change", async (ev) => {
        const f = ev.target.files[0];
        if (!f) return;
        try { fertig(await hochladen(await verkleinern(f))); } catch (err) { meldung(err.message, "fehler"); }
      });
      d.addEventListener("paste", async (ev) => {
        const f = [...(ev.clipboardData?.files || [])].find((x) => x.type.startsWith("image/"));
        if (!f) return;
        ev.preventDefault();
        try { fertig(await hochladen(await verkleinern(f))); } catch (err) { meldung(err.message, "fehler"); }
      });
      const treffer = $("[data-treffer]", d);
      const suchen = async (ev) => {
        ev?.preventDefault();
        const f = new FormData($("[data-suche]", d));
        if (!String(f.get("q")).trim()) return;
        treffer.innerHTML = '<div class="laden" style="grid-column:1/-1"><div class="kreisel"></div></div>';
        try {
          const r = await holen("bildsuche", { q: f.get("q"), quelle: f.get("quelle") });
          treffer.innerHTML = r.treffer.length ? r.treffer.map((t, i) => `<button type="button" data-i="${i}" title="${e(t.titel)} – ${e(t.nachweis)}"><img src="${e(t.vorschau)}" alt="${e(t.titel)}" loading="lazy" referrerpolicy="no-referrer"></button>`).join("")
            : '<p class="leise" style="grid-column:1/-1">Nichts gefunden.</p>';
          treffer.onclick = async (ev2) => {
            const b = ev2.target.closest("[data-i]");
            if (!b) return;
            const t = r.treffer[b.dataset.i];
            b.style.opacity = ".4";
            try { fertig(await senden("bild_von_adresse", { url: t.url, nachweis: t.nachweis })); } catch (err) { meldung(err.message, "fehler"); b.style.opacity = ""; }
          };
        } catch (err) {
          treffer.innerHTML = `<p class="leise" style="grid-column:1/-1">${e(err.message)}</p>`;
        }
      };
      $("[data-suche]", d).addEventListener("submit", suchen);
      $("[data-adresse]", d).addEventListener("submit", async (ev) => {
        ev.preventDefault();
        const u = new FormData(ev.target).get("url");
        try { fertig(await senden("bild_von_adresse", { url: u, nachweis: "Quelle: " + (new URL(u).hostname) })); } catch (err) { meldung(err.message, "fehler"); }
      });
      if (suche) suchen();
    },
  });
}

/** Große Fotos vor dem Hochladen verkleinern (längste Seite 1800 px, WebP) */
export async function verkleinern(datei, max = 1800) {
  if (!/^image\/(jpeg|png|webp)$/.test(datei.type)) return datei;
  try {
    const bmp = await createImageBitmap(datei);
    const f = Math.min(1, max / Math.max(bmp.width, bmp.height));
    if (f === 1 && datei.size < 1.5e6) return datei;
    const c = Object.assign(document.createElement("canvas"), { width: Math.round(bmp.width * f), height: Math.round(bmp.height * f) });
    c.getContext("2d").drawImage(bmp, 0, 0, c.width, c.height);
    const blob = await new Promise((ok) => c.toBlob(ok, "image/webp", 0.86));
    return blob ? new File([blob], datei.name.replace(/\.\w+$/, ".webp"), { type: "image/webp" }) : datei;
  } catch {
    return datei;
  }
}
