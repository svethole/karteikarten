<?php
// Anki-Austausch (.apkg, Sammlung im Format „collection.anki2“ = Schema 11, das Anki beim Export mit
// „Unterstützung älterer Anki-Versionen“ schreibt und jede Anki-Version lesen kann).
//
// Import: Notiztypen (Felder + Vorlagen → Bausteine), Decks (A::B → Unterdecks), Notizen, Bilder/Töne, Lernstand (SM-2 → FSRS grob).
// Export: Notiztypen als Anki-Modelle (Bausteine → HTML-Vorlagen), Markdown-Felder als HTML, Medien, optional Lernstand.
declare(strict_types=1);

/* ---------- Import ---------- */

function apkg_import(): array
{
    $pid = (int) ($_POST['projekt'] ?? 0);
    projekt_pruefen($pid, true);
    $f = $_FILES['datei'] ?? null;
    if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK) fehler(400, 'Keine Datei angekommen.');
    @set_time_limit(600);
    $zip = new ZipArchive();
    if ($zip->open($f['tmp_name']) !== true) fehler(400, 'Das ist keine gültige .apkg-Datei.');
    // Neuere Anki-Versionen legen die echte Sammlung als .anki21b (zstd) ab und nur einen Platzhalter als .anki2
    $sammlung = $zip->getFromName('collection.anki21');
    if ($sammlung === false) $sammlung = $zip->getFromName('collection.anki2');
    if ($sammlung === false || ($zip->locateName('collection.anki21b') !== false && strlen($sammlung) < 200000 && !str_contains($sammlung, 'CREATE TABLE notes'))) $sammlung = null;
    if ($sammlung === null) fehler(415, 'Diese Datei stammt aus einer neueren Anki-Version. Bitte in Anki beim Exportieren „Unterstützung älterer Anki-Versionen“ anhaken.');
    $tmp = tempnam(sys_get_temp_dir(), 'kk-anki');
    file_put_contents($tmp, $sammlung);
    try {
        $ergebnis = apkg_einlesen($pid, new PDO('sqlite:' . $tmp, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]), $zip);
    } finally {
        @unlink($tmp);
        $zip->close();
    }
    return ['ok' => true] + $ergebnis;
}

/** Anki-HTML eines Felds → Markdown (fett, kursiv, Zeilen, Listen); Bilder und Töne werden herausgelöst */
function anki_html_zu_md(string $html, array &$bilder, array &$toene): string
{
    $html = preg_replace_callback('/\[sound:([^\]]+)\]/', function ($m) use (&$toene) { $toene[] = $m[1]; return ''; }, $html);
    $html = preg_replace_callback('/<img[^>]+src=["\']?([^"\' >]+)["\']?[^>]*>/i', function ($m) use (&$bilder) { $bilder[] = html_entity_decode($m[1]); return ''; }, $html);
    $html = preg_replace('#<(b|strong)>(.*?)</\1>#is', '**$2**', $html);
    $html = preg_replace('#<(i|em)>(.*?)</\1>#is', '*$2*', $html);
    $html = preg_replace('#<li[^>]*>#i', "\n- ", $html);
    $html = preg_replace('#</li>#i', '', $html);
    $html = preg_replace('#<br\s*/?>|</div>|</p>|</ul>|</ol>|</tr>#i', "\n", $html);
    $html = preg_replace('#</t[dh]>#i', ' | ', $html);
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace("/[ \t\x{00A0}]+/u", ' ', $text);
    $text = preg_replace("/\n\s*\n\s*\n+/", "\n\n", $text);
    return trim($text);
}

/** Anki-Vorlage (qfmt/afmt) → Bausteine */
function anki_vorlage_zu_bausteinen(string $vorlage, bool $hinten, array $felder, bool $erstes = true): array
{
    $bloecke = [];
    preg_match_all('/\{\{([^}]+)\}\}|<hr id=["\']?answer["\']?>|<hr\s*\/?>/i', $vorlage, $m, PREG_SET_ORDER);
    foreach ($m as $x) {
        if (!isset($x[1]) || $x[1] === '') { if ($bloecke && end($bloecke)['t'] !== 'trenner') $bloecke[] = ['t' => 'trenner']; continue; }
        $tag = trim($x[1]);
        if ($tag === 'FrontSide') { $bloecke[] = ['t' => 'vorderseite']; continue; }
        if ($tag[0] === '#' || $tag[0] === '/' || $tag[0] === '^' || $tag === 'Tags' || $tag === 'Deck' || $tag === 'Card' || $tag === 'Subdeck') continue;
        if (str_starts_with($tag, 'cloze:')) { $bloecke[] = ['t' => 'luecke', 'feld' => trim(substr($tag, 6))]; continue; }
        if (str_starts_with($tag, 'type:')) { $f = trim(preg_replace('/^cloze:/', '', substr($tag, 5))); if (in_array($f, $felder, true)) $bloecke[] = ['t' => 'eingabe', 'feld' => $f]; continue; }
        $f = trim(preg_replace('/^(text|furigana|kana|kanji|hint|tts[^:]*):/', '', $tag));
        if (!in_array($f, $felder, true)) continue;
        $bloecke[] = ['t' => 'feld', 'feld' => $f, 'stil' => !$hinten && !$bloecke && $erstes ? 'gross' : 'normal', 'wenn' => $f];
    }
    return $bloecke;
}

function apkg_einlesen(int $pid, PDO $a, ZipArchive $zip): array
{
    $col = $a->query('SELECT crt, models, decks FROM col')->fetch();
    $models = json_decode((string) $col['models'], true) ?: [];
    $ankiDecks = json_decode((string) $col['decks'], true) ?: [];
    $crt = (int) $col['crt'];
    $medienMap = json_decode((string) $zip->getFromName('media'), true) ?: [];
    $nachName = array_flip(array_map('strval', $medienMap));
    $medienCache = [];
    $medium = function (string $name) use ($zip, $nachName, &$medienCache): string {
        if (isset($medienCache[$name])) return $medienCache[$name];
        $idx = $nachName[$name] ?? null;
        $inhalt = $idx !== null ? $zip->getFromName((string) $idx) : false;
        if ($inhalt === false) return $medienCache[$name] = '';
        try { $r = medien_ablegen($inhalt, $name, 'aus Anki importiert'); return $medienCache[$name] = $r['verweis']; } catch (Antwort $e) { return $medienCache[$name] = ''; }
    };

    db()->exec('BEGIN');
    $t = jetzt_ms();
    // Notiztypen anlegen
    $typen = [];
    foreach ($models as $mid => $m) {
        $feldNamen = array_map(fn($f) => str_replace(['{', '}'], '', (string) $f['name']), $m['flds'] ?? []);
        $istLuecke = (int) ($m['type'] ?? 0) === 1;
        $felder = array_map(fn($n) => ['name' => $n, 'art' => 'md'], $feldNamen);
        $felder[] = ['name' => 'Bild', 'art' => 'bild'];
        $felder[] = ['name' => 'Audio', 'art' => 'audio'];
        $vorlagen = [];
        foreach ($m['tmpls'] ?? [] as $tm) {
            $vorne = anki_vorlage_zu_bausteinen((string) $tm['qfmt'], false, $feldNamen);
            $hinten = anki_vorlage_zu_bausteinen((string) $tm['afmt'], true, $feldNamen);
            $vorne[] = ['t' => 'bild', 'feld' => 'Bild', 'wenn' => 'Bild'];
            $vorne[] = ['t' => 'audio', 'feld' => 'Audio', 'wenn' => 'Audio'];
            if (!$hinten) $hinten = [['t' => 'vorderseite'], ['t' => 'trenner']];
            $vorlagen[] = ['name' => (string) ($tm['name'] ?? 'Karte'), 'bedingung' => '', 'vorne' => $vorne, 'hinten' => $hinten];
        }
        if (!$vorlagen) continue;
        q('INSERT INTO notiztypen (projekt_id, name, art, felder, vorlagen, stil, geaendert) VALUES (?,?,?,?,?,?,?)',
            [$pid, text_kuerzen('Anki: ' . ($m['name'] ?? 'Typ'), 80), $istLuecke ? 'luecke' : 'normal', json_kodiere($felder), json_kodiere($istLuecke ? [$vorlagen[0]] : $vorlagen), '{}', $t]);
        $typen[(string) $mid] = ['id' => (int) db()->lastInsertId(), 'felder' => $feldNamen, 'luecke' => $istLuecke];
    }
    // Decks (A::B::C → Baum)
    $deckIds = [];
    $pfadId = function (string $name) use ($pid, &$deckIds) {
        $eltern = null;
        $pfad = '';
        foreach (explode('::', $name) as $teil) {
            $pfad .= ($pfad === '' ? '' : '::') . $teil;
            if (!isset($deckIds[$pfad])) {
                $id = qwert('SELECT id FROM decks WHERE projekt_id = ? AND name = ? AND ' . ($eltern === null ? 'eltern_id IS NULL' : 'eltern_id = ' . (int) $eltern), [$pid, $teil]);
                if (!$id) {
                    q('INSERT INTO decks (projekt_id, eltern_id, name, sortierung) VALUES (?,?,?, (SELECT COALESCE(MAX(sortierung),0)+1 FROM decks WHERE projekt_id = ?))', [$pid, $eltern, text_kuerzen($teil, 120), $pid]);
                    $id = db()->lastInsertId();
                }
                $deckIds[$pfad] = (int) $id;
            }
            $eltern = $deckIds[$pfad];
        }
        return $eltern;
    };
    // Deck je Notiz: Deck der ersten Karte
    $notizDeck = [];
    foreach ($a->query('SELECT nid, did FROM cards ORDER BY ord') as $c) $notizDeck[$c['nid']] ??= (string) $c['did'];
    $sort = (int) qwert('SELECT COALESCE(MAX(sortierung),0) FROM notizen WHERE projekt_id = ?', [$pid]);
    $nAnzahl = 0;
    $notizMap = [];
    foreach ($a->query('SELECT id, mid, tags, flds FROM notes ORDER BY id') as $n) {
        $typ = $typen[(string) $n['mid']] ?? null;
        if (!$typ) continue;
        $werte = explode("\x1f", (string) $n['flds']);
        $felder = [];
        $bilder = [];
        $toene = [];
        foreach ($typ['felder'] as $i => $name) $felder[$name] = anki_html_zu_md((string) ($werte[$i] ?? ''), $bilder, $toene);
        $felder['Bild'] = $bilder ? $medium($bilder[0]) : '';
        $felder['Audio'] = $toene ? $medium($toene[0]) : '';
        $deckName = (string) ($ankiDecks[$notizDeck[$n['id']] ?? '']['name'] ?? 'Anki-Import');
        $deck = $pfadId($deckName === 'Default' ? 'Anki-Import' : $deckName);
        q('INSERT INTO notizen (projekt_id, deck_id, typ_id, felder, tags, sortierung, angelegt, geaendert) VALUES (?,?,?,?,?,?,?,?)',
            [$pid, $deck, $typ['id'], json_kodiere((object) $felder), tags_normalisieren((string) $n['tags']), ++$sort, $t, $t]);
        $nid = (int) db()->lastInsertId();
        karten_abgleichen($nid, $pid, notiztyp_laden($typ['id']), $felder);
        $notizMap[$n['id']] = ['id' => $nid, 'luecke' => $typ['luecke']];
        $nAnzahl++;
    }
    // Lernstand (nur gelernte Karten): Intervall ≈ Stabilität, Leichtigkeit → Schwierigkeit
    $lernstand = 0;
    foreach ($a->query('SELECT nid, ord, type, ivl, factor, reps, lapses, due FROM cards WHERE type = 2') as $c) {
        $n = $notizMap[$c['nid']] ?? null;
        if (!$n) continue;
        $ord = $n['luecke'] ? (int) $c['ord'] + 1 : (int) $c['ord'];
        $kid = qwert('SELECT id FROM karten WHERE notiz_id = ? AND ord = ?', [$n['id'], $ord]);
        if (!$kid) continue;
        $d = max(1, min(10, 11 - ((int) $c['factor'] - 1300) / 170));
        $faellig = ($crt + (int) $c['due'] * 86400) * 1000;
        q('INSERT OR IGNORE INTO zustand (benutzer, karte_id, st, s, d, faellig, zuletzt, erstmals, wdh, fehler, t) VALUES (?,?,?,?,?,?,?,?,?,?,?)', [
            benutzer(), $kid, 'review', max(1, (float) $c['ivl']), round($d, 3), $faellig, $faellig - (int) $c['ivl'] * TAG_MS, $faellig - (int) $c['ivl'] * TAG_MS, (int) $c['reps'], (int) $c['lapses'], 1]);
        $lernstand++;
    }
    db()->exec('COMMIT');
    $karten = (int) qwert('SELECT COUNT(*) FROM karten WHERE projekt_id = ? AND geloescht = 0', [$pid]);
    return ['notizen' => $nAnzahl, 'karten' => $karten, 'medien' => count(array_filter($medienCache)), 'lernstand' => $lernstand, 'notiztypen' => count($typen)];
}

/* ---------- Export ---------- */

/** Markdown → einfaches HTML für Anki (gleiche Regeln wie public/app/md.js, vereinfacht) */
function md_html(string $s): string
{
    $zeilen = explode("\n", str_replace("\r", '', $s));
    $aus = [];
    $liste = null;
    $tabelle = [];
    $inline = function (string $t): string {
        $t = htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
        $t = preg_replace('/\*\*(.+?)\*\*/', '<b>$1</b>', $t);
        $t = preg_replace('/(?<![*\w])\*([^*\s][^*]*?)\*(?!\*)/', '<i>$1</i>', $t);
        $t = preg_replace('/`([^`]+)`/', '<code>$1</code>', $t);
        return preg_replace('/==([^=]+)==/', '<mark>$1</mark>', $t);
    };
    $schliessen = function () use (&$liste, &$aus, &$tabelle, $inline) {
        if ($liste) { $aus[] = "</$liste>"; $liste = null; }
        if ($tabelle) {
            $html = '<table>';
            foreach ($tabelle as $z) if (!preg_match('/^[\s|:-]+$/', $z)) $html .= '<tr>' . implode('', array_map(fn($c) => '<td>' . $inline(trim($c)) . '</td>', explode('|', trim(trim($z), '|')))) . '</tr>';
            $aus[] = $html . '</table>';
            $tabelle = [];
        }
    };
    foreach ($zeilen as $z) {
        if (preg_match('/^\s*\|.*\|\s*$/', $z)) { if ($liste) { $aus[] = "</$liste>"; $liste = null; } $tabelle[] = $z; continue; }
        if (preg_match('/^\s*([-*•]|\d+[.)])\s+(.*)$/', $z, $m)) {
            $art = ctype_digit($m[1][0]) ? 'ol' : 'ul';
            if ($liste !== $art) { $schliessen(); $aus[] = "<$art>"; $liste = $art; }
            $aus[] = '<li>' . $inline($m[2]) . '</li>';
            continue;
        }
        $schliessen();
        $aus[] = trim($z) === '' ? '<br>' : $inline($z) . '<br>';
    }
    $schliessen();
    return preg_replace('/(<br>)+$/', '', implode('', $aus));
}

/** Bausteine → Anki-Vorlage (HTML mit {{Feldern}}) */
function bausteine_zu_anki(array $bloecke, array $artVon): string
{
    $html = '';
    foreach ($bloecke as $b) {
        $f = $b['feld'] ?? '';
        $teil = match ($b['t']) {
            'vorderseite' => '{{FrontSide}}',
            'trenner' => '<hr id=answer>',
            'ueberschrift' => '<h2>' . preg_replace('/\{\{([^}]+)\}\}/', '{{$1}}', (string) ($b['text'] ?? '')) . '</h2>',
            'text', 'kasten' => '<div class="' . e_attr($b['t'] === 'kasten' ? 'kasten ' . ($b['art'] ?? 'info') : 'text') . '">' . (!empty($b['titel']) ? '<b>' . htmlspecialchars((string) $b['titel']) . '</b><br>' : '') . (string) ($b['text'] ?? '') . '</div>',
            'feld', 'sprachtext', 'tabelle', 'liste' => $f !== '' ? '<div class="' . e_attr($b['stil'] ?? 'feld') . '">{{' . $f . '}}</div>' : '',
            'bild', 'audio', 'verdeckung' => $f !== '' ? '{{' . $f . '}}' : '',
            'eingabe' => $f !== '' ? '{{type:' . $f . '}}' : '',
            'luecke' => $f !== '' ? '{{cloze:' . $f . '}}' : '',
            'auswahl' => !empty($b['richtig']) ? '{{' . $b['richtig'] . '}}' : '',
            'tags' => '<div class="tags">{{Tags}}</div>',
            default => '',
        };
        if ($teil === '') continue;
        if (!empty($b['wenn'])) $teil = '{{#' . $b['wenn'] . '}}' . $teil . '{{/' . $b['wenn'] . '}}';
        $html .= $teil . "\n";
    }
    return $html;
}
function e_attr(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function anki_guid(): string
{
    $zeichen = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!#$%&()*+,-./:;<=>?@[]^_`{|}~';
    $g = '';
    for ($i = 0; $i < 10; $i++) $g .= $zeichen[random_int(0, strlen($zeichen) - 1)];
    return $g;
}

function apkg_export(int $projektId, ?int $deckId, bool $mitLernstand): void
{
    $p = projekt_pruefen($projektId);
    @set_time_limit(600);
    $decks = $deckId ? deck_teilbaum($deckId) : array_map('intval', q('SELECT id FROM decks WHERE projekt_id = ?', [$projektId])->fetchAll(PDO::FETCH_COLUMN));
    if (!$decks) fehler(404, 'Keine Decks.');
    $liste = implode(',', $decks);
    $notizen = qalle("SELECT n.* FROM notizen n WHERE n.deck_id IN ($liste) AND n.geloescht = 0 AND " . sql_notiz_frei() . ' ORDER BY n.sortierung', [':b' => benutzer()]);
    if (!$notizen) fehler(404, 'Hier gibt es keine Karten zum Exportieren.');

    $tmp = tempnam(sys_get_temp_dir(), 'kk-exp');
    @unlink($tmp);
    $a = new PDO('sqlite:' . $tmp, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $a->exec(<<<SQL
        CREATE TABLE col (id integer primary key, crt integer not null, mod integer not null, scm integer not null, ver integer not null, dty integer not null, usn integer not null, ls integer not null, conf text not null, models text not null, decks text not null, dconf text not null, tags text not null);
        CREATE TABLE notes (id integer primary key, guid text not null, mid integer not null, mod integer not null, usn integer not null, tags text not null, flds text not null, sfld integer not null, csum integer not null, flags integer not null, data text not null);
        CREATE TABLE cards (id integer primary key, nid integer not null, did integer not null, ord integer not null, mod integer not null, usn integer not null, type integer not null, queue integer not null, due integer not null, ivl integer not null, factor integer not null, reps integer not null, lapses integer not null, left integer not null, odue integer not null, odid integer not null, flags integer not null, data text not null);
        CREATE TABLE revlog (id integer primary key, cid integer not null, usn integer not null, ease integer not null, ivl integer not null, lastIvl integer not null, factor integer not null, time integer not null, type integer not null);
        CREATE TABLE graves (usn integer not null, oid integer not null, type integer not null);
        CREATE INDEX ix_notes_usn on notes (usn); CREATE INDEX ix_cards_usn on cards (usn); CREATE INDEX ix_revlog_usn on revlog (usn);
        CREATE INDEX ix_cards_nid on cards (nid); CREATE INDEX ix_cards_sched on cards (did, queue, due); CREATE INDEX ix_revlog_cid on revlog (cid); CREATE INDEX ix_notes_csum on notes (csum);
    SQL);
    $jetzt = time();
    $crt = intdiv(tagesbeginn(), 1000);
    $basisId = $jetzt * 1000;

    // Decks
    $ankiDecks = ['1' => anki_deck(1, 'Default')];
    $deckZeilen = qalle("SELECT id, eltern_id, name FROM decks WHERE id IN ($liste)");
    $nachId = array_column($deckZeilen, null, 'id');
    $voll = function (int $id) use (&$voll, $nachId, $p) {
        $d = $nachId[$id] ?? null;
        if (!$d) return $p['name'];
        return ($d['eltern_id'] !== null && isset($nachId[$d['eltern_id']]) ? $voll((int) $d['eltern_id']) : $p['name']) . '::' . str_replace('::', ':', $d['name']);
    };
    $ankiDeckId = [];
    foreach ($deckZeilen as $i => $d) {
        $aid = $basisId + 100 + $i;
        $ankiDeckId[(int) $d['id']] = $aid;
        $ankiDecks[(string) $aid] = anki_deck($aid, $voll((int) $d['id']));
    }
    $ankiDecks[(string) ($basisId + 99)] = anki_deck($basisId + 99, $p['name']);

    // Modelle
    $modelle = [];
    $typen = [];
    foreach (array_unique(array_column($notizen, 'typ_id')) as $i => $tid) {
        $typ = notiztyp_laden((int) $tid);
        $mid = $basisId + 1000 + $i;
        $artVon = array_column($typ['felder'], 'art', 'name');
        $tmpls = [];
        foreach ($typ['vorlagen'] as $o => $v) {
            $tmpls[] = ['name' => $v['name'] ?? 'Karte', 'ord' => $o, 'qfmt' => bausteine_zu_anki($v['vorne'] ?? [], $artVon), 'afmt' => bausteine_zu_anki($v['hinten'] ?? [], $artVon),
                'did' => null, 'bqfmt' => '', 'bafmt' => '', 'bfont' => '', 'bsize' => 0];
        }
        $modelle[(string) $mid] = [
            'id' => $mid, 'name' => $typ['name'], 'type' => $typ['art'] === 'luecke' ? 1 : 0, 'mod' => $jetzt, 'usn' => -1, 'sortf' => 0, 'did' => $basisId + 99,
            'tmpls' => $tmpls, 'flds' => array_map(fn($f, $o) => ['name' => $f['name'], 'ord' => $o, 'sticky' => false, 'rtl' => false, 'font' => 'Arial', 'size' => 20, 'media' => []], $typ['felder'], array_keys($typ['felder'])),
            'css' => ".card { font-family: Inter, Arial, sans-serif; font-size: 20px; text-align: center; color: #1d2126; background: #fffdf9; }\n.kasten { text-align: left; background: #e5f1f0; border-left: 4px solid #2c6e70; padding: 8px 12px; border-radius: 8px; margin: 8px 0; }\n.gross { font-size: 1.6em; }\n.leise { color: #646a72; font-size: .9em; }\ntable { margin: auto; border-collapse: collapse; } td { border: 1px solid #ddd; padding: 3px 8px; }\n.cloze { font-weight: bold; color: #2c6e70; }",
            'latexPre' => "\\documentclass[12pt]{article}\n\\special{papersize=3in,5in}\n\\usepackage[utf8]{inputenc}\n\\usepackage{amssymb,amsmath}\n\\pagestyle{empty}\n\\setlength{\\parindent}{0in}\n\\begin{document}\n",
            'latexPost' => '\\end{document}', 'latexsvg' => false, 'req' => array_map(fn($o) => [$o, 'any', [0]], array_keys($tmpls)), 'tags' => [], 'vers' => [],
        ];
        $typen[(int) $tid] = ['mid' => $mid, 'typ' => $typ];
    }

    // Notizen, Karten, Medien
    $medien = [];
    $medienName = function (string $verweis) use (&$medien): string {
        if (isset($medien[$verweis])) return $medien[$verweis]['name'];
        $pfad = null;
        $name = '';
        if (str_starts_with($verweis, 'medien:')) { $id = substr($verweis, 7); $pfad = medien_ordner() . '/' . $id; $name = $id; }
        elseif (str_starts_with($verweis, 'quelle:')) { [$x] = explode('#', substr($verweis, 7)); [$q, $rest] = explode('/', $x, 2) + [1 => '']; $pfad = quelle_pfad($q, $rest); $name = $q . '-' . str_replace('/', '-', $rest); }
        elseif (str_starts_with($verweis, 'bilder/')) { $pfad = APP_WURZEL . '/public/' . $verweis; $name = basename($verweis); }
        if (!$pfad || !is_file($pfad)) return '';
        $medien[$verweis] = ['pfad' => $pfad, 'name' => $name];
        return $name;
    };
    $karteNr = 0;
    $stNote = $a->prepare('INSERT INTO notes VALUES (?,?,?,?,?,?,?,?,?,?,?)');
    $stKarte = $a->prepare('INSERT INTO cards VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $b = benutzer();
    foreach ($notizen as $i => $n) {
        $t = $typen[(int) $n['typ_id']];
        $fw = json_lies($n['felder'], []);
        $werte = [];
        foreach ($t['typ']['felder'] as $f) {
            $w = (string) ($fw[$f['name']] ?? '');
            if ($f['art'] === 'bild') $werte[] = $w !== '' && ($m = $medienName($w)) ? '<img src="' . e_attr($m) . '">' : '';
            elseif ($f['art'] === 'audio') $werte[] = $w !== '' && ($m = $medienName($w)) ? '[sound:' . $m . ']' : '';
            elseif ($f['art'] === 'masken') $werte[] = '';
            else $werte[] = md_html($w);
        }
        $nid = $basisId + 10000 + $i;
        $sfld = trim(strip_tags($werte[0] ?? ''));
        $stNote->execute([$nid, anki_guid(), $t['mid'], $jetzt, -1, ' ' . str_replace(' ', ' ', (string) $n['tags']) . ' ', implode("\x1f", $werte), $sfld, hexdec(substr(sha1($sfld), 0, 8)), 0, '']);
        foreach (qalle('SELECT k.id, k.ord, z.st, z.s, z.d, z.faellig, z.wdh, z.fehler FROM karten k LEFT JOIN zustand z ON z.karte_id = k.id AND z.benutzer = ? WHERE k.notiz_id = ? AND k.geloescht = 0 ORDER BY k.ord', [$b, $n['id']]) as $k) {
            $ord = $t['typ']['art'] === 'luecke' ? (int) $k['ord'] - 1 : ($t['typ']['art'] === 'verdeckung' ? 0 : (int) $k['ord']);
            if ($t['typ']['art'] === 'verdeckung' && $ord !== 0) continue;
            $gelernt = $mitLernstand && $k['st'] === 'review';
            $tage = $gelernt ? max(0, intdiv(intdiv((int) $k['faellig'], 1000) - $crt, 86400)) : $i + 1;
            $stKarte->execute([$basisId + 500000 + (++$karteNr), $nid, $ankiDeckId[(int) $n['deck_id']] ?? 1, $ord, $jetzt, -1,
                $gelernt ? 2 : 0, $gelernt ? 2 : 0, $tage, $gelernt ? max(1, (int) round((float) $k['s'])) : 0,
                $gelernt ? (int) round(2500 + (5 - (float) $k['d']) * 170) : 0, $gelernt ? (int) $k['wdh'] : 0, $gelernt ? (int) $k['fehler'] : 0, 0, 0, 0, 0, '']);
        }
    }
    $conf = ['nextPos' => count($notizen) + 1, 'estTimes' => true, 'activeDecks' => [1], 'sortType' => 'noteFld', 'timeLim' => 0, 'sortBackwards' => false,
        'addToCur' => true, 'curDeck' => 1, 'newBury' => true, 'newSpread' => 0, 'dueCounts' => true, 'curModel' => null, 'collapseTime' => 1200];
    $a->prepare('INSERT INTO col VALUES (1,?,?,?,11,0,0,0,?,?,?,?,?)')->execute([$crt, $jetzt * 1000, $jetzt * 1000, json_kodiere($conf), json_kodiere($modelle), json_kodiere($ankiDecks), json_kodiere(['1' => anki_deckeinstellung()]), '{}']);
    $a = null;

    $zipDatei = tempnam(sys_get_temp_dir(), 'kk-apkg');
    $zip = new ZipArchive();
    $zip->open($zipDatei, ZipArchive::OVERWRITE);
    $zip->addFile($tmp, 'collection.anki2');
    $karte = [];
    $nr = 0;
    foreach ($medien as $m) { $zip->addFile($m['pfad'], (string) $nr); $karte[(string) $nr] = $m['name']; $nr++; }
    $zip->addFromString('media', json_kodiere((object) $karte));
    $zip->close();
    @unlink($tmp);
    $name = preg_replace('/[^A-Za-z0-9_-]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT', $p['name']) ?: 'karteikarten');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . trim($name, '-') . '.apkg"');
    header('Content-Length: ' . filesize($zipDatei));
    header('Cache-Control: no-store');
    readfile($zipDatei);
    @unlink($zipDatei);
    exit;
}

function anki_deck(int $id, string $name): array
{
    return ['id' => $id, 'name' => $name, 'mod' => time(), 'usn' => -1, 'lrnToday' => [0, 0], 'revToday' => [0, 0], 'newToday' => [0, 0], 'timeToday' => [0, 0],
        'collapsed' => false, 'browserCollapsed' => false, 'desc' => '', 'dyn' => 0, 'conf' => 1, 'extendNew' => 0, 'extendRev' => 0];
}

function anki_deckeinstellung(): array
{
    return ['id' => 1, 'name' => 'Default', 'mod' => 0, 'usn' => 0, 'maxTaken' => 60, 'autoplay' => true, 'timer' => 0, 'replayq' => true, 'dyn' => false,
        'new' => ['bury' => true, 'delays' => [1, 10], 'initialFactor' => 2500, 'ints' => [1, 4, 7], 'order' => 1, 'perDay' => 20, 'separate' => true],
        'rev' => ['bury' => true, 'ease4' => 1.3, 'fuzz' => 0.05, 'ivlFct' => 1, 'maxIvl' => 36500, 'minSpace' => 1, 'perDay' => 200, 'hardFactor' => 1.2],
        'lapse' => ['delays' => [10], 'leechAction' => 1, 'leechFails' => 8, 'minInt' => 1, 'mult' => 0]];
}
