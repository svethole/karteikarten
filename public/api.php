<?php
// Einziger Einstieg für die Oberfläche: api.php?r=<route>. Antworten als JSON (außer Dateien).
// Schreibende Anfragen nur per POST mit Kopf „X-Karten: 1“ (Schutz vor fremden Formularen).
declare(strict_types=1);

require __DIR__ . '/../lib/basis.php';
require __DIR__ . '/../lib/notiztypen.php';
require __DIR__ . '/../lib/projekte.php';
require __DIR__ . '/../lib/lernen.php';
require __DIR__ . '/../lib/bearbeiten.php';
require __DIR__ . '/../lib/medien.php';
require __DIR__ . '/../lib/statistik.php';
require __DIR__ . '/../lib/import/gemeinsam.php';
require __DIR__ . '/../lib/apkg.php';
require __DIR__ . '/../lib/ki.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

$route = (string) ($_GET['r'] ?? '');
$methode = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function eingabe(): array
{
    static $e = null;
    if ($e !== null) return $e;
    $typ = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
    if (str_starts_with($typ, 'application/json')) {
        $roh = (string) file_get_contents('php://input', false, null, 0, 8 * 1024 * 1024);
        $e = json_decode($roh, true);
        if (!is_array($e)) fehler(400, 'Ungültige Daten.');
    } else {
        $e = $_POST;
    }
    return $e;
}

function senden(array $daten, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_kodiere($daten);
}

try {
    // Dateien zuerst (eigene Antwortköpfe)
    switch ($route) {
        case 'medien': benutzer(); medien_ausliefern((string) ($_GET['id'] ?? '')); exit;
        case 'quelle': benutzer(); quelle_ausliefern((string) ($_GET['q'] ?? ''), (string) ($_GET['pfad'] ?? '')); exit;
        case 'tts': benutzer(); tts_ausliefern((string) ($_GET['sprache'] ?? ''), (string) ($_GET['t'] ?? ''), (string) ($_GET['stimme'] ?? ''), !empty($_GET['langsam'])); exit;
        case 'apkg_export': benutzer(); apkg_export((int) ($_GET['projekt'] ?? 0), isset($_GET['deck']) ? (int) $_GET['deck'] : null, !empty($_GET['lernstand'])); exit;
    }

    if ($methode === 'POST') {
        if (($_SERVER['HTTP_X_KARTEN'] ?? '') !== '1') fehler(403, 'Anfrage ohne Kennung abgelehnt.');
        $e = eingabe();
        $antwort = match ($route) {
            'wiederholungen' => wiederholungen_speichern((array) ($e['liste'] ?? [])),
            'karte_aktion' => karte_aktion($e),
            'projekt_speichern' => projekt_speichern($e),
            'projekt_loeschen' => projekt_loeschen((int) ($e['id'] ?? 0)),
            'deck_speichern' => deck_speichern($e),
            'deck_loeschen' => deck_loeschen((int) ($e['id'] ?? 0)),
            'deck_sortieren' => deck_sortieren($e),
            'kategorie_speichern' => kategorie_speichern($e),
            'kategorie_loeschen' => kategorie_loeschen((int) ($e['id'] ?? 0)),
            'notiztyp_speichern' => notiztyp_speichern($e),
            'notiztyp_loeschen' => notiztyp_loeschen((int) ($e['id'] ?? 0)),
            'notiz_speichern' => notiz_speichern($e),
            'notiz_loeschen' => notiz_loeschen((int) ($e['id'] ?? 0)),
            'notizen_verschieben' => notizen_verschieben($e),
            'zusatz_speichern' => zusatz_speichern($e),
            'einstellungen_speichern' => einstellungen_speichern($e),
            'freigaben_setzen' => freigaben_setzen($e),
            'medien_hochladen' => medien_hochladen(),
            'bild_von_adresse' => bild_von_adresse((string) ($e['url'] ?? ''), (string) ($e['nachweis'] ?? '')),
            'abgleich' => abgleich_anstossen($e),
            'apkg_import' => apkg_import(),
            'ki_vorschlag' => ki_vorschlag($e),
            default => fehler(404, 'Unbekannte Aktion.'),
        };
    } else {
        $g = $_GET;
        $antwort = match ($route) {
            'ich' => ich(),
            'uebersicht' => uebersicht(),
            'projekt' => projekt_details((int) ($g['id'] ?? 0)),
            'lernpaket' => lernpaket(isset($g['deck']) ? (int) $g['deck'] : null, isset($g['projekt']) ? (int) $g['projekt'] : null),
            'karten' => kartenliste($g),
            'notiz' => notiz_details((int) ($g['id'] ?? 0)),
            'notiztyp' => notiztyp_details((int) ($g['id'] ?? 0)),
            'statistik' => statistik(isset($g['projekt']) ? (int) $g['projekt'] : null),
            'bildsuche' => bildsuche((string) ($g['q'] ?? ''), (string) ($g['quelle'] ?? 'wikimedia')),
            'tts_stimmen' => tts_stimmen((string) ($g['sprache'] ?? '')),
            'lexikon' => lexikon((string) ($g['sprache'] ?? '')),
            'benutzer' => benutzerliste(),
            default => fehler(404, 'Unbekannte Abfrage.'),
        };
    }
    senden($antwort);
} catch (Antwort $a) {
    senden($a->daten, $a->status);
} catch (Throwable $t) {
    error_log('karteikarten: ' . $t->getMessage() . ' @ ' . $t->getFile() . ':' . $t->getLine());
    senden(['ok' => false, 'fehler' => config('entwicklung') ? $t->getMessage() . ' @ ' . basename($t->getFile()) . ':' . $t->getLine() : 'Interner Fehler. Bitte später nochmal versuchen.'], 500);
}

function ich(): array
{
    eingebaute_projekte_sicherstellen();
    $n = benutzer();
    return ['ok' => true, 'benutzer' => $n, 'admin' => ist_admin(), 'einstellungen' => benutzer_einstellungen(),
        'tagesbeginn' => tagesbeginn(), 'jetzt' => jetzt_ms(), 'ki' => ki_bereit(), 'entwicklung' => (bool) config('entwicklung')];
}

/** Abgleich aus der Oberfläche: wer ein eingebautes Projekt sieht, darf es abgleichen (höchstens alle 30 s) */
function abgleich_anstossen(array $e): array
{
    $pid = (int) ($e['projekt'] ?? 0);
    $p = projekt_pruefen($pid);
    if ($p['quelle'] === null) fehler(400, 'Nur eingebaute Projekte werden abgeglichen.');
    if ($p['abgleich'] && jetzt_ms() - (int) $p['abgleich'] < 30000) return ['ok' => true, 'ergebnis' => ['uebersprungen' => true]];
    @set_time_limit(300);
    $r = abgleich($p['quelle']);
    $x = $r[$p['quelle']] ?? ['ok' => false, 'fehler' => 'Kein Ergebnis.'];
    if (empty($x['ok'])) fehler(500, 'Abgleich fehlgeschlagen: ' . ($x['fehler'] ?? ''));
    return ['ok' => true, 'ergebnis' => $x];
}
