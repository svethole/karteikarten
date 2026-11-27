<?php
// Gemeinsamer Rahmen für den Abgleich der eingebauten Projekte mit ihren Quellen auf dem Server.
//
// Ablauf je Quelle: Importer liest die Dateien und meldet Decks und Notizen über ImportLauf.
// Notizen tragen einen stabilen Schlüssel (z. B. v:a01.3), Karten den Karten-Schlüssel des Trainers
// (z. B. v:a01.3:it). Was beim Lauf nicht mehr vorkommt, wird als gelöscht markiert (Lernstand bleibt).
// Danach: Freischaltungen je Benutzer (abgeschlossene Lektionen) und einmalige Übernahme des Lernstands.
declare(strict_types=1);

require_once __DIR__ . '/its.php';
require_once __DIR__ . '/sprachen.php';

final class ImportLauf
{
    public int $projekt;
    public array $typen;
    public int $neu = 0;
    public int $geaendert = 0;
    public int $entfernt = 0;
    private array $gesehen = [];
    private array $deckGesehen = [];
    private int $sort = 0;
    private array $bestand = [];

    public function __construct(int $projekt)
    {
        $this->projekt = $projekt;
        $this->typen = eingebaute_typen_sicherstellen();
        // Bestand einmal laden: Vergleich im Speicher ist viel schneller als je Notiz eine Abfrage
        foreach (qalle('SELECT id, schluessel, felder, deck_id, typ_id, tags, sperre, kategorie_id, sortierung, geloescht FROM notizen WHERE projekt_id = ? AND schluessel IS NOT NULL', [$projekt]) as $n) {
            $this->bestand[$n['schluessel']] = $n;
        }
    }

    private array $deckSchluessel = [];
    public function deck(string $schluessel, string $name, ?int $eltern = null): int
    {
        if (isset($this->deckSchluessel[$schluessel])) return $this->deckSchluessel[$schluessel];
        $id = deck_sicherstellen($this->projekt, $schluessel, $name, $eltern, count($this->deckGesehen));
        $this->deckGesehen[$id] = true;
        return $this->deckSchluessel[$schluessel] = $id;
    }

    public function kategorie(string $name, string $farbe, string $symbol): int
    {
        $id = qwert('SELECT id FROM kategorien WHERE projekt_id = ? AND name = ?', [$this->projekt, $name]);
        if ($id) {
            q('UPDATE kategorien SET farbe = ?, symbol = ? WHERE id = ?', [$farbe, $symbol, $id]);
            return (int) $id;
        }
        q('INSERT INTO kategorien (projekt_id, name, farbe, symbol, sortierung) VALUES (?,?,?,?, (SELECT COALESCE(MAX(sortierung),0)+1 FROM kategorien WHERE projekt_id = ?))', [$this->projekt, $name, $farbe, $symbol, $this->projekt]);
        return (int) db()->lastInsertId();
    }

    /**
     * Notiz anlegen oder aktualisieren.
     * $karten: ord → Karten-Schlüssel des Trainers (nur für die Karten, die es geben soll)
     */
    public function notiz(string $schluessel, int $deck, string $typKey, array $felder, array $karten, ?string $sperre = null, string $tags = '', ?int $kategorie = null): void
    {
        if (isset($this->gesehen[$schluessel])) return;
        $this->gesehen[$schluessel] = true;
        $typId = $this->typen[$typKey];
        $typ = $this->typCache($typId);
        // Felder in der Reihenfolge des Typs, fehlende leer
        $f = [];
        foreach ($typ['felder'] as $def) $f[$def['name']] = (string) ($felder[$def['name']] ?? '');
        $json = json_kodiere((object) $f);
        $sort = ++$this->sort;
        $t = jetzt_ms();
        $alt = $this->bestand[$schluessel] ?? null;
        if ($alt) {
            $id = (int) $alt['id'];
            $anders = $alt['felder'] !== $json || (int) $alt['deck_id'] !== $deck || (int) $alt['typ_id'] !== $typId || $alt['tags'] !== $tags
                || $alt['sperre'] !== $sperre || ($alt['kategorie_id'] === null ? null : (int) $alt['kategorie_id']) !== $kategorie || (int) $alt['geloescht'] === 1;
            if ($anders) {
                q('UPDATE notizen SET felder = ?, deck_id = ?, typ_id = ?, tags = ?, sperre = ?, kategorie_id = ?, sortierung = ?, geloescht = 0, geaendert = ? WHERE id = ?',
                    [$json, $deck, $typId, $tags, $sperre, $kategorie, $sort, $t, $id]);
                $this->geaendert++;
            } elseif ((int) $alt['sortierung'] !== $sort) {
                q('UPDATE notizen SET sortierung = ? WHERE id = ?', [$sort, $id]);
            }
        } else {
            q('INSERT INTO notizen (projekt_id, deck_id, typ_id, felder, kategorie_id, tags, schluessel, sperre, sortierung, angelegt, geaendert) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                [$this->projekt, $deck, $typId, $json, $kategorie, $tags, $schluessel, $sperre, $sort, $t, $t]);
            $id = (int) db()->lastInsertId();
            $this->neu++;
        }
        $this->kartenSetzen($id, $typ, $f, $karten);
    }

    private array $typen_cache = [];
    private function typCache(int $id): array
    {
        return $this->typen_cache[$id] ??= notiztyp_laden($id);
    }

    /** Karten genau nach Vorgabe des Importers (nicht nach den Bedingungen des Typs) */
    private function kartenSetzen(int $notiz, array $typ, array $felder, array $karten): void
    {
        $ist = [];
        foreach (qalle('SELECT id, ord, schluessel, geloescht FROM karten WHERE notiz_id = ?', [$notiz]) as $k) $ist[(int) $k['ord']] = $k;
        foreach ($karten as $ord => $key) {
            if (isset($ist[$ord])) {
                if ($ist[$ord]['schluessel'] !== $key || (int) $ist[$ord]['geloescht'] === 1) {
                    // Schlüssel könnte an anderer (gelöschter) Karte hängen: dort freigeben
                    q('UPDATE karten SET schluessel = NULL WHERE projekt_id = ? AND schluessel = ? AND id <> ?', [$this->projekt, $key, $ist[$ord]['id']]);
                    q('UPDATE karten SET schluessel = ?, geloescht = 0 WHERE id = ?', [$key, $ist[$ord]['id']]);
                }
            } else {
                $frei = qwert('SELECT id FROM karten WHERE projekt_id = ? AND schluessel = ?', [$this->projekt, $key]);
                if ($frei) {
                    // Karte gab es schon an einer anderen Notiz (z. B. Lektion umgebaut): umhängen, Lernstand bleibt
                    q('UPDATE karten SET notiz_id = ?, ord = ?, geloescht = 0 WHERE id = ?', [$notiz, $ord, $frei]);
                } else {
                    q('INSERT INTO karten (notiz_id, projekt_id, ord, schluessel, angelegt) VALUES (?,?,?,?,?)', [$notiz, $this->projekt, $ord, $key, jetzt_ms()]);
                }
            }
        }
        foreach ($ist as $ord => $k) if (!isset($karten[$ord]) && !(int) $k['geloescht']) q('UPDATE karten SET geloescht = 1 WHERE id = ?', [$k['id']]);
    }

    /** Alles, was nicht mehr gemeldet wurde, als gelöscht markieren; leere Decks entfernen */
    public function abschliessen(): void
    {
        foreach ($this->bestand as $key => $n) {
            if (!isset($this->gesehen[$key]) && !(int) $n['geloescht']) {
                q('UPDATE notizen SET geloescht = 1, geaendert = ? WHERE id = ?', [jetzt_ms(), $n['id']]);
                $this->entfernt++;
            }
        }
        foreach (qalle('SELECT id FROM decks WHERE projekt_id = ? AND schluessel IS NOT NULL', [$this->projekt]) as $d) {
            if (isset($this->deckGesehen[(int) $d['id']])) continue;
            if (!qwert('SELECT 1 FROM notizen WHERE deck_id = ? AND geloescht = 0 LIMIT 1', [$d['id']]) && !qwert('SELECT 1 FROM decks WHERE eltern_id = ? LIMIT 1', [$d['id']])) {
                q('UPDATE notizen SET deck_id = (SELECT id FROM decks WHERE projekt_id = ? AND id <> ? ORDER BY sortierung LIMIT 1) WHERE deck_id = ?', [$this->projekt, $d['id'], $d['id']]);
                q('DELETE FROM decks WHERE id = ?', [$d['id']]);
            }
        }
    }
}

/** Abgleich einer Quelle (its, cz, it, nl) oder aller. Gibt je Quelle eine Zusammenfassung zurück. */
function abgleich(?string $nur = null, bool $ausgabe = false): array
{
    eingebaute_projekte_sicherstellen();
    $ergebnis = [];
    foreach (qalle('SELECT * FROM projekte WHERE quelle IS NOT NULL AND geloescht = 0') as $p) {
        $q = $p['quelle'];
        if ($nur !== null && $nur !== $q) continue;
        $basis = ((array) config('quellen'))[$q] ?? '';
        $start = microtime(true);
        $lauf = new ImportLauf((int) $p['id']);
        $info = [];
        db()->exec('BEGIN IMMEDIATE');
        try {
            if (!is_dir($basis)) throw new RuntimeException("Quelle $basis fehlt.");
            $info = $q === 'its' ? import_its($lauf, $basis) : import_sprache($lauf, $q, $basis);
            $lauf->abschliessen();
            // Benutzer: Freischaltungen und einmalige Übernahme des Lernstands
            $info['benutzer'] = sprachen_benutzer_abgleichen((int) $p['id'], $q, $basis, $info['_lektionen'] ?? null);
            unset($info['_lektionen']);
            $info['dauer_ms'] = (int) ((microtime(true) - $start) * 1000);
            $info['karten'] = (int) qwert('SELECT COUNT(*) FROM karten WHERE projekt_id = ? AND geloescht = 0', [$p['id']]);
            q('UPDATE projekte SET abgleich = ?, abgleich_info = ? WHERE id = ?', [jetzt_ms(), json_kodiere($info + ['neu' => $lauf->neu, 'geaendert' => $lauf->geaendert, 'entfernt' => $lauf->entfernt]), $p['id']]);
            q('INSERT INTO abgleich_log (projekt_id, zeit, neu, geaendert, entfernt, meldung) VALUES (?,?,?,?,?,?)', [$p['id'], jetzt_ms(), $lauf->neu, $lauf->geaendert, $lauf->entfernt, 'ok']);
            db()->exec('COMMIT');
            $ergebnis[$q] = ['ok' => true, 'neu' => $lauf->neu, 'geaendert' => $lauf->geaendert, 'entfernt' => $lauf->entfernt] + $info;
        } catch (Throwable $e) {
            db()->exec('ROLLBACK');
            q('INSERT INTO abgleich_log (projekt_id, zeit, meldung) VALUES (?,?,?)', [$p['id'], jetzt_ms(), text_kuerzen('Fehler: ' . $e->getMessage(), 500)]);
            $ergebnis[$q] = ['ok' => false, 'fehler' => $e->getMessage()];
        }
        // Alte Protokollzeilen begrenzen
        q('DELETE FROM abgleich_log WHERE projekt_id = ? AND id NOT IN (SELECT id FROM abgleich_log WHERE projekt_id = ? ORDER BY id DESC LIMIT 200)', [$p['id'], $p['id']]);
        if ($ausgabe) echo $q . ': ' . json_kodiere($ergebnis[$q]) . "\n";
    }
    return $ergebnis;
}

/** JSON-Datei einer Quelle lesen (fehlt sie, kommt $standard) */
function quelle_json(string $datei, $standard = [])
{
    if (!is_file($datei)) return $standard;
    $w = json_decode((string) file_get_contents($datei), true);
    return is_array($w) ? $w : $standard;
}
