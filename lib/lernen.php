<?php
// Lernpaket für die Lernansicht (auch offline nutzbar) und Speichern der Bewertungen.
// Die FSRS-Rechnung läuft im Browser (public/app/fsrs.js), der Server speichert Ergebnis und Protokoll.
declare(strict_types=1);

/** Notizen samt Zusätzen und Typen für eine Liste von Notiz-IDs */
function notizen_fuer_client(array $notizIds): array
{
    $notizen = [];
    $typen = [];
    $kategorien = [];
    foreach (array_chunk($notizIds, 500) as $teil) {
        $platz = implode(',', array_fill(0, count($teil), '?'));
        foreach (qalle("SELECT n.id, n.typ_id, n.felder, n.tags, n.kategorie_id, n.deck_id, n.schluessel, n.projekt_id, z.notiz AS z_notiz, z.bild AS z_bild, z.tags AS z_tags
                        FROM notizen n LEFT JOIN zusaetze z ON z.notiz_id = n.id AND z.benutzer = ? WHERE n.id IN ($platz)", array_merge([benutzer()], $teil)) as $n) {
            $notizen[$n['id']] = [
                'id' => (int) $n['id'], 'typ' => (int) $n['typ_id'], 'felder' => json_lies($n['felder'], new stdClass()), 'tags' => $n['tags'],
                'kategorie' => $n['kategorie_id'] === null ? null : (int) $n['kategorie_id'], 'deck' => (int) $n['deck_id'],
                'schluessel' => $n['schluessel'], 'projekt' => (int) $n['projekt_id'],
                'zusatz' => ['notiz' => (string) $n['z_notiz'], 'bild' => (string) $n['z_bild'], 'tags' => (string) $n['z_tags']],
            ];
            $typen[(int) $n['typ_id']] = true;
            if ($n['kategorie_id'] !== null) $kategorien[(int) $n['kategorie_id']] = true;
        }
    }
    $typAus = [];
    foreach (array_keys($typen) as $t) {
        $typ = notiztyp_laden($t);
        if ($typ) $typAus[$t] = ['id' => (int) $typ['id'], 'name' => $typ['name'], 'art' => $typ['art'], 'felder' => $typ['felder'], 'vorlagen' => $typ['vorlagen'], 'stil' => $typ['stil'], 'eingebaut' => $typ['eingebaut']];
    }
    $katAus = [];
    if ($kategorien) {
        $ids = array_keys($kategorien);
        foreach (qalle('SELECT id, name, farbe, symbol FROM kategorien WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids) as $k) $katAus[$k['id']] = $k;
    }
    return [$notizen, $typAus, $katAus];
}

function zustand_fuer_client(?array $z): ?array
{
    if (!$z) return null;
    return [
        'st' => $z['st'], 's' => $z['s'] === null ? null : (float) $z['s'], 'd' => $z['d'] === null ? null : (float) $z['d'],
        'due' => $z['faellig'] === null ? null : (int) $z['faellig'], 'last' => $z['zuletzt'] === null ? null : (int) $z['zuletzt'],
        'first' => $z['erstmals'] === null ? null : (int) $z['erstmals'], 'reps' => (int) $z['wdh'], 'lapses' => (int) $z['fehler'],
        'pausiert' => (int) $z['pausiert'], 'markiert' => (int) $z['markiert'], 'zurueck' => (int) $z['zurueck_bis'], 't' => (int) $z['t'],
    ];
}

/** Lernpaket für ein Deck (samt Unterdecks) oder ein ganzes Projekt */
function lernpaket(?int $deckId, ?int $projektId): array
{
    if ($deckId) {
        $deck = deck_pruefen($deckId);
        $projekt = projekt_pruefen((int) $deck['projekt_id']);
        $decks = deck_teilbaum($deckId);
    } else {
        $projekt = projekt_pruefen((int) $projektId);
        $decks = array_map('intval', q('SELECT id FROM decks WHERE projekt_id = ?', [$projekt['id']])->fetchAll(PDO::FETCH_COLUMN));
        $deck = null;
    }
    $b = benutzer();
    $karten = [];
    $zustand = [];
    $notizIds = [];
    foreach (array_chunk($decks, 400) as $teil) {
        // Positionsparameter und benannte lassen sich in PDO nicht mischen: Deck-IDs direkt einsetzen (sind int)
        $liste = implode(',', array_map('intval', $teil));
        $st = db()->prepare("SELECT k.id, k.notiz_id, k.ord, k.schluessel, n.deck_id, n.sortierung, z.* FROM karten k
                JOIN notizen n ON n.id = k.notiz_id
                LEFT JOIN zustand z ON z.karte_id = k.id AND z.benutzer = :b
                WHERE n.deck_id IN ($liste) AND k.geloescht = 0 AND n.geloescht = 0 AND " . sql_notiz_frei());
        $st->execute([':b' => $b]);
        foreach ($st->fetchAll() as $r) {
            $kid = (int) $r['id'];
            $karten[] = ['id' => $kid, 'notiz' => (int) $r['notiz_id'], 'ord' => (int) $r['ord'], 'deck' => (int) $r['deck_id'], 'sort' => (int) $r['sortierung'], 'schluessel' => $r['schluessel']];
            if ($r['benutzer'] !== null) $zustand[$kid] = zustand_fuer_client($r);
            $notizIds[(int) $r['notiz_id']] = true;
        }
    }
    usort($karten, fn($a, $c) => [$a['sort'], $a['notiz'], $a['ord']] <=> [$c['sort'], $c['notiz'], $c['ord']]);
    [$notizen, $typen, $kategorien] = notizen_fuer_client(array_keys($notizIds));
    [$baum, $summe] = deck_baum($projekt);
    $einst = projekt_einstellungen($projekt);
    $alle = benutzer_einstellungen();
    return [
        'ok' => true,
        'projekt' => ['id' => (int) $projekt['id'], 'name' => $projekt['name'], 'farbe' => $projekt['farbe'], 'sprache' => $projekt['sprache'], 'quelle' => $projekt['quelle']],
        'deck' => $deck ? ['id' => (int) $deck['id'], 'name' => $deck['name']] : null,
        'decknamen' => array_column(qalle('SELECT id, name FROM decks WHERE projekt_id = ?', [$projekt['id']]), 'name', 'id'),
        'karten' => $karten, 'zustand' => (object) $zustand, 'notizen' => (object) $notizen, 'typen' => (object) $typen, 'kategorien' => (object) $kategorien,
        'heute' => ['neu' => $summe['heute_neu'], 'wdh' => $summe['heute_wdh']],
        'grenzen' => ['neu' => (int) $einst['neu_pro_tag'], 'wdh' => (int) $einst['max_wdh_pro_tag'], 'mischen' => $einst['neu_mischen']],
        'ziel' => (float) $alle['ziel'],
        'tagesbeginn' => tagesbeginn(), 'jetzt' => jetzt_ms(),
    ];
}

/** Bewertungen speichern. Jede hat eine cid (vom Gerät); doppelt gesendete werden übersprungen. */
function wiederholungen_speichern(array $liste): array
{
    $b = benutzer();
    $gespeichert = 0;
    db()->exec('BEGIN IMMEDIATE');
    try {
        foreach ($liste as $w) {
            $cid = text_kuerzen((string) ($w['cid'] ?? ''), 80);
            $kid = (int) ($w['karte'] ?? 0);
            $nach = $w['nach'] ?? null;
            $note = (int) ($w['note'] ?? 0);
            if ($cid === '' || $kid <= 0 || !is_array($nach) || $note < 1 || $note > 4) continue;
            if (qwert('SELECT 1 FROM wiederholungen WHERE cid = ?', [$cid])) continue;
            // Zugriff prüfen: Karte muss in einem sichtbaren Projekt liegen
            $pid = qwert('SELECT projekt_id FROM karten WHERE id = ?', [$kid]);
            if (!$pid || !q1('SELECT 1 FROM projekte p WHERE p.id = :id AND ' . sql_projekt_sichtbar(), [':id' => $pid, ':b' => $b])) continue;
            $zeit = (int) ($w['zeit'] ?? jetzt_ms());
            $alt = q1('SELECT * FROM zustand WHERE benutzer = ? AND karte_id = ?', [$b, $kid]);
            q('INSERT INTO wiederholungen (benutzer, karte_id, zeit, note, st_vorher, intervall, s, d, dauer, cid) VALUES (?,?,?,?,?,?,?,?,?,?)', [
                $b, $kid, $zeit, $note, $alt['st'] ?? null, isset($nach['ivl']) ? (float) $nach['ivl'] : null,
                (float) ($nach['s'] ?? 0), (float) ($nach['d'] ?? 0), min(600000, max(0, (int) ($w['dauer'] ?? 0))), $cid,
            ]);
            // Neuerer Stand gewinnt (Offline-Geräte können in falscher Reihenfolge senden)
            if ($alt && (int) $alt['t'] > $zeit) { $gespeichert++; continue; }
            $st = in_array($nach['st'] ?? '', ['learn', 'relearn', 'review'], true) ? $nach['st'] : 'review';
            q('INSERT INTO zustand (benutzer, karte_id, st, s, d, faellig, zuletzt, erstmals, wdh, fehler, t) VALUES (?,?,?,?,?,?,?,?,?,?,?)
               ON CONFLICT(benutzer, karte_id) DO UPDATE SET st = excluded.st, s = excluded.s, d = excluded.d, faellig = excluded.faellig,
               zuletzt = excluded.zuletzt, erstmals = COALESCE(zustand.erstmals, excluded.erstmals), wdh = excluded.wdh, fehler = excluded.fehler, t = excluded.t, zurueck_bis = 0', [
                $b, $kid, $st, (float) $nach['s'], (float) $nach['d'], (int) $nach['due'], $zeit, (int) ($alt['erstmals'] ?? $zeit),
                (int) ($nach['reps'] ?? 1), (int) ($nach['lapses'] ?? 0), $zeit,
            ]);
            $gespeichert++;
        }
        db()->exec('COMMIT');
    } catch (Throwable $e) {
        db()->exec('ROLLBACK');
        throw $e;
    }
    return ['ok' => true, 'gespeichert' => $gespeichert];
}

/** Pausieren, markieren, zurückstellen, zurücksetzen – für eine Karte oder alle Karten einer Notiz */
function karte_aktion(array $e): array
{
    $b = benutzer();
    $ids = [];
    if (!empty($e['notiz'])) $ids = array_map('intval', q('SELECT id FROM karten WHERE notiz_id = ?', [(int) $e['notiz']])->fetchAll(PDO::FETCH_COLUMN));
    elseif (!empty($e['karten'])) $ids = array_map('intval', (array) $e['karten']);
    elseif (!empty($e['karte'])) $ids = [(int) $e['karte']];
    $aktion = (string) ($e['aktion'] ?? '');
    $n = 0;
    foreach ($ids as $kid) {
        $pid = qwert('SELECT projekt_id FROM karten WHERE id = ?', [$kid]);
        if (!$pid) continue;
        projekt_pruefen((int) $pid);
        q('INSERT OR IGNORE INTO zustand (benutzer, karte_id, t) VALUES (?, ?, ?)', [$b, $kid, 0]);
        switch ($aktion) {
            case 'pausieren': q('UPDATE zustand SET pausiert = 1 WHERE benutzer = ? AND karte_id = ?', [$b, $kid]); break;
            case 'fortsetzen': q('UPDATE zustand SET pausiert = 0 WHERE benutzer = ? AND karte_id = ?', [$b, $kid]); break;
            case 'markieren': q('UPDATE zustand SET markiert = 1 - markiert WHERE benutzer = ? AND karte_id = ?', [$b, $kid]); break;
            case 'zurueckstellen': q('UPDATE zustand SET zurueck_bis = ? WHERE benutzer = ? AND karte_id = ?', [tagesbeginn() + TAG_MS, $b, $kid]); break;
            case 'zuruecksetzen':
                q('UPDATE zustand SET st = NULL, s = NULL, d = NULL, faellig = NULL, zuletzt = NULL, wdh = 0, fehler = 0, t = ? WHERE benutzer = ? AND karte_id = ?', [jetzt_ms(), $b, $kid]);
                break;
            default: fehler(400, 'Unbekannte Aktion.');
        }
        $n++;
    }
    return ['ok' => true, 'anzahl' => $n];
}

/** Letzte Bewertung zurücknehmen: Protokollzeile löschen, vorherigen Zustand wiederherstellen (vom Gerät mitgeschickt) */
function wiederholung_zurueck(array $e): array
{
    $b = benutzer();
    $cid = (string) ($e['cid'] ?? '');
    $w = q1('SELECT karte_id FROM wiederholungen WHERE cid = ? AND benutzer = ?', [$cid, $b]);
    if (!$w) return ['ok' => true, 'gefunden' => false];
    $kid = (int) $w['karte_id'];
    q('DELETE FROM wiederholungen WHERE cid = ? AND benutzer = ?', [$cid, $b]);
    $v = $e['vorher'] ?? null;
    if (is_array($v) && !empty($v['st'])) {
        q('UPDATE zustand SET st = ?, s = ?, d = ?, faellig = ?, zuletzt = ?, wdh = ?, fehler = ?, t = ? WHERE benutzer = ? AND karte_id = ?', [
            in_array($v['st'], ['learn', 'relearn', 'review'], true) ? $v['st'] : 'review', (float) ($v['s'] ?? 1), (float) ($v['d'] ?? 5),
            (int) ($v['due'] ?? jetzt_ms()), isset($v['last']) ? (int) $v['last'] : null, (int) ($v['reps'] ?? 0), (int) ($v['lapses'] ?? 0), jetzt_ms(), $b, $kid]);
    } else {
        q('UPDATE zustand SET st = NULL, s = NULL, d = NULL, faellig = NULL, zuletzt = NULL, wdh = 0, fehler = 0, t = ? WHERE benutzer = ? AND karte_id = ?', [jetzt_ms(), $b, $kid]);
    }
    return ['ok' => true, 'gefunden' => true];
}
