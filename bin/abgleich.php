<?php
// Abgleich der eingebauten Projekte mit ihren Quellen (für den systemd-Timer und von Hand):
//   sudo -u www-data php bin/abgleich.php [its|cz|it|nl]
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require __DIR__ . '/../lib/basis.php';
require __DIR__ . '/../lib/notiztypen.php';
require __DIR__ . '/../lib/projekte.php';
require __DIR__ . '/../lib/import/gemeinsam.php';
$nur = $argv[1] ?? null;
// Benutzer für die Freigaben: der Abgleich läuft ohne Anmeldung
$_SERVER['REMOTE_USER'] = (string) config('standard_benutzer');
$erg = abgleich($nur, true);
foreach ($erg as $r) if (empty($r['ok'])) exit(2);
