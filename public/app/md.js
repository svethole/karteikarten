// Kleines, sicheres Markdown: zuerst alles maskieren, dann nur bekannte Muster in Tags verwandeln.
// Unterstützt: Absätze, Zeilenumbrüche, **fett**, *kursiv*, ~~durch~~, ==markiert==, `code`, Listen (-, *, 1.),
// Tabellen (| a | b |), Überschriften (#–###), Zitate (>), Links [Text](https://…).
import { e } from "./ui.js";

export function inline(s) {
  let t = e(s);
  t = t.replace(/`([^`]+)`/g, "<code>$1</code>");
  t = t.replace(/\*\*([^*]+?)\*\*/g, "<strong>$1</strong>");
  t = t.replace(/__([^_]+?)__/g, "<strong>$1</strong>");
  t = t.replace(/(^|[^*\w])\*([^*\s][^*]*?)\*(?!\*)/g, "$1<em>$2</em>");
  t = t.replace(/(^|[\s(])_([^_\s][^_]*?)_(?=[\s).,;:!?]|$)/g, "$1<em>$2</em>");
  t = t.replace(/~~([^~]+)~~/g, "<del>$1</del>");
  t = t.replace(/==([^=]+)==/g, "<mark>$1</mark>");
  t = t.replace(/\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)/g, (m, txt, url) => `<a href="${url}" target="_blank" rel="noopener noreferrer">${txt}</a>`);
  return t;
}

export function md(quelle) {
  const zeilen = String(quelle ?? "").replace(/\r/g, "").split("\n");
  const aus = [];
  let i = 0;
  const istListe = (z) => /^\s*([-*•]|\d+[.)])\s+/.test(z);
  const istTabelle = (z) => /^\s*\|.*\|\s*$/.test(z);
  while (i < zeilen.length) {
    const z = zeilen[i];
    if (!z.trim()) { i++; continue; }
    let m;
    if ((m = z.match(/^(#{1,3})\s+(.*)$/))) {
      const n = Math.min(4, m[1].length + 2);
      aus.push(`<h${n}>${inline(m[2])}</h${n}>`);
      i++;
      continue;
    }
    if (istTabelle(z)) {
      const tz = [];
      while (i < zeilen.length && istTabelle(zeilen[i])) tz.push(zeilen[i++]);
      const zellen = (r) => r.trim().replace(/^\||\|$/g, "").split("|").map((c) => c.trim());
      let kopf = null;
      if (tz.length > 1 && /^[\s|:-]+$/.test(tz[1])) { kopf = zellen(tz[0]); tz.splice(0, 2); }
      aus.push(`<table>${kopf ? `<thead><tr>${kopf.map((c) => `<th>${inline(c)}</th>`).join("")}</tr></thead>` : ""}<tbody>${tz
        .map((r) => `<tr>${zellen(r).map((c) => `<td>${inline(c)}</td>`).join("")}</tr>`).join("")}</tbody></table>`);
      continue;
    }
    if (istListe(z)) {
      // Verschachtelung über Einrückung (2+ Leerzeichen)
      const eintraege = [];
      while (i < zeilen.length && (istListe(zeilen[i]) || (/^\s{2,}\S/.test(zeilen[i]) && eintraege.length))) {
        const r = zeilen[i++];
        const lm = r.match(/^(\s*)([-*•]|\d+[.)])\s+(.*)$/);
        if (lm) eintraege.push({ tief: Math.floor(lm[1].length / 2), nr: /\d/.test(lm[2]), text: lm[3] });
        else eintraege[eintraege.length - 1].text += " " + r.trim();
      }
      aus.push(liste(eintraege, 0).html);
      continue;
    }
    if (z.startsWith(">")) {
      const q = [];
      while (i < zeilen.length && zeilen[i].startsWith(">")) q.push(zeilen[i++].replace(/^>\s?/, ""));
      aus.push(`<blockquote>${q.map(inline).join("<br>")}</blockquote>`);
      continue;
    }
    const absatz = [];
    while (i < zeilen.length && zeilen[i].trim() && !istListe(zeilen[i]) && !istTabelle(zeilen[i]) && !/^#{1,3}\s/.test(zeilen[i]) && !zeilen[i].startsWith(">")) {
      absatz.push(zeilen[i++].trim());
    }
    aus.push(`<p>${absatz.map(inline).join("<br>")}</p>`);
  }
  return aus.join("");
}

function liste(eintraege, tief, start = 0) {
  let html = "";
  let i = start;
  const nr = eintraege[start]?.nr;
  html += nr ? "<ol>" : "<ul>";
  while (i < eintraege.length && eintraege[i].tief >= tief) {
    if (eintraege[i].tief > tief) {
      const unter = liste(eintraege, eintraege[i].tief, i);
      html = html.replace(/<\/li>$/, "") + unter.html + "</li>";
      i = unter.ende;
      continue;
    }
    html += `<li>${inline(eintraege[i].text)}</li>`;
    i++;
  }
  html += nr ? "</ol>" : "</ul>";
  return { html, ende: i };
}

/** Text ohne Markdown (für Vorschauen in Listen) */
export function klartext(s) {
  return String(s ?? "").replace(/\{\{c\d+::(.*?)(::.*?)?\}\}/g, "$1").replace(/[*_`#>|=~]/g, "").replace(/\s+/g, " ").trim();
}
