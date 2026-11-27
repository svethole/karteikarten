<?php
// Datenbankschema mit fortlaufenden Versionen. Neue Änderungen nur unten anhängen, nie alte umschreiben.
declare(strict_types=1);

function schema_aktualisieren(PDO $pdo): void
{
    $version = (int) $pdo->query('PRAGMA user_version')->fetchColumn();
    $schritte = schema_schritte();
    if ($version >= count($schritte)) return;
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        // Nach dem Sperren nochmal lesen: ein paralleler Aufruf kann schon fertig sein
        $version = (int) $pdo->query('PRAGMA user_version')->fetchColumn();
        for ($i = $version; $i < count($schritte); $i++) {
            $pdo->exec($schritte[$i]);
            $pdo->exec('PRAGMA user_version = ' . ($i + 1));
        }
        $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    }
}

function schema_schritte(): array
{
    return [
        // 1: Grundaufbau
        <<<SQL
        CREATE TABLE benutzer (
            name TEXT PRIMARY KEY,
            angelegt INTEGER NOT NULL,
            einstellungen TEXT NOT NULL DEFAULT '{}'
        );
        -- quelle: NULL = eigenes Projekt, sonst its/cz/it/nl (eingebaut, Inhalt kommt vom Import)
        CREATE TABLE projekte (
            id INTEGER PRIMARY KEY,
            quelle TEXT UNIQUE,
            besitzer TEXT,
            name TEXT NOT NULL,
            beschreibung TEXT NOT NULL DEFAULT '',
            farbe TEXT NOT NULL DEFAULT '#4f6d7a',
            symbol TEXT NOT NULL DEFAULT '',
            flagge TEXT NOT NULL DEFAULT '',
            sprache TEXT NOT NULL DEFAULT '',
            bild TEXT NOT NULL DEFAULT '',
            bild_nachweis TEXT NOT NULL DEFAULT '',
            einstellungen TEXT NOT NULL DEFAULT '{}',
            angelegt INTEGER NOT NULL,
            geaendert INTEGER NOT NULL,
            abgleich INTEGER,
            abgleich_info TEXT NOT NULL DEFAULT '{}',
            geloescht INTEGER NOT NULL DEFAULT 0
        );
        -- Wer ein eingebautes Projekt sehen darf (eigene Projekte sieht nur der Besitzer)
        CREATE TABLE freigaben (
            projekt_id INTEGER NOT NULL REFERENCES projekte(id) ON DELETE CASCADE,
            benutzer TEXT NOT NULL,
            PRIMARY KEY (projekt_id, benutzer)
        );
        -- schluessel: stabile Kennung aus der Quelle (Import), sonst NULL
        CREATE TABLE decks (
            id INTEGER PRIMARY KEY,
            projekt_id INTEGER NOT NULL REFERENCES projekte(id) ON DELETE CASCADE,
            eltern_id INTEGER REFERENCES decks(id) ON DELETE CASCADE,
            name TEXT NOT NULL,
            beschreibung TEXT NOT NULL DEFAULT '',
            sortierung INTEGER NOT NULL DEFAULT 0,
            schluessel TEXT,
            UNIQUE (projekt_id, schluessel)
        );
        CREATE INDEX decks_projekt ON decks(projekt_id);
        CREATE TABLE kategorien (
            id INTEGER PRIMARY KEY,
            projekt_id INTEGER NOT NULL REFERENCES projekte(id) ON DELETE CASCADE,
            name TEXT NOT NULL,
            farbe TEXT NOT NULL DEFAULT '#64748b',
            symbol TEXT NOT NULL DEFAULT '',
            sortierung INTEGER NOT NULL DEFAULT 0,
            UNIQUE (projekt_id, name)
        );
        -- Notiztyp: felder = [{name, art: text|md|bild|audio|masken}], vorlagen = [{name, bedingung, vorne: [Bausteine], hinten: [Bausteine]}]
        -- art: normal | luecke (eine Karte je Lücke) | verdeckung (eine Karte je Maske)
        -- eingebaut: Kennung der fest eingebauten Typen (für den Import), NULL bei eigenen
        CREATE TABLE notiztypen (
            id INTEGER PRIMARY KEY,
            projekt_id INTEGER REFERENCES projekte(id) ON DELETE CASCADE,
            name TEXT NOT NULL,
            art TEXT NOT NULL DEFAULT 'normal',
            felder TEXT NOT NULL,
            vorlagen TEXT NOT NULL,
            stil TEXT NOT NULL DEFAULT '{}',
            eingebaut TEXT UNIQUE,
            geaendert INTEGER NOT NULL
        );
        -- sperre: Karten erst sichtbar, wenn für den Benutzer freigeschaltet (z. B. lektion:a01, benutzer:svetho)
        CREATE TABLE notizen (
            id INTEGER PRIMARY KEY,
            projekt_id INTEGER NOT NULL REFERENCES projekte(id) ON DELETE CASCADE,
            deck_id INTEGER NOT NULL REFERENCES decks(id),
            typ_id INTEGER NOT NULL REFERENCES notiztypen(id),
            felder TEXT NOT NULL,
            kategorie_id INTEGER REFERENCES kategorien(id) ON DELETE SET NULL,
            tags TEXT NOT NULL DEFAULT '',
            schluessel TEXT,
            sperre TEXT,
            sortierung INTEGER NOT NULL DEFAULT 0,
            angelegt INTEGER NOT NULL,
            geaendert INTEGER NOT NULL,
            geloescht INTEGER NOT NULL DEFAULT 0,
            UNIQUE (projekt_id, schluessel)
        );
        CREATE INDEX notizen_deck ON notizen(deck_id);
        -- ord: Nummer der Vorlage, der Lücke oder der Maske; schluessel: Karten-ID der Quelle (z. B. v:a01.3:it)
        CREATE TABLE karten (
            id INTEGER PRIMARY KEY,
            notiz_id INTEGER NOT NULL REFERENCES notizen(id) ON DELETE CASCADE,
            projekt_id INTEGER NOT NULL REFERENCES projekte(id) ON DELETE CASCADE,
            ord INTEGER NOT NULL,
            schluessel TEXT,
            angelegt INTEGER NOT NULL,
            geloescht INTEGER NOT NULL DEFAULT 0,
            UNIQUE (notiz_id, ord),
            UNIQUE (projekt_id, schluessel)
        );
        -- Lernstand je Benutzer und Karte (FSRS). st NULL = neu. Zeiten in ms.
        CREATE TABLE zustand (
            benutzer TEXT NOT NULL,
            karte_id INTEGER NOT NULL REFERENCES karten(id) ON DELETE CASCADE,
            st TEXT,
            s REAL, d REAL,
            faellig INTEGER,
            zuletzt INTEGER,
            erstmals INTEGER,
            wdh INTEGER NOT NULL DEFAULT 0,
            fehler INTEGER NOT NULL DEFAULT 0,
            pausiert INTEGER NOT NULL DEFAULT 0,
            markiert INTEGER NOT NULL DEFAULT 0,
            zurueck_bis INTEGER NOT NULL DEFAULT 0,
            t INTEGER NOT NULL DEFAULT 0,
            PRIMARY KEY (benutzer, karte_id)
        );
        CREATE INDEX zustand_faellig ON zustand(benutzer, faellig);
        -- Protokoll jeder Bewertung; cid vom Gerät verhindert doppeltes Speichern (Offline-Warteschlange)
        CREATE TABLE wiederholungen (
            id INTEGER PRIMARY KEY,
            benutzer TEXT NOT NULL,
            karte_id INTEGER NOT NULL REFERENCES karten(id) ON DELETE CASCADE,
            zeit INTEGER NOT NULL,
            note INTEGER NOT NULL,
            st_vorher TEXT,
            intervall REAL,
            s REAL, d REAL,
            dauer INTEGER NOT NULL DEFAULT 0,
            cid TEXT UNIQUE
        );
        CREATE INDEX wdh_benutzer_zeit ON wiederholungen(benutzer, zeit);
        -- Zusätze eines Benutzers zu einer Notiz (bei importierten Karten die einzige Änderungsmöglichkeit)
        CREATE TABLE zusaetze (
            benutzer TEXT NOT NULL,
            notiz_id INTEGER NOT NULL REFERENCES notizen(id) ON DELETE CASCADE,
            notiz TEXT NOT NULL DEFAULT '',
            bild TEXT NOT NULL DEFAULT '',
            tags TEXT NOT NULL DEFAULT '',
            geaendert INTEGER NOT NULL,
            PRIMARY KEY (benutzer, notiz_id)
        );
        CREATE TABLE freischaltungen (
            benutzer TEXT NOT NULL,
            projekt_id INTEGER NOT NULL REFERENCES projekte(id) ON DELETE CASCADE,
            sperre TEXT NOT NULL,
            zeit INTEGER NOT NULL,
            PRIMARY KEY (benutzer, projekt_id, sperre)
        );
        -- Einmalige Übernahme des Lernstands aus einem Trainer
        CREATE TABLE uebernahmen (
            benutzer TEXT NOT NULL,
            quelle TEXT NOT NULL,
            zeit INTEGER NOT NULL,
            anzahl INTEGER NOT NULL,
            PRIMARY KEY (benutzer, quelle)
        );
        CREATE TABLE medien (
            id TEXT PRIMARY KEY,
            besitzer TEXT NOT NULL,
            mime TEXT NOT NULL,
            groesse INTEGER NOT NULL,
            name TEXT NOT NULL DEFAULT '',
            nachweis TEXT NOT NULL DEFAULT '',
            angelegt INTEGER NOT NULL
        );
        CREATE TABLE abgleich_log (
            id INTEGER PRIMARY KEY,
            projekt_id INTEGER REFERENCES projekte(id) ON DELETE CASCADE,
            zeit INTEGER NOT NULL,
            neu INTEGER NOT NULL DEFAULT 0,
            geaendert INTEGER NOT NULL DEFAULT 0,
            entfernt INTEGER NOT NULL DEFAULT 0,
            meldung TEXT NOT NULL DEFAULT ''
        );
        CREATE TABLE ki_nutzung (
            monat TEXT NOT NULL,
            benutzer TEXT NOT NULL,
            anzahl INTEGER NOT NULL DEFAULT 0,
            PRIMARY KEY (monat, benutzer)
        );
        SQL,
    ];
}
