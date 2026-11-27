<?php
// Servertest ohne Browser: frische Datenbank, Abgleich aller Quellen, Idempotenz, Kartenregeln, Notiz anlegen, Bewertung speichern.
//   php tests/server.php [ordner-mit-den-trainer-repos]   (Standard: das Elternverzeichnis dieses Repos)
declare(strict_types=1);
$basis = rtrim($argv[1] ?? dirname(__DIR__, 2), '/');
$tmp = sys_get_temp_dir() . '/kk-test-' . getmypid();
@mkdir($tmp);
file_put_contents("$tmp/config.php", '<?php return ' . var_export([
    'datenbank' => "$tmp/test.sqlite", 'medien' => "$tmp/medien", 'entwicklung' => true,
    'quellen' => ['its' => "$basis/its-karteikarten", 'cz' => "$basis/tschechischtrainer", 'it' => "$basis/italienisch", 'nl' => "$basis/nederlands"],
], true) . ';');
putenv("KARTEIKARTEN_CONFIG=$tmp/config.php");
$_SERVER['REMOTE_USER'] = 'svetho';
require __DIR__ . '/../lib/basis.php';
require __DIR__ . '/../lib/notiztypen.php';
require __DIR__ . '/../lib/projekte.php';
require __DIR__ . '/../lib/lernen.php';
require __DIR__ . '/../lib/bearbeiten.php';
require __DIR__ . '/../lib/medien.php';
require __DIR__ . '/../lib/import/gemeinsam.php';

$fehler = 0;
function pruefe(bool $ok, string $was): void { global $fehler; echo ($ok ? '✓ ' : '✗ ') . $was . "\n"; if (!$ok) $fehler++; }

$r = abgleich();
foreach ($r as $q => $x) pruefe(!empty($x['ok']) && $x['karten'] > 0, "Abgleich $q: " . ($x['karten'] ?? 0) . ' Karten ' . ($x['fehler'] ?? ''));
$r2 = abgleich();
foreach ($r2 as $q => $x) pruefe($x['neu'] === 0 && $x['geaendert'] === 0 && $x['entfernt'] === 0, "zweiter Abgleich $q ändert nichts");

// Kartenschlüssel wie in den Trainern
$its = (int) qwert("SELECT id FROM projekte WHERE quelle = 'its'");
pruefe((bool) qwert("SELECT 1 FROM karten WHERE projekt_id = ? AND schluessel = 'atm-resp-insuffizienz-typen'", [$its]), 'ITS-Karte behält ihre ID');
if ($cz = (int) qwert("SELECT id FROM projekte WHERE quelle = 'cz'")) {
    pruefe((bool) qwert("SELECT 1 FROM karten WHERE projekt_id = ? AND schluessel = 'v:dobry-den:cz'", [$cz]), 'Tschechisch: v:<id>:cz');
    pruefe((bool) qwert("SELECT 1 FROM karten WHERE projekt_id = ? AND schluessel LIKE 'l:%:cz'", [$cz]), 'Tschechisch: Grundformen l:…');
}
if ($it = (int) qwert("SELECT id FROM projekte WHERE quelle = 'it'")) {
    pruefe((bool) qwert("SELECT 1 FROM karten WHERE projekt_id = ? AND schluessel = 'v:a01.1:it'", [$it]), 'Italienisch: Vokabel-ID wie prepLesson (a01.1)');
    pruefe((bool) qwert("SELECT 1 FROM notizen WHERE projekt_id = ? AND felder LIKE '%quelle:it/audio/%'", [$it]), 'Italienisch: Assimil-Ausschnitt als Audio');
}

// Eigenes Projekt, Lückentext, Bewertung
$p = projekt_speichern(['name' => 'Test']);
$deck = (int) qwert('SELECT id FROM decks WHERE projekt_id = ?', [$p['id']]);
$typ = eingebaute_typen_sicherstellen()['luecke'];
$n = notiz_speichern(['deck' => $deck, 'typ' => $typ, 'felder' => ['Text' => 'Die {{c1::Aorta}} entspringt dem {{c2::linken Ventrikel}}.']]);
pruefe($n['karten'] === 2, 'Lückentext ergibt zwei Karten');
$k = (int) qwert('SELECT id FROM karten WHERE notiz_id = ? AND ord = 1', [$n['id']]);
wiederholungen_speichern([['cid' => 't1', 'karte' => $k, 'zeit' => jetzt_ms(), 'note' => 3, 'nach' => ['st' => 'learn', 's' => 3.17, 'd' => 5.3, 'due' => jetzt_ms() + 600000, 'reps' => 1]]]);
wiederholungen_speichern([['cid' => 't1', 'karte' => $k, 'zeit' => jetzt_ms(), 'note' => 3, 'nach' => ['st' => 'learn', 's' => 3.17, 'd' => 5.3, 'due' => jetzt_ms() + 600000, 'reps' => 1]]]);
pruefe((int) qwert('SELECT COUNT(*) FROM wiederholungen WHERE karte_id = ?', [$k]) === 1, 'doppelt gesendete Bewertung zählt einmal');
[, $s] = deck_baum(projekt_pruefen($p['id']));
pruefe($s['lernen'] === 1 && $s['neu'] === 1 && $s['heute_neu'] === 1, 'Zählung nach Bewertung (1 lernend, 1 neu)');

exec('rm -rf ' . escapeshellarg($tmp));
echo $fehler ? "\n$fehler Fehler\n" : "\nAlles gut.\n";
exit($fehler ? 1 : 0);
