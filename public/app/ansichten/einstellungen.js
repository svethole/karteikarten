// Persönliche Einstellungen: Lerngrenzen, Ziel-Behaltensquote, Darstellung, Ton; Offline-Stand; für Admins der Abgleich.
import { holen, senden, warteschlangeLaenge, warteschlangeSenden } from "../api.js";
import { $, e, meldung, ICON, vorWann } from "../ui.js";
import { FSRS } from "../fsrs.js";
import { APP, designSetzen } from "../main.js";

export async function zeigen(app) {
  const s = APP.einstellungen;
  const offen = await warteschlangeLaenge();
  app.innerHTML = `<h1>Einstellungen</h1>
    <form class="karte-box" data-form style="display:grid;gap:14px;max-width:760px">
      <h3 style="margin:0">Lernen</h3>
      <div class="spalten">
        <label class="feld">Neue Karten pro Tag (Standard je Projekt)<input class="eingabe" type="number" name="neu_pro_tag" min="0" max="9999" value="${s.neu_pro_tag ?? 20}"></label>
        <label class="feld">Höchstens Wiederholungen pro Tag<input class="eingabe" type="number" name="max_wdh_pro_tag" min="0" max="99999" value="${s.max_wdh_pro_tag ?? 400}"></label>
      </div>
      <label class="feld">Ziel-Behaltensquote: <b data-ziel-text>${Math.round((s.ziel ?? 0.9) * 100)} %</b>
        <input type="range" name="ziel" min="0.75" max="0.97" step="0.01" value="${s.ziel ?? 0.9}">
        <span class="klein leise">Höher = öfter wiederholen, dafür seltener vergessen. 90 % ist ein guter Mittelweg (wie in den Sprachtrainern).</span></label>
      <label class="feld">Neue Karten<select class="eingabe" name="neu_mischen">${[["gemischt", "zwischen die Wiederholungen mischen"], ["reihenfolge", "erst Wiederholungen, dann neue"], ["zufall", "zufällige Reihenfolge"]].map(([k, n]) => `<option value="${k}" ${s.neu_mischen === k ? "selected" : ""}>${n}</option>`).join("")}</select></label>
      <h3 style="margin:8px 0 0">Darstellung und Ton</h3>
      <label class="feld">Erscheinungsbild<select class="eingabe" name="design">${[["auto", "wie das Gerät"], ["hell", "hell"], ["dunkel", "dunkel"]].map(([k, n]) => `<option value="${k}" ${s.design === k ? "selected" : ""}>${n}</option>`).join("")}</select></label>
      <label class="zeile"><input type="checkbox" name="sprachausgabe_auto" ${s.sprachausgabe_auto !== false ? "checked" : ""}> Aussprache automatisch abspielen (wo die Karte es vorsieht)</label>
      <label class="zeile"><input type="checkbox" name="genus_effekte" ${s.genus_effekte !== false ? "checked" : ""}> Genus-Effekte (männlich vereist, weiblich brennt, sächlich explodiert)</label>
      <div class="zeile ende"><button class="knopf haupt">Speichern</button></div>
    </form>
    <div class="karte-box" style="max-width:760px;margin-top:14px"><h3>Offline</h3>
      <p class="klein" style="margin:0">${offen ? `<b>${offen}</b> Bewertungen warten auf die Übertragung.` : "Alles ist mit dem Server abgeglichen."}</p>
      <p class="klein leise">Lernen geht auch ohne Netz: Jedes Deck, das Du einmal mit Verbindung geöffnet hast, ist auf diesem Gerät gespeichert. Am Handy kannst Du die Seite „Zum Home-Bildschirm hinzufügen“.</p>
      ${offen ? `<button class="knopf klein" data-senden>${ICON.sync} Jetzt übertragen</button>` : ""}</div>
    ${APP.ich?.admin ? `<div class="karte-box" style="max-width:760px;margin-top:14px"><h3>Eingebaute Projekte abgleichen</h3><div data-abgleich class="klein leise">lädt …</div></div>` : ""}
    <p class="klein leise" style="max-width:760px;margin-top:18px">Angemeldet als <b>${e(APP.ich?.benutzer || "?")}</b>. Schriften: Inter und Fraunces (SIL Open Font License), lokal ausgeliefert.</p>`;
  const form = $("[data-form]", app);
  form.ziel.addEventListener("input", () => ($("[data-ziel-text]", app).textContent = `${Math.round(form.ziel.value * 100)} %`));
  form.addEventListener("submit", async (ev) => {
    ev.preventDefault();
    const fd = new FormData(form);
    const daten = { ...Object.fromEntries(fd), sprachausgabe_auto: fd.has("sprachausgabe_auto"), genus_effekte: fd.has("genus_effekte") };
    try {
      const r = await senden("einstellungen_speichern", daten);
      APP.einstellungen = r.einstellungen;
      FSRS.ret = r.einstellungen.ziel;
      designSetzen(r.einstellungen.design);
      try { localStorage.setItem("kk-einstellungen", JSON.stringify(r.einstellungen)); } catch {}
      meldung("Gespeichert.");
    } catch (err) { meldung(err.message, "fehler"); }
  });
  $("[data-senden]", app)?.addEventListener("click", async () => { await warteschlangeSenden(); zeigen(app); });
  const ab = $("[data-abgleich]", app);
  if (ab) {
    const u = await holen("uebersicht");
    const eingebaut = u.projekte.filter((p) => p.quelle);
    ab.innerHTML = eingebaut.map((p) => `<div class="zeile" style="justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--rand)">
      <span><b style="color:var(--text)">${e(p.name)}</b> · ${e(vorWann(p.abgleich))}${p.abgleich_info?.karten ? ` · ${p.abgleich_info.karten} Karten` : ""}</span>
      <button class="knopf klein" data-p="${p.id}">${ICON.sync} Abgleichen</button></div>`).join("") + '<p style="margin:8px 0 0">Der Server gleicht außerdem automatisch alle 15 Minuten ab (systemd-Timer).</p>';
    ab.addEventListener("click", async (ev) => {
      const b = ev.target.closest("[data-p]");
      if (!b) return;
      b.disabled = true;
      try {
        const r = await senden("abgleich", { projekt: b.dataset.p });
        const x = r.ergebnis;
        meldung(x.uebersprungen ? "Gerade eben erst abgeglichen." : `${x.neu} neu, ${x.geaendert} geändert, ${x.entfernt} entfernt.`);
      } catch (err) { meldung(err.message, "fehler"); }
      b.disabled = false;
    });
  }
}
