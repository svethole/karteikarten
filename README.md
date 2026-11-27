# Karteikarten

Karteikarten mit Wiederholung in Abständen (FSRS-5) – der Kern von Anki als Webanwendung für **karteikarten.svetho.net**.

- **Übersicht** mit Bildkacheln je Projekt: fällige und neue Karten, Fortschritt, Serie, letzter Abgleich, Lernen-Knopf. Per Ziehen sortierbar.
- **Projekte** mit Decks und beliebig tiefen Unterdecks, farbigen Kategorien und Tags; Kartenliste mit Suche und Filtern; Statistik.
- **Designer:** Notiztypen wie bei Anki – Felder plus Kartenvorlagen, zusammengesetzt aus Bausteinen (Überschrift, Text, Feld, Liste, Merke-Kasten,
  Tabelle, Bild, Ton, Sprachausgabe, Antwort eintippen, Multiple-Choice, Lückentext, Bild-Verdeckung, Sprachtext mit antippbaren Wörtern,
  Genus-Effekt, Kategorie/Deck/Tags …), die man auf Vorder- und Rückseite zieht.
- **Lernen** im Browser, auch offline (PWA): Tasten Leertaste/Enter, 1–4, U; am Handy wischen. Bewertungen werden gesammelt abgeglichen.
- **Eingebaute Projekte**, automatisch abgeglichen: ITS-Karteikarten, Tschechisch, Italienisch, Niederländisch (Karten aus abgeschlossenen Lektionen,
  Lernstand der Trainer einmal übernommen, Sprachausgabe und Originalaufnahmen der Trainer).
- **Anki-Austausch** (.apkg Import/Export), **Claude-Vorschläge** beim Anlegen eigener Karten.

Anforderungen: [SPEZIFIKATION.md](SPEZIFIKATION.md) · Einrichtung auf dem Server: [deploy/EINRICHTUNG.md](deploy/EINRICHTUNG.md) · Hinweise für Claude: [CLAUDE.md](CLAUDE.md)

## Lokal ausprobieren

```bash
cp config.beispiel.php config.php      # Pfade unter 'quellen' auf die Klone der Trainer setzen, 'entwicklung' => true
php bin/abgleich.php
php -S 127.0.0.1:8099 -t public
```

Dann <http://127.0.0.1:8099> öffnen. Tests: `php tests/server.php ..` und `node tests/ablauf.cjs`.

## Lizenzen

Schriften Inter und Fraunces (SIL Open Font License, `public/schriften/`). Kachelbilder siehe `public/bilder/kacheln/nachweise.json`
(3D-Herzmodell: Z-Anatomy/BodyParts3D, CC BY-SA 4.0; Fotos aus den Sprachtrainern mit Nachweis).
