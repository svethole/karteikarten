<?php
// Projekte, Decks, Zugriffsrechte und Zählungen für Übersicht und Deckbaum.
declare(strict_types=1);

/** Eingebaute Projekte: Grunddaten (Name, Farbe, Bild …). Bilder liegen unter public/bilder/kacheln/. */
function eingebaute_projekte(): array
{
    return [
        'its' => [
            'name' => 'ITS-Karteikarten', 'beschreibung' => 'Fragen und Antworten aus der Intensivpflege',
            'farbe' => '#b4533c', 'symbol' => '🫀', 'flagge' => '', 'sprache' => 'de',
            'bild' => 'bilder/kacheln/its.webp',
            'bild_nachweis' => '3D-Herzmodell aus dem Kardio-Trainer: Z-Anatomy / BodyParts3D (DBCLS), CC BY-SA 4.0',
            'alle_sehen' => true,
        ],
        'cz' => [
            'name' => 'Tschechisch', 'beschreibung' => 'Mluvíme česky – Vokabeln, Sätze, Grundformen und ten/ta/to',
            'farbe' => '#2563a8', 'symbol' => '', 'flagge' => 'cz', 'sprache' => 'cs',
            'bild' => 'bilder/kacheln/cz.webp', 'bild_nachweis' => '', 'alle_sehen' => false,
        ],
        'it' => [
            'name' => 'Italienisch', 'beschreibung' => 'Parliamo italiano – Assimil, Dieci, Kleine Wörter und il/la',
            'farbe' => '#1f7a4d', 'symbol' => '', 'flagge' => 'it', 'sprache' => 'it',
            'bild' => 'bilder/kacheln/it.webp', 'bild_nachweis' => '', 'alle_sehen' => false,
        ],
        'nl' => [
            'name' => 'Niederländisch', 'beschreibung' => 'Nederlands leren – In gang, In actie, Kleine Wörter und de/het',
            'farbe' => '#d4691e', 'symbol' => '', 'flagge' => 'nl', 'sprache' => 'nl',
            'bild' => 'bilder/kacheln/nl.webp', 'bild_nachweis' => '', 'alle_sehen' => false,
        ],
    ];
}

/** Legt die eingebauten Projekte an, falls ihre Quelle auf dem Server vorhanden ist. */
function eingebaute_projekte_sicherstellen(): void
{
    static $fertig = false;
    if ($fertig) return;
    $fertig = true;
    $quellen = (array) config('quellen');
    $nachweise = @json_decode((string) @file_get_contents(APP_WURZEL . '/public/bilder/kacheln/nachweise.json'), true) ?: [];
    foreach (eingebaute_projekte() as $q => $p) {
        if (empty($quellen[$q]) || !is_dir($quellen[$q])) continue;
        if (qwert('SELECT id FROM projekte WHERE quelle = ?', [$q])) continue;
        $t = jetzt_ms();
        q('INSERT INTO projekte (quelle, name, beschreibung, farbe, symbol, flagge, sprache, bild, bild_nachweis, angelegt, geaendert) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
            [$q, $p['name'], $p['beschreibung'], $p['farbe'], $p['symbol'], $p['flagge'], $p['sprache'], $p['bild'], $nachweise[$q] ?? $p['bild_nachweis'], $t, $t]);
        $id = (int) db()->lastInsertId();
        // Standard-Freigabe: ITS für alle (Platzhalter *), Sprachen nur für die Admins
        if ($p['alle_sehen']) q('INSERT INTO freigaben (projekt_id, benutzer) VALUES (?, ?)', [$id, '*']);
        else foreach ((array) config('admins') as $a) q('INSERT OR IGNORE INTO freigaben (projekt_id, benutzer) VALUES (?, ?)', [$id, $a]);
    }
}

/** SQL-Bedingung: Projekte, die der Benutzer sehen darf (Platzhalter :b) */
function sql_projekt_sichtbar(string $p = 'p'): string
{
    return "$p.geloescht = 0 AND (($p.quelle IS NULL AND $p.besitzer = :b) OR ($p.quelle IS NOT NULL AND EXISTS (SELECT 1 FROM freigaben fr WHERE fr.projekt_id = $p.id AND (fr.benutzer = :b OR fr.benutzer = '*'))))";
}

/** SQL-Bedingung: Notiz für den Benutzer freigeschaltet (Lektion abgeschlossen bzw. eigene Karten) */
function sql_notiz_frei(string $n = 'n'): string
{
    return "($n.sperre IS NULL OR EXISTS (SELECT 1 FROM freischaltungen fs WHERE fs.benutzer = :b AND fs.projekt_id = $n.projekt_id AND fs.sperre = $n.sperre))";
}

function projekt_pruefen(int $id, bool $schreiben = false): array
{
    eingebaute_projekte_sicherstellen();
    $p = q1('SELECT * FROM projekte p WHERE p.id = :id AND ' . sql_projekt_sichtbar(), [':id' => $id, ':b' => benutzer()]);
    if (!$p) fehler(404, 'Projekt nicht gefunden.');
    if ($schreiben && $p['quelle'] !== null) fehler(403, 'Eingebaute Projekte bekommen ihre Inhalte vom Import. Ändern kannst Du nur Zusätze.');
    return $p;
}

function deck_pruefen(int $deckId, bool $schreiben = false): array
{
    $d = q1('SELECT * FROM decks WHERE id = ?', [$deckId]);
    if (!$d) fehler(404, 'Deck nicht gefunden.');
    projekt_pruefen((int) $d['projekt_id'], $schreiben);
    return $d;
}

function projekt_einstellungen(array $projekt): array
{
    $alle = benutzer_einstellungen();
    $eigen = $alle['projekte'][$projekt['id']] ?? [];
    return array_replace(['neu_pro_tag' => $alle['neu_pro_tag'], 'max_wdh_pro_tag' => $alle['max_wdh_pro_tag'], 'neu_mischen' => $alle['neu_mischen']], $eigen);
}

/** Zählungen je Deck (nur direkt enthaltene Karten) für den Benutzer */
function deck_zaehlungen(int $projektId): array
{
    $b = benutzer();
    $jetzt = jetzt_ms();
    $ende = tagesbeginn($jetzt) + TAG_MS;
    $zeilen = qalle(
        "SELECT n.deck_id AS deck,
            COUNT(*) AS gesamt,
            SUM(CASE WHEN z.st IS NULL AND COALESCE(z.pausiert,0) = 0 THEN 1 ELSE 0 END) AS neu,
            SUM(CASE WHEN z.st IN ('learn','relearn') AND z.faellig < :ende AND z.pausiert = 0 AND z.zurueck_bis <= :jetzt THEN 1 ELSE 0 END) AS lernen,
            SUM(CASE WHEN z.st = 'review' AND z.faellig < :ende AND z.pausiert = 0 AND z.zurueck_bis <= :jetzt THEN 1 ELSE 0 END) AS faellig,
            SUM(CASE WHEN z.st IS NOT NULL THEN 1 ELSE 0 END) AS gelernt,
            SUM(CASE WHEN z.st = 'review' AND z.s >= 21 THEN 1 ELSE 0 END) AS sicher,
            SUM(CASE WHEN COALESCE(z.pausiert,0) = 1 THEN 1 ELSE 0 END) AS pausiert
         FROM karten k
         JOIN notizen n ON n.id = k.notiz_id
         LEFT JOIN zustand z ON z.karte_id = k.id AND z.benutzer = :b
         WHERE k.projekt_id = :p AND k.geloescht = 0 AND n.geloescht = 0 AND " . sql_notiz_frei() . "
         GROUP BY n.deck_id",
        [':b' => $b, ':p' => $projektId, ':ende' => $ende, ':jetzt' => $jetzt]
    );
    $aus = [];
    foreach ($zeilen as $z) {
        $aus[(int) $z['deck']] = array_map('intval', $z);
    }
    return $aus;
}

/** Heute schon eingeführte neue Karten und Wiederholungen je Deck */
function deck_heute(int $projektId): array
{
    $zeilen = qalle(
        "SELECT n.deck_id AS deck,
            SUM(CASE WHEN w.st_vorher IS NULL THEN 1 ELSE 0 END) AS neu,
            SUM(CASE WHEN w.st_vorher IS NOT NULL THEN 1 ELSE 0 END) AS wdh
         FROM wiederholungen w JOIN karten k ON k.id = w.karte_id JOIN notizen n ON n.id = k.notiz_id
         WHERE w.benutzer = :b AND k.projekt_id = :p AND w.zeit >= :start GROUP BY n.deck_id",
        [':b' => benutzer(), ':p' => $projektId, ':start' => tagesbeginn()]
    );
    $aus = [];
    foreach ($zeilen as $z) $aus[(int) $z['deck']] = ['neu' => (int) $z['neu'], 'wdh' => (int) $z['wdh']];
    return $aus;
}

/** Deckbaum mit aufsummierten Zählungen. Gibt [baum, summe] zurück. */
function deck_baum(array $projekt): array
{
    $pid = (int) $projekt['id'];
    $decks = qalle('SELECT id, eltern_id, name, beschreibung, sortierung, schluessel FROM decks WHERE projekt_id = ? ORDER BY sortierung, name COLLATE NOCASE', [$pid]);
    $zahl = deck_zaehlungen($pid);
    $heute = deck_heute($pid);
    $knoten = [];
    foreach ($decks as $d) {
        $id = (int) $d['id'];
        $z = $zahl[$id] ?? [];
        $knoten[$id] = [
            'id' => $id, 'eltern' => $d['eltern_id'] === null ? null : (int) $d['eltern_id'], 'name' => $d['name'],
            'beschreibung' => $d['beschreibung'], 'schluessel' => $d['schluessel'], 'kinder' => [],
            'eigen' => ['gesamt' => $z['gesamt'] ?? 0, 'neu' => $z['neu'] ?? 0, 'lernen' => $z['lernen'] ?? 0, 'faellig' => $z['faellig'] ?? 0,
                'gelernt' => $z['gelernt'] ?? 0, 'sicher' => $z['sicher'] ?? 0, 'pausiert' => $z['pausiert'] ?? 0,
                'heute_neu' => $heute[$id]['neu'] ?? 0, 'heute_wdh' => $heute[$id]['wdh'] ?? 0],
        ];
    }
    $wurzeln = [];
    foreach ($knoten as $id => &$k) {
        if ($k['eltern'] !== null && isset($knoten[$k['eltern']])) $knoten[$k['eltern']]['kinder'][] = &$k;
        else $wurzeln[] = &$k;
    }
    unset($k);
    $summe = function (array &$k) use (&$summe) {
        $s = $k['eigen'];
        foreach ($k['kinder'] as &$c) {
            $cs = $summe($c);
            foreach ($s as $f => $v) $s[$f] = $v + $cs[$f];
        }
        $k['summe'] = $s;
        return $s;
    };
    $gesamt = ['gesamt' => 0, 'neu' => 0, 'lernen' => 0, 'faellig' => 0, 'gelernt' => 0, 'sicher' => 0, 'pausiert' => 0, 'heute_neu' => 0, 'heute_wdh' => 0];
    foreach ($wurzeln as &$w) {
        $s = $summe($w);
        foreach ($gesamt as $f => $v) $gesamt[$f] = $v + $s[$f];
    }
    unset($w);
    // Neue Karten heute: Tagesbudget des Projekts, alle Decks teilen es sich
    $einst = projekt_einstellungen($projekt);
    $rest = max(0, (int) $einst['neu_pro_tag'] - $gesamt['heute_neu']);
    $wdhRest = max(0, (int) $einst['max_wdh_pro_tag'] - $gesamt['heute_wdh']);
    $setze = function (array &$k) use (&$setze, $rest, $wdhRest) {
        $k['summe']['neu_heute'] = min($k['summe']['neu'], $rest);
        $k['summe']['faellig_heute'] = min($k['summe']['faellig'], $wdhRest);
        foreach ($k['kinder'] as &$c) $setze($c);
    };
    foreach ($wurzeln as &$w) $setze($w);
    unset($w);
    $gesamt['neu_heute'] = min($gesamt['neu'], $rest);
    $gesamt['faellig_heute'] = min($gesamt['faellig'], $wdhRest);
    return [$wurzeln, $gesamt];
}

/** Alle Deck-IDs eines Teilbaums */
function deck_teilbaum(int $deckId): array
{
    $ids = [$deckId];
    $rand = [$deckId];
    while ($rand) {
        $platz = implode(',', array_fill(0, count($rand), '?'));
        $rand = array_map('intval', q("SELECT id FROM decks WHERE eltern_id IN ($platz)", $rand)->fetchAll(PDO::FETCH_COLUMN));
        $ids = array_merge($ids, $rand);
    }
    return $ids;
}

/** Deck per Schlüssel holen oder anlegen (Import) */
function deck_sicherstellen(int $projektId, string $schluessel, string $name, ?int $eltern, int $sortierung): int
{
    $d = q1('SELECT id, name, eltern_id, sortierung FROM decks WHERE projekt_id = ? AND schluessel = ?', [$projektId, $schluessel]);
    if ($d) {
        if ($d['name'] !== $name || (int) $d['sortierung'] !== $sortierung || ($d['eltern_id'] === null ? null : (int) $d['eltern_id']) !== $eltern) {
            q('UPDATE decks SET name = ?, eltern_id = ?, sortierung = ? WHERE id = ?', [$name, $eltern, $sortierung, $d['id']]);
        }
        return (int) $d['id'];
    }
    q('INSERT INTO decks (projekt_id, eltern_id, name, sortierung, schluessel) VALUES (?, ?, ?, ?, ?)', [$projektId, $eltern, $name, $sortierung, $schluessel]);
    return (int) db()->lastInsertId();
}

/** Serie: Tage am Stück mit mindestens einer Wiederholung (heute zählt, sobald gelernt) */
function serie(?int $projektId = null): int
{
    $sql = 'SELECT DISTINCT w.zeit FROM wiederholungen w' . ($projektId ? ' JOIN karten k ON k.id = w.karte_id AND k.projekt_id = :p' : '') .
        ' WHERE w.benutzer = :b AND w.zeit >= :ab';
    $p = [':b' => benutzer(), ':ab' => jetzt_ms() - 400 * TAG_MS];
    if ($projektId) $p[':p'] = $projektId;
    $tage = [];
    foreach (q($sql, $p)->fetchAll(PDO::FETCH_COLUMN) as $z) $tage[tagesschluessel((int) $z)] = true;
    $n = 0;
    $t = tagesbeginn();
    if (!isset($tage[tagesschluessel($t)])) $t -= TAG_MS; // heute noch nicht gelernt: Serie läuft noch
    while (isset($tage[tagesschluessel($t)])) { $n++; $t -= TAG_MS; }
    return $n;
}
