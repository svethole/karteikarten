<?php
// Grundlagen: Konfiguration, Datenbank, Anmeldung, Antworten, Lerntag.
declare(strict_types=1);

const APP_WURZEL = __DIR__ . '/..';
const TAG_MS = 86400000;

function config(?string $schluessel = null)
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = [
            'datenbank' => APP_WURZEL . '/data/karteikarten.sqlite',
            'medien' => APP_WURZEL . '/data/medien',
            'standard_benutzer' => 'svetho',
            'sso_benutzer_schluessel' => '',
            'admins' => ['svetho'],
            'zeitzone' => 'Europe/Berlin',
            'quellen' => [
                'its' => '/var/www/its-karteikarten',
                'cz' => '/var/www/tschechischtrainer',
                'it' => '/var/www/italienisch',
                'nl' => '/var/www/nederlands',
            ],
            'trainer_benutzer' => [],
            'ki' => ['schluessel' => '', 'modell' => 'claude-opus-5-5', 'monatslimit' => 300],
            'entwicklung' => false,
        ];
        // KARTEIKARTEN_CONFIG erlaubt Tests mit eigener Konfiguration
        $datei = getenv('KARTEIKARTEN_CONFIG') ?: APP_WURZEL . '/config.php';
        if (is_file($datei)) {
            $eigen = include $datei;
            if (is_array($eigen)) $cfg = array_replace($cfg, $eigen);
        }
        date_default_timezone_set($cfg['zeitzone']);
    }
    return $schluessel === null ? $cfg : ($cfg[$schluessel] ?? null);
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;
    $datei = config('datenbank');
    if (!is_dir(dirname($datei))) @mkdir(dirname($datei), 0750, true);
    $pdo = new PDO('sqlite:' . $datei, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode = WAL; PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 8000; PRAGMA synchronous = NORMAL;');
    require_once __DIR__ . '/schema.php';
    schema_aktualisieren($pdo);
    return $pdo;
}

/** Kurzform für vorbereitete Abfragen */
function q(string $sql, array $p = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($p);
    return $st;
}
function q1(string $sql, array $p = [])
{
    $r = q($sql, $p)->fetch();
    return $r === false ? null : $r;
}
function qwert(string $sql, array $p = [])
{
    $r = q($sql, $p)->fetchColumn();
    return $r === false ? null : $r;
}
function qalle(string $sql, array $p = []): array
{
    return q($sql, $p)->fetchAll();
}

function jetzt_ms(): int
{
    return (int) floor(microtime(true) * 1000);
}

/** Beginn des Lerntags (4 Uhr früh, wie bei Anki und in den Trainern) in Millisekunden */
function tagesbeginn(?int $ms = null): int
{
    $ms = $ms ?? jetzt_ms();
    $d = new DateTime('@' . intdiv($ms - 4 * 3600000, 1000));
    $d->setTimezone(new DateTimeZone(config('zeitzone')));
    $d->setTime(0, 0, 0);
    return $d->getTimestamp() * 1000 + 4 * 3600000;
}
function tagesschluessel(int $ms): string
{
    $d = new DateTime('@' . intdiv(tagesbeginn($ms), 1000));
    $d->setTimezone(new DateTimeZone(config('zeitzone')));
    return $d->format('Y-m-d');
}

function json_kodiere($w): string
{
    return json_encode($w, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
}
function json_lies(?string $s, $standard = [])
{
    if ($s === null || $s === '') return $standard;
    $w = json_decode($s, true);
    return $w === null ? $standard : $w;
}

final class Antwort extends Exception
{
    public int $status;
    public array $daten;
    public function __construct(int $status, array $daten)
    {
        parent::__construct((string) ($daten['fehler'] ?? ''));
        $this->status = $status;
        $this->daten = $daten;
    }
}
function fehler(int $status, string $text): void
{
    throw new Antwort($status, ['ok' => false, 'fehler' => $text]);
}

/* ---------- Anmeldung ---------- */

function benutzer_name_gueltig(string $n): bool
{
    return (bool) preg_match('/^[A-Za-z0-9_.@-]{1,64}$/', $n);
}

/** Name des angemeldeten Kontos. Die svetho-Anmeldung liefert nur „angemeldet“, deshalb mehrere Quellen. */
function benutzer(): string
{
    static $name = null;
    if ($name !== null) return $name;
    $kandidat = (string) ($_SERVER['REMOTE_USER'] ?? $_SERVER['REDIRECT_REMOTE_USER'] ?? $_SERVER['PHP_AUTH_USER'] ?? '');
    if ($kandidat === '') $kandidat = sso_cookie_benutzer() ?? '';
    if ($kandidat === '' && config('entwicklung') && isset($_GET['als'])) $kandidat = (string) $_GET['als'];
    if ($kandidat === '' && config('entwicklung') && isset($_COOKIE['kk_als'])) $kandidat = (string) $_COOKIE['kk_als'];
    if ($kandidat === '') $kandidat = (string) config('standard_benutzer');
    if (!benutzer_name_gueltig($kandidat)) fehler(401, 'Nicht angemeldet.');
    $name = $kandidat;
    q('INSERT OR IGNORE INTO benutzer (name, angelegt) VALUES (?, ?)', [$name, jetzt_ms()]);
    return $name;
}

/** Signiertes Cookie „svetho_wer“ = base64url(name) . "." . hmac_sha256(name), optional vom Portal gesetzt */
function sso_cookie_benutzer(): ?string
{
    $key = (string) config('sso_benutzer_schluessel');
    $c = (string) ($_COOKIE['svetho_wer'] ?? '');
    if ($key === '' || $c === '' || !str_contains($c, '.')) return null;
    [$b64, $sig] = explode('.', $c, 2);
    $n = base64_decode(strtr($b64, '-_', '+/'), true);
    if ($n === false || !hash_equals(hash_hmac('sha256', $n, $key), $sig)) return null;
    return $n;
}

function ist_admin(?string $n = null): bool
{
    return in_array($n ?? benutzer(), (array) config('admins'), true);
}
function admin_pflicht(): void
{
    if (!ist_admin()) fehler(403, 'Nur für Admins.');
}

function benutzer_einstellungen(?string $n = null): array
{
    $e = json_lies(qwert('SELECT einstellungen FROM benutzer WHERE name = ?', [$n ?? benutzer()]), []);
    return array_replace([
        'neu_pro_tag' => 20,
        'max_wdh_pro_tag' => 400,
        'ziel' => 0.9,
        'neu_mischen' => 'gemischt',
        'design' => 'auto',
        'sprachausgabe_auto' => true,
        'genus_effekte' => true,
        'kachel_reihenfolge' => [],
    ], is_array($e) ? $e : []);
}

/* ---------- Kleine Helfer ---------- */

function text_kuerzen(string $s, int $n): string
{
    return mb_strlen($s) > $n ? mb_substr($s, 0, $n) : $s;
}

/** Tags normalisieren: Leerzeichen-getrennt, ohne Doppelte, Unterstrich statt Leerzeichen innerhalb */
function tags_normalisieren($tags): string
{
    if (is_string($tags)) $tags = preg_split('/[\s,]+/u', $tags);
    $aus = [];
    foreach ((array) $tags as $t) {
        $t = trim(preg_replace('/[^\p{L}\p{N}_:.\/-]+/u', '_', (string) $t), '_');
        if ($t !== '' && !in_array(mb_strtolower($t), array_map('mb_strtolower', $aus), true)) $aus[] = text_kuerzen($t, 60);
    }
    return implode(' ', $aus);
}
