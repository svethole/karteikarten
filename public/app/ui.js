// Kleine Helfer für die Oberfläche: Maskieren, Auswahl, Meldungen, Dialoge, Zeitangaben, Symbole.

export const $ = (s, el = document) => el.querySelector(s);
export const $$ = (s, el = document) => [...el.querySelectorAll(s)];

// HTML maskieren – jeder fremde Text läuft hier durch, bevor er in innerHTML landet
export function e(s) {
  return String(s ?? "").replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[c]);
}

export function meldung(text, art = "") {
  let box = $(".meldungen");
  if (!box) {
    box = document.createElement("div");
    box.className = "meldungen";
    box.setAttribute("role", "status");
    document.body.append(box);
  }
  const m = document.createElement("div");
  m.className = "meldung " + art;
  m.textContent = text;
  box.append(m);
  setTimeout(() => m.remove(), art === "fehler" ? 6000 : 3200);
}

// Dialog: gibt ein Promise zurück, das mit dem Rückgabewert von schliessen(w) erfüllt wird (Abbrechen = null)
export function dialog({ titel, inhalt, fuss = "", breit = false, beimOeffnen }) {
  return new Promise((fertig) => {
    const d = document.createElement("dialog");
    d.className = "fenster" + (breit ? " breit" : "");
    d.innerHTML = `<div class="fenster-kopf"><h2>${e(titel)}</h2><button class="knopf leise rund" data-zu aria-label="Schließen">${ICON.x}</button></div>
      <div class="fenster-inhalt">${inhalt}</div>${fuss ? `<div class="fenster-fuss">${fuss}</div>` : ""}`;
    document.body.append(d);
    let wert = null;
    const schliessen = (w = null) => { wert = w; d.close(); };
    d.addEventListener("close", () => { d.remove(); fertig(wert); });
    d.addEventListener("click", (ev) => {
      if (ev.target === d) schliessen();
      if (ev.target.closest("[data-zu]")) schliessen();
    });
    d.showModal();
    beimOeffnen?.(d, schliessen);
  });
}

export async function bestaetigen(text, { ja = "Ja", gefahr = false } = {}) {
  return dialog({
    titel: "Sicher?",
    inhalt: `<p style="margin:0">${e(text)}</p>`,
    fuss: `<button class="knopf leise" data-zu>Abbrechen</button><button class="knopf ${gefahr ? "gefahr" : "haupt"}" data-ja>${e(ja)}</button>`,
    beimOeffnen: (d, zu) => $("[data-ja]", d).addEventListener("click", () => zu(true)),
  });
}

export async function eingabeDialog(titel, { wert = "", feld = "Name", knopf = "Speichern" } = {}) {
  return dialog({
    titel,
    inhalt: `<label class="feld">${e(feld)}<input class="eingabe" data-w value="${e(wert)}" maxlength="120"></label>`,
    fuss: `<button class="knopf leise" data-zu>Abbrechen</button><button class="knopf haupt" data-ok>${e(knopf)}</button>`,
    beimOeffnen: (d, zu) => {
      const i = $("[data-w]", d);
      i.focus();
      i.select();
      const ok = () => i.value.trim() && zu(i.value.trim());
      $("[data-ok]", d).addEventListener("click", ok);
      i.addEventListener("keydown", (ev) => ev.key === "Enter" && ok());
    },
  });
}

export function ladeAnzeige(text = "Einen Moment …") {
  return `<div class="laden"><div class="kreisel"></div><p class="klein">${e(text)}</p></div>`;
}

export function zahl(n) {
  return new Intl.NumberFormat("de-DE").format(n || 0);
}

export function vorWann(ms) {
  if (!ms) return "noch nie";
  const s = (Date.now() - ms) / 1000;
  if (s < 60) return "gerade eben";
  if (s < 3600) return `vor ${Math.round(s / 60)} Min.`;
  if (s < 86400) return `vor ${Math.round(s / 3600)} Std.`;
  const t = Math.round(s / 86400);
  return t === 1 ? "gestern" : `vor ${t} Tagen`;
}

export function datum(ms) {
  return new Date(ms).toLocaleDateString("de-DE", { day: "numeric", month: "short", year: "numeric" });
}

export function inTagen(ms, tagesbeginn) {
  const t = Math.round((ms - tagesbeginn) / 86400000);
  if (t <= 0) return "heute";
  if (t === 1) return "morgen";
  if (t < 30) return `in ${t} Tagen`;
  if (t < 365) return `in ${Math.round(t / 30)} Mon.`;
  return `in ${(t / 365).toFixed(1).replace(".", ",")} J.`;
}

// Flaggen als kleine SVGs (keine externen Dateien)
export function flagge(code) {
  const f = {
    cz: '<svg viewBox="0 0 30 20" class="flagge"><rect width="30" height="10" fill="#fff"/><rect y="10" width="30" height="10" fill="#d7141a"/><path d="M0 0 15 10 0 20z" fill="#11457e"/></svg>',
    it: '<svg viewBox="0 0 30 20" class="flagge"><rect width="10" height="20" fill="#009246"/><rect x="10" width="10" height="20" fill="#fff"/><rect x="20" width="10" height="20" fill="#ce2b37"/></svg>',
    nl: '<svg viewBox="0 0 30 20" class="flagge"><rect width="30" height="7" fill="#ae1c28"/><rect y="7" width="30" height="6" fill="#fff"/><rect y="13" width="30" height="7" fill="#21468b"/></svg>',
    de: '<svg viewBox="0 0 30 20" class="flagge"><rect width="30" height="7" fill="#000"/><rect y="7" width="30" height="6" fill="#dd0000"/><rect y="13" width="30" height="7" fill="#ffce00"/></svg>',
  };
  return f[code] || "";
}

// Symbole (Linien-Icons, 24er Raster)
const svg = (p) => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${p}</svg>`;
export const ICON = {
  x: svg('<path d="M18 6 6 18M6 6l12 12"/>'),
  haus: svg('<path d="M3 11.5 12 4l9 7.5"/><path d="M5 10v10h14V10"/>'),
  statistik: svg('<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>'),
  zahnrad: svg('<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>'),
  plus: svg('<path d="M12 5v14M5 12h14"/>'),
  stift: svg('<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>'),
  papierkorb: svg('<path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6"/>'),
  pfeil: svg('<path d="m6 9 6 6 6-6"/>'),
  zurueck: svg('<path d="M15 18l-6-6 6-6"/>'),
  spielen: svg('<path d="M7 4v16l13-8z"/>'),
  lautsprecher: svg('<path d="M11 5 6 9H2v6h4l5 4z"/><path d="M15.5 8.5a5 5 0 0 1 0 7M19 5a10 10 0 0 1 0 14"/>'),
  schnecke: svg('<path d="M3 18h14a4 4 0 0 0 4-4V9"/><circle cx="11" cy="12" r="5"/><path d="M11 12a1.5 1.5 0 1 1 0-3"/><path d="M19 9V5M21 9V6"/>'),
  sync: svg('<path d="M21 12a9 9 0 0 1-15.5 6.3L3 16"/><path d="M3 12A9 9 0 0 1 18.5 5.7L21 8"/><path d="M21 3v5h-5M3 21v-5h5"/>'),
  stern: svg('<path d="m12 3 2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1L3.2 9.5l6.1-.9z"/>'),
  pause: svg('<path d="M9 5v14M15 5v14"/>'),
  karten: svg('<rect x="3" y="6" width="14" height="14" rx="2"/><path d="M7 2h12a2 2 0 0 1 2 2v12"/>'),
  liste: svg('<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>'),
  bild: svg('<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-5-5L5 21"/>'),
  hochladen: svg('<path d="M12 16V4M7 9l5-5 5 5"/><path d="M4 16v4h16v-4"/>'),
  herunter: svg('<path d="M12 4v12M7 11l5 5 5-5"/><path d="M4 20h16"/>'),
  funke: svg('<path d="M12 3v4M12 17v4M3 12h4M17 12h4M6 6l2.5 2.5M15.5 15.5 18 18M6 18l2.5-2.5M15.5 8.5 18 6"/>'),
  griff: svg('<circle cx="9" cy="6" r="1"/><circle cx="15" cy="6" r="1"/><circle cx="9" cy="12" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="9" cy="18" r="1"/><circle cx="15" cy="18" r="1"/>'),
  kopie: svg('<rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15H4a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v1"/>'),
  schloss: svg('<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>'),
  wolke: svg('<path d="M17.5 19H8a5 5 0 1 1 .9-9.9A6 6 0 0 1 20.5 11 4 4 0 0 1 17.5 19z"/><path d="M3 3l18 18"/>'),
};

export const MARKE = `<svg viewBox="0 0 32 32" aria-hidden="true"><rect x="7" y="4" width="20" height="24" rx="4" fill="var(--akzent)" opacity=".35" transform="rotate(8 17 16)"/><rect x="5" y="5" width="20" height="24" rx="4" fill="var(--akzent)"/><path d="M10 13h10M10 18h7" stroke="var(--akzent-text)" stroke-width="2.2" stroke-linecap="round"/></svg>`;

// Mischen (Fisher-Yates)
export function mischen(a) {
  const b = [...a];
  for (let i = b.length - 1; i > 0; i--) {
    const j = Math.floor(Math.random() * (i + 1));
    [b[i], b[j]] = [b[j], b[i]];
  }
  return b;
}
