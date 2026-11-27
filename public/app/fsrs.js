// FSRS-5 – gleiche Gewichte und Planung wie der Karteikasten der Sprachtrainer (Tschechisch/Italienisch/Niederländisch),
// damit übernommene Lernstände nahtlos weiterlaufen. Ziel-Behaltensquote einstellbar (Standard 0,9).

const W = [0.40255, 1.18385, 3.173, 15.69105, 7.1949, 0.5345, 1.4604, 0.0046, 1.54575, 0.1192, 1.01925, 1.9395, 0.11, 0.29605, 2.2698, 0.2315, 2.9898, 0.51655, 0.6621];
const DECAY = -0.5;
const FACTOR = 19 / 81;
export const MIN = 60e3;
export const TAG = 86400e3;

export const FSRS = {
  ret: 0.9,
  clampD: (d) => Math.min(10, Math.max(1, d)),
  R(t, s) { return Math.pow(1 + (FACTOR * t) / s, DECAY); },
  days(s) { return Math.max(1, Math.round((s / FACTOR) * (Math.pow(this.ret, 1 / DECAY) - 1))); },
  initS(g) { return Math.max(0.1, W[g - 1]); },
  initD(g) { return this.clampD(W[4] - Math.exp(W[5] * (g - 1)) + 1); },
  nextD(d, g) {
    const dp = d - (W[6] * (g - 3) * (10 - d)) / 9;
    return this.clampD(W[7] * this.initD(4) + (1 - W[7]) * dp);
  },
  recallS(d, s, r, g) {
    const hard = g === 2 ? W[15] : 1, easy = g === 4 ? W[16] : 1;
    return s * (1 + Math.exp(W[8]) * (11 - d) * Math.pow(s, -W[9]) * (Math.exp((1 - r) * W[10]) - 1) * hard * easy);
  },
  forgetS(d, s, r) { return Math.min(s, W[11] * Math.pow(d, -W[12]) * (Math.pow(s + 1, W[13]) - 1) * Math.exp((1 - r) * W[14])); },
  shortS(s, g) { return s * Math.exp(W[17] * (g - 3 + W[18])); },
};

/** Lerntag beginnt um 4 Uhr früh (Ortszeit des Geräts) */
export const tagesbeginn = (ts) => {
  const d = new Date(ts - 4 * 3600e3);
  d.setHours(0, 0, 0, 0);
  return d.getTime() + 4 * 3600e3;
};

/** Planung für alle vier Bewertungen auf einmal (wie FC.plan in den Trainern) */
export function planen(s, jetzt) {
  const res = {};
  if (!s || !s.st) {
    for (const g of [1, 2, 3, 4]) {
      const S0 = FSRS.initS(g), D0 = FSRS.initD(g);
      res[g] = g === 4
        ? { st: "review", s: S0, d: D0, due: tagesbeginn(jetzt) + FSRS.days(S0) * TAG, ivl: FSRS.days(S0) }
        : { st: "learn", s: S0, d: D0, due: jetzt + [1, 6, 10][g - 1] * MIN, mins: [1, 6, 10][g - 1] };
    }
    return res;
  }
  if (s.st === "learn" || s.st === "relearn") {
    for (const g of [1, 2, 3, 4]) {
      const ns = FSRS.shortS(s.s, g), nd = FSRS.nextD(s.d, g);
      if (g <= 2) res[g] = { st: s.st, s: ns, d: nd, due: jetzt + (g === 1 ? 1 : 6) * MIN, mins: g === 1 ? 1 : 6 };
      else {
        let ivl = FSRS.days(ns);
        if (g === 4) ivl = Math.max(ivl, (res[3] ? res[3].ivl : 1) + 1);
        res[g] = { st: "review", s: ns, d: nd, ivl, due: tagesbeginn(jetzt) + ivl * TAG };
      }
    }
    return res;
  }
  const t = Math.max(0, (jetzt - (s.last || jetzt)) / TAG), r = FSRS.R(t, s.s);
  res[1] = { st: "relearn", s: FSRS.forgetS(s.d, s.s, r), d: FSRS.nextD(s.d, 1), due: jetzt + 10 * MIN, mins: 10, lapse: true };
  const iv = {};
  for (const g of [2, 3, 4]) {
    const ns = FSRS.recallS(s.d, s.s, r, g);
    iv[g] = { s: ns, d: FSRS.nextD(s.d, g), ivl: FSRS.days(ns) };
  }
  iv[2].ivl = Math.min(iv[2].ivl, iv[3].ivl);
  iv[3].ivl = Math.max(iv[3].ivl, iv[2].ivl + 1);
  iv[4].ivl = Math.max(iv[4].ivl, iv[3].ivl + 1);
  for (const g of [2, 3, 4]) res[g] = { st: "review", s: iv[g].s, d: iv[g].d, ivl: iv[g].ivl, due: tagesbeginn(jetzt) + iv[g].ivl * TAG };
  return res;
}

export function abstandText(p) {
  if (p.mins) return p.mins < 2 ? "<1 Min" : p.mins + " Min";
  const d = p.ivl;
  if (d < 30) return d + (d === 1 ? " Tag" : " Tage");
  if (d < 365) return String(Math.round((d / 30) * 10) / 10).replace(".", ",") + " Mon";
  return String(Math.round((d / 365) * 10) / 10).replace(".", ",") + " J";
}

/** Neuer Zustand nach einer Bewertung */
export function bewerten(alt, g, jetzt) {
  const p = planen(alt, jetzt)[g];
  return {
    st: p.st, s: +p.s.toFixed(4), d: +p.d.toFixed(4), due: p.due, ivl: p.ivl ?? p.mins / 1440,
    last: jetzt, first: alt?.first || jetzt, reps: (alt?.reps || 0) + 1, lapses: (alt?.lapses || 0) + (p.lapse ? 1 : 0), t: jetzt,
  };
}
