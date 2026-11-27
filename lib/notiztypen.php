<?php
// Notiztypen: eingebaute Vorlagen und die Regeln, welche Karten eine Notiz erzeugt.
//
// Bausteine (Vorder-/Rückseite einer Vorlage), jeder optional mit "wenn": "<Feld>" (nur zeigen, wenn gefüllt):
//   ueberschrift {text, groesse 1–3}        text {text (Markdown mit {{Feld}}), groesse}
//   feld {feld, stil: normal|gross|klein|leise}  liste {eintraege[], nummeriert} | {feld}
//   kasten {art: merke|achtung|info, titel, text}  trenner
//   bild {feld | quelle}  audio {feld, auto}  sprachausgabe {feld, sprache, auto}
//   eingabe {feld}  luecke {feld}  auswahl {richtig, falsch | optionen}  tabelle {zeilen[][], kopf} | {feld}
//   verdeckung {feld, masken}  tags {zeige: tags|deck|kategorie|alle}  sprachtext {feld, sprache, sprachausgabe}
//   genus {feld}  vorderseite
declare(strict_types=1);

function nt_feld(string $name, string $art = 'text'): array
{
    return ['name' => $name, 'art' => $art];
}

/** Fest eingebaute Notiztypen. Schlüssel = notiztypen.eingebaut. */
function eingebaute_notiztypen(): array
{
    $vorderseite = ['t' => 'vorderseite'];
    $trenner = ['t' => 'trenner'];
    return [
        'basis' => [
            'name' => 'Einfach',
            'felder' => [nt_feld('Vorderseite', 'md'), nt_feld('Rückseite', 'md'), nt_feld('Bild', 'bild'), nt_feld('Notiz', 'md')],
            'vorlagen' => [[
                'name' => 'Karte',
                'vorne' => [['t' => 'feld', 'feld' => 'Vorderseite', 'stil' => 'gross'], ['t' => 'bild', 'feld' => 'Bild', 'wenn' => 'Bild']],
                'hinten' => [$vorderseite, $trenner, ['t' => 'feld', 'feld' => 'Rückseite'], ['t' => 'kasten', 'art' => 'info', 'titel' => '', 'text' => '{{Notiz}}', 'wenn' => 'Notiz']],
            ]],
        ],
        'basis-beide' => [
            'name' => 'Einfach (beide Richtungen)',
            'felder' => [nt_feld('Vorderseite', 'md'), nt_feld('Rückseite', 'md'), nt_feld('Bild', 'bild'), nt_feld('Notiz', 'md')],
            'vorlagen' => [
                [
                    'name' => 'Vorwärts',
                    'vorne' => [['t' => 'feld', 'feld' => 'Vorderseite', 'stil' => 'gross'], ['t' => 'bild', 'feld' => 'Bild', 'wenn' => 'Bild']],
                    'hinten' => [$vorderseite, $trenner, ['t' => 'feld', 'feld' => 'Rückseite'], ['t' => 'kasten', 'art' => 'info', 'text' => '{{Notiz}}', 'wenn' => 'Notiz']],
                ],
                [
                    'name' => 'Rückwärts',
                    'vorne' => [['t' => 'feld', 'feld' => 'Rückseite', 'stil' => 'gross']],
                    'hinten' => [$vorderseite, $trenner, ['t' => 'feld', 'feld' => 'Vorderseite'], ['t' => 'bild', 'feld' => 'Bild', 'wenn' => 'Bild'], ['t' => 'kasten', 'art' => 'info', 'text' => '{{Notiz}}', 'wenn' => 'Notiz']],
                ],
            ],
        ],
        'eingabe' => [
            'name' => 'Antwort eintippen',
            'felder' => [nt_feld('Frage', 'md'), nt_feld('Antwort'), nt_feld('Erklärung', 'md')],
            'vorlagen' => [[
                'name' => 'Karte',
                'vorne' => [['t' => 'feld', 'feld' => 'Frage', 'stil' => 'gross'], ['t' => 'eingabe', 'feld' => 'Antwort']],
                'hinten' => [['t' => 'feld', 'feld' => 'Frage', 'stil' => 'gross'], ['t' => 'eingabe', 'feld' => 'Antwort'], ['t' => 'feld', 'feld' => 'Erklärung', 'wenn' => 'Erklärung']],
            ]],
        ],
        'luecke' => [
            'name' => 'Lückentext',
            'art' => 'luecke',
            'felder' => [nt_feld('Text', 'md'), nt_feld('Extra', 'md')],
            'vorlagen' => [[
                'name' => 'Lücke',
                'vorne' => [['t' => 'luecke', 'feld' => 'Text']],
                'hinten' => [['t' => 'luecke', 'feld' => 'Text'], ['t' => 'feld', 'feld' => 'Extra', 'stil' => 'leise', 'wenn' => 'Extra']],
            ]],
        ],
        'auswahl' => [
            'name' => 'Multiple-Choice',
            'felder' => [nt_feld('Frage', 'md'), nt_feld('Richtig'), nt_feld('Falsch'), nt_feld('Erklärung', 'md')],
            'vorlagen' => [[
                'name' => 'Karte',
                'vorne' => [['t' => 'feld', 'feld' => 'Frage', 'stil' => 'gross'], ['t' => 'auswahl', 'richtig' => 'Richtig', 'falsch' => 'Falsch']],
                'hinten' => [['t' => 'feld', 'feld' => 'Frage', 'stil' => 'gross'], ['t' => 'auswahl', 'richtig' => 'Richtig', 'falsch' => 'Falsch'], ['t' => 'feld', 'feld' => 'Erklärung', 'wenn' => 'Erklärung']],
            ]],
        ],
        'verdeckung' => [
            'name' => 'Bild-Verdeckung',
            'art' => 'verdeckung',
            'felder' => [nt_feld('Überschrift'), nt_feld('Bild', 'bild'), nt_feld('Masken', 'masken'), nt_feld('Extra', 'md')],
            'vorlagen' => [[
                'name' => 'Maske',
                'vorne' => [['t' => 'ueberschrift', 'text' => '{{Überschrift}}', 'groesse' => 2, 'wenn' => 'Überschrift'], ['t' => 'verdeckung', 'feld' => 'Bild', 'masken' => 'Masken']],
                'hinten' => [['t' => 'ueberschrift', 'text' => '{{Überschrift}}', 'groesse' => 2, 'wenn' => 'Überschrift'], ['t' => 'verdeckung', 'feld' => 'Bild', 'masken' => 'Masken'], ['t' => 'feld', 'feld' => 'Extra', 'wenn' => 'Extra']],
            ]],
        ],

        /* ----- für den Import ----- */
        'its-frage' => [
            'name' => 'ITS: Frage und Antwort',
            'felder' => [nt_feld('Frage', 'md'), nt_feld('Antwort', 'md')],
            'vorlagen' => [[
                'name' => 'Karte',
                'vorne' => [['t' => 'tags', 'zeige' => 'kategorie'], ['t' => 'feld', 'feld' => 'Frage', 'stil' => 'gross']],
                'hinten' => [$vorderseite, $trenner, ['t' => 'feld', 'feld' => 'Antwort']],
            ]],
        ],
        'sprache-vokabel' => [
            'name' => 'Sprache: Vokabel',
            'felder' => [nt_feld('Wort'), nt_feld('Übersetzung'), nt_feld('Satz'), nt_feld('Hinweis', 'md'), nt_feld('Bild', 'bild'),
                nt_feld('Audio', 'audio'), nt_feld('Genus'), nt_feld('Sprache')],
            'vorlagen' => [
                [
                    'name' => 'Fremdsprache → Deutsch',
                    'vorne' => [['t' => 'bild', 'feld' => 'Bild', 'wenn' => 'Bild'], ['t' => 'sprachtext', 'feld' => 'Wort', 'sprachausgabe' => true, 'gross' => true], ['t' => 'genus', 'feld' => 'Genus']],
                    'hinten' => [$vorderseite, $trenner, ['t' => 'feld', 'feld' => 'Übersetzung', 'stil' => 'gross'],
                        ['t' => 'feld', 'feld' => 'Hinweis', 'stil' => 'leise', 'wenn' => 'Hinweis'], ['t' => 'sprachtext', 'feld' => 'Satz', 'sprachausgabe' => true, 'wenn' => 'Satz']],
                ],
                [
                    'name' => 'Deutsch → Fremdsprache',
                    'vorne' => [['t' => 'bild', 'feld' => 'Bild', 'wenn' => 'Bild'], ['t' => 'feld', 'feld' => 'Übersetzung', 'stil' => 'gross']],
                    'hinten' => [$vorderseite, $trenner, ['t' => 'sprachtext', 'feld' => 'Wort', 'sprachausgabe' => true, 'gross' => true, 'auto' => true], ['t' => 'genus', 'feld' => 'Genus'],
                        ['t' => 'feld', 'feld' => 'Hinweis', 'stil' => 'leise', 'wenn' => 'Hinweis'], ['t' => 'sprachtext', 'feld' => 'Satz', 'sprachausgabe' => true, 'wenn' => 'Satz']],
                ],
            ],
        ],
        'sprache-satz' => [
            'name' => 'Sprache: Satz',
            'felder' => [nt_feld('Satz'), nt_feld('Übersetzung'), nt_feld('Hinweis', 'md'), nt_feld('Sprecher'), nt_feld('Bild', 'bild'), nt_feld('Audio', 'audio'), nt_feld('Sprache')],
            'vorlagen' => [[
                'name' => 'Satz → Deutsch',
                'vorne' => [['t' => 'bild', 'feld' => 'Bild', 'wenn' => 'Bild'], ['t' => 'feld', 'feld' => 'Sprecher', 'stil' => 'leise', 'wenn' => 'Sprecher'],
                    ['t' => 'sprachtext', 'feld' => 'Satz', 'sprachausgabe' => true, 'gross' => true]],
                'hinten' => [$vorderseite, $trenner, ['t' => 'feld', 'feld' => 'Übersetzung', 'stil' => 'gross'], ['t' => 'feld', 'feld' => 'Hinweis', 'stil' => 'leise', 'wenn' => 'Hinweis']],
            ]],
        ],
        'sprache-grundform' => [
            'name' => 'Sprache: Grundform',
            'felder' => [nt_feld('Grundform'), nt_feld('Bedeutung'), nt_feld('Info'), nt_feld('Satz'), nt_feld('Formen', 'md'), nt_feld('Genus'),
                nt_feld('Beide Richtungen'), nt_feld('Sprache')],
            'vorlagen' => [
                [
                    'name' => 'Grundform → Deutsch',
                    'vorne' => [['t' => 'sprachtext', 'feld' => 'Grundform', 'sprachausgabe' => true, 'gross' => true], ['t' => 'genus', 'feld' => 'Genus']],
                    'hinten' => [$vorderseite, $trenner, ['t' => 'feld', 'feld' => 'Bedeutung', 'stil' => 'gross'], ['t' => 'feld', 'feld' => 'Info', 'stil' => 'leise', 'wenn' => 'Info'],
                        ['t' => 'sprachtext', 'feld' => 'Satz', 'sprachausgabe' => true, 'wenn' => 'Satz'], ['t' => 'feld', 'feld' => 'Formen', 'wenn' => 'Formen']],
                ],
                [
                    'name' => 'Deutsch → Grundform',
                    'bedingung' => 'Beide Richtungen',
                    'vorne' => [['t' => 'feld', 'feld' => 'Bedeutung', 'stil' => 'gross']],
                    'hinten' => [$vorderseite, $trenner, ['t' => 'sprachtext', 'feld' => 'Grundform', 'sprachausgabe' => true, 'gross' => true, 'auto' => true], ['t' => 'genus', 'feld' => 'Genus'],
                        ['t' => 'feld', 'feld' => 'Info', 'stil' => 'leise', 'wenn' => 'Info'], ['t' => 'feld', 'feld' => 'Formen', 'wenn' => 'Formen']],
                ],
            ],
        ],
        'sprache-genus' => [
            'name' => 'Sprache: Genus',
            'felder' => [nt_feld('Wort'), nt_feld('Lösung'), nt_feld('Optionen'), nt_feld('Mit Artikel'), nt_feld('Bedeutung'), nt_feld('Genus'), nt_feld('Sprache')],
            'vorlagen' => [[
                'name' => 'Welches Geschlecht?',
                'vorne' => [['t' => 'sprachtext', 'feld' => 'Wort', 'sprachausgabe' => true, 'gross' => true], ['t' => 'auswahl', 'richtig' => 'Lösung', 'optionen' => 'Optionen', 'sofort' => true]],
                'hinten' => [['t' => 'sprachtext', 'feld' => 'Mit Artikel', 'sprachausgabe' => true, 'gross' => true], ['t' => 'auswahl', 'richtig' => 'Lösung', 'optionen' => 'Optionen'],
                    ['t' => 'feld', 'feld' => 'Bedeutung'], ['t' => 'genus', 'feld' => 'Genus']],
            ]],
        ],
        'sprache-kleinwort' => [
            'name' => 'Sprache: Wort mit Notiz',
            'felder' => [nt_feld('Wort'), nt_feld('Übersetzung'), nt_feld('Notiz', 'md'), nt_feld('Beispiele', 'md'), nt_feld('Bild', 'bild'), nt_feld('Genus'),
                nt_feld('Formen', 'md'), nt_feld('Satz'), nt_feld('Rückrichtung'), nt_feld('Sprache')],
            'vorlagen' => [
                [
                    'name' => 'Fremdsprache → Deutsch',
                    'vorne' => [['t' => 'bild', 'feld' => 'Bild', 'wenn' => 'Bild'], ['t' => 'sprachtext', 'feld' => 'Wort', 'sprachausgabe' => true, 'gross' => true], ['t' => 'genus', 'feld' => 'Genus']],
                    'hinten' => [$vorderseite, $trenner, ['t' => 'feld', 'feld' => 'Übersetzung', 'stil' => 'gross'], ['t' => 'feld', 'feld' => 'Notiz', 'wenn' => 'Notiz'],
                        ['t' => 'sprachtext', 'feld' => 'Satz', 'sprachausgabe' => true, 'wenn' => 'Satz'], ['t' => 'feld', 'feld' => 'Beispiele', 'stil' => 'leise', 'wenn' => 'Beispiele'], ['t' => 'feld', 'feld' => 'Formen', 'wenn' => 'Formen']],
                ],
                [
                    'name' => 'Deutsch → Fremdsprache',
                    'bedingung' => 'Rückrichtung',
                    'vorne' => [['t' => 'bild', 'feld' => 'Bild', 'wenn' => 'Bild'], ['t' => 'feld', 'feld' => 'Übersetzung', 'stil' => 'gross']],
                    'hinten' => [$vorderseite, $trenner, ['t' => 'sprachtext', 'feld' => 'Wort', 'sprachausgabe' => true, 'gross' => true, 'auto' => true], ['t' => 'genus', 'feld' => 'Genus'],
                        ['t' => 'feld', 'feld' => 'Notiz', 'wenn' => 'Notiz'], ['t' => 'feld', 'feld' => 'Beispiele', 'stil' => 'leise', 'wenn' => 'Beispiele'], ['t' => 'feld', 'feld' => 'Formen', 'wenn' => 'Formen']],
                ],
            ],
        ],
    ];
}

/** Legt fehlende eingebaute Typen an (projekt_id NULL = in allen Projekten verfügbar) und gibt eingebaut → id zurück */
function eingebaute_typen_sicherstellen(): array
{
    static $ids = null;
    if ($ids !== null) return $ids;
    $ids = [];
    foreach (eingebaute_notiztypen() as $key => $def) {
        $id = qwert('SELECT id FROM notiztypen WHERE eingebaut = ?', [$key]);
        $felder = json_kodiere($def['felder']);
        $vorlagen = json_kodiere($def['vorlagen']);
        if ($id === null) {
            q('INSERT INTO notiztypen (projekt_id, name, art, felder, vorlagen, eingebaut, geaendert) VALUES (NULL, ?, ?, ?, ?, ?, ?)',
                [$def['name'], $def['art'] ?? 'normal', $felder, $vorlagen, $key, jetzt_ms()]);
            $id = (int) db()->lastInsertId();
        } else {
            // Eingebaute Typen folgen immer dem Code (Verbesserungen kommen so bei allen an)
            q('UPDATE notiztypen SET name = ?, art = ?, felder = ?, vorlagen = ? WHERE id = ? AND (felder <> ? OR vorlagen <> ? OR name <> ?)',
                [$def['name'], $def['art'] ?? 'normal', $felder, $vorlagen, $id, $felder, $vorlagen, $def['name']]);
        }
        $ids[$key] = (int) $id;
    }
    return $ids;
}

function notiztyp_laden(int $id): ?array
{
    $t = q1('SELECT * FROM notiztypen WHERE id = ?', [$id]);
    if (!$t) return null;
    $t['felder'] = json_lies($t['felder']);
    $t['vorlagen'] = json_lies($t['vorlagen']);
    $t['stil'] = json_lies($t['stil'], new stdClass());
    return $t;
}

/** Welche Karten (ord) eine Notiz erzeugt */
function karten_ords(array $typ, array $felder): array
{
    $wert = fn($n) => trim((string) ($felder[$n] ?? ''));
    if ($typ['art'] === 'luecke') {
        // Lückenfeld = Feld des ersten luecke-Bausteins
        $feld = null;
        foreach ($typ['vorlagen'] as $v) foreach ($v['vorne'] as $b) if (($b['t'] ?? '') === 'luecke') { $feld = $b['feld']; break 2; }
        preg_match_all('/\{\{c(\d+)::/', $wert($feld ?? 'Text'), $m);
        $ns = array_values(array_unique(array_map('intval', $m[1])));
        sort($ns);
        return $ns;
    }
    if ($typ['art'] === 'verdeckung') {
        $feld = 'Masken';
        foreach ($typ['vorlagen'] as $v) foreach ($v['vorne'] as $b) if (($b['t'] ?? '') === 'verdeckung') { $feld = $b['masken'] ?? 'Masken'; break 2; }
        $masken = json_lies($wert($feld), []);
        // Masken mit gleicher Gruppe ergeben eine gemeinsame Karte
        $ords = [];
        foreach ((array) $masken as $i => $m) $ords[] = isset($m['gruppe']) && $m['gruppe'] !== '' ? (int) $m['gruppe'] : $i + 1;
        $ords = array_values(array_unique($ords));
        sort($ords);
        return $ords;
    }
    $aus = [];
    foreach ($typ['vorlagen'] as $i => $v) {
        $bed = $v['bedingung'] ?? '';
        if ($bed !== '' && $wert($bed) === '') continue;
        $aus[] = $i;
    }
    return $aus;
}

/** Karten einer Notiz an die Felder anpassen. $schluessel: ord → Kartenschlüssel (Import). Gibt Anzahl neuer Karten zurück. */
function karten_abgleichen(int $notizId, int $projektId, array $typ, array $felder, array $schluessel = []): int
{
    $soll = karten_ords($typ, $felder);
    $ist = [];
    foreach (qalle('SELECT id, ord, geloescht, schluessel FROM karten WHERE notiz_id = ?', [$notizId]) as $k) $ist[(int) $k['ord']] = $k;
    $neu = 0;
    foreach ($soll as $ord) {
        $key = $schluessel[$ord] ?? null;
        if (isset($ist[$ord])) {
            if ($ist[$ord]['geloescht'] || $ist[$ord]['schluessel'] !== $key) q('UPDATE karten SET geloescht = 0, schluessel = ? WHERE id = ?', [$key, $ist[$ord]['id']]);
        } else {
            q('INSERT INTO karten (notiz_id, projekt_id, ord, schluessel, angelegt) VALUES (?, ?, ?, ?, ?)', [$notizId, $projektId, $ord, $key, jetzt_ms()]);
            $neu++;
        }
    }
    // Überzählige Karten nur als gelöscht markieren: Lernstand bleibt, falls die Karte zurückkommt
    foreach ($ist as $ord => $k) if (!in_array($ord, $soll, true) && !$k['geloescht']) q('UPDATE karten SET geloescht = 1 WHERE id = ?', [$k['id']]);
    return $neu;
}
