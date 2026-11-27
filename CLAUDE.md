# Karteikarten – Hinweise für Claude

Webanwendung mit dem Kern von Anki für SveTho: Wiederholen mit FSRS-5, Projekte › Decks mit Unterdecks, Notiztypen mit Kartenvorlagen
aus Bausteinen (Designer), eingebaute Projekte, die automatisch aus den ITS-Karteikarten und den drei Sprachtrainern importiert werden.
Läuft unter **https://karteikarten.svetho.net** hinter der gemeinsamen svetho-Anmeldung. Vereinbarte Anforderungen: [SPEZIFIKATION.md](SPEZIFIKATION.md),
Server: [deploy/EINRICHTUNG.md](deploy/EINRICHTUNG.md).

## Aufbau

- **PHP 8 + SQLite**, kein Composer, kein Build. `public/` ist das einzige, was nginx ausliefert; PHP nur über `public/api.php?r=<route>`
  (GET lesend, POST schreibend mit Kopf `X-Karten: 1`). Fehler werfen `fehler(status, text)` (→ `Antwort`-Ausnahme → JSON).
- `lib/basis.php` (Konfiguration aus `config.php`, `db()`, `q/q1/qwert/qalle`, `benutzer()`, Lerntag ab 4 Uhr `tagesbeginn()`),
  `lib/schema.php` (Schema als fortlaufende Schritte über `PRAGMA user_version` – **nur hinten anhängen, nie alte Schritte ändern**),
  `lib/notiztypen.php` (eingebaute Notiztypen, `karten_ords()` = welche Karten eine Notiz erzeugt), `lib/projekte.php` (Sichtbarkeit, Deckbaum,
  Zählungen), `lib/lernen.php` (Lernpaket, Bewertungen mit `cid`, Rückgängig), `lib/bearbeiten.php` (CRUD, Kartenliste, Freigaben),
  `lib/medien.php` (Uploads, Trainer-Dateien `quelle:`, Bildsuche über den Server, Sprachausgabe über die `tts-lib.php` der Trainer, Wörterbuch),
  `lib/statistik.php`, `lib/apkg.php` (Anki-Import/-Export, Schema 11), `lib/ki.php` (Claude-Vorschläge, Messages-API per curl, strukturierte Ausgabe).
- **Oberfläche** `public/app/` (ES-Module): `main.js` (Router `#/…`), `api.js` (Fetch, IndexedDB: Lernpakete + Warteschlange der Bewertungen),
  `fsrs.js` (gleiche Gewichte/Planung wie die Trainer), `karte.js` (Bausteine → HTML, Bedienung), `md.js` (sicheres Mini-Markdown),
  `medien.js` (Verweise, Ton, Sprachausgabe, Wörterbuch-Blatt), `ansichten/*.js`. `sw.js` hält die Oberfläche offline bereit
  (neue Dateien in `OBERFLAECHE` eintragen und `VERSION` erhöhen).
- **Verweise in Feldern:** `medien:<sha>.<endung>` (eigene Uploads in `data/medien/`), `quelle:<its|cz|it|nl>/<pfad>[#start-ende]` (Dateien der
  Trainer, Ausschnitte in Sekunden), `bilder/…` (mitgeliefert).

## Eingebaute Projekte (Import)

- `bin/abgleich.php` (systemd-Timer alle 15 Min., Knopf „Jetzt abgleichen“) → `lib/import/gemeinsam.php` (`ImportLauf`: Decks/Notizen per
  stabilem Schlüssel, nicht mehr Gemeldetes wird als gelöscht markiert, Lernstand bleibt) → `its.php` bzw. `sprachen.php`.
- **Karten-IDs = IDs der Trainer** (`v:<vokabel>:<it|cz|de>`, `s:<lektion>:<index>`, `l:<lexId>:cz|de`, `g:<id>`, `p:<id>:it|de`, `k:<id>:it|de`,
  eigene `u:<trainerbenutzer>/<id>[:r]`, ITS: Karten-`id` aus `karten.js`). Daran hängen Lernstand und Übernahme – **nie ändern**.
- `sprachen.php` bildet die Kartenregeln der Trainer nach (`FC.courseUniverse`, `rodUniverse`, `pwUniverse`, `chatUniverse`, `ownUniverse`,
  `prepLesson`, `vocabCtx`, `lessonLemmas` in deren `index.html`). Ändert sich dort etwas, hier nachziehen und `tests/server.php` laufen lassen.
- Lektionsnotizen haben `sperre = lektion:<id>` und erscheinen erst, wenn die Lektion im Trainer abgeschlossen ist (`data/u-<name>.json` → `lessons`);
  eigene Karten `benutzer:<konto>`. Der FSRS-Lernstand und die Eselsbrücken werden **einmal** übernommen (`uebernahmen`), danach getrennt.
- Trainer-Benutzername: Die Trainer tragen `REMOTE_USER` fest ein (Italienisch/Niederländisch `lukas`), Zuordnung über `trainer_benutzer`
  (Text oder je Quelle), sonst für Admins die einzige `u-*.json` (`trainer_name()`).
- Importierte Inhalte sind in der App nicht änderbar – nur **Zusätze** (`zusaetze`: Eselsbrücke, Bild, Tags) und Pausieren/Markieren.
- Die Sprachkurse enthalten Buchinhalte (nur persönlicher Gebrauch). **Dieses Repo ist öffentlich** und enthält keine davon – Inhalte kommen erst beim
  Abgleich auf dem Server in die Datenbank. Keine Buchtexte in Code, Tests oder Commits; die Projekte nur für den Admin freigeben (Standard).
- Die Quell-Repos werden **nur gelesen**, nie verändert (Ausnahme zur Laufzeit: der `tts/`-Zwischenspeicher der Trainer).

## Bausteine (Notiztypen)

Arten und Eigenschaften im Kopf von `lib/notiztypen.php`; Darstellung `public/app/karte.js`, Palette/Eigenschaften `public/app/ansichten/designer.js`,
erlaubte Arten `BAUSTEIN_ARTEN` in `lib/bearbeiten.php`, Anki-Umsetzung `bausteine_zu_anki()` in `lib/apkg.php`. Neuer Baustein = alle vier Stellen.
Eingebaute Notiztypen (`eingebaut`) folgen immer dem Code (werden beim Start aktualisiert) und sind in der App nur kopierbar.

## Prüfen

- `php -l` für geänderte PHP-Dateien, `node --input-type=module --check < datei.js` für JS.
- `php tests/server.php /pfad/zu/den/repos` (frische DB, Abgleich, Idempotenz, Kartenregeln).
- Browser: `KARTEIKARTEN_CONFIG=… php -S 127.0.0.1:8099 -t public` (Konfiguration mit `entwicklung => true` und Test-Datenbank), dann
  `NODE_PATH=$(npm root -g) node tests/ablauf.cjs http://127.0.0.1:8099 [bilderordner]`. Desktop und 390 px, hell und dunkel ansehen.

## Stil

Duzen, Deutsch mit süddeutschem Lexikon, Emojis dosiert, gendern mit Asterisk. Bezeichner und Kommentare deutsch, Kommentare erklären das Warum.
Keine externen Dienste im Browser (Schriften lokal in `public/schriften/`). Commit-Messages deutsch, knapp.
