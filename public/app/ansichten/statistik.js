// Statistik (gesamt oder je Projekt): Kennzahlen, Kalender, Prognose, Kartenstände, Intervalle, Behaltensquote je Deck.
import { holen } from "../api.js";
import { e, zahl } from "../ui.js";

const prozent = (x) => (x == null ? "–" : `${Math.round(x * 1000) / 10} %`.replace(".", ","));

export function statistikHTML(d) {
  // Kalender: 53 Wochen, Spalten = Wochen (Mo–So)
  const heute = new Date(d.heute + "T12:00:00");
  const start = new Date(heute);
  start.setDate(start.getDate() - 364 - ((start.getDay() + 6) % 7));
  const werte = Object.values(d.kalender);
  const max = Math.max(1, ...werte);
  const zellen = [];
  for (let t = new Date(start); t <= heute; t.setDate(t.getDate() + 1)) {
    const k = t.toISOString().slice(0, 10);
    const n = d.kalender[k] || 0;
    const stufe = n === 0 ? 0 : Math.min(4, Math.ceil((n / max) * 4));
    zellen.push(`<span data-s="${stufe}" title="${e(t.toLocaleDateString("de-DE"))}: ${n} Wiederholungen"></span>`);
  }
  const tageGelernt = werte.filter((n) => n > 0).length;
  const minuten = Math.round(Object.values(d.zeit).reduce((a, b) => a + b, 0) / 60000);
  const st = d.staende;
  const summe = Math.max(1, st.neu + st.lernen + st.jung + st.sicher);
  const pmax = Math.max(1, ...d.prognose);
  const imax = Math.max(1, ...Object.values(d.intervalle));
  const kn = d.knoepfe;
  const knSumme = Math.max(1, kn[1] + kn[2] + kn[3] + kn[4]);

  return `
    <div class="kennzahlen">
      <div class="zahlkarte"><b>${d.serie}</b><span>Tage am Stück</span></div>
      <div class="zahlkarte"><b>${zahl(tageGelernt)}</b><span>Lerntage im Jahr</span></div>
      <div class="zahlkarte"><b>${prozent(d.quote30)}</b><span>Behalten (30 Tage)</span></div>
      <div class="zahlkarte"><b>${zahl(d.wdh30)}</b><span>Wiederholungen (30 T.)</span></div>
      <div class="zahlkarte"><b>${zahl(minuten)}</b><span>Minuten im Jahr</span></div>
    </div>
    <div class="karte-box"><h3>Kalender</h3><div class="heatmap">${zellen.join("")}</div></div>
    <div class="spalten" style="margin-top:14px">
      <div class="karte-box"><h3>Fällig in den nächsten 30 Tagen</h3>
        <div class="saeulen">${d.prognose.map((n, i) => `<div style="height:${(n / pmax) * 100}%" title="${i === 0 ? "heute" : i === 1 ? "morgen" : `in ${i} Tagen`}: ${n}"></div>`).join("")}</div>
        <div class="achse"><span>heute</span><span>+15</span><span>+29</span></div></div>
      <div class="karte-box"><h3>Kartenstände</h3>
        <div class="balken" style="height:14px;margin:8px 0 12px">
          <span style="width:${(st.neu / summe) * 100}%;background:var(--neu-farbe)"></span><span style="width:${(st.lernen / summe) * 100}%;background:var(--lernen-farbe)"></span>
          <span style="width:${(st.jung / summe) * 100}%;background:var(--schwer)"></span><span style="width:${(st.sicher / summe) * 100}%;background:var(--gut)"></span></div>
        <div class="klein" style="display:grid;grid-template-columns:1fr auto;gap:3px 12px">
          <span><span class="chip" style="color:var(--neu-farbe)"><span class="punkt"></span>neu</span></span><b>${zahl(st.neu)}</b>
          <span><span class="chip" style="color:var(--lernen-farbe)"><span class="punkt"></span>lernend</span></span><b>${zahl(st.lernen)}</b>
          <span><span class="chip" style="color:var(--schwer)"><span class="punkt"></span>jung (&lt; 21 Tage)</span></span><b>${zahl(st.jung)}</b>
          <span><span class="chip" style="color:var(--gut)"><span class="punkt"></span>sicher (≥ 21 Tage)</span></span><b>${zahl(st.sicher)}</b>
          <span class="leise">pausiert</span><b>${zahl(st.pausiert)}</b></div></div>
      <div class="karte-box"><h3>Stabilität (Intervalle)</h3>
        ${Object.entries(d.intervalle).map(([k, n]) => `<div class="klein" style="display:grid;grid-template-columns:150px 1fr 40px;gap:8px;align-items:center;margin:4px 0"><span>${e(k)}</span><div class="balken"><span style="width:${(n / imax) * 100}%;background:var(--akzent)"></span></div><b style="text-align:right">${zahl(n)}</b></div>`).join("")}</div>
      <div class="karte-box"><h3>Antworten (30 Tage)</h3>
        ${[[1, "Nochmal", "nochmal"], [2, "Schwer", "schwer"], [3, "Gut", "gut"], [4, "Einfach", "einfach"]].map(([g, n, f]) => `<div class="klein" style="display:grid;grid-template-columns:80px 1fr 60px;gap:8px;align-items:center;margin:4px 0"><span>${n}</span><div class="balken"><span style="width:${(kn[g] / knSumme) * 100}%;background:var(--${f})"></span></div><b style="text-align:right">${prozent(kn[g] / knSumme)}</b></div>`).join("")}</div>
    </div>
    ${d.decks.length ? `<div class="karte-box" style="margin-top:14px"><h3>Behaltensquote je Deck (30 Tage)</h3><table class="liste" style="border:0"><thead><tr><th>Deck</th><th class="mobil-weg">Projekt</th><th>Wiederholungen</th><th>Behalten</th></tr></thead><tbody>
      ${d.decks.map((x) => `<tr><td>${e(x.name)}</td><td class="mobil-weg leise">${e(x.projekt)}</td><td>${zahl(x.anzahl)}</td><td><b style="color:${x.quote >= 0.85 ? "var(--gut)" : x.quote >= 0.7 ? "var(--schwer)" : "var(--nochmal)"}">${prozent(x.quote)}</b></td></tr>`).join("")}</tbody></table></div>` : ""}`;
}

export async function zeigen(app) {
  const d = await holen("statistik");
  app.innerHTML = `<h1>Statistik</h1><p class="leise" style="margin-top:-6px">Über alle Projekte</p>${statistikHTML(d)}`;
}
