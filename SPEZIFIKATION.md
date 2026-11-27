# Karteikarten – Spezifikation

Webanwendung mit dem Kern von Anki (Wiederholen in Abständen, Decks mit Unterdecks, gestaltbare Karten),
erreichbar unter **https://karteikarten.svetho.net** hinter der gemeinsamen svetho-Anmeldung.
Vereinbart im Gespräch mit SveTho am 27.11.2026.

## Technik

- PHP 8 + SQLite (eine Datei `data/karteikarten.sqlite`), nginx + PHP-FPM auf dem svetho-VPS.
- Oberfläche als Single-Page-App ohne Build-Schritt (ES-Module), hell und dunkel, Schriften lokal,
  keine externen Dienste. Installierbar als PWA, Lernen auch offline (Abgleich später).
- Anmeldung über die gemeinsame svetho-Anmeldung. Benutzer = `REMOTE_USER`, sonst signiertes
  Cookie `svetho_wer` (optional, Erweiterung im Portal), sonst `standard_benutzer` aus der Konfiguration.
- Mehrere svetho-Konten: jedes Konto hat eigene Projekte und eigenen Lernstand.

## Gliederung

- **Projekt** (Kachel auf der Übersicht: Bild, Name, Farbe, Flagge/Symbol, fällige Karten, Fortschritt,
  letzter Abgleich) › **Decks** mit beliebig tiefen **Unterdecks** › **Notizen** › **Karten**.
- Je Notiz genau eine farbige **Kategorie** (Themenfilter des Projekts) und beliebig viele **Tags**.
- Eigene Projekte: Name, Beschreibung, Farbe, Symbol, Kachelbild (Upload), Kategorien, Tags, Notiztypen.

## Karten und Gestaltung

- **Notiztypen wie bei Anki:** Felder (Text, Markdown, Bild, Audio) und eine oder mehrere Kartenvorlagen
  (Vorder- und Rückseite). Jede Vorlage erzeugt eine Karte je Notiz (optional nur, wenn ein Feld gefüllt ist).
- Vorlagen entstehen im **Designer** aus fertigen Bausteinen, die auf die Karte gezogen werden:
  Überschrift, Text (mit Feldern), Feld, Liste, Merke/Achtung/Info-Kasten, Trennlinie, Bild, Audio,
  Sprachausgabe, Eingabe (Antwort eintippen), Lückentext (eine Karte je Lücke), Multiple-Choice,
  Tabelle, Bild-Verdeckung (eine Karte je Maske), Tags/Deck-Anzeige, Sprachtext (antippbare Wörter
  mit Formentabelle), Genus-Effekt.

## Wiederholen

- **FSRS-5** wie in den Sprachtrainern (Nochmal/Schwer/Gut/Einfach, Lernschritte 1/6/10 Min.,
  Ziel-Behaltensquote einstellbar, Lerntag beginnt um 4 Uhr). Neue Karten je Tag und Deck begrenzt.
- Tastatur: Leertaste/Enter = Aufdecken bzw. „Gut“, 1–4 = Bewertung; am Handy große Knöpfe und Wischen.
- Karten markieren, pausieren, zurückstellen.

## Eingebaute Projekte (automatischer Import)

Ein Import-Skript liest die Daten **direkt vom Server** (`/var/www/…`) – per systemd-Timer und über den
Knopf „Jetzt abgleichen“. Inhalte importierter Karten kommen immer aus der Quelle; in der App sind nur
**Zusätze** möglich (Eselsbrücke/Notiz, eigenes Bild, Tags, Markierung, Pausieren).

| Projekt | Quelle | Karten |
|---|---|---|
| ITS-Karteikarten | `its-karteikarten/public/karteikarten/karten.js` | 117+ Frage-Antwort-Karten, Decks und Kategorien je Thema. Lernstand startet bei null. |
| Tschechisch | `tschechischtrainer` | Vokabeln (beide Richtungen, mit Satz/Bild der Einführungskarte), Sätze, Grundformen (Wörterbuch), Genus (ten/ta/to), eigene Karten |
| Italienisch | `italienisch` | Vokabeln, Sätze (mit Assimil-Originalaufnahme), Genus (il/la), Kleine Wörter, eigene Karten |
| Niederländisch | `nederlands` | Vokabeln, Sätze, Genus (de/het), Kleine Wörter, Aus dem Chat, eigene Karten |

- Sprachen: Kurs › Einheit/Kapitel › Lektion, daneben Decks für Grundformen, Genus, Kleine Wörter,
  Eigene Karten, Aus dem Chat. Karten-IDs = die IDs der Trainer (`v:…`, `s:…`, `l:…`, `g:…`, `p:…`, `k:…`, `u:…`).
- Karten einer Lektion sind erst freigeschaltet, wenn die Lektion **im Trainer abgeschlossen** ist
  (aus `data/u-<benutzer>.json`).
- Der **FSRS-Lernstand der Trainer wird einmal übernommen** (samt Eselsbrücken), danach lernen beide
  Apps getrennt.
- Komfort: Sprachausgabe über die Serverstimmen der Trainer, Assimil-Originalaufnahmen, Genus-Effekte
  (männlich vereist, weiblich brennt, sächlich explodiert), antippbare Wörter mit Formentabelle.
- **Sichtbarkeit:** der Admin gibt jedes importierte Projekt für Konten frei (Sprachkurse enthalten
  Buchinhalte, nur persönlicher Gebrauch). Standard: Sprachen nur für den Admin, ITS für alle.

## Übersicht

- Bildkacheln mit Farbverlauf: Fotos aus den Trainern (Tag `hero`) bzw. gemeinfreie Anatomie-Tafel
  von Wikimedia Commons, jeweils mit Bildnachweis. Eigene Projekte mit eigenem Bild.
- Auf der Kachel: fällige und neue Karten heute, Fortschritt (Anteil gelernter Karten), Serie,
  letzter Abgleich, Flagge/Symbol, Projektfarbe. Knopf „Lernen“ direkt auf der Kachel.

## Weiteres

- **Statistiken:** Kalender-Heatmap, Prognose der Fälligkeiten, Behaltensquote je Deck, Kartenstände.
- **Anki-Austausch:** Import von `.apkg` (Format „ältere Anki-Versionen unterstützen“), Export als `.apkg`.
- **Claude-Vorschläge** beim Anlegen eigener Karten (eigener Schlüssel, Monatslimit).
- Datenschutz: keine externen Dienste im Browser; Bildsuche (Wikimedia/Openverse) läuft über den Server.
