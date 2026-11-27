<?php
// Konfiguration der Karteikarten-App. Als config.php daneben speichern (nicht im Repo) und anpassen.
// Alles, was hier fehlt, nimmt den Standardwert aus lib/basis.php.
return [
    // SQLite-Datenbank und Ablage für hochgeladene Bilder/Töne (beides vom Webserver beschreibbar)
    'datenbank' => __DIR__ . '/data/karteikarten.sqlite',
    'medien' => __DIR__ . '/data/medien',

    // Anmeldung: Die gemeinsame svetho-Anmeldung sagt nur „angemeldet“, nicht wer.
    // Reihenfolge: REMOTE_USER von nginx → signiertes Cookie svetho_wer (falls Schlüssel gesetzt) → Standardbenutzer.
    'standard_benutzer' => 'svetho',
    'sso_benutzer_schluessel' => '',
    'admins' => ['svetho'],

    'zeitzone' => 'Europe/Berlin',

    // Quellen der eingebauten Projekte (Klone der Repos auf dem Server)
    'quellen' => [
        'its' => '/var/www/its-karteikarten',
        'cz' => '/var/www/tschechischtrainer',
        'it' => '/var/www/italienisch',
        'nl' => '/var/www/nederlands',
    ],
    // Benutzername in den Sprachtrainern (data/u-<name>.json und cards-<name>.json), falls er vom App-Konto abweicht.
    // Die Trainer tragen REMOTE_USER fest in nginx ein. Ohne Eintrag nimmt die App für Admins die einzige
    // Fortschrittsdatei eines Trainers. Je Quelle verschieden: 'svetho' => ['cz' => 'svetho', 'it' => 'lukas', 'nl' => 'lukas']
    'trainer_benutzer' => [
        // 'svetho' => 'lukas',
    ],

    // Claude-Vorschläge beim Anlegen eigener Karten (leer = aus)
    'ki' => [
        'schluessel' => '',
        'modell' => 'claude-opus-5-5',
        'monatslimit' => 300,
    ],

    // Nur für die lokale Entwicklung: Benutzer per ?als=<name> wechseln
    'entwicklung' => false,
];
