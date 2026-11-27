<?php
// Import der Sprachtrainer (Tschechisch, Italienisch, Niederländisch).
//
// Die Karten werden nach denselben Regeln gebildet wie im Karteikasten der Trainer (index.html: FC.courseUniverse,
// rodUniverse, pwUniverse, chatUniverse, ownUniverse), damit die Karten-IDs übereinstimmen und der FSRS-Lernstand
// übernommen werden kann. Wer an den Trainern etwas an diesen Regeln ändert, muss es hier nachziehen.
declare(strict_types=1);

const SPRACHCODE = ['cz' => 'cs', 'it' => 'it', 'nl' => 'nl'];
const GENUS_DECK = ['cz' => 'ten / ta / to', 'it' => 'il oder la?', 'nl' => 'de oder het?'];

/** tokensText der Trainer: Wörter mit Leerzeichen, Satzzeichen ohne */
function tokens_text($tokens): string
{
    if (is_string($tokens)) return $tokens;
    $aus = '';
    foreach ((array) $tokens as $i => $t) {
        $x = is_array($t) ? (string) ($t['t'] ?? '') : (string) $t;
        $aus .= ($i === 0 || preg_match('/^[.,!?;:…]$/u', $x) ? '' : ' ') . $x;
    }
    return $aus;
}
function norm_wort(string $s): string
{
    return trim(preg_replace('/\s+/u', ' ', preg_replace('/[.,!?…„“"()]/u', '', mb_strtolower($s))));
}
/** plainLow der Trainer: klein, ohne Akzente */
function plain_low(string $s): string
{
    $s = mb_strtolower($s);
    if (class_exists('Normalizer')) {
        $s = Normalizer::normalize($s, Normalizer::FORM_D);
        return preg_replace('/\p{Mn}/u', '', $s);
    }
    return strtr($s, ['à' => 'a', 'á' => 'a', 'è' => 'e', 'é' => 'e', 'ì' => 'i', 'í' => 'i', 'ò' => 'o', 'ó' => 'o', 'ù' => 'u', 'ú' => 'u', 'ë' => 'e', 'ï' => 'i', 'ö' => 'o', 'ü' => 'u']);
}
function genus_von_eintrag(?array $e): ?string
{
    if (!$e || empty($e['gender']) || !preg_match('/^(Substantiv|Eigenname)/u', (string) ($e['pos'] ?? ''))) return null;
    $g = (string) $e['gender'];
    return str_starts_with($g, 'männlich') ? 'm' : (str_starts_with($g, 'weiblich') ? 'f' : (str_starts_with($g, 'sächlich') ? 'n' : null));
}
/** Formentabellen eines Wörterbucheintrags als Markdown-Tabelle */
function formen_md(array $e): string
{
    $teile = [];
    foreach ((array) ($e['tables'] ?? []) as $t) {
        $zeilen = [];
        if (!empty($t['title'])) $zeilen[] = '**' . $t['title'] . '**';
        $cols = (array) ($t['cols'] ?? []);
        $breite = max(count($cols), ...array_map(fn($r) => count((array) $r), (array) ($t['rows'] ?? [[]])));
        if ($breite === 0) continue;
        $cols = array_pad($cols, $breite, '');
        $zeilen[] = '| ' . implode(' | ', array_map(fn($c) => str_replace('|', '/', (string) $c), $cols)) . ' |';
        $zeilen[] = '|' . str_repeat(' --- |', $breite);
        foreach ((array) ($t['rows'] ?? []) as $r) $zeilen[] = '| ' . implode(' | ', array_map(fn($c) => str_replace('|', '/', (string) $c), array_pad((array) $r, $breite, ''))) . ' |';
        $teile[] = implode("\n", $zeilen);
    }
    return implode("\n\n", $teile);
}

/** Medienzuordnung des Trainers (data/media.json): Schlüssel → quelle:-Verweis */
function trainer_medien(string $q, string $basis): array
{
    $aus = [];
    foreach (quelle_json($basis . '/data/media.json') as $k => $e) {
        $f = is_array($e) ? (string) ($e['file'] ?? '') : (string) $e;
        if (preg_match('#^media/[A-Za-z0-9._-]+$#', $f)) $aus[$k] = 'quelle:' . $q . '/' . $f;
    }
    return $aus;
}

/** Bildpfad einer Lektionskarte (Tschechisch: img/…svg) */
function quelle_bild(string $q, ?string $pfad): string
{
    if (!$pfad || !preg_match('#^(img|photos|illus)/[A-Za-z0-9._/-]+$#', $pfad)) return '';
    return 'quelle:' . $q . '/' . $pfad;
}

/** Alle Lektionen in Kursreihenfolge: [kurs, einheit, eintrag, json] */
function sprach_lektionen(string $q, string $basis): array
{
    $kurs = quelle_json($basis . '/course.json');
    $kurse = $q === 'cz'
        ? [['id' => 'kurs', 'name' => $kurs['title'] ?? 'Kurs', 'units' => $kurs['units'] ?? []]]
        : ($kurs['courses'] ?? []);
    $aus = [];
    foreach ($kurse as $c) foreach ((array) ($c['units'] ?? []) as $u) foreach ((array) ($u['lessons'] ?? []) as $l) {
        if (empty($l['file']) || !is_file($basis . '/' . $l['file'])) continue;
        $j = quelle_json($basis . '/' . $l['file'], null);
        if (!is_array($j)) continue;
        $j['id'] = $j['id'] ?? $l['id'];
        $aus[] = [$c, $u, $l, $j];
    }
    return $aus;
}

/** prepLesson der Trainer (Italienisch/Niederländisch): Textzeilen werden zu Karten, Vokabeln bekommen IDs */
function lektion_vorbereiten(array $j): array
{
    $j['cards'] = $j['cards'] ?? [];
    $j['vocab'] = $j['vocab'] ?? [];
    if (isset($j['blocks'])) {
        $j['cards'] = [];
        foreach ((array) $j['blocks'] as $b) {
            if (($b['type'] ?? '') !== 'text') continue;
            foreach ((array) ($b['lines'] ?? []) as $ln) {
                $j['cards'][] = ['type' => 'intro', 'it' => (string) ($ln['it'] ?? ''), 'de' => (string) ($ln['de'] ?? ''), 'hint' => (string) ($ln['hint'] ?? ''),
                    'who' => (string) ($ln['who'] ?? ''), 'seg' => $ln['seg'] ?? null, 'nocard' => !empty($ln['nocard'])];
            }
        }
    }
    foreach ($j['vocab'] as $i => &$v) if (empty($v['id'])) $v['id'] = $j['id'] . '.' . ($i + 1);
    unset($v);
    return $j;
}

/** vocabCtx der Trainer: erster Satz der Lektion, der das Wort enthält */
function vokabel_kontext(string $q, array $les, array $v): string
{
    if (isset($v['ctx'])) return (string) $v['ctx'];
    $muster = $q === 'nl' ? '/^(de|het|een|\'t)\s+/iu' : '/^(il|lo|la|l\'|i|gli|le|un|uno|una|un\')\s*/iu';
    $teil = preg_split('/[ ,(]/u', preg_replace($muster, '', (string) $v['it']))[0] ?? '';
    $key = plain_low($teil);
    if (mb_strlen($key) <= 2) return '';
    foreach ($les['cards'] as $c) if (str_contains(plain_low($c['it']), $key)) return $c['it'];
    return '';
}

function artikel_it(string $wort, string $g): string
{
    $w = mb_strtolower($wort);
    if (preg_match('/^[aeiouhà-ù]/u', $w)) return "l'";
    if ($g === 'f') return 'la';
    return preg_match('/^(s[^aeiou]|z|gn|ps|pn|x|y|i[aeiou])/u', $w) ? 'lo' : 'il';
}

function import_sprache(ImportLauf $lauf, string $q, string $basis): array
{
    $sprache = SPRACHCODE[$q];
    $medien = trainer_medien($q, $basis);
    $lx = quelle_json($basis . '/lexicon.json');
    $lexBy = [];
    foreach ((array) ($lx['entries'] ?? []) as $e) {
        foreach (array_merge([$e['lemma'] ?? ''], (array) ($e['aliases'] ?? [])) as $k) {
            $k = mb_strtolower((string) $k);
            if ($k !== '' && !isset($lexBy[$k])) $lexBy[$k] = $e;
        }
    }
    $lexFor = function ($t) use ($lexBy) {
        if (!is_array($t)) return null;
        return $lexBy[mb_strtolower((string) ($t['l'] ?? ''))] ?? $lexBy[mb_strtolower((string) ($t['t'] ?? ''))] ?? null;
    };
    $lektionen = sprach_lektionen($q, $basis);
    foreach ($lektionen as &$x) $x[3] = $q === 'cz' ? $x[3] + ['cards' => [], 'vocab' => []] : lektion_vorbereiten($x[3]);
    unset($x);

    // Tschechisch: Menge aller Vokabeln (Grundformen, die schon Vokabel sind, bekommen keine eigene Karte)
    $vocabSet = [];
    if ($q === 'cz') foreach ($lektionen as [, , , $j]) foreach ((array) $j['vocab'] as $v) foreach (explode(' / ', (string) $v['cz']) as $p) $vocabSet[norm_wort($p)] = true;

    $lessonLemmas = function (array $les) use ($lexFor): array {
        $aus = [];
        $seen = [];
        foreach ($les['cards'] as $c) {
            if (($c['type'] ?? '') !== 'intro' || !is_array($c['cz'] ?? null)) continue;
            foreach ($c['cz'] as $t) {
                $e = $lexFor($t);
                if ($e && empty($e['nocard']) && !isset($seen[$e['id']])) { $seen[$e['id']] = true; $aus[] = [$e, tokens_text($c['cz'])]; }
            }
        }
        return $aus;
    };

    $kursDecks = [];
    $lektionsIds = [];
    $seenLemma = [];
    $genusListe = [];
    $seenGenus = [];
    $wortFeld = $q === 'cz' ? 'cz' : 'it';
    foreach ($lektionen as [$c, $u, $l, $les]) {
        $lid = (string) $les['id'];
        $lektionsIds[] = $lid;
        $kursKey = 'kurs:' . $c['id'];
        $kursDecks[$kursKey] ??= $lauf->deck($kursKey, (string) ($c['name'] ?? $c['id']));
        $einheitName = $q === 'cz'
            ? 'Kapitel ' . $u['id'] . ' · ' . ($u['de'] ?? '')
            : trim(($u['label'] ?? '') . ' · ' . ($u['de'] ?? ($u['it'] ?? '')), ' ·');
        $einheit = $lauf->deck('einheit:' . $c['id'] . ':' . $u['id'], $einheitName, $kursDecks[$kursKey]);
        $lekName = $q === 'cz'
            ? $l['id'] . ' · ' . ($l['cz'] ?? '') . ' – ' . ($l['de'] ?? '')
            : trim(($l['num'] ?? '') . '. ' . ($l['it'] ?? '') . ' – ' . ($l['de'] ?? ''), ' .–');
        $deck = $lauf->deck('lektion:' . $lid, $lekName, $einheit);
        $sperre = 'lektion:' . $lid;
        $tags = 'lektion_' . $lid;

        // Vokabeln (je zwei Karten: Fremdsprache → Deutsch, Deutsch → Fremdsprache)
        $linked = [];
        foreach ((array) $les['vocab'] as $v) {
            $vid = (string) $v['id'];
            $wort = (string) ($v[$wortFeld] ?? '');
            $f = ['Wort' => $wort, 'Übersetzung' => (string) ($v['de'] ?? ''), 'Sprache' => $sprache];
            if ($q === 'cz') {
                $card = isset($v['intro']) ? ($les['cards'][$v['intro']] ?? null) : null;
                if ($card) {
                    $linked[(int) $v['intro']] = true;
                    $f['Wort'] = tokens_text($card['cz'] ?? []);
                    $f['Hinweis'] = (string) ($card['hint'] ?? '');
                    $f['Bild'] = $medien['c:' . $lid . ':' . $v['intro']] ?? quelle_bild($q, $card['img'] ?? null);
                }
                $g = $v['genus'] ?? (str_contains($wort, ' / ') ? null : genus_von_eintrag($lexBy[norm_wort($wort)] ?? null));
                $f['Genus'] = (string) ($g ?? '');
            } else {
                $ctx = vokabel_kontext($q, $les, $v);
                if ($ctx !== '' && plain_low($ctx) !== plain_low($wort)) $f['Satz'] = $ctx;
                $f['Hinweis'] = (string) ($v['note'] ?? '');
                $f['Genus'] = (string) ($v['genus'] ?? '');
            }
            if (empty($f['Bild'])) $f['Bild'] = $medien['v:' . $vid] ?? '';
            $lauf->notiz('v:' . $vid, $deck, 'sprache-vokabel', $f, [0 => "v:$vid:$wortFeld", 1 => "v:$vid:de"], $sperre, $tags);
            // Genus-Abfrage (Italienisch/Niederländisch): Substantive mit Geschlecht, je Wort einmal
            if ($q !== 'cz' && !empty($v['genus']) && !empty($v['noun'])) {
                $nk = mb_strtolower((string) $v['noun']);
                if (!isset($seenGenus[$nk])) { $seenGenus[$nk] = true; $genusListe[] = ['v' => $v, 'lid' => $lid]; }
            }
            if ($q === 'cz' && !str_contains($wort, ' / ')) {
                $e = $lexBy[norm_wort($wort)] ?? null;
                if ($e && empty($e['nocard']) && genus_von_eintrag($e) && !isset($seenGenus[$e['id']])) { $seenGenus[$e['id']] = true; $genusListe[] = ['e' => $e, 'lid' => $lid]; }
            }
        }

        // Sätze
        foreach ($les['cards'] as $i => $c2) {
            if ($q === 'cz') {
                if (($c2['type'] ?? '') !== 'intro' || isset($linked[$i])) continue;
                $f = ['Satz' => tokens_text($c2['cz'] ?? []), 'Übersetzung' => (string) ($c2['de'] ?? ''), 'Hinweis' => (string) ($c2['hint'] ?? ''),
                    'Bild' => $medien["c:$lid:$i"] ?? quelle_bild($q, $c2['img'] ?? null), 'Sprache' => $sprache];
            } else {
                if (!empty($c2['nocard']) || ($c2['de'] ?? '') === '') continue;
                $audio = '';
                if (is_array($c2['seg']) && !empty($les['audio']) && preg_match('#^audio/[A-Za-z0-9._/-]+$#', (string) $les['audio'])) {
                    $audio = 'quelle:' . $q . '/' . $les['audio'] . '#' . round((float) $c2['seg'][0], 2) . '-' . round((float) $c2['seg'][1], 2);
                }
                $f = ['Satz' => $c2['it'], 'Übersetzung' => $c2['de'], 'Hinweis' => $c2['hint'], 'Sprecher' => $c2['who'] !== '—' ? $c2['who'] : '',
                    'Bild' => $medien["c:$lid:$i"] ?? '', 'Audio' => $audio, 'Sprache' => $sprache];
            }
            $lauf->notiz("s:$lid:$i", $deck, 'sprache-satz', $f, [0 => "s:$lid:$i"], $sperre, $tags);
        }

        // Grundformen (nur Tschechisch: Wörter mit Token-Angaben)
        if ($q === 'cz') {
            foreach ($lessonLemmas($les) as [$e, $ctx]) {
                if (genus_von_eintrag($e) && !isset($seenGenus[$e['id']])) { $seenGenus[$e['id']] = true; $genusListe[] = ['e' => $e, 'lid' => $lid]; }
                if (isset($seenLemma[$e['id']]) || isset($vocabSet[norm_wort((string) $e['lemma'])])) continue;
                $seenLemma[$e['id']] = ['e' => $e, 'ctx' => $ctx, 'lid' => $lid];
            }
        }
    }

    $info = ['lektionen' => count($lektionsIds)];
    if ($q === 'cz' && $seenLemma) {
        $gf = $lauf->deck('grundformen', 'Grundformen');
        foreach ($seenLemma as $id => $x) {
            $e = $x['e'];
            $info_zeile = trim(implode(' · ', array_filter([trim(($e['pos'] ?? '') . (!empty($e['gender']) ? ', ' . $e['gender'] : '')), $e['summary'] ?? ''])));
            $beide = ($e['kind'] ?? '') === 'content';
            $f = ['Grundform' => $e['lemma'], 'Bedeutung' => (string) ($e['de'] ?? ''), 'Info' => $info_zeile, 'Satz' => $x['ctx'], 'Formen' => formen_md($e),
                'Genus' => (string) (genus_von_eintrag($e) ?? ''), 'Beide Richtungen' => $beide ? '1' : '', 'Sprache' => $sprache];
            $karten = [0 => "l:$id:cz"];
            if ($beide) $karten[1] = "l:$id:de";
            $lauf->notiz("l:$id", $gf, 'sprache-grundform', $f, $karten, 'lektion:' . $x['lid'], 'lektion_' . $x['lid']);
        }
        $info['grundformen'] = count($seenLemma);
    }

    // Genus-Abfrage
    if ($genusListe) {
        $gd = $lauf->deck('genus', GENUS_DECK[$q]);
        foreach ($genusListe as $x) {
            if ($q === 'cz') {
                $e = $x['e'];
                $g = genus_von_eintrag($e);
                $art = ['m' => 'ten', 'f' => 'ta', 'n' => 'to'][$g];
                $f = ['Wort' => $e['lemma'], 'Lösung' => $art, 'Optionen' => 'ten|ta|to', 'Mit Artikel' => $art . ' ' . $e['lemma'],
                    'Bedeutung' => ($e['de'] ?? '') . ' · ' . ($e['gender'] ?? ''), 'Genus' => $g, 'Sprache' => $sprache];
                $key = 'g:' . $e['id'];
            } elseif ($q === 'it') {
                $v = $x['v'];
                $g = (string) $v['genus'];
                $art = (string) ($v['art'] ?? artikel_it((string) $v['noun'], $g));
                $f = ['Wort' => $v['noun'], 'Lösung' => $g === 'f' ? 'weiblich' : 'männlich', 'Optionen' => 'männlich|weiblich',
                    'Mit Artikel' => $art === "l'" ? "l'" . $v['noun'] : $art . ' ' . $v['noun'], 'Bedeutung' => (string) ($v['de'] ?? ''), 'Genus' => $g, 'Sprache' => $sprache];
                $key = 'g:' . $v['id'];
            } else {
                $v = $x['v'];
                $art = in_array($v['genus'], ['het', 'n'], true) ? 'het' : 'de';
                $f = ['Wort' => $v['noun'], 'Lösung' => $art, 'Optionen' => 'de|het', 'Mit Artikel' => $art . ' ' . $v['noun'],
                    'Bedeutung' => (string) ($v['de'] ?? ''), 'Genus' => $art, 'Sprache' => $sprache];
                $key = 'g:' . $v['id'];
            }
            $lauf->notiz($key, $gd, 'sprache-genus', $f, [0 => $key], 'lektion:' . $x['lid'], 'lektion_' . $x['lid']);
        }
        $info['genus'] = count($genusListe);
    }

    // Kleine Wörter (Italienisch, Niederländisch): nicht an Lektionen gebunden
    $piccole = quelle_json($basis . '/piccole.json')['items'] ?? [];
    if ($piccole) {
        $kw = $lauf->deck('kleine-woerter', 'Kleine Wörter');
        $kat = [];
        foreach ($piccole as $p) {
            if (empty($p['id']) || empty($p['it'])) continue;
            $c = (string) ($p['cat'] ?? 'Allgemein');
            $kat[$c] ??= $lauf->deck('kleine-woerter:' . $c, $c, $kw);
            $bsp = implode("\n", array_map(fn($e) => '- *' . ($e[0] ?? '') . '* – ' . ($e[1] ?? ''), (array) ($p['ex'] ?? [])));
            $f = ['Wort' => $p['it'], 'Übersetzung' => (string) ($p['de'] ?? ''), 'Notiz' => (string) ($p['note'] ?? ''), 'Beispiele' => $bsp,
                'Bild' => $medien['p:' . $p['id']] ?? '', 'Rückrichtung' => '1', 'Sprache' => $sprache];
            $lauf->notiz('p:' . $p['id'], $kat[$c], 'sprache-kleinwort', $f, [0 => "p:{$p['id']}:it", 1 => "p:{$p['id']}:de"], null, 'niveau_' . ($p['lvl'] ?? 'A1'));
        }
        $info['kleine_woerter'] = count($piccole);
    }

    // Aus dem Chat (Niederländisch)
    $chat = quelle_json($basis . '/chatkaarten.json')['items'] ?? [];
    if ($chat) {
        $cd = $lauf->deck('chat', 'Aus dem Chat');
        foreach ($chat as $k) {
            if (empty($k['id']) || empty($k['it'])) continue;
            $f = ['Wort' => $k['it'], 'Übersetzung' => (string) ($k['de'] ?? ''), 'Notiz' => (string) ($k['note'] ?? ''), 'Rückrichtung' => !empty($k['de']) ? '1' : '', 'Sprache' => $sprache];
            $karten = [0 => "k:{$k['id']}:it"];
            if (!empty($k['de'])) $karten[1] = "k:{$k['id']}:de";
            $lauf->notiz('k:' . $k['id'], $cd, 'sprache-kleinwort', $f, $karten, null, tags_normalisieren((string) ($k['thema'] ?? '')));
        }
        $info['chat'] = count($chat);
    }

    // Eigene Karten der Benutzer (data/cards-<trainer-benutzer>.json), nur für das jeweilige Konto sichtbar
    $eigene = 0;
    foreach (projekt_benutzer($lauf->projekt) as $b) {
        $tb = trainer_name($b);
        $daten = quelle_json($basis . '/data/cards-' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $tb) . '.json');
        if (empty($daten['cards'])) continue;
        $fortschritt = quelle_json($basis . '/data/u-' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $tb) . '.json');
        $wurzel = $lauf->deck('eigene:' . $b, count(projekt_benutzer($lauf->projekt)) > 1 ? 'Eigene Karten (' . $b . ')' : 'Eigene Karten');
        $deckIds = [];
        foreach ((array) ($daten['decks'] ?? []) as $did => $d) {
            if (!empty($d['del'])) continue;
            $deckIds[$did] = $lauf->deck('eigene:' . $b . ':' . $did, (string) ($d['name'] ?? $did), $wurzel);
        }
        $liste = array_filter((array) $daten['cards'], fn($c) => empty($c['del']) && trim((string) ($c['f'] ?? '')) !== '');
        uasort($liste, fn($a, $c) => ($a['at'] ?? 0) <=> ($c['at'] ?? 0));
        foreach ($liste as $id => $c) {
            $did = (string) ($c['deck'] ?? '');
            $deck = $deckIds[$did] ?? $wurzel;
            $einst = $fortschritt['settings']['fc_e_' . $did] ?? [];
            $beide = ($einst['dirs'] ?? 'front') === 'both' && trim((string) ($c['b'] ?? '')) !== '';
            $fremd = ($c[$wortFeld] ?? true) !== false;
            $f = ['Wort' => (string) $c['f'], 'Übersetzung' => (string) ($c['b'] ?? ''), 'Notiz' => (string) ($c['n'] ?? ''), 'Formen' => (string) ($c['formen'] ?? ''),
                'Satz' => ($c['ctx'] ?? '') !== ($c['f'] ?? '') ? (string) ($c['ctx'] ?? '') : '', 'Genus' => (string) ($c['g'] ?? ''),
                'Bild' => $medien['u:' . $id] ?? '', 'Rückrichtung' => $beide ? '1' : '', 'Sprache' => $fremd ? $sprache : ''];
            $karten = [0 => "u:$tb/$id"];
            if ($beide) $karten[1] = "u:$tb/$id:r";
            $lauf->notiz("u:$tb/$id", $deck, 'sprache-kleinwort', $f, $karten, 'benutzer:' . $b, '');
            $eigene++;
        }
    }
    $info['eigene'] = $eigene;
    $info['_lektionen'] = $lektionsIds;
    return $info;
}

/** Konten, die ein Projekt sehen (Platzhalter * = alle bekannten Konten) */
function projekt_benutzer(int $projekt): array
{
    $f = q('SELECT benutzer FROM freigaben WHERE projekt_id = ?', [$projekt])->fetchAll(PDO::FETCH_COLUMN);
    if (in_array('*', $f, true)) return q('SELECT name FROM benutzer ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);
    return $f;
}

function trainer_name(string $benutzer): string
{
    return (string) (((array) config('trainer_benutzer'))[$benutzer] ?? $benutzer);
}

/**
 * Je Konto: abgeschlossene Lektionen freischalten (aus data/u-<name>.json, einmal frei = bleibt frei),
 * eigene Karten freischalten und einmalig den FSRS-Lernstand samt Eselsbrücken übernehmen.
 */
function sprachen_benutzer_abgleichen(int $projekt, string $q, string $basis, ?array $lektionen): array
{
    if ($q === 'its') return [];
    $aus = [];
    foreach (projekt_benutzer($projekt) as $b) {
        $tb = trainer_name($b);
        $t = jetzt_ms();
        q('INSERT OR IGNORE INTO freischaltungen (benutzer, projekt_id, sperre, zeit) VALUES (?,?,?,?)', [$b, $projekt, 'benutzer:' . $b, $t]);
        $datei = $basis . '/data/u-' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $tb) . '.json';
        $d = quelle_json($datei, null);
        if (!is_array($d)) { $aus[$b] = ['fortschritt' => false]; continue; }
        $frei = 0;
        foreach ((array) ($d['lessons'] ?? []) as $lid => $l) {
            if (!is_array($l) && $l !== true) continue;
            $frei += q('INSERT OR IGNORE INTO freischaltungen (benutzer, projekt_id, sperre, zeit) VALUES (?,?,?,?)', [$b, $projekt, 'lektion:' . $lid, (int) ($l['at'] ?? $t)])->rowCount();
        }
        $info = ['fortschritt' => true, 'neu_frei' => $frei, 'lektionen_frei' => (int) qwert("SELECT COUNT(*) FROM freischaltungen WHERE benutzer = ? AND projekt_id = ? AND sperre LIKE 'lektion:%'", [$b, $projekt])];
        if (!qwert('SELECT 1 FROM uebernahmen WHERE benutzer = ? AND quelle = ?', [$b, $q])) $info['uebernommen'] = lernstand_uebernehmen($projekt, $b, $tb, $q, $d);
        $aus[$b] = $info;
    }
    return $aus;
}

/** Einmalige Übernahme: FSRS-Stände (fc) und Eselsbrücken (notes) des Trainers */
function lernstand_uebernehmen(int $projekt, string $b, string $tb, string $q, array $d): int
{
    $karten = [];
    foreach (qalle('SELECT id, schluessel FROM karten WHERE projekt_id = ? AND schluessel IS NOT NULL', [$projekt]) as $k) $karten[$k['schluessel']] = (int) $k['id'];
    // Eigene Karten heißen im Trainer u:<id>, hier u:<trainer-benutzer>/<id>
    $umbenennen = fn(string $k) => preg_match('/^u:([^:\/]+)(:r)?$/', $k, $m) ? "u:$tb/{$m[1]}" . ($m[2] ?? '') : $k;
    $n = 0;
    foreach ((array) ($d['fc'] ?? []) as $key => $s) {
        $kid = $karten[$umbenennen((string) $key)] ?? null;
        if (!$kid || !is_array($s) || !in_array($s['st'] ?? '', ['learn', 'relearn', 'review'], true)) continue;
        $n += q('INSERT OR IGNORE INTO zustand (benutzer, karte_id, st, s, d, faellig, zuletzt, erstmals, wdh, fehler, t) VALUES (?,?,?,?,?,?,?,?,?,?,?)', [
            $b, $kid, $s['st'], (float) ($s['s'] ?? 1), (float) ($s['d'] ?? 5), (int) ($s['due'] ?? jetzt_ms()), (int) ($s['last'] ?? jetzt_ms()),
            (int) ($s['first'] ?? $s['last'] ?? jetzt_ms()), (int) ($s['reps'] ?? 1), (int) ($s['lapses'] ?? 0), (int) ($s['t'] ?? 1),
        ])->rowCount();
    }
    // Eselsbrücken: Schlüssel = Karten-ID ohne Richtung = Notiz-Schlüssel
    $notizen = [];
    foreach (qalle('SELECT id, schluessel FROM notizen WHERE projekt_id = ? AND schluessel IS NOT NULL', [$projekt]) as $x) $notizen[$x['schluessel']] = (int) $x['id'];
    foreach ((array) ($d['notes'] ?? []) as $key => $m) {
        $text = trim((string) (is_array($m) ? ($m['x'] ?? '') : $m));
        $nid = $notizen[$umbenennen((string) $key)] ?? null;
        if ($text === '' || !$nid) continue;
        q('INSERT OR IGNORE INTO zusaetze (benutzer, notiz_id, notiz, geaendert) VALUES (?,?,?,?)', [$b, $nid, text_kuerzen($text, 8000), jetzt_ms()]);
    }
    q('INSERT INTO uebernahmen (benutzer, quelle, zeit, anzahl) VALUES (?,?,?,?)', [$b, $q, jetzt_ms(), $n]);
    return $n;
}
