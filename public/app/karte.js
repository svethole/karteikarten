// Darstellung einer Karte aus den Bausteinen ihrer Vorlage (siehe lib/notiztypen.php für die Bausteinarten).
// karteHTML() baut das HTML, karteBinden() hängt die Bedienung an (Antwort tippen, Auswahl, Wörter antippen, Ton).
import { e, ICON, mischen } from "./ui.js";
import { md, inline } from "./md.js";
import { url, tonKnoepfe, sprich, spiele, wortZeigen } from "./medien.js";

const GENUS = {
  m: { klasse: "genus-m", text: "männlich", fx: "eis" }, de: { klasse: "genus-de genus-m", text: "de-Wort", fx: "eis" },
  f: { klasse: "genus-f", text: "weiblich", fx: "feuer" },
  n: { klasse: "genus-n", text: "sächlich", fx: "knall" }, het: { klasse: "genus-het genus-n", text: "het-Wort", fx: "knall" },
};

function feldwert(notiz, name) {
  return String(notiz.felder?.[name] ?? "");
}
function feldArt(typ, name) {
  return typ.felder.find((f) => f.name === name)?.art || "text";
}
/** {{Feld}} ersetzen. roh: Werte unverändert (für Markdown), sonst maskiert */
function ersetze(text, notiz, roh = false) {
  return String(text || "").replace(/\{\{([^{}]+)\}\}/g, (m, n) => {
    const w = feldwert(notiz, n.trim());
    return roh ? w : e(w);
  });
}
function leer(notiz, name) {
  return !name || feldwert(notiz, name).trim() === "";
}

/** Gleichbleibende Mischung je Karte (Vorder- und Rückseite zeigen dieselbe Reihenfolge) */
function stabilMischen(liste, saat) {
  let s = 0;
  for (const c of String(saat)) s = (s * 31 + c.charCodeAt(0)) >>> 0;
  const zufall = () => ((s = (s * 1103515245 + 12345) >>> 0) / 4294967296);
  const a = [...liste];
  for (let i = a.length - 1; i > 0; i--) {
    const j = Math.floor(zufall() * (i + 1));
    [a[i], a[j]] = [a[j], a[i]];
  }
  return a;
}

function textBlock(text, art) {
  if (art === "md") return `<div class="md${text.length < 80 && !text.includes("\n") ? " kurz" : ""}">${md(text)}</div>`;
  return e(text).replace(/\n/g, "<br>");
}

function sprachtextHTML(text, sprache, { woerter = true } = {}) {
  if (!sprache || !woerter) return e(text);
  // Wörter einzeln antippbar machen, Satzzeichen und Leerraum bleiben stehen
  return `<span class="sprachtext" lang="${e(sprache)}">${String(text).split(/(\s+|[.,!?;:…„“"()«»¿¡–—])/).map((t) =>
    /[\p{L}\p{N}]/u.test(t) ? `<span class="w" data-wort="${e(t)}">${e(t)}</span>` : e(t)).join("")}</span>`;
}

function clozeHTML(text, ord, hinten) {
  // {{cN::Antwort::Hinweis}}: aktive Lücke verdecken bzw. aufdecken, andere Lücken normal zeigen
  const ersetzt = String(text).replace(/\{\{c(\d+)::([\s\S]*?)(?:::([\s\S]*?))?\}\}/g, (m, n, antwort, hinweis) => {
    if (Number(n) !== ord) return antwort;
    return hinten ? `\u0001${antwort}\u0002` : `\u0001${hinweis ? hinweis : "…"}\u0003`;
  });
  return md(ersetzt).replace(/\u0001([\s\S]*?)\u0002/g, '<span class="luecke offen">$1</span>').replace(/\u0001([\s\S]*?)\u0003/g, '<span class="luecke">[$1]</span>');
}

function maskenHTML(notiz, b, ord, hinten) {
  const bild = feldwert(notiz, b.feld || "Bild");
  let masken = [];
  try { masken = JSON.parse(feldwert(notiz, b.masken || "Masken") || "[]"); } catch {}
  if (!bild) return '<p class="leise">Kein Bild.</p>';
  return `<div class="verdeckung"><img src="${e(url(bild))}" alt="">${masken.map((m, i) => {
    const gruppe = m.gruppe !== "" && m.gruppe != null ? Number(m.gruppe) : i + 1;
    const aktiv = gruppe === ord;
    const klasse = aktiv ? (hinten ? "maske aufgedeckt" : "maske") : "maske andere";
    return `<div class="${klasse}" style="left:${m.x}%;top:${m.y}%;width:${m.w}%;height:${m.h}%">${aktiv ? (hinten ? e(m.text || "") : "?") : ""}</div>`;
  }).join("")}</div>`;
}

/** Ein Baustein → HTML. kontext: {typ, notiz, ord, hinten, sprache, zustand, kategorien, decknamen, saat} */
function baustein(b, k) {
  const { typ, notiz } = k;
  if (b.wenn && leer(notiz, b.wenn)) return "";
  switch (b.t) {
    case "ueberschrift": {
      const n = Math.min(3, Math.max(1, Number(b.groesse) || 2)) + 1;
      const t = ersetze(b.text, notiz);
      return t.trim() ? `<h${n} class="b-ueberschrift">${t}</h${n}>` : "";
    }
    case "text": {
      const t = ersetze(b.text, notiz, true);
      return t.trim() ? `<div class="b-text md ${e(b.groesse || "")}">${md(t)}</div>` : "";
    }
    case "feld": {
      const w = feldwert(notiz, b.feld);
      if (!w.trim()) return "";
      return `<div class="b-feld ${e(b.stil || "normal")}">${textBlock(w, feldArt(typ, b.feld))}</div>`;
    }
    case "liste": {
      const eintraege = b.feld ? feldwert(notiz, b.feld).split("\n") : (b.eintraege || []).map((x) => ersetze(x, notiz, true));
      const l = eintraege.map((x) => x.trim()).filter(Boolean);
      if (!l.length) return "";
      const tag = b.nummeriert ? "ol" : "ul";
      return `<div class="md"><${tag}>${l.map((x) => `<li>${inline(x)}</li>`).join("")}</${tag}></div>`;
    }
    case "kasten": {
      const t = ersetze(b.text, notiz, true);
      if (!t.trim() && !b.titel) return "";
      const titel = { merke: "Merke", achtung: "Achtung", info: "" }[b.art || "info"];
      return `<div class="b-kasten ${e(b.art || "info")}">${b.titel || titel ? `<span class="titel">${e(b.titel || titel)}</span>` : ""}<div class="md">${md(t)}</div></div>`;
    }
    case "trenner":
      return '<hr class="b-trenner">';
    case "abstand":
      return '<div style="height:14px"></div>';
    case "bild": {
      const v = b.quelle || feldwert(notiz, b.feld);
      if (!v) return "";
      return `<div class="b-bild"><img src="${e(url(v))}" alt="" loading="lazy" ${b.hoehe ? `style="max-height:${Number(b.hoehe)}px"` : ""}></div>`;
    }
    case "audio": {
      const v = feldwert(notiz, b.feld);
      if (!v) return "";
      return `<div class="b-audio">${tonKnoepfe(`data-audio="${e(v)}" ${b.auto && k.hinten ? "data-auto" : ""}`)}</div>`;
    }
    case "sprachausgabe": {
      const t = feldwert(notiz, b.feld);
      const sp = b.sprache || feldwert(notiz, "Sprache") || k.sprache;
      if (!t || !sp) return "";
      return `<div class="b-audio">${tonKnoepfe(`data-sprich="${e(t)}" data-sprache="${e(sp)}" ${b.auto ? "data-auto" : ""}`)}</div>`;
    }
    case "sprachtext": {
      const t = feldwert(notiz, b.feld);
      if (!t.trim()) return "";
      const sp = b.sprache || feldwert(notiz, "Sprache") || "";
      // Originalaufnahme (Assimil) für den Satz, sonst Sprachausgabe
      const audio = b.feld === "Satz" && feldwert(notiz, "Audio") ? feldwert(notiz, "Audio") : "";
      const ton = b.sprachausgabe && (audio || sp) ? tonKnoepfe(audio ? `data-audio="${e(audio)}" data-sprich-ersatz="${e(t)}" data-sprache="${e(sp)}" ${b.auto ? "data-auto" : ""}` : `data-sprich="${e(t)}" data-sprache="${e(sp)}" ${b.auto ? "data-auto" : ""}`) : "";
      return `<div class="b-sprachtext ${b.gross ? "gross-text" : ""}">${sprachtextHTML(t, sp)}${ton}</div>`;
    }
    case "eingabe": {
      const soll = feldwert(notiz, b.feld);
      if (!k.hinten) return `<div class="b-eingabe"><input type="text" data-eingabe autocomplete="off" autocapitalize="off" spellcheck="false" placeholder="Antwort eintippen …" aria-label="Antwort"></div>`;
      const ist = k.zustand?.eingabe ?? "";
      return `<div class="b-eingabe"><div class="vergleich">${vergleich(ist, soll)}</div>${ist && normal(ist) !== normal(soll) ? `<div class="leise klein">Richtig: <b>${e(soll)}</b></div>` : ""}</div>`;
    }
    case "luecke":
      return `<div class="b-luecke">${clozeHTML(feldwert(notiz, b.feld), k.ord, k.hinten)}</div>`;
    case "auswahl": {
      const richtig = feldwert(notiz, b.richtig).trim();
      const optionen = b.optionen
        ? feldwert(notiz, b.optionen).split("|").map((x) => x.trim()).filter(Boolean)
        : stabilMischen([richtig, ...feldwert(notiz, b.falsch).split("\n").map((x) => x.trim()).filter(Boolean)], k.saat);
      if (!optionen.length) return "";
      const gew = k.zustand?.gewaehlt;
      return `<div class="b-auswahl" data-richtig="${e(richtig)}" ${b.sofort ? "data-sofort" : ""}>${optionen.map((o) => {
        let kl = "";
        if (k.hinten) kl = o === richtig ? "richtig" : gew === o ? "falsch" : "";
        if (gew === o) kl += " gewaehlt";
        return `<button type="button" data-option="${e(o)}" class="${kl}" ${k.hinten ? "disabled" : ""}>${inline(o)}</button>`;
      }).join("")}</div>`;
    }
    case "tabelle": {
      if (b.feld) return `<div class="md">${md(feldwert(notiz, b.feld))}</div>`;
      const z = (b.zeilen || []).map((r) => r.map((c) => ersetze(c, notiz, true)));
      if (!z.length) return "";
      const [kopf, ...rumpf] = b.kopf ? z : [null, ...z];
      return `<div class="md"><table>${kopf ? `<thead><tr>${kopf.map((c) => `<th>${inline(c)}</th>`).join("")}</tr></thead>` : ""}<tbody>${rumpf.map((r) => `<tr>${r.map((c) => `<td>${inline(c)}</td>`).join("")}</tr>`).join("")}</tbody></table></div>`;
    }
    case "verdeckung":
      return `<div class="b-verdeckung">${maskenHTML(notiz, b, k.ord, k.hinten)}</div>`;
    case "tags": {
      const teile = [];
      const zeige = b.zeige || "alle";
      const kat = notiz.kategorie != null ? k.kategorien?.[notiz.kategorie] : null;
      if (kat && (zeige === "kategorie" || zeige === "alle")) teile.push(`<span class="chip" style="color:${e(kat.farbe)}"><span class="punkt"></span>${e(kat.symbol || "")} ${e(kat.name)}</span>`);
      if (zeige === "deck" || zeige === "alle") { const dn = k.decknamen?.[notiz.deck]; if (dn) teile.push(`<span class="chip">${e(dn)}</span>`); }
      if (zeige === "tags" || zeige === "alle") for (const t of `${notiz.tags || ""} ${notiz.zusatz?.tags || ""}`.split(" ").filter(Boolean)) teile.push(`<span class="chip">#${e(t)}</span>`);
      return teile.length ? `<div class="b-tags">${teile.join("")}</div>` : "";
    }
    case "genus":
      return ""; // wirkt auf die ganze Karte (siehe karteHTML)
    case "vorderseite":
      return seiteHTML(k.vorlage.vorne, { ...k, hinten: true, vorderseite: true });
    default:
      return "";
  }
}

function seiteHTML(bloecke, k) {
  return (bloecke || []).filter((b) => !(k.vorderseite && b.t === "vorderseite")).map((b) => baustein(b, k)).join("");
}

/** Normalisieren für den Antwortvergleich: Groß/klein und Leerraum egal */
function normal(s) {
  return String(s).trim().toLowerCase().replace(/\s+/g, " ");
}
/** Einfacher Zeichenvergleich (wie Anki): richtige Zeichen grün, falsche durchgestrichen, fehlende ergänzt */
function vergleich(ist, soll) {
  if (!ist) return `<span class="fehlt">${e(soll)}</span>`;
  const a = [...ist], b = [...soll];
  const n = a.length, m = b.length;
  const dp = Array.from({ length: n + 1 }, () => new Array(m + 1).fill(0));
  for (let i = n - 1; i >= 0; i--) for (let j = m - 1; j >= 0; j--) dp[i][j] = a[i].toLowerCase() === b[j].toLowerCase() ? dp[i + 1][j + 1] + 1 : Math.max(dp[i + 1][j], dp[i][j + 1]);
  let i = 0, j = 0, aus = "";
  while (i < n && j < m) {
    if (a[i].toLowerCase() === b[j].toLowerCase()) { aus += `<span class="ok">${e(b[j])}</span>`; i++; j++; }
    else if (dp[i + 1][j] >= dp[i][j + 1]) aus += `<span class="falsch">${e(a[i++])}</span>`;
    else aus += `<span class="fehlt">${e(b[j++])}</span>`;
  }
  while (i < n) aus += `<span class="falsch">${e(a[i++])}</span>`;
  while (j < m) aus += `<span class="fehlt">${e(b[j++])}</span>`;
  return aus;
}

function genusFX(art) {
  if (art === "eis") {
    const kristalle = Array.from({ length: 9 }, (_, i) => {
      const x = 40 + i * 90 + (i % 2) * 20, y = 38 + (i % 3) * 8, r = 7 + (i % 3) * 3;
      return `<g class="kristall" style="animation-delay:${i * 0.3}s" transform="translate(${x} ${y})" stroke="#7fb8de" stroke-width="1.6" stroke-linecap="round">${[0, 60, 120].map((a) => `<line x1="${-r}" y1="0" x2="${r}" y2="0" transform="rotate(${a})"/>`).join("")}</g>`;
    }).join("");
    return `<svg viewBox="0 0 860 70" preserveAspectRatio="none">${kristalle}</svg>`;
  }
  if (art === "feuer") {
    const flammen = Array.from({ length: 14 }, (_, i) => {
      const x = i * 64 + 10, h = 30 + ((i * 37) % 26);
      return `<path class="flamme" style="animation-delay:${(i % 5) * 0.2}s" d="M${x} 70 C${x - 4} ${70 - h * 0.5} ${x + 14} ${70 - h * 0.7} ${x + 16} ${70 - h} C${x + 22} ${70 - h * 0.55} ${x + 34} ${70 - h * 0.45} ${x + 30} 70 Z" fill="${i % 2 ? "#f4a259" : "#e86a3a"}" opacity=".55"/>`;
    }).join("");
    return `<svg viewBox="0 0 900 70" preserveAspectRatio="none">${flammen}</svg>`;
  }
  const funken = Array.from({ length: 8 }, (_, i) => {
    const x = 60 + i * 105, y = 40 + (i % 2) * 10;
    return `<g class="funke" style="animation-delay:${i * 0.22}s;transform-origin:${x}px ${y}px">${[0, 45, 90, 135, 180, 225, 270, 315].map((a) => `<line x1="${x}" y1="${y}" x2="${x + Math.cos((a * Math.PI) / 180) * 14}" y2="${y + Math.sin((a * Math.PI) / 180) * 14}" stroke="#e5b52a" stroke-width="2" stroke-linecap="round"/>`).join("")}</g>`;
  }).join("");
  return `<svg viewBox="0 0 900 70" preserveAspectRatio="none">${funken}</svg>`;
}

/**
 * Karte als HTML. o: {typ, notiz, ord, hinten, zustand ({eingabe, gewaehlt}), kategorien, decknamen, sprache, genusEffekte}
 */
export function karteHTML(o) {
  const { typ, notiz } = o;
  const vorlagen = typ.vorlagen || [];
  const vorlage = typ.art === "normal" ? vorlagen[o.ord] || vorlagen[0] : vorlagen[0];
  if (!vorlage) return '<div class="karte"><p class="leise">Diese Karte hat keine Vorlage.</p></div>';
  const k = { ...o, vorlage, saat: `${notiz.id}:${o.ord}` };
  let inhalt = seiteHTML(o.hinten ? vorlage.hinten : vorlage.vorne, k);
  if (!inhalt.trim()) inhalt = '<p class="leise">(leer)</p>';
  // Zusätze (Eselsbrücke, eigenes Bild) immer auf der Rückseite
  const z = notiz.zusatz || {};
  if (o.hinten && (z.notiz || z.bild)) {
    inhalt += `<div class="zusatz"><span class="titel">Eselsbrücke &amp; Notiz</span>${z.bild ? `<div class="b-bild"><img src="${e(url(z.bild))}" alt=""></div>` : ""}${z.notiz ? `<div class="md">${md(z.notiz)}</div>` : ""}</div>`;
  }
  // Genus: Baustein vorhanden und Feld gefüllt → Färbung und Effekt am unteren Rand
  let genusKlasse = "", fx = "";
  const gb = [...(vorlage.vorne || []), ...(vorlage.hinten || [])].find((b) => b.t === "genus");
  const g = gb ? GENUS[feldwert(notiz, gb.feld).trim()] : null;
  if (g && o.genusEffekte !== false) {
    genusKlasse = g.klasse;
    fx = `<div class="genus-fx" aria-hidden="true">${genusFX(g.fx)}</div><span class="genus-zeichen">${e(g.text)}</span>`;
  }
  const stil = typ.stil || {};
  const klassen = ["karte", stil.ausrichtung === "links" ? "" : "mitte", stil.schrift || "", stil.groesse || "", genusKlasse].filter(Boolean).join(" ");
  const hg = stil.hintergrund ? ` style="background:${e(stil.hintergrund)}"` : "";
  return `<div class="${klassen}"${hg} data-seite="${o.hinten ? "hinten" : "vorne"}">${inhalt}${fx}</div>`;
}

/**
 * Bedienung anhängen. Rückrufe: beiEingabe(text) (Enter im Antwortfeld), beiAuswahl(option, richtig, sofort).
 */
export function karteBinden(el, { beiEingabe, beiAuswahl, autoTon = true } = {}) {
  el.addEventListener("click", (ev) => {
    const w = ev.target.closest(".sprachtext .w");
    if (w) return wortZeigen(w.dataset.wort, w.closest("[lang]").getAttribute("lang"));
    const t = ev.target.closest("[data-audio],[data-sprich]");
    if (t) return tonAus(t);
    const opt = ev.target.closest("[data-option]");
    if (opt && !opt.disabled) {
      const box = opt.closest(".b-auswahl");
      box.querySelectorAll("button").forEach((b) => b.classList.remove("gewaehlt"));
      opt.classList.add("gewaehlt");
      beiAuswahl?.(opt.dataset.option, opt.dataset.option === box.dataset.richtig, box.hasAttribute("data-sofort"));
    }
  });
  const ein = el.querySelector("[data-eingabe]");
  if (ein) {
    ein.addEventListener("keydown", (ev) => {
      if (ev.key === "Enter") { ev.preventDefault(); ev.stopPropagation(); beiEingabe?.(ein.value); }
    });
    setTimeout(() => ein.focus(), 30);
  }
  if (autoTon) {
    const auto = el.querySelector("[data-auto]");
    if (auto) setTimeout(() => tonAus(auto), 150);
  }
}

async function tonAus(t) {
  const langsam = t.hasAttribute("data-langsam");
  t.classList.add("laeuft");
  try {
    if (t.dataset.audio) {
      try { await spiele(t.dataset.audio, { langsam }); }
      catch { if (t.dataset.sprichErsatz) await sprich(t.dataset.sprichErsatz, t.dataset.sprache, { langsam }); }
    } else await sprich(t.dataset.sprich, t.dataset.sprache, { langsam });
  } catch {}
  t.classList.remove("laeuft");
}

/** Kurzer Klartext einer Karte für Listen (erstes gefülltes Feld der Vorderseite) */
export function kartenTitel(typ, notiz, ord) {
  const vorlage = typ.art === "normal" ? typ.vorlagen[ord] || typ.vorlagen[0] : typ.vorlagen[0];
  for (const b of vorlage?.vorne || []) {
    const f = b.feld || (b.text && /\{\{([^}]+)\}\}/.exec(b.text)?.[1]);
    if (f && feldwert(notiz, f).trim() && feldArt(typ, f) !== "bild" && feldArt(typ, f) !== "masken") return feldwert(notiz, f);
  }
  for (const f of typ.felder) if (["text", "md"].includes(f.art) && feldwert(notiz, f.name).trim()) return feldwert(notiz, f.name);
  return "(ohne Text)";
}
export { GENUS };
