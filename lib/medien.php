<?php
// Medien: Hochladen und Ausliefern eigener Bilder/Töne, Dateien aus den Trainern, Bildsuche, Sprachausgabe, Wörterbuch.
declare(strict_types=1);

const MEDIEN_TYPEN = [
    'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif', 'image/svg+xml' => 'svg',
    'audio/mpeg' => 'mp3', 'audio/ogg' => 'ogg', 'audio/wav' => 'wav', 'audio/x-wav' => 'wav', 'audio/mp4' => 'm4a', 'audio/webm' => 'webm',
];
const MEDIEN_MAX = 15 * 1024 * 1024;

function medien_ordner(): string
{
    $d = (string) config('medien');
    if (!is_dir($d)) @mkdir($d, 0750, true);
    return $d;
}

/** Inhalt prüfen und speichern. Gleiche Dateien werden nur einmal abgelegt (Name = Prüfsumme). */
function medien_ablegen(string $inhalt, string $name = '', string $nachweis = ''): array
{
    if (strlen($inhalt) > MEDIEN_MAX) fehler(413, 'Die Datei ist zu groß (höchstens 15 MB).');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($inhalt) ?: '';
    if ($mime === 'text/xml' || $mime === 'text/plain' || $mime === 'image/svg') {
        if (preg_match('/<svg[\s>]/i', substr($inhalt, 0, 2000))) $mime = 'image/svg+xml';
    }
    if (!isset(MEDIEN_TYPEN[$mime])) fehler(415, 'Dieser Dateityp wird nicht unterstützt (' . $mime . ').');
    if ($mime === 'image/svg+xml' && preg_match('/<script|on[a-z]+\s*=|javascript:/i', $inhalt)) fehler(415, 'SVG mit Skripten wird nicht angenommen.');
    $id = substr(hash('sha256', $inhalt), 0, 32) . '.' . MEDIEN_TYPEN[$mime];
    $datei = medien_ordner() . '/' . $id;
    if (!is_file($datei)) {
        $tmp = $datei . '.tmp' . bin2hex(random_bytes(4));
        file_put_contents($tmp, $inhalt);
        rename($tmp, $datei);
    }
    q('INSERT OR IGNORE INTO medien (id, besitzer, mime, groesse, name, nachweis, angelegt) VALUES (?,?,?,?,?,?,?)',
        [$id, benutzer(), $mime, strlen($inhalt), text_kuerzen($name, 200), text_kuerzen($nachweis, 300), jetzt_ms()]);
    if ($nachweis !== '') q('UPDATE medien SET nachweis = ? WHERE id = ? AND nachweis = \'\'', [text_kuerzen($nachweis, 300), $id]);
    return ['ok' => true, 'id' => $id, 'verweis' => 'medien:' . $id, 'mime' => $mime];
}

function medien_hochladen(): array
{
    $f = $_FILES['datei'] ?? null;
    if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK) fehler(400, 'Keine Datei angekommen.');
    return medien_ablegen((string) file_get_contents($f['tmp_name']), (string) ($f['name'] ?? ''), (string) ($_POST['nachweis'] ?? ''));
}

/** Datei ausliefern, mit Teilabrufen (Range) für Audio und langem Zwischenspeichern */
function datei_senden(string $pfad, string $mime, bool $privat = true): void
{
    if (!is_file($pfad)) { http_response_code(404); exit; }
    $groesse = filesize($pfad);
    $etag = '"' . substr(md5($pfad . filemtime($pfad) . $groesse), 0, 16) . '"';
    header('Content-Type: ' . $mime);
    header('Cache-Control: ' . ($privat ? 'private' : 'public') . ', max-age=2592000');
    header('ETag: ' . $etag);
    header('Accept-Ranges: bytes');
    header('X-Content-Type-Options: nosniff');
    if ($mime === 'image/svg+xml') header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src data:");
    if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) { http_response_code(304); exit; }
    $start = 0;
    $ende = $groesse - 1;
    if (preg_match('/bytes=(\d*)-(\d*)/', (string) ($_SERVER['HTTP_RANGE'] ?? ''), $m)) {
        if ($m[1] === '' && $m[2] !== '') { $start = max(0, $groesse - (int) $m[2]); }
        else { $start = (int) $m[1]; if ($m[2] !== '') $ende = min($ende, (int) $m[2]); }
        if ($start > $ende || $start >= $groesse) { http_response_code(416); header("Content-Range: bytes */$groesse"); exit; }
        http_response_code(206);
        header("Content-Range: bytes $start-$ende/$groesse");
    }
    header('Content-Length: ' . ($ende - $start + 1));
    $fh = fopen($pfad, 'rb');
    fseek($fh, $start);
    $rest = $ende - $start + 1;
    while ($rest > 0 && !feof($fh)) {
        $stueck = fread($fh, min(65536, $rest));
        echo $stueck;
        $rest -= strlen($stueck);
    }
    fclose($fh);
    exit;
}

function medien_ausliefern(string $id): void
{
    if (!preg_match('/^[a-f0-9]{16,64}\.[a-z0-9]{2,5}$/', $id)) { http_response_code(404); exit; }
    $mime = qwert('SELECT mime FROM medien WHERE id = ?', [$id]);
    if (!$mime) { http_response_code(404); exit; }
    datei_senden(medien_ordner() . '/' . $id, $mime);
}

/** Dateien aus den Trainer-Repos: nur bestimmte Ordner und Endungen */
const QUELLE_ORDNER = [
    'its' => ['public/vorschau/', 'public/karteikarten/'],
    'cz' => ['img/', 'photos/', 'media/', 'audio/'],
    'it' => ['img/', 'photos/', 'media/', 'audio/', 'illus/'],
    'nl' => ['img/', 'photos/', 'media/', 'audio/', 'illus/'],
];
const QUELLE_TYPEN = ['svg' => 'image/svg+xml', 'webp' => 'image/webp', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'ogg' => 'audio/ogg'];

function quelle_pfad(string $q, string $pfad): ?string
{
    $basis = ((array) config('quellen'))[$q] ?? null;
    if (!$basis || !isset(QUELLE_ORDNER[$q]) || str_contains($pfad, '..') || !preg_match('#^[A-Za-z0-9/_. -]+$#', $pfad)) return null;
    $erlaubt = false;
    foreach (QUELLE_ORDNER[$q] as $o) if (str_starts_with($pfad, $o)) $erlaubt = true;
    $endung = strtolower(pathinfo($pfad, PATHINFO_EXTENSION));
    if (!$erlaubt || !isset(QUELLE_TYPEN[$endung])) return null;
    $voll = rtrim($basis, '/') . '/' . $pfad;
    return is_file($voll) ? $voll : null;
}

function quelle_ausliefern(string $q, string $pfad): void
{
    $voll = quelle_pfad($q, $pfad);
    if (!$voll) { http_response_code(404); exit; }
    // Nur wer ein Projekt dieser Quelle sehen darf
    $pid = qwert('SELECT id FROM projekte WHERE quelle = ?', [$q]);
    if (!$pid) { http_response_code(404); exit; }
    projekt_pruefen((int) $pid);
    datei_senden($voll, QUELLE_TYPEN[strtolower(pathinfo($voll, PATHINFO_EXTENSION))]);
}

/* ---------- Bildsuche über den Server (keine Anfragen vom Browser an fremde Dienste) ---------- */

function http_holen(string $url, int $max = MEDIEN_MAX, array $kopf = []): array
{
    $ch = curl_init($url);
    $daten = '';
    curl_setopt_array($ch, [
        CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_USERAGENT => 'Karteikarten/1.0 (persoenliche Lernanwendung)',
        CURLOPT_HTTPHEADER => $kopf,
        CURLOPT_WRITEFUNCTION => function ($ch, $teil) use (&$daten, $max) {
            $daten .= $teil;
            return strlen($daten) > $max ? 0 : strlen($teil);
        },
    ]);
    // Interne Netze sperren (keine Anfragen an den eigenen Server oder das Heimnetz)
    $host = parse_url($url, PHP_URL_HOST) ?: '';
    $ip = gethostbyname($host);
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) fehler(400, 'Diese Adresse ist nicht erlaubt.');
    curl_setopt($ch, CURLOPT_RESOLVE, [$host . ':443:' . $ip, $host . ':80:' . $ip]);
    curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$status, $daten];
}

function bildsuche(string $suche, string $quelle): array
{
    $suche = trim(text_kuerzen($suche, 100));
    if ($suche === '') return ['ok' => true, 'treffer' => []];
    $treffer = [];
    if ($quelle === 'openverse') {
        [$st, $roh] = http_holen('https://api.openverse.org/v1/images/?page_size=24&q=' . rawurlencode($suche), 2000000);
        foreach ((json_lies($roh, [])['results'] ?? []) as $r) {
            $treffer[] = ['vorschau' => $r['thumbnail'] ?? $r['url'], 'url' => $r['url'], 'titel' => $r['title'] ?? '',
                'nachweis' => trim(($r['creator'] ?? '') . ', ' . strtoupper((string) ($r['license'] ?? '')) . ' ' . ($r['license_version'] ?? '') . ', Openverse', ', ')];
        }
    } else {
        $url = 'https://commons.wikimedia.org/w/api.php?action=query&format=json&generator=search&gsrnamespace=6&gsrlimit=24&prop=imageinfo&iiprop=url|extmetadata&iiurlwidth=400&gsrsearch='
            . rawurlencode($suche . ' filetype:bitmap|drawing');
        [$st, $roh] = http_holen($url, 3000000);
        foreach ((json_lies($roh, [])['query']['pages'] ?? []) as $p) {
            $ii = $p['imageinfo'][0] ?? null;
            if (!$ii) continue;
            $meta = $ii['extmetadata'] ?? [];
            $autor = trim(strip_tags((string) ($meta['Artist']['value'] ?? '')));
            $lizenz = (string) ($meta['LicenseShortName']['value'] ?? '');
            $treffer[] = ['vorschau' => $ii['thumburl'] ?? $ii['url'], 'url' => $ii['thumburl'] ? preg_replace('#/\d+px-#', '/1200px-', $ii['thumburl']) : $ii['url'],
                'titel' => preg_replace('/^File:|\.[a-z]+$/i', '', (string) $p['title']),
                'nachweis' => trim(text_kuerzen($autor, 80) . ', ' . $lizenz . ', Wikimedia Commons', ', ')];
        }
    }
    return ['ok' => true, 'treffer' => $treffer];
}

/** Bild per Adresse übernehmen (aus der Bildsuche oder eingefügt) */
function bild_von_adresse(string $url, string $nachweis): array
{
    if (!preg_match('#^https?://#i', $url)) fehler(400, 'Bitte eine vollständige Adresse (https://…) angeben.');
    [$status, $daten] = http_holen($url);
    if ($status < 200 || $status >= 300 || $daten === '') fehler(502, 'Das Bild konnte nicht geladen werden (' . $status . ').');
    return medien_ablegen($daten, basename((string) parse_url($url, PHP_URL_PATH)), $nachweis);
}

/* ---------- Sprachausgabe über die Serverstimmen der Trainer ---------- */

const SPRACHE_QUELLE = ['cs' => 'cz', 'it' => 'it', 'nl' => 'nl'];

function tts_lib_laden(string $sprache): ?string
{
    $q = SPRACHE_QUELLE[$sprache] ?? null;
    $basis = $q ? (((array) config('quellen'))[$q] ?? null) : null;
    if (!$basis || !is_file($basis . '/tools/tts-lib.php')) return null;
    require_once $basis . '/tools/tts-lib.php';
    return $basis;
}

function tts_stimmen(string $sprache): array
{
    if (!tts_lib_laden($sprache)) return ['ok' => true, 'stimmen' => []];
    $cfg = tts_config();
    $aus = [];
    foreach (tts_voices($cfg) as $id => $v) $aus[] = ['id' => $id, 'name' => $v['label']];
    return ['ok' => true, 'stimmen' => $aus];
}

function tts_ausliefern(string $sprache, string $text, string $stimme, bool $langsam): void
{
    if (!tts_lib_laden($sprache)) fehler(404, 'Für diese Sprache gibt es keine Serverstimme.');
    $cfg = tts_config();
    if (!tts_voices($cfg)) fehler(503, 'Keine Serverstimme bereit.');
    $voice = tts_voice($cfg, $stimme);
    $text = tts_norm($text);
    if ($text === '' || mb_strlen($text) > (int) ($cfg['max_chars'] ?? 300)) fehler(400, 'Text leer oder zu lang.');
    $err = tts_sicherstellen($cfg, $voice, $text, $langsam);
    if ($err) fehler(503, $err);
    datei_senden(tts_datei($cfg, $voice, $text, $langsam), 'audio/wav');
}

/** Wörterbuch eines Trainers (Grundformen mit Formentabellen) für antippbare Wörter */
function lexikon(string $sprache): array
{
    $q = SPRACHE_QUELLE[$sprache] ?? null;
    $basis = $q ? (((array) config('quellen'))[$q] ?? null) : null;
    if (!$basis) return ['ok' => true, 'eintraege' => [], 'glossar' => (object) []];
    $pid = qwert('SELECT id FROM projekte WHERE quelle = ?', [$q]);
    if ($pid) projekt_pruefen((int) $pid);
    $lx = json_lies(@file_get_contents($basis . '/lexicon.json') ?: null, []);
    $eintraege = [];
    foreach ((array) ($lx['entries'] ?? []) as $e) {
        $eintraege[] = ['lemma' => $e['lemma'] ?? '', 'de' => $e['de'] ?? '', 'pos' => $e['pos'] ?? '', 'gender' => $e['gender'] ?? '',
            'summary' => $e['summary'] ?? '', 'note' => $e['note'] ?? '', 'aliases' => $e['aliases'] ?? [], 'tables' => $e['tables'] ?? []];
    }
    // Glossar der Lektionen (Italienisch/Niederländisch): Form → {l, de, p, f, n}
    $glossar = [];
    if ($q !== 'cz') {
        $kurs = json_lies(@file_get_contents($basis . '/course.json') ?: null, []);
        foreach ((array) ($kurs['courses'] ?? []) as $c) foreach ($c['units'] ?? [] as $u) foreach ($u['lessons'] ?? [] as $l) {
            if (empty($l['file'])) continue;
            $j = json_lies(@file_get_contents($basis . '/' . $l['file']) ?: null, []);
            foreach ((array) ($j['gloss'] ?? []) as $form => $g) {
                $k = mb_strtolower((string) $form);
                if (!isset($glossar[$k])) $glossar[$k] = $g;
            }
        }
    }
    return ['ok' => true, 'eintraege' => $eintraege, 'glossar' => (object) $glossar];
}
