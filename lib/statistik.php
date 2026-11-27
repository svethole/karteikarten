<?php
// Statistiken: Kalender (Wiederholungen je Tag), Prognose der Fälligkeiten, Behaltensquote je Deck, Kartenstände.
declare(strict_types=1);

function statistik(?int $projektId): array
{
    eingebaute_projekte_sicherstellen();
    $b = benutzer();
    $par = [':b' => $b];
    if ($projektId) {
        projekt_pruefen($projektId);
        $filter = 'k.projekt_id = :p';
        $par[':p'] = $projektId;
    } else {
        $ids = array_map('intval', q('SELECT p.id FROM projekte p WHERE ' . sql_projekt_sichtbar(), [':b' => $b])->fetchAll(PDO::FETCH_COLUMN));
        $filter = $ids ? 'k.projekt_id IN (' . implode(',', $ids) . ')' : '0';
    }
    $start = tagesbeginn();

    // Kalender: letzte 365 Tage
    $kalender = [];
    $zeit = [];
    foreach (qalle("SELECT w.zeit, w.note, w.dauer FROM wiederholungen w JOIN karten k ON k.id = w.karte_id WHERE w.benutzer = :b AND $filter AND w.zeit >= :ab", $par + [':ab' => $start - 370 * TAG_MS]) as $w) {
        $tag = tagesschluessel((int) $w['zeit']);
        $kalender[$tag] = ($kalender[$tag] ?? 0) + 1;
        $zeit[$tag] = ($zeit[$tag] ?? 0) + (int) $w['dauer'];
    }

    // Prognose: fällige Karten der nächsten 30 Tage (Überfälliges zählt auf heute)
    $prognose = array_fill(0, 30, 0);
    foreach (qalle("SELECT z.faellig FROM zustand z JOIN karten k ON k.id = z.karte_id JOIN notizen n ON n.id = k.notiz_id
                    WHERE z.benutzer = :b AND $filter AND z.st IS NOT NULL AND z.pausiert = 0 AND k.geloescht = 0 AND n.geloescht = 0", $par) as $z) {
        $i = (int) floor((max((int) $z['faellig'], $start) - $start) / TAG_MS);
        if ($i < 30) $prognose[$i]++;
    }

    // Kartenstände
    $staende = q1("SELECT
            SUM(CASE WHEN z.st IS NULL THEN 1 ELSE 0 END) AS neu,
            SUM(CASE WHEN z.st IN ('learn','relearn') THEN 1 ELSE 0 END) AS lernen,
            SUM(CASE WHEN z.st = 'review' AND z.s < 21 THEN 1 ELSE 0 END) AS jung,
            SUM(CASE WHEN z.st = 'review' AND z.s >= 21 THEN 1 ELSE 0 END) AS sicher,
            SUM(CASE WHEN z.pausiert = 1 THEN 1 ELSE 0 END) AS pausiert
        FROM karten k JOIN notizen n ON n.id = k.notiz_id LEFT JOIN zustand z ON z.karte_id = k.id AND z.benutzer = :b
        WHERE $filter AND k.geloescht = 0 AND n.geloescht = 0 AND " . sql_notiz_frei(), $par) ?: [];

    // Behaltensquote je Deck (letzte 30 Tage, nur Wiederholungen von gelernten Karten) und Antworten
    $decks = [];
    foreach (qalle("SELECT n.deck_id, d.name, p.name AS projekt, COUNT(*) AS anzahl, SUM(CASE WHEN w.note > 1 THEN 1 ELSE 0 END) AS richtig
                    FROM wiederholungen w JOIN karten k ON k.id = w.karte_id JOIN notizen n ON n.id = k.notiz_id JOIN decks d ON d.id = n.deck_id JOIN projekte p ON p.id = k.projekt_id
                    WHERE w.benutzer = :b AND $filter AND w.zeit >= :ab AND w.st_vorher = 'review' GROUP BY n.deck_id ORDER BY anzahl DESC LIMIT 40", $par + [':ab' => $start - 30 * TAG_MS]) as $r) {
        $decks[] = ['deck' => (int) $r['deck_id'], 'name' => $r['name'], 'projekt' => $r['projekt'], 'anzahl' => (int) $r['anzahl'], 'quote' => $r['anzahl'] ? round($r['richtig'] / $r['anzahl'], 3) : null];
    }
    $knoepfe = array_fill(1, 4, 0);
    foreach (qalle("SELECT w.note, COUNT(*) AS n FROM wiederholungen w JOIN karten k ON k.id = w.karte_id WHERE w.benutzer = :b AND $filter AND w.zeit >= :ab GROUP BY w.note", $par + [':ab' => $start - 30 * TAG_MS]) as $r) {
        $knoepfe[(int) $r['note']] = (int) $r['n'];
    }
    $gesamt30 = q1("SELECT COUNT(*) AS n, SUM(CASE WHEN w.note > 1 THEN 1 ELSE 0 END) AS r FROM wiederholungen w JOIN karten k ON k.id = w.karte_id
                    WHERE w.benutzer = :b AND $filter AND w.zeit >= :ab AND w.st_vorher = 'review'", $par + [':ab' => $start - 30 * TAG_MS]);

    // Intervalle (Stabilität) der gelernten Karten, gruppiert
    $intervalle = ['1 Tag' => 0, '2–6 Tage' => 0, '1–3 Wochen' => 0, '3 Wochen – 3 Monate' => 0, '3–12 Monate' => 0, 'über 1 Jahr' => 0];
    foreach (q("SELECT z.s FROM zustand z JOIN karten k ON k.id = z.karte_id WHERE z.benutzer = :b AND $filter AND z.st = 'review' AND k.geloescht = 0", $par)->fetchAll(PDO::FETCH_COLUMN) as $s) {
        $s = (float) $s;
        $k = $s < 1.5 ? '1 Tag' : ($s < 7 ? '2–6 Tage' : ($s < 21 ? '1–3 Wochen' : ($s < 90 ? '3 Wochen – 3 Monate' : ($s < 365 ? '3–12 Monate' : 'über 1 Jahr'))));
        $intervalle[$k]++;
    }

    return [
        'ok' => true, 'heute' => tagesschluessel($start), 'kalender' => (object) $kalender, 'zeit' => (object) $zeit, 'prognose' => $prognose,
        'staende' => array_map('intval', $staende), 'decks' => $decks, 'knoepfe' => $knoepfe, 'intervalle' => $intervalle,
        'quote30' => $gesamt30 && $gesamt30['n'] ? round($gesamt30['r'] / $gesamt30['n'], 3) : null, 'wdh30' => (int) ($gesamt30['n'] ?? 0),
        'serie' => serie($projektId),
    ];
}
