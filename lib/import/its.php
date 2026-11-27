<?php
// Import der ITS-Karteikarten aus its-karteikarten/public/karteikarten/karten.js (+ Farben/Symbole aus app.js).
declare(strict_types=1);

/** Gemeinsame Einrückung entfernen (Antworten stehen eingerückt zwischen Backticks) */
function text_ausruecken(string $s): string
{
    $s = str_replace("\r", '', $s);
    $zeilen = explode("\n", $s);
    while ($zeilen && trim($zeilen[0]) === '') array_shift($zeilen);
    while ($zeilen && trim(end($zeilen)) === '') array_pop($zeilen);
    $min = PHP_INT_MAX;
    foreach ($zeilen as $z) if (trim($z) !== '') $min = min($min, strlen($z) - strlen(ltrim($z, ' ')));
    if ($min === PHP_INT_MAX) $min = 0;
    return implode("\n", array_map(fn($z) => substr($z, min($min, strlen($z) - strlen(ltrim($z, ' ')))), $zeilen));
}

/** Liest karten.js. Gibt [stand, karten[{id, thema, frage, antwort}]] zurück. */
function its_karten_lesen(string $datei): array
{
    $js = (string) @file_get_contents($datei);
    if ($js === '') throw new RuntimeException('karten.js fehlt oder ist leer.');
    // Kopfkommentar entfernen (enthält ein Beispiel mit id)
    $js = preg_replace('#/\*.*?\*/#s', '', $js);
    preg_match('/stand:\s*"([^"]*)"/', $js, $st);
    $muster = '/\{\s*id:\s*"([^"]+)",\s*thema:\s*"((?:[^"\\\\]|\\\\.)*)",\s*frage:\s*(?:"((?:[^"\\\\]|\\\\.)*)"|`([^`]*)`),\s*antwort:\s*(?:`([^`]*)`|"((?:[^"\\\\]|\\\\.)*)")\s*,?\s*\}/s';
    preg_match_all($muster, $js, $m, PREG_SET_ORDER);
    $karten = [];
    $js_string = fn($s) => json_decode('"' . str_replace("\n", '\n', $s) . '"') ?? stripslashes($s);
    foreach ($m as $k) {
        $karten[] = [
            'id' => $k[1],
            'thema' => $js_string($k[2]),
            'frage' => ($k[3] ?? '') !== '' ? $js_string($k[3]) : text_ausruecken($k[4] ?? ''),
            'antwort' => ($k[5] ?? '') !== '' ? text_ausruecken($k[5]) : $js_string($k[6] ?? ''),
        ];
    }
    if (!$karten) throw new RuntimeException('In karten.js wurden keine Karten gefunden.');
    return [$st[1] ?? '', $karten];
}

/** Farben und Symbole der Themen aus app.js („Thema“: { farbe: "#…", symbol: "…" }) */
function its_themen_stil(string $datei): array
{
    $js = (string) @file_get_contents($datei);
    preg_match_all('/(?:"([^"]+)"|([A-Za-zÄÖÜäöüß]+)):\s*\{\s*farbe:\s*"(#[0-9a-fA-F]{6})",\s*symbol:\s*"([^"]*)"\s*\}/u', $js, $m, PREG_SET_ORDER);
    $aus = [];
    foreach ($m as $t) $aus[$t[1] !== '' ? $t[1] : $t[2]] = ['farbe' => $t[3], 'symbol' => $t[4]];
    return $aus;
}

function import_its(ImportLauf $lauf, string $basis): array
{
    [$stand, $karten] = its_karten_lesen($basis . '/public/karteikarten/karten.js');
    $stile = its_themen_stil($basis . '/public/karteikarten/app.js');
    $ersatz = ['#475569', '#0f766e', '#9333ea', '#b45309', '#1d4ed8'];
    $decks = [];
    $kats = [];
    foreach ($karten as $k) {
        $thema = $k['thema'] !== '' ? $k['thema'] : 'Allgemein';
        if (!isset($decks[$thema])) {
            $stil = $stile[$thema] ?? ['farbe' => $ersatz[count($decks) % count($ersatz)], 'symbol' => '📘'];
            $decks[$thema] = $lauf->deck('thema:' . $thema, $thema);
            $kats[$thema] = $lauf->kategorie($thema, $stil['farbe'], $stil['symbol']);
        }
        // Karten-ID der ITS-Karteikarten bleibt Schlüssel (an ihr hängt das Karten-Register)
        $lauf->notiz('its:' . $k['id'], $decks[$thema], 'its-frage', ['Frage' => $k['frage'], 'Antwort' => $k['antwort']], [0 => $k['id']], null, '', $kats[$thema]);
    }
    return ['stand' => $stand, 'themen' => count($decks)];
}
