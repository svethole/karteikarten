// Medienverweise auflösen, Töne abspielen (ganze Dateien, Ausschnitte, Sprachausgabe), Wörterbuch für antippbare Wörter.
import { holen } from "./api.js";
import { e, $, ICON } from "./ui.js";
import { md } from "./md.js";

/** Verweis → Adresse: medien:<id> (eigene Dateien), quelle:<q>/<pfad> (Trainer-Dateien), bilder/… (mitgeliefert) */
export function url(verweis) {
  const v = String(verweis || "");
  if (v.startsWith("medien:")) return `api.php?r=medien&id=${encodeURIComponent(v.slice(7))}`;
  if (v.startsWith("quelle:")) {
    const [pfad] = v.slice(7).split("#");
    const [q, ...rest] = pfad.split("/");
    return `api.php?r=quelle&q=${encodeURIComponent(q)}&pfad=${encodeURIComponent(rest.join("/"))}`;
  }
  if (/^bilder\//.test(v)) return v;
  return "";
}

/* ---------- Ton ---------- */

let ctx = null;
const puffer = new Map();
let laeuft = null;

function audioCtx() {
  ctx = ctx || new (window.AudioContext || window.webkitAudioContext)();
  if (ctx.state === "suspended") ctx.resume();
  return ctx;
}

async function dekodiert(adresse) {
  if (!puffer.has(adresse)) {
    puffer.set(adresse, fetch(adresse, { credentials: "same-origin" }).then((r) => {
      if (!r.ok) throw new Error("Ton nicht gefunden");
      return r.arrayBuffer();
    }).then((b) => audioCtx().decodeAudioData(b)));
  }
  return puffer.get(adresse);
}

export function stopp() {
  try { laeuft?.stop(); } catch {}
  laeuft = null;
  speechSynthesis?.cancel?.();
}

/** Audioverweis abspielen; „#start-ende“ am Ende spielt nur diesen Ausschnitt (Sekunden) */
export async function spiele(verweis, { langsam = false } = {}) {
  stopp();
  const [, seg] = String(verweis).split("#");
  const buf = await dekodiert(url(verweis));
  const quelle = audioCtx().createBufferSource();
  quelle.buffer = buf;
  quelle.playbackRate.value = langsam ? 0.75 : 1;
  quelle.connect(audioCtx().destination);
  if (seg) {
    const [a, b] = seg.split("-").map(Number);
    quelle.start(0, a, Math.max(0.1, b - a));
  } else quelle.start();
  laeuft = quelle;
  return new Promise((ok) => (quelle.onended = ok));
}

const stimmen = {};
async function serverStimme(sprache) {
  if (!(sprache in stimmen)) {
    stimmen[sprache] = holen("tts_stimmen", { sprache }).then((d) => d.stimmen?.[0]?.id || null).catch(() => null);
  }
  return stimmen[sprache];
}

const BROWSER_SPRACHE = { cs: "cs-CZ", it: "it-IT", nl: "nl-NL", de: "de-DE", en: "en-GB", fr: "fr-FR", es: "es-ES", da: "da-DK" };

/** Sprachausgabe: zuerst die Serverstimmen der Trainer, sonst die Stimme des Geräts */
export async function sprich(text, sprache, { langsam = false } = {}) {
  text = String(text || "").replace(/\s+/g, " ").trim();
  if (!text) return;
  stopp();
  const stimme = await serverStimme(sprache);
  if (stimme) {
    const adresse = `api.php?r=tts&sprache=${encodeURIComponent(sprache)}&stimme=${encodeURIComponent(stimme)}&t=${encodeURIComponent(text)}${langsam ? "&langsam=1" : ""}`;
    try {
      const buf = await dekodiert(adresse);
      const q = audioCtx().createBufferSource();
      q.buffer = buf;
      q.connect(audioCtx().destination);
      q.start();
      laeuft = q;
      return new Promise((ok) => (q.onended = ok));
    } catch {
      puffer.delete(adresse);
    }
  }
  if (!("speechSynthesis" in window)) return;
  const u = new SpeechSynthesisUtterance(text);
  u.lang = BROWSER_SPRACHE[sprache] || sprache;
  u.rate = langsam ? 0.7 : 0.95;
  const v = speechSynthesis.getVoices().find((x) => x.lang?.toLowerCase().startsWith((BROWSER_SPRACHE[sprache] || sprache).slice(0, 2).toLowerCase()));
  if (v) u.voice = v;
  speechSynthesis.speak(u);
}

/** Knöpfe „anhören“ / „langsam“ (data-Attribute werden von karte.js gebunden) */
export function tonKnoepfe(attr) {
  return `<span class="ton"><button type="button" ${attr} aria-label="Anhören">${ICON.lautsprecher}</button><button type="button" ${attr} data-langsam aria-label="Langsam anhören">${ICON.schnecke}</button></span>`;
}

/* ---------- Wörterbuch ---------- */

const lexika = {};
export function lexikon(sprache) {
  if (!lexika[sprache]) {
    lexika[sprache] = holen("lexikon", { sprache }).then((d) => {
      const nach = new Map();
      for (const x of d.eintraege || []) for (const k of [x.lemma, ...(x.aliases || [])]) if (k && !nach.has(k.toLowerCase())) nach.set(k.toLowerCase(), x);
      return { nach, glossar: d.glossar || {} };
    }).catch(() => ({ nach: new Map(), glossar: {} }));
  }
  return lexika[sprache];
}

/** Wort nachschlagen und in einem Blatt zeigen */
export async function wortZeigen(wort, sprache) {
  const lx = await lexikon(sprache);
  const w = wort.toLowerCase().replace(/[.,!?;:…„“"()«»¿¡]/g, "");
  const g = lx.glossar[w];
  const eintrag = lx.nach.get(w) || (g && lx.nach.get(String(g.l || "").toLowerCase()));
  $(".blatt")?.remove();
  const b = document.createElement("div");
  b.className = "blatt";
  b.setAttribute("role", "dialog");
  let inhalt = "";
  if (eintrag) {
    inhalt = `<h3 lang="${e(sprache)}">${e(eintrag.lemma)} ${tonKnoepfe(`data-sprich="${e(eintrag.lemma)}" data-sprache="${e(sprache)}"`)}</h3>
      <p><b>${e(eintrag.de)}</b><br><span class="leise klein">${e([eintrag.pos, eintrag.gender].filter(Boolean).join(", "))}</span></p>
      ${eintrag.summary ? `<p class="klein">${e(eintrag.summary)}</p>` : ""}
      ${eintrag.note ? `<div class="md klein">${md(eintrag.note)}</div>` : ""}
      ${(eintrag.tables || []).map((t) => `${t.title ? `<p class="klein"><b>${e(t.title)}</b></p>` : ""}<div class="md"><table>${t.cols?.some(Boolean) ? `<thead><tr>${t.cols.map((c) => `<th>${e(c)}</th>`).join("")}</tr></thead>` : ""}<tbody>${t.rows
        .map((r) => `<tr>${r.map((c, i) => (i === 0 ? `<th>${e(c)}</th>` : `<td lang="${e(sprache)}" ${String(c).toLowerCase().split(/\s*[\/,]\s*/).includes(w) ? 'style="background:var(--akzent-weich);font-weight:650"' : ""}>${e(c)}</td>`)).join("")}</tr>`).join("")}</tbody></table></div>`).join("")}`;
  } else if (g) {
    inhalt = `<h3 lang="${e(sprache)}">${e(wort)} ${tonKnoepfe(`data-sprich="${e(wort)}" data-sprache="${e(sprache)}"`)}</h3>
      <p><b>${e(g.de || "")}</b>${g.l && g.l !== w ? ` <span class="leise">(Grundform: <i>${e(g.l)}</i>)</span>` : ""}</p>
      ${g.f || g.p ? `<p class="leise klein">${e([g.p, g.f].filter(Boolean).join(" · "))}</p>` : ""}${g.n ? `<div class="md klein">${md(g.n)}</div>` : ""}`;
  } else {
    inhalt = `<h3 lang="${e(sprache)}">${e(wort)} ${tonKnoepfe(`data-sprich="${e(wort)}" data-sprache="${e(sprache)}"`)}</h3><p class="leise">Kein Eintrag im Wörterbuch des Trainers.</p>`;
  }
  b.innerHTML = `<div class="zeile" style="justify-content:flex-end;margin-bottom:-28px"><button class="knopf leise rund" data-blatt-zu aria-label="Schließen">${ICON.x}</button></div>${inhalt}`;
  document.body.append(b);
  b.addEventListener("click", (ev) => {
    if (ev.target.closest("[data-blatt-zu]")) b.remove();
    const s = ev.target.closest("[data-sprich]");
    if (s) sprich(s.dataset.sprich, s.dataset.sprache, { langsam: s.hasAttribute("data-langsam") });
  });
  const weg = (ev) => { if (!b.contains(ev.target)) { b.remove(); document.removeEventListener("pointerdown", weg, true); } };
  setTimeout(() => document.addEventListener("pointerdown", weg, true), 0);
}
