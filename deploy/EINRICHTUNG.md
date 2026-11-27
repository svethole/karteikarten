# Einrichtung auf dem Server (svetho-VPS, Debian, nginx + PHP-FPM)

Die Einrichtung folgt den Sprachkursen (`italiano.svetho.net`, `nederlands.svetho.net`):

- Code in `/var/www/karteikarten` (gehört root), geklont über einen Deploy-Key.
- nginx liefert nur `public/` aus, PHP läuft nur für `public/api.php`.
- Zugang nur über die **gemeinsame svetho-Anmeldung**.
- Daten (SQLite-Datenbank, hochgeladene Bilder) in `/var/www/karteikarten/data/` (www-data).
- Der Abgleich mit den Trainern liest direkt deren Ordner unter `/var/www/` (nur lesend; Sprachausgabe schreibt in deren `tts/`-Zwischenspeicher).

| Was | Wo |
|---|---|
| Code | `/var/www/karteikarten` (gehört root) |
| Datenbank, Bilder | `/var/www/karteikarten/data/` (www-data) |
| Einstellungen | `/var/www/karteikarten/config.php` (nicht im Repo) |
| nginx | `/etc/nginx/sites-available/karteikarten.svetho.net` |
| Abgleich alle 15 Min. | `karteikarten-abgleich.timer` (systemd) |

---

### 1. PHP-Erweiterungen prüfen

```bash
php -m | grep -E -i 'pdo_sqlite|sqlite3|mbstring|curl|zip|intl|fileinfo'
```

Fehlt etwas: `sudo apt install php-sqlite3 php-mbstring php-curl php-zip php-intl` und danach `sudo systemctl restart php*-fpm`.

### 2. DNS

```bash
dig +short karteikarten.svetho.net   # muss dieselbe IP liefern wie dig +short nederlands.svetho.net
```

Falls leer: bei manitu einen A-Eintrag (und AAAA, falls genutzt) `karteikarten` anlegen.

### 3. Deploy-Key (einmalig)

```bash
sudo ssh-keygen -t ed25519 -N "" -C "deploy karteikarten" -f /root/.ssh/deploy_karteikarten
sudo cat /root/.ssh/deploy_karteikarten.pub
# eintragen unter https://github.com/svethole/karteikarten/settings/keys/new („Allow write access“ NICHT anhaken)

sudo tee -a /root/.ssh/config >/dev/null <<'EOT'

Host github-karteikarten
    HostName github.com
    User git
    IdentityFile /root/.ssh/deploy_karteikarten
    IdentitiesOnly yes
EOT
sudo chmod 600 /root/.ssh/config
sudo ssh -T git@github-karteikarten
```

### 4. Klonen, Rechte, Einstellungen

```bash
cd /var/www
sudo git clone -b main git@github-karteikarten:svethole/karteikarten.git karteikarten
cd karteikarten
sudo chown -R www-data:www-data data
sudo chmod 750 data
sudo cp config.beispiel.php config.php
sudo nano config.php
```

In `config.php` wichtig:

- `quellen`: Pfade der Trainer (Standard: `/var/www/its-karteikarten`, `/var/www/tschechischtrainer`, `/var/www/italienisch`, `/var/www/nederlands`). Fehlt ein Ordner, gibt es das Projekt einfach nicht.
- `trainer_benutzer`: Die Sprachkurse speichern den Fortschritt unter dem in nginx fest eingetragenen Namen (Italienisch und Niederländisch: `lukas`). Ohne Eintrag nimmt die App für Admins die einzige Fortschrittsdatei eines Trainers. Sicher ist: `'svetho' => ['cz' => '…', 'it' => 'lukas', 'nl' => 'lukas']`.
- `ki.schluessel`: Anthropic-Schlüssel für die Claude-Vorschläge (kann derselbe wie in `/var/www/tschechischtrainer/ki.config.php` sein). Leer = aus.

Dann den ersten Abgleich laufen lassen (legt die Datenbank und die eingebauten Projekte an):

```bash
sudo -u www-data php bin/abgleich.php
```

Die Ausgabe zeigt je Projekt die Zahl der Karten und, ob der Lernstand aus dem Trainer übernommen wurde (`"uebernommen": …`).

### 5. nginx und Zertifikat

```bash
sudo cp deploy/karteikarten.svetho.net.conf /etc/nginx/sites-available/karteikarten.svetho.net
ls /run/php/                     # Socket prüfen; gibt es php-fpm.sock, passt die Vorlage
sudo ln -s /etc/nginx/sites-available/karteikarten.svetho.net /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
sudo certbot --nginx -d karteikarten.svetho.net     # Frage nach Umleitung: 2 (Redirect)
```

### 6. Automatischer Abgleich

```bash
sudo cp deploy/karteikarten-abgleich.service deploy/karteikarten-abgleich.timer /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now karteikarten-abgleich.timer
systemctl list-timers | grep karteikarten
```

Protokoll: `journalctl -u karteikarten-abgleich -n 20`.

### 7. Testen

- <https://karteikarten.svetho.net> ohne Anmeldung → Umleitung zum Portal.
- Angemeldet: Übersicht mit vier Kacheln (ITS, Tschechisch, Italienisch, Niederländisch).
- Eine Lektion im Sprachtrainer abschließen, im Projekt „Jetzt abgleichen“ → das Lektionsdeck ist freigeschaltet.

### 8. Portal-Kachel

In `svetho-portal/public/portal/projekte.json` eine Kachel für `https://karteikarten.svetho.net` ergänzen, dann:

```bash
cd /var/www/svetho-portal && sudo git pull
sudo systemctl start svetho-vorschau.service
```

---

## Mehrere Konten

Die svetho-Anmeldung setzt ein gemeinsames Cookie und verrät der App nicht, **wer** angemeldet ist. Solange das so bleibt,
sind alle Angemeldeten das Konto `standard_benutzer`. Für echte Mehrbenutzer-Nutzung kann das Portal beim Anmelden
zusätzlich ein signiertes Cookie `svetho_wer` setzen (`base64url(name) . "." . hmac_sha256(name, schlüssel)`); denselben Schlüssel
trägst Du dann in `config.php` unter `sso_benutzer_schluessel` ein. Freigaben der eingebauten Projekte verwaltet der Admin
im Projekt unter „Einstellungen“.

## Aktualisieren

```bash
cd /var/www/karteikarten && sudo ./aktualisieren.sh
```

## Sicherung

Alles Wichtige liegt in `data/` (Datenbank `karteikarten.sqlite` samt `-wal`, Ordner `medien/`). Für eine saubere Kopie im laufenden Betrieb:

```bash
sudo -u www-data php -r '$p=new PDO("sqlite:/var/www/karteikarten/data/karteikarten.sqlite"); $p->exec("VACUUM INTO \"/var/www/karteikarten/data/sicherung.sqlite\"");'
```
