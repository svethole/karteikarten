<?php
// Anlegen, Ändern, Löschen: Projekte, Decks, Kategorien, Notiztypen, Notizen, Zusätze. Dazu Übersicht und Kartenliste.
declare(strict_types=1);

const FARBE_MUSTER = '/^#[0-9a-fA-F]{6}$/';

function farbe_pruefen(string $f, string $standard): string
{
    return preg_match(FARBE_MUSTER, $f) ? strtolower($f) : $standard;
}

/** Bildverweis prüfen: eigene Medien (medien:<id>), mitgelieferte Kachelbilder oder Quelldateien */
function bildverweis_pruefen(string $b): string
{
    $b = trim($b);
    if ($b === '') return '';
    if (preg_match('/^medien:[a-f0-9]{16,64}\.[a-z0-9]{2,5}$/', $b)) return $b;
    if (preg_match('#^bilder/[a-z0-9/_-]+\.(webp|jpg|jpeg|png|svg)$#', $b)) return $b;
    if (preg_match('#^quelle:(its|cz|it|nl)/[A-Za-z0-9/_.-]+$#', $b) && !str_contains($b, '..')) return $b;
    fehler(400, 'Ungültiger Bildverweis.');
    return '';
}

function uebersicht(): array
{
    eingebaute_projekte_sicherstellen();
    $b = benutzer();
    $projekte = qalle('SELECT * FROM projekte p WHERE ' . sql_projekt_sichtbar() . ' ORDER BY p.quelle IS NULL, p.id', [':b' => $b]);
    $aus = [];
    foreach ($projekte as $p) {
        [, $s] = deck_baum($p);
        $info = json_lies($p['abgleich_info'], []);
        $aus[] = [
            'id' => (int) $p['id'], 'name' => $p['name'], 'beschreibung' => $p['beschreibung'], 'farbe' => $p['farbe'], 'symbol' => $p['symbol'],
            'flagge' => $p['flagge'], 'bild' => $p['bild'], 'bild_nachweis' => $p['bild_nachweis'], 'quelle' => $p['quelle'], 'sprache' => $p['sprache'],
            'zahlen' => $s, 'serie' => serie((int) $p['id']), 'abgleich' => $p['abgleich'] === null ? null : (int) $p['abgleich'], 'abgleich_info' => $info,
        ];
    }
    $e = benutzer_einstellungen();
    $wdhHeute = (int) qwert('SELECT COUNT(*) FROM wiederholungen WHERE benutzer = ? AND zeit >= ?', [$b, tagesbeginn()]);
    return ['ok' => true, 'projekte' => $aus, 'serie' => serie(), 'heute' => $wdhHeute, 'reihenfolge' => $e['kachel_reihenfolge']];
}

function projekt_details(int $id): array
{
    $p = projekt_pruefen($id);
    [$baum, $summe] = deck_baum($p);
    $kategorien = qalle('SELECT id, name, farbe, symbol, sortierung FROM kategorien WHERE projekt_id = ? ORDER BY sortierung, name COLLATE NOCASE', [$id]);
    $typen = [];
    foreach (qalle('SELECT id, name, art, eingebaut, projekt_id FROM notiztypen WHERE projekt_id = ? OR projekt_id IS NULL ORDER BY projekt_id IS NULL, name', [$id]) as $t) {
        // Import-Typen nur in Projekten zeigen, die sie benützen
        if ($t['eingebaut'] && preg_match('/^(its|sprache)-/', $t['eingebaut']) && $p['quelle'] === null
            && !qwert('SELECT 1 FROM notizen WHERE projekt_id = ? AND typ_id = ? LIMIT 1', [$id, $t['id']])) continue;
        $typen[] = ['id' => (int) $t['id'], 'name' => $t['name'], 'art' => $t['art'], 'eingebaut' => $t['eingebaut'], 'eigen' => $t['projekt_id'] !== null];
    }
    $tags = [];
    foreach (q('SELECT tags FROM notizen WHERE projekt_id = ? AND geloescht = 0 AND tags <> \'\'', [$id])->fetchAll(PDO::FETCH_COLUMN) as $t) {
        foreach (explode(' ', $t) as $x) if ($x !== '') $tags[$x] = ($tags[$x] ?? 0) + 1;
    }
    foreach (q('SELECT z.tags FROM zusaetze z JOIN notizen n ON n.id = z.notiz_id WHERE n.projekt_id = ? AND z.benutzer = ? AND z.tags <> \'\'', [$id, benutzer()])->fetchAll(PDO::FETCH_COLUMN) as $t) {
        foreach (explode(' ', $t) as $x) if ($x !== '') $tags[$x] = ($tags[$x] ?? 0) + 1;
    }
    ksort($tags, SORT_NATURAL | SORT_FLAG_CASE);
    $freigaben = ist_admin() && $p['quelle'] !== null ? q('SELECT benutzer FROM freigaben WHERE projekt_id = ?', [$id])->fetchAll(PDO::FETCH_COLUMN) : null;
    $log = $p['quelle'] !== null ? qalle('SELECT zeit, neu, geaendert, entfernt, meldung FROM abgleich_log WHERE projekt_id = ? ORDER BY id DESC LIMIT 10', [$id]) : [];
    return [
        'ok' => true,
        'projekt' => [
            'id' => (int) $p['id'], 'name' => $p['name'], 'beschreibung' => $p['beschreibung'], 'farbe' => $p['farbe'], 'symbol' => $p['symbol'],
            'flagge' => $p['flagge'], 'sprache' => $p['sprache'], 'bild' => $p['bild'], 'bild_nachweis' => $p['bild_nachweis'], 'quelle' => $p['quelle'],
            'abgleich' => $p['abgleich'] === null ? null : (int) $p['abgleich'], 'abgleich_info' => json_lies($p['abgleich_info'], []),
            'einstellungen' => projekt_einstellungen($p), 'darf_bearbeiten' => $p['quelle'] === null,
        ],
        'decks' => $baum, 'summe' => $summe, 'kategorien' => $kategorien, 'typen' => $typen, 'tags' => $tags,
        'freigaben' => $freigaben, 'abgleich_log' => $log, 'serie' => serie($id),
    ];
}

function projekt_speichern(array $e): array
{
    $name = trim(text_kuerzen((string) ($e['name'] ?? ''), 80));
    $id = (int) ($e['id'] ?? 0);
    $t = jetzt_ms();
    if ($id) {
        $p = projekt_pruefen($id);
        // Eingebaute Projekte: nur Darstellung (Bild, Farbe) und nur für Admins
        if ($p['quelle'] !== null) {
            admin_pflicht();
            q('UPDATE projekte SET farbe = ?, bild = ?, bild_nachweis = ?, geaendert = ? WHERE id = ?', [
                farbe_pruefen((string) ($e['farbe'] ?? $p['farbe']), $p['farbe']), bildverweis_pruefen((string) ($e['bild'] ?? $p['bild'])),
                text_kuerzen((string) ($e['bild_nachweis'] ?? $p['bild_nachweis']), 300), $t, $id]);
        } else {
            if ($name === '') fehler(400, 'Bitte gib dem Projekt einen Namen.');
            q('UPDATE projekte SET name = ?, beschreibung = ?, farbe = ?, symbol = ?, sprache = ?, bild = ?, bild_nachweis = ?, geaendert = ? WHERE id = ?', [
                $name, text_kuerzen((string) ($e['beschreibung'] ?? ''), 300), farbe_pruefen((string) ($e['farbe'] ?? ''), $p['farbe']),
                text_kuerzen((string) ($e['symbol'] ?? ''), 8), text_kuerzen((string) ($e['sprache'] ?? ''), 8),
                bildverweis_pruefen((string) ($e['bild'] ?? '')), text_kuerzen((string) ($e['bild_nachweis'] ?? ''), 300), $t, $id]);
        }
    } else {
        if ($name === '') fehler(400, 'Bitte gib dem Projekt einen Namen.');
        q('INSERT INTO projekte (besitzer, name, beschreibung, farbe, symbol, sprache, bild, bild_nachweis, angelegt, geaendert) VALUES (?,?,?,?,?,?,?,?,?,?)', [
            benutzer(), $name, text_kuerzen((string) ($e['beschreibung'] ?? ''), 300), farbe_pruefen((string) ($e['farbe'] ?? ''), '#4f6d7a'),
            text_kuerzen((string) ($e['symbol'] ?? ''), 8), text_kuerzen((string) ($e['sprache'] ?? ''), 8),
            bildverweis_pruefen((string) ($e['bild'] ?? '')), text_kuerzen((string) ($e['bild_nachweis'] ?? ''), 300), $t, $t]);
        $id = (int) db()->lastInsertId();
        q('INSERT INTO decks (projekt_id, name, sortierung) VALUES (?, ?, 0)', [$id, 'Allgemein']);
    }
    // Persönliche Lerneinstellungen des Projekts
    if (isset($e['einstellungen']) && is_array($e['einstellungen'])) {
        $alle = benutzer_einstellungen();
        $alt = $alle['projekte'][$id] ?? [];
        foreach (['neu_pro_tag' => [0, 9999], 'max_wdh_pro_tag' => [0, 99999]] as $k => [$min, $max]) {
            if (isset($e['einstellungen'][$k])) $alt[$k] = max($min, min($max, (int) $e['einstellungen'][$k]));
        }
        if (isset($e['einstellungen']['neu_mischen']) && in_array($e['einstellungen']['neu_mischen'], ['reihenfolge', 'gemischt', 'zufall'], true)) $alt['neu_mischen'] = $e['einstellungen']['neu_mischen'];
        $alle['projekte'][$id] = $alt;
        einstellungen_schreiben($alle);
    }
    return ['ok' => true, 'id' => $id];
}

function einstellungen_schreiben(array $e): void
{
    q('UPDATE benutzer SET einstellungen = ? WHERE name = ?', [json_kodiere($e), benutzer()]);
}

function einstellungen_speichern(array $e): array
{
    $alle = benutzer_einstellungen();
    if (isset($e['neu_pro_tag'])) $alle['neu_pro_tag'] = max(0, min(9999, (int) $e['neu_pro_tag']));
    if (isset($e['max_wdh_pro_tag'])) $alle['max_wdh_pro_tag'] = max(0, min(99999, (int) $e['max_wdh_pro_tag']));
    if (isset($e['ziel'])) $alle['ziel'] = max(0.7, min(0.97, (float) $e['ziel']));
    if (isset($e['neu_mischen']) && in_array($e['neu_mischen'], ['reihenfolge', 'gemischt', 'zufall'], true)) $alle['neu_mischen'] = $e['neu_mischen'];
    if (isset($e['design']) && in_array($e['design'], ['auto', 'hell', 'dunkel'], true)) $alle['design'] = $e['design'];
    foreach (['sprachausgabe_auto', 'genus_effekte'] as $k) if (isset($e[$k])) $alle[$k] = (bool) $e[$k];
    if (isset($e['kachel_reihenfolge']) && is_array($e['kachel_reihenfolge'])) $alle['kachel_reihenfolge'] = array_slice(array_map('intval', $e['kachel_reihenfolge']), 0, 200);
    einstellungen_schreiben($alle);
    return ['ok' => true, 'einstellungen' => $alle];
}

function projekt_loeschen(int $id): array
{
    projekt_pruefen($id, true);
    q('UPDATE projekte SET geloescht = 1, geaendert = ? WHERE id = ?', [jetzt_ms(), $id]);
    return ['ok' => true];
}

function deck_speichern(array $e): array
{
    $pid = (int) ($e['projekt'] ?? 0);
    projekt_pruefen($pid, true);
    $name = trim(text_kuerzen((string) ($e['name'] ?? ''), 120));
    if ($name === '') fehler(400, 'Bitte gib dem Deck einen Namen.');
    $eltern = !empty($e['eltern']) ? (int) $e['eltern'] : null;
    if ($eltern !== null && (int) qwert('SELECT projekt_id FROM decks WHERE id = ?', [$eltern]) !== $pid) fehler(400, 'Ungültiges Elterndeck.');
    $id = (int) ($e['id'] ?? 0);
    if ($id) {
        $d = deck_pruefen($id, true);
        if ((int) $d['projekt_id'] !== $pid) fehler(400, 'Falsches Projekt.');
        if ($eltern !== null && in_array($eltern, deck_teilbaum($id), true)) fehler(400, 'Ein Deck kann nicht in sich selbst liegen.');
        q('UPDATE decks SET name = ?, eltern_id = ?, beschreibung = ? WHERE id = ?', [$name, $eltern, text_kuerzen((string) ($e['beschreibung'] ?? ''), 500), $id]);
    } else {
        $sort = (int) qwert('SELECT COALESCE(MAX(sortierung), 0) + 1 FROM decks WHERE projekt_id = ?', [$pid]);
        q('INSERT INTO decks (projekt_id, eltern_id, name, beschreibung, sortierung) VALUES (?,?,?,?,?)', [$pid, $eltern, $name, text_kuerzen((string) ($e['beschreibung'] ?? ''), 500), $sort]);
        $id = (int) db()->lastInsertId();
    }
    return ['ok' => true, 'id' => $id];
}

function deck_loeschen(int $id): array
{
    $d = deck_pruefen($id, true);
    $ids = deck_teilbaum($id);
    $platz = implode(',', array_map('intval', $ids));
    $anzahl = (int) qwert("SELECT COUNT(*) FROM decks WHERE projekt_id = ?", [$d['projekt_id']]);
    if ($anzahl <= count($ids)) fehler(400, 'Das letzte Deck eines Projekts bleibt stehen.');
    db()->exec('BEGIN');
    q("UPDATE notizen SET geloescht = 1, geaendert = ? WHERE deck_id IN ($platz)", [jetzt_ms()]);
    // Gelöschte Notizen in ein verbleibendes Deck hängen, damit die Fremdschlüssel gültig bleiben
    $rest = (int) qwert("SELECT id FROM decks WHERE projekt_id = ? AND id NOT IN ($platz) ORDER BY sortierung LIMIT 1", [$d['projekt_id']]);
    q("UPDATE notizen SET deck_id = ? WHERE deck_id IN ($platz)", [$rest]);
    q("DELETE FROM decks WHERE id IN ($platz)");
    db()->exec('COMMIT');
    return ['ok' => true];
}

function deck_sortieren(array $e): array
{
    $pid = (int) ($e['projekt'] ?? 0);
    projekt_pruefen($pid, true);
    foreach ((array) ($e['reihenfolge'] ?? []) as $i => $did) q('UPDATE decks SET sortierung = ? WHERE id = ? AND projekt_id = ?', [$i, (int) $did, $pid]);
    return ['ok' => true];
}

function kategorie_speichern(array $e): array
{
    $pid = (int) ($e['projekt'] ?? 0);
    projekt_pruefen($pid, true);
    $name = trim(text_kuerzen((string) ($e['name'] ?? ''), 60));
    if ($name === '') fehler(400, 'Bitte gib der Kategorie einen Namen.');
    $farbe = farbe_pruefen((string) ($e['farbe'] ?? ''), '#64748b');
    $symbol = text_kuerzen((string) ($e['symbol'] ?? ''), 8);
    $id = (int) ($e['id'] ?? 0);
    try {
        if ($id) q('UPDATE kategorien SET name = ?, farbe = ?, symbol = ? WHERE id = ? AND projekt_id = ?', [$name, $farbe, $symbol, $id, $pid]);
        else {
            q('INSERT INTO kategorien (projekt_id, name, farbe, symbol, sortierung) VALUES (?,?,?,?, (SELECT COALESCE(MAX(sortierung),0)+1 FROM kategorien WHERE projekt_id = ?))', [$pid, $name, $farbe, $symbol, $pid]);
            $id = (int) db()->lastInsertId();
        }
    } catch (PDOException $ex) {
        fehler(400, 'Eine Kategorie mit diesem Namen gibt es schon.');
    }
    return ['ok' => true, 'id' => $id];
}

function kategorie_loeschen(int $id): array
{
    $pid = (int) qwert('SELECT projekt_id FROM kategorien WHERE id = ?', [$id]);
    projekt_pruefen($pid, true);
    q('DELETE FROM kategorien WHERE id = ?', [$id]);
    return ['ok' => true];
}

/* ---------- Notiztypen ---------- */

function notiztyp_details(int $id): array
{
    $t = notiztyp_laden($id);
    if (!$t) fehler(404, 'Notiztyp nicht gefunden.');
    if ($t['projekt_id'] !== null) projekt_pruefen((int) $t['projekt_id']);
    $anzahl = (int) qwert('SELECT COUNT(*) FROM notizen WHERE typ_id = ? AND geloescht = 0', [$id]);
    return ['ok' => true, 'typ' => [
        'id' => (int) $t['id'], 'projekt' => $t['projekt_id'] === null ? null : (int) $t['projekt_id'], 'name' => $t['name'], 'art' => $t['art'],
        'felder' => $t['felder'], 'vorlagen' => $t['vorlagen'], 'stil' => $t['stil'], 'eingebaut' => $t['eingebaut'], 'notizen' => $anzahl,
    ]];
}

const BAUSTEIN_ARTEN = ['ueberschrift', 'text', 'feld', 'liste', 'kasten', 'trenner', 'bild', 'audio', 'sprachausgabe', 'eingabe', 'luecke',
    'auswahl', 'tabelle', 'verdeckung', 'tags', 'sprachtext', 'genus', 'vorderseite', 'abstand'];

/** Bausteine aus dem Designer säubern: nur bekannte Arten und Eigenschaften, Texte begrenzt */
function bausteine_pruefen($liste): array
{
    $aus = [];
    foreach (array_slice((array) $liste, 0, 60) as $b) {
        if (!is_array($b) || !in_array($b['t'] ?? '', BAUSTEIN_ARTEN, true)) continue;
        $sauber = ['t' => $b['t']];
        foreach ($b as $k => $v) {
            if ($k === 't' || !preg_match('/^[a-z_]{1,20}$/', (string) $k)) continue;
            if (is_bool($v) || is_int($v) || is_float($v)) $sauber[$k] = $v;
            elseif (is_string($v)) $sauber[$k] = text_kuerzen($v, 4000);
            elseif ($k === 'zeilen' && is_array($v)) $sauber[$k] = array_map(fn($z) => array_map(fn($c) => text_kuerzen((string) $c, 500), array_slice((array) $z, 0, 12)), array_slice($v, 0, 40));
            elseif ($k === 'eintraege' && is_array($v)) $sauber[$k] = array_map(fn($c) => text_kuerzen((string) $c, 500), array_slice($v, 0, 40));
        }
        $aus[] = $sauber;
    }
    return $aus;
}

function notiztyp_speichern(array $e): array
{
    $id = (int) ($e['id'] ?? 0);
    $pid = (int) ($e['projekt'] ?? 0);
    if ($id) {
        $alt = notiztyp_laden($id);
        if (!$alt || $alt['projekt_id'] === null) fehler(403, 'Eingebaute Notiztypen lassen sich nicht ändern – leg eine Kopie an.');
        $pid = (int) $alt['projekt_id'];
    }
    projekt_pruefen($pid, true);
    $name = trim(text_kuerzen((string) ($e['name'] ?? ''), 80));
    if ($name === '') fehler(400, 'Bitte gib dem Notiztyp einen Namen.');
    $art = in_array($e['art'] ?? 'normal', ['normal', 'luecke', 'verdeckung'], true) ? $e['art'] : 'normal';
    $felder = [];
    $namen = [];
    foreach (array_slice((array) ($e['felder'] ?? []), 0, 30) as $f) {
        $n = trim(text_kuerzen((string) ($f['name'] ?? ''), 40));
        $n = str_replace(['{', '}'], '', $n);
        if ($n === '' || in_array(mb_strtolower($n), $namen, true)) continue;
        $namen[] = mb_strtolower($n);
        $felder[] = ['name' => $n, 'art' => in_array($f['art'] ?? 'text', ['text', 'md', 'bild', 'audio', 'masken'], true) ? $f['art'] : 'text', 'alt' => text_kuerzen((string) ($f['alt'] ?? ''), 40)];
    }
    if (!$felder) fehler(400, 'Ein Notiztyp braucht mindestens ein Feld.');
    $vorlagen = [];
    foreach (array_slice((array) ($e['vorlagen'] ?? []), 0, 10) as $v) {
        $vorlagen[] = ['name' => text_kuerzen(trim((string) ($v['name'] ?? 'Karte')), 60) ?: 'Karte', 'bedingung' => text_kuerzen((string) ($v['bedingung'] ?? ''), 40),
            'vorne' => bausteine_pruefen($v['vorne'] ?? []), 'hinten' => bausteine_pruefen($v['hinten'] ?? [])];
    }
    if (!$vorlagen) fehler(400, 'Ein Notiztyp braucht mindestens eine Kartenvorlage.');
    if ($art !== 'normal') $vorlagen = [$vorlagen[0]];
    $stil = [];
    foreach (['ausrichtung' => ['mitte', 'links'], 'schrift' => ['sans', 'serif', 'mono'], 'groesse' => ['klein', 'normal', 'gross']] as $k => $erlaubt) {
        if (isset($e['stil'][$k]) && in_array($e['stil'][$k], $erlaubt, true)) $stil[$k] = $e['stil'][$k];
    }
    if (isset($e['stil']['hintergrund']) && preg_match(FARBE_MUSTER, (string) $e['stil']['hintergrund'])) $stil['hintergrund'] = $e['stil']['hintergrund'];
    $t = jetzt_ms();
    if ($id) {
        // Felder umbenannt? Werte in allen Notizen mitziehen (alt = bisheriger Name)
        $umbenannt = [];
        foreach ($felder as &$f) {
            if ($f['alt'] !== '' && $f['alt'] !== $f['name']) $umbenannt[$f['alt']] = $f['name'];
            unset($f['alt']);
        }
        unset($f);
        db()->exec('BEGIN');
        q('UPDATE notiztypen SET name = ?, art = ?, felder = ?, vorlagen = ?, stil = ?, geaendert = ? WHERE id = ?', [$name, $art, json_kodiere($felder), json_kodiere($vorlagen), json_kodiere((object) $stil), $t, $id]);
        $typ = notiztyp_laden($id);
        foreach (qalle('SELECT id, projekt_id, felder FROM notizen WHERE typ_id = ? AND geloescht = 0', [$id]) as $n) {
            $fw = json_lies($n['felder'], []);
            if ($umbenannt) {
                foreach ($umbenannt as $alt => $neu) if (array_key_exists($alt, $fw)) { $fw[$neu] = $fw[$alt]; unset($fw[$alt]); }
                q('UPDATE notizen SET felder = ? WHERE id = ?', [json_kodiere((object) $fw), $n['id']]);
            }
            karten_abgleichen((int) $n['id'], (int) $n['projekt_id'], $typ, $fw);
        }
        db()->exec('COMMIT');
    } else {
        foreach ($felder as &$f) unset($f['alt']);
        unset($f);
        q('INSERT INTO notiztypen (projekt_id, name, art, felder, vorlagen, stil, geaendert) VALUES (?,?,?,?,?,?,?)', [$pid, $name, $art, json_kodiere($felder), json_kodiere($vorlagen), json_kodiere((object) $stil), $t]);
        $id = (int) db()->lastInsertId();
    }
    return ['ok' => true, 'id' => $id];
}

function notiztyp_loeschen(int $id): array
{
    $t = notiztyp_laden($id);
    if (!$t || $t['projekt_id'] === null) fehler(403, 'Eingebaute Notiztypen bleiben.');
    projekt_pruefen((int) $t['projekt_id'], true);
    if (qwert('SELECT 1 FROM notizen WHERE typ_id = ? AND geloescht = 0 LIMIT 1', [$id])) fehler(400, 'Der Notiztyp wird noch von Notizen benützt.');
    q('UPDATE notizen SET typ_id = (SELECT id FROM notiztypen WHERE eingebaut = \'basis\') WHERE typ_id = ?', [$id]);
    q('DELETE FROM notiztypen WHERE id = ?', [$id]);
    return ['ok' => true];
}

/* ---------- Notizen ---------- */

function notiz_details(int $id): array
{
    $n = q1('SELECT * FROM notizen WHERE id = ?', [$id]);
    if (!$n) fehler(404, 'Notiz nicht gefunden.');
    $p = projekt_pruefen((int) $n['projekt_id']);
    [$notizen, $typen, $kat] = notizen_fuer_client([$id]);
    $karten = qalle('SELECT k.id, k.ord, k.schluessel, z.st, z.faellig, z.s, z.wdh, z.fehler, z.pausiert, z.markiert FROM karten k LEFT JOIN zustand z ON z.karte_id = k.id AND z.benutzer = ? WHERE k.notiz_id = ? AND k.geloescht = 0 ORDER BY k.ord', [benutzer(), $id]);
    return ['ok' => true, 'notiz' => $notizen[$id], 'typ' => $typen[(int) $n['typ_id']] ?? null, 'kategorien' => $kat, 'karten' => $karten, 'darf_bearbeiten' => $p['quelle'] === null];
}

function felder_saeubern(array $typ, $felder): array
{
    $aus = [];
    foreach ($typ['felder'] as $f) {
        $w = (string) (((array) $felder)[$f['name']] ?? '');
        $max = $f['art'] === 'masken' ? 20000 : 20000;
        $w = text_kuerzen($w, $max);
        if ($f['art'] === 'bild' && $w !== '') $w = bildverweis_pruefen($w);
        if ($f['art'] === 'audio' && $w !== '' && !preg_match('/^(medien:[a-f0-9]{16,64}\.[a-z0-9]{2,5}|quelle:[a-z]{2,3}\/[A-Za-z0-9\/_.#-]+)$/', $w)) fehler(400, 'Ungültiger Audioverweis.');
        if ($f['art'] === 'masken' && $w !== '') {
            $m = json_decode($w, true);
            if (!is_array($m)) fehler(400, 'Masken sind ungültig.');
            $w = json_kodiere(array_values(array_map(fn($x) => [
                'x' => round((float) ($x['x'] ?? 0), 2), 'y' => round((float) ($x['y'] ?? 0), 2), 'w' => round((float) ($x['w'] ?? 10), 2), 'h' => round((float) ($x['h'] ?? 10), 2),
                'text' => text_kuerzen((string) ($x['text'] ?? ''), 120), 'gruppe' => isset($x['gruppe']) && $x['gruppe'] !== '' ? (int) $x['gruppe'] : '',
            ], array_slice($m, 0, 60))));
        }
        $aus[$f['name']] = $w;
    }
    return $aus;
}

function notiz_speichern(array $e): array
{
    $id = (int) ($e['id'] ?? 0);
    $deckId = (int) ($e['deck'] ?? 0);
    $deck = deck_pruefen($deckId, true);
    $pid = (int) $deck['projekt_id'];
    $typId = (int) ($e['typ'] ?? 0);
    $typ = notiztyp_laden($typId);
    if (!$typ || ($typ['projekt_id'] !== null && (int) $typ['projekt_id'] !== $pid)) fehler(400, 'Ungültiger Notiztyp.');
    $felder = felder_saeubern($typ, $e['felder'] ?? []);
    $erstes = '';
    foreach ($felder as $w) if (trim($w) !== '') { $erstes = $w; break; }
    if ($erstes === '') fehler(400, 'Die Notiz ist leer.');
    if (!karten_ords($typ, $felder)) {
        fehler(400, $typ['art'] === 'luecke' ? 'Markiere mindestens eine Lücke, z. B. {{c1::Antwort}}.' : ($typ['art'] === 'verdeckung' ? 'Zeichne mindestens eine Maske auf das Bild.' : 'Aus diesen Feldern entsteht keine Karte.'));
    }
    $kat = !empty($e['kategorie']) ? (int) $e['kategorie'] : null;
    if ($kat !== null && (int) qwert('SELECT projekt_id FROM kategorien WHERE id = ?', [$kat]) !== $pid) $kat = null;
    $tags = tags_normalisieren($e['tags'] ?? '');
    $t = jetzt_ms();
    db()->exec('BEGIN');
    if ($id) {
        $alt = q1('SELECT * FROM notizen WHERE id = ?', [$id]);
        if (!$alt || (int) $alt['projekt_id'] !== $pid) { db()->exec('ROLLBACK'); fehler(404, 'Notiz nicht gefunden.'); }
        q('UPDATE notizen SET deck_id = ?, typ_id = ?, felder = ?, kategorie_id = ?, tags = ?, geaendert = ? WHERE id = ?', [$deckId, $typId, json_kodiere((object) $felder), $kat, $tags, $t, $id]);
    } else {
        $sort = (int) qwert('SELECT COALESCE(MAX(sortierung), 0) + 1 FROM notizen WHERE projekt_id = ?', [$pid]);
        q('INSERT INTO notizen (projekt_id, deck_id, typ_id, felder, kategorie_id, tags, sortierung, angelegt, geaendert) VALUES (?,?,?,?,?,?,?,?,?)', [$pid, $deckId, $typId, json_kodiere((object) $felder), $kat, $tags, $sort, $t, $t]);
        $id = (int) db()->lastInsertId();
    }
    $neu = karten_abgleichen($id, $pid, $typ, $felder);
    db()->exec('COMMIT');
    return ['ok' => true, 'id' => $id, 'neue_karten' => $neu, 'karten' => count(karten_ords($typ, $felder))];
}

function notiz_loeschen(int $id): array
{
    $n = q1('SELECT projekt_id FROM notizen WHERE id = ?', [$id]);
    if (!$n) fehler(404, 'Notiz nicht gefunden.');
    projekt_pruefen((int) $n['projekt_id'], true);
    q('UPDATE notizen SET geloescht = 1, geaendert = ? WHERE id = ?', [jetzt_ms(), $id]);
    return ['ok' => true];
}

/** Notizen in ein anderes Deck verschieben (nur eigene Projekte) */
function notizen_verschieben(array $e): array
{
    $deck = deck_pruefen((int) ($e['deck'] ?? 0), true);
    $n = 0;
    foreach ((array) ($e['notizen'] ?? []) as $nid) {
        $n += q('UPDATE notizen SET deck_id = ?, geaendert = ? WHERE id = ? AND projekt_id = ?', [$deck['id'], jetzt_ms(), (int) $nid, $deck['projekt_id']])->rowCount();
    }
    return ['ok' => true, 'anzahl' => $n];
}

/** Zusätze: Eselsbrücke, eigenes Bild, eigene Tags (auch bei importierten Notizen) */
function zusatz_speichern(array $e): array
{
    $nid = (int) ($e['notiz'] ?? 0);
    $pid = qwert('SELECT projekt_id FROM notizen WHERE id = ?', [$nid]);
    if (!$pid) fehler(404, 'Notiz nicht gefunden.');
    projekt_pruefen((int) $pid);
    $notiz = text_kuerzen((string) ($e['notiz_text'] ?? ''), 8000);
    $bild = bildverweis_pruefen((string) ($e['bild'] ?? ''));
    $tags = tags_normalisieren($e['tags'] ?? '');
    if ($notiz === '' && $bild === '' && $tags === '') {
        q('DELETE FROM zusaetze WHERE benutzer = ? AND notiz_id = ?', [benutzer(), $nid]);
    } else {
        q('INSERT INTO zusaetze (benutzer, notiz_id, notiz, bild, tags, geaendert) VALUES (?,?,?,?,?,?)
           ON CONFLICT(benutzer, notiz_id) DO UPDATE SET notiz = excluded.notiz, bild = excluded.bild, tags = excluded.tags, geaendert = excluded.geaendert',
            [benutzer(), $nid, $notiz, $bild, $tags, jetzt_ms()]);
    }
    return ['ok' => true];
}

/** Kartenliste (Browser) mit Suche und Filtern */
function kartenliste(array $g): array
{
    $pid = (int) ($g['projekt'] ?? 0);
    projekt_pruefen($pid);
    $b = benutzer();
    $wo = ['k.projekt_id = :p', 'k.geloescht = 0', 'n.geloescht = 0', sql_notiz_frei()];
    $par = [':p' => $pid, ':b' => $b];
    if (!empty($g['deck'])) {
        $ids = implode(',', array_map('intval', deck_teilbaum((int) $g['deck'])));
        $wo[] = "n.deck_id IN ($ids)";
    }
    if (!empty($g['kategorie'])) { $wo[] = 'n.kategorie_id = :kat'; $par[':kat'] = (int) $g['kategorie']; }
    if (!empty($g['tag'])) {
        $wo[] = "(' ' || n.tags || ' ' || COALESCE(zs.tags, '') || ' ') LIKE :tag ESCAPE '\\'";
        $par[':tag'] = '% ' . str_replace(['%', '_'], ['\%', '\_'], (string) $g['tag']) . ' %';
    }
    if (!empty($g['suche'])) {
        $wo[] = "(n.felder LIKE :s ESCAPE '\\' OR COALESCE(zs.notiz, '') LIKE :s ESCAPE '\\')";
        $par[':s'] = '%' . str_replace(['%', '_'], ['\%', '\_'], (string) $g['suche']) . '%';
    }
    $ende = tagesbeginn() + TAG_MS;
    switch ($g['zustand'] ?? '') {
        case 'neu': $wo[] = 'z.st IS NULL'; break;
        case 'lernen': $wo[] = "z.st IN ('learn','relearn')"; break;
        case 'faellig': $wo[] = "z.st IS NOT NULL AND z.faellig < $ende AND z.pausiert = 0"; break;
        case 'gelernt': $wo[] = 'z.st IS NOT NULL'; break;
        case 'pausiert': $wo[] = 'z.pausiert = 1'; break;
        case 'markiert': $wo[] = 'z.markiert = 1'; break;
        case 'schwer': $wo[] = 'z.fehler >= 3'; break;
    }
    $sortierung = match ($g['sortierung'] ?? '') {
        'faellig' => 'z.faellig IS NULL, z.faellig',
        'neu' => 'n.angelegt DESC',
        'fehler' => 'z.fehler DESC',
        default => 'n.sortierung, n.id, k.ord',
    };
    $sql = 'FROM karten k JOIN notizen n ON n.id = k.notiz_id LEFT JOIN zustand z ON z.karte_id = k.id AND z.benutzer = :b
            LEFT JOIN zusaetze zs ON zs.notiz_id = n.id AND zs.benutzer = :b WHERE ' . implode(' AND ', $wo);
    $gesamt = (int) qwert("SELECT COUNT(*) $sql", $par);
    $seite = max(0, (int) ($g['seite'] ?? 0));
    $proSeite = 60;
    $zeilen = qalle("SELECT k.id, k.notiz_id, k.ord, z.st, z.faellig, z.s, z.wdh, z.fehler, COALESCE(z.pausiert,0) AS pausiert, COALESCE(z.markiert,0) AS markiert $sql ORDER BY $sortierung LIMIT $proSeite OFFSET " . ($seite * $proSeite), $par);
    $nids = array_values(array_unique(array_map(fn($r) => (int) $r['notiz_id'], $zeilen)));
    [$notizen, $typen, $kat] = notizen_fuer_client($nids);
    return ['ok' => true, 'gesamt' => $gesamt, 'seite' => $seite, 'pro_seite' => $proSeite, 'karten' => array_map(fn($r) => [
        'id' => (int) $r['id'], 'notiz' => (int) $r['notiz_id'], 'ord' => (int) $r['ord'], 'st' => $r['st'], 'faellig' => $r['faellig'] === null ? null : (int) $r['faellig'],
        's' => $r['s'] === null ? null : (float) $r['s'], 'wdh' => (int) $r['wdh'], 'fehler' => (int) $r['fehler'], 'pausiert' => (int) $r['pausiert'], 'markiert' => (int) $r['markiert'],
    ], $zeilen), 'notizen' => (object) $notizen, 'typen' => (object) $typen, 'kategorien' => (object) $kat];
}

/* ---------- Freigaben (Admin) ---------- */

function freigaben_setzen(array $e): array
{
    admin_pflicht();
    $pid = (int) ($e['projekt'] ?? 0);
    $p = q1('SELECT quelle FROM projekte WHERE id = ?', [$pid]);
    if (!$p || $p['quelle'] === null) fehler(400, 'Freigaben gibt es nur für eingebaute Projekte.');
    $liste = [];
    foreach ((array) ($e['benutzer'] ?? []) as $n) {
        $n = trim((string) $n);
        if ($n === '*' || benutzer_name_gueltig($n)) $liste[] = $n;
    }
    db()->exec('BEGIN');
    q('DELETE FROM freigaben WHERE projekt_id = ?', [$pid]);
    foreach (array_unique($liste) as $n) q('INSERT INTO freigaben (projekt_id, benutzer) VALUES (?, ?)', [$pid, $n]);
    db()->exec('COMMIT');
    return ['ok' => true];
}

function benutzerliste(): array
{
    admin_pflicht();
    return ['ok' => true, 'benutzer' => q('SELECT name FROM benutzer ORDER BY name')->fetchAll(PDO::FETCH_COLUMN)];
}
