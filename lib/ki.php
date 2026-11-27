<?php
// Claude-Vorschläge beim Anlegen eigener Karten: füllt leere Felder eines Notiztyps aus dem, was schon dasteht.
// Direkte HTTP-Anfrage an die Messages-API (das Projekt kommt ohne Composer aus, wie die Sprachtrainer).
// Antwort als JSON nach Schema (output_config.format), Thinking bleibt adaptiv (Standard), Effort niedrig.
declare(strict_types=1);

function ki_einstellungen(): array
{
    return array_replace(['schluessel' => '', 'modell' => 'claude-opus-5-5', 'monatslimit' => 300, 'version' => '2026-10-21'], (array) config('ki'));
}

function ki_bereit(): bool
{
    return trim((string) ki_einstellungen()['schluessel']) !== '';
}

function ki_vorschlag(array $e): array
{
    $cfg = ki_einstellungen();
    if (!ki_bereit()) fehler(503, 'Claude-Vorschläge sind auf diesem Server nicht eingerichtet.');
    $typ = notiztyp_laden((int) ($e['typ'] ?? 0));
    if (!$typ) fehler(400, 'Unbekannter Notiztyp.');
    if ($typ['projekt_id'] !== null) projekt_pruefen((int) $typ['projekt_id']);
    $monat = date('Y-m');
    $verbraucht = (int) qwert('SELECT anzahl FROM ki_nutzung WHERE monat = ? AND benutzer = ?', [$monat, benutzer()]);
    if ($verbraucht >= (int) $cfg['monatslimit']) fehler(429, 'Das Monatslimit für Claude-Vorschläge ist erreicht.');

    // Nur Textfelder vorschlagen lassen (Bilder, Audio, Masken nicht)
    $textfelder = array_values(array_filter($typ['felder'], fn($f) => in_array($f['art'], ['text', 'md'], true)));
    if (!$textfelder) fehler(400, 'Dieser Notiztyp hat keine Textfelder.');
    $felder = (array) ($e['felder'] ?? []);
    $gefuellt = [];
    foreach ($textfelder as $f) {
        $w = trim((string) ($felder[$f['name']] ?? ''));
        if ($w !== '') $gefuellt[$f['name']] = text_kuerzen($w, 2000);
    }
    if (!$gefuellt) fehler(400, 'Füll zuerst mindestens ein Feld aus.');
    $projekt = $typ['projekt_id'] !== null ? q1('SELECT name, sprache FROM projekte WHERE id = ?', [$typ['projekt_id']]) : null;
    if (!empty($e['projekt'])) $projekt = projekt_pruefen((int) $e['projekt']);

    $schema = ['type' => 'object', 'properties' => [], 'required' => [], 'additionalProperties' => false];
    foreach ($textfelder as $f) {
        $schema['properties'][$f['name']] = ['type' => 'string'];
        $schema['required'][] = $f['name'];
    }
    $system = 'Du hilfst beim Anlegen von Karteikarten für eine Lernanwendung. Antworte auf Deutsch (süddeutsch, Du-Form). '
        . 'Fülle die leeren Felder sinnvoll und knapp aus; Felder, die schon Text haben, gibst Du unverändert zurück. '
        . 'Felder der Art „md“ dürfen einfaches Markdown enthalten (fett, kursiv, Listen). Erfinde nichts Unsicheres; '
        . 'wenn Du ein Feld nicht sinnvoll füllen kannst, lass es leer.';
    $beschreibung = "Notiztyp: {$typ['name']}\nFelder:\n";
    foreach ($textfelder as $f) $beschreibung .= "- {$f['name']} ({$f['art']})\n";
    if ($projekt) $beschreibung .= "\nProjekt: {$projekt['name']}" . (!empty($projekt['sprache']) ? " (Lernsprache: {$projekt['sprache']})" : '') . "\n";
    $beschreibung .= "\nBisheriger Inhalt (JSON):\n" . json_kodiere((object) $gefuellt);
    $frage = trim(text_kuerzen((string) ($e['hinweis'] ?? ''), 500));
    if ($frage !== '') $beschreibung .= "\n\nWunsch der lernenden Person: " . $frage;

    $anfrage = [
        'model' => $cfg['modell'],
        'max_tokens' => 4000,
        'system' => $system,
        'messages' => [['role' => 'user', 'content' => $beschreibung]],
        'output_config' => ['effort' => 'low', 'format' => ['type' => 'json_schema', 'schema' => $schema]],
        // Fällt eine Antwort einer Sicherheitsprüfung zum Opfer, darf der Server auf ein passendes Modell ausweichen
        'fallbacks' => 'default',
    ];
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 90, CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['content-type: application/json', 'x-api-key: ' . trim((string) $cfg['schluessel']),
            'anthropic-version: ' . $cfg['version'], 'anthropic-beta: server-side-fallback-2026-07-01'],
        CURLOPT_POSTFIELDS => json_kodiere($anfrage),
    ]);
    $roh = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    q('INSERT INTO ki_nutzung (monat, benutzer, anzahl) VALUES (?, ?, 1) ON CONFLICT(monat, benutzer) DO UPDATE SET anzahl = anzahl + 1', [$monat, benutzer()]);
    $antwort = json_lies($roh, []);
    if ($status !== 200) {
        $msg = (string) ($antwort['error']['message'] ?? 'Keine Antwort');
        fehler($status === 429 || $status === 529 ? 503 : 502, 'Claude ist gerade nicht erreichbar (' . $status . '): ' . text_kuerzen($msg, 200));
    }
    if (($antwort['stop_reason'] ?? '') === 'refusal') fehler(422, 'Claude hat diese Anfrage abgelehnt.');
    if (($antwort['stop_reason'] ?? '') === 'max_tokens') fehler(502, 'Die Antwort war zu lang und wurde abgeschnitten.');
    $text = '';
    foreach ((array) ($antwort['content'] ?? []) as $b) if (($b['type'] ?? '') === 'text') $text .= $b['text'];
    $vorschlag = json_decode($text, true);
    if (!is_array($vorschlag)) fehler(502, 'Die Antwort von Claude war unbrauchbar.');
    // Nur leere Felder überschreiben
    $aus = [];
    foreach ($textfelder as $f) {
        if (isset($gefuellt[$f['name']])) continue;
        $w = trim((string) ($vorschlag[$f['name']] ?? ''));
        if ($w !== '') $aus[$f['name']] = text_kuerzen($w, 4000);
    }
    return ['ok' => true, 'felder' => (object) $aus, 'verbraucht' => $verbraucht + 1, 'limit' => (int) $cfg['monatslimit']];
}
