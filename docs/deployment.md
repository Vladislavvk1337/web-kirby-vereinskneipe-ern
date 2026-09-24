# Deployment

Die Website läuft auf einem eigenen **Debian-Server**, eingerichtet wie
[astro-web-basic-template](https://github.com/Vladislavvk1337/astro-web-basic-template):
gehärtet mit **basis-schutz-os**, ausgeliefert von **Caddy** mit
automatischem TLS, aktualisiert von einem **systemd-Timer** alle 5 Minuten.
Unterschied zur Astro-Vorlage: Kirby läuft dynamisch über **PHP-FPM**, und
Inhalte, die im Panel gepflegt werden, liegen außerhalb des Programmcodes.

> **HTTPS ist Voraussetzung.** Panel-Anmeldung und Formular nur über HTTPS
> betreiben – Caddy leitet HTTP automatisch um und holt die Zertifikate.

| Baustein | Aufgabe |
| --- | --- |
| **basis-schutz-os** | Admin-Benutzer, SSH nur mit Schlüssel, Firewall, fail2ban, automatische Updates, gehärtetes PHP |
| **Caddy** | TLS, Sicherheits-Header, CSP, gekürzte Zugriffsprotokolle; nur `/assets/` und `/media/` direkt, alles andere über `index.php` |
| **PHP-FPM-Pool `kirby-<domain>`** | eigener Pool mit `open_basedir`, Uploads bis 10 MB, Pfade und Geheimnisse aus der Umgebung |
| **site-update.timer** | alle 5 Minuten: `git fetch`, bei Änderung `composer install`, PHP-Syntax und Unit-Tests, dann Code veröffentlichen |
| **kneipe-cleanup.timer** | täglich: Anfragen nach Ablauf der Löschfrist löschen |
| **kneipe-backup.timer** | täglich: Inhalte, Konten und Konfiguration sichern |

Alle Namen und Adressen stehen in **[`deploy/site.env`](../deploy/site.env)**.

```
GitHub: <OWNER>/<REPO> (main)
   │  git fetch alle 5 min
   ▼
/srv/<PROJECT>/repo       composer install, php -l, Unit-Tests (als <BUILD_USER>)
   │  nur bei Erfolg: rsync (Programmcode)
   ▼
/var/www/<DOMAIN>/app/    index.php, kirby/, vendor/, site/, assets/, bin/
/var/www/<DOMAIN>/data/   content/, media/, storage/ (Konten, Sitzungen, Cache)   ◄── Panel
/var/www/<DOMAIN>/config/ kirby.env (Salt, Cookie-Schlüssel, SMTP)
   ▲
Caddy (TLS) ──► PHP-FPM (kirby-<domain>) ──► Kirby
```

## Voraussetzungen

- **Debian 12** (empfohlen) oder Ubuntu 24.04, 1 GB RAM genügt (kein
  Node-Build), öffentliche IPv4.
- Firewall des Hosters: eingehend 22/tcp, 80/tcp, 443/tcp (443/udp für HTTP/3).
- SSH-Schlüssel für root (`ssh-copy-id root@<server-ip>`), siehe
  Astro-Vorlage, Abschnitt „Voraussetzungen“.
- DNS-Zugriff auf die Domain, ein SMTP-Postfach (z. B. `noreply@<DOMAIN>`).
- Eine **Kirby-Lizenz** für den Livebetrieb (siehe README).

## Einrichtung Schritt für Schritt

### Schritt 1 – `deploy/site.env` ausfüllen (im Repository)

```bash
PROJECT="kneipe"
BRANCH="main"
DOMAIN="<domain.de>"             # ohne www
PREVIEW_HOST="labs.<domain.de>"
LIVE_HOST="www.<domain.de>"
SITE_PHASE="preview"
ACME_EMAIL="admin@<domain.de>"
ADMIN_USER="kneipeadm"
BUILD_USER="kneipebuild"
```

Committen und auf `main` pushen (bzw. `BRANCH` auf den gewünschten Branch
setzen). Die Skripte brechen ab, solange `example.de` eingetragen ist.

### Schritt 2 – DNS für die Vorschau

`<PREVIEW_HOST>` als A/AAAA-Eintrag auf den Server. MX-Einträge nicht ändern.

### Schritt 3 – Git, Build-Benutzer, Repository (als root)

```bash
apt-get update && apt-get install -y git
PROJECT=kneipe
BUILD_USER=kneipebuild
REPO=git@github.com:<OWNER>/<REPO>.git

useradd --system --create-home --home-dir /var/lib/$BUILD_USER --shell /usr/sbin/nologin $BUILD_USER
install -d -m 700 -o $BUILD_USER -g $BUILD_USER /var/lib/$BUILD_USER/.ssh
runuser -u $BUILD_USER -- ssh-keygen -t ed25519 -N '' -C $PROJECT-deploy -f /var/lib/$BUILD_USER/.ssh/id_ed25519
cat /var/lib/$BUILD_USER/.ssh/id_ed25519.pub   # → GitHub: Settings → Deploy keys, nur lesen

install -d -m 755 /srv/$PROJECT
install -d -o $BUILD_USER -g $BUILD_USER /srv/$PROJECT/repo
runuser -u $BUILD_USER -- git -c core.sshCommand='ssh -o StrictHostKeyChecking=accept-new' \
  clone --branch main $REPO /srv/$PROJECT/repo
```

### Schritt 4 – Basisschutz

```bash
/srv/$PROJECT/repo/deploy/basis-schutz.sh
```

Führt die Module base, user, ssh, firewall, fail2ban, updates, sysctl und
php aus (nginx nicht). **Root-Sitzung offen lassen** und in einem zweiten
Terminal `ssh <ADMIN_USER>@<server-ip>` und `sudo -v` prüfen.

### Schritt 5 – Kirby einrichten

```bash
sudo /srv/kneipe/repo/deploy/server-setup.sh
```

Das Skript installiert PHP-Erweiterungen (gd, intl, mbstring, xml, curl,
zip), Composer und Caddy, legt `app/`, `data/` und `config/` an, erzeugt
**`config/kirby.env` mit zufälligem `KIRBY_CONTENT_SALT` und
`KIRBY_COOKIE_KEY`**, schreibt den PHP-FPM-Pool und das Caddyfile, installiert
Timer und veröffentlicht zum ersten Mal. Beim ersten Mal wird `content/`
aus dem Repository als Startinhalt übernommen.

### Schritt 6 – SMTP eintragen

```bash
sudo -u kneipeadm nano /var/www/<DOMAIN>/config/kirby.env
```

```bash
KIRBY_SMTP_HOST=smtp.<anbieter>.de
KIRBY_SMTP_PORT=587
KIRBY_SMTP_SECURITY=tls
KIRBY_SMTP_USER=noreply@<DOMAIN>
KIRBY_SMTP_PASSWORD=…
KIRBY_MAIL_FROM=noreply@<DOMAIN>      # muss zum SMTP-Konto passen (SPF/DMARC)
KIRBY_MAIL_FROM_NAME="Ehrenamtskneipe Erndtebrück"
```

Danach `sudo systemctl reload php*-fpm`. Die Datei gehört nie ins Repository.

### Schritt 7 – Erster Administrator, Moderatoren

```bash
sudo kneipe-cli create-user --email=vorname@<DOMAIN> --name="Vorname Nachname" --role=admin
sudo kneipe-cli create-user --email=… --name="…" --role=moderator
```

Das Passwort wird abgefragt (mindestens 12 Zeichen). Weitere Konten kann die
Administration auch im Panel unter **Accounts** anlegen. Für jede Person
ein eigenes Konto. Das Panel lässt die Installation über den Browser im
Livebetrieb bewusst nicht zu (`panel.install = false`).

Dann im Panel: **Übersicht → Stammdaten** ausfüllen, **Einstellungen →
Empfänger der Terminanfragen** setzen und die Demo-Inhalte ersetzen oder
löschen (siehe README, „Demo-Inhalte“).

### Schritt 8 – Prüfen

```bash
curl -sI http://<PREVIEW_HOST>                          # 308 → https
curl -sI https://<PREVIEW_HOST>/                        # 200, X-Robots-Tag: noindex, CSP
curl -sI https://<PREVIEW_HOST>/gibtsnicht              # 404
curl -sI https://<PREVIEW_HOST>/content/site.txt        # 404
curl -sI https://<PREVIEW_HOST>/site/config/config.php  # 404
curl -sI https://<PREVIEW_HOST>/anfragen                # 404
curl -s  https://<PREVIEW_HOST>/termine.ics | head -3   # BEGIN:VCALENDAR
systemctl list-timers 'site-update*' 'kneipe-*'
journalctl -u site-update -n 20 --no-pager
```

Dann eine Testanfrage über `/termin-anfragen` senden: Weiterleitung auf
`/termin-anfragen/danke`, Mail an die Redaktion, Anfrage im Panel.

## Betrieb im Alltag

- **Code-Änderungen:** auf `main` pushen – spätestens 5 Minuten später online.
  Schlägt `composer install`, `php -l` oder ein Unit-Test fehl, bleibt die
  bisherige Version online (`journalctl -u site-update`).
- **Inhalte** werden nur im Panel gepflegt. Änderungen an `content/` im
  Repository erreichen den Server nicht (nur beim allerersten Deployment).
- Sofort veröffentlichen: `sudo FORCE=1 site-update`.
- **Änderungen in `deploy/`** meldet das Journal; übernehmen mit
  `sudo /srv/kneipe/repo/deploy/server-setup.sh`.
- **basis-schutz-os** wird per Workflow gespiegelt (`deploy/basis-schutz-os/`
  nicht von Hand ändern); anwenden mit `sudo deploy/basis-schutz.sh`.
  Einrichtung des Tokens: Astro-Vorlage, `Deployment.md`, Abschnitt
  „basis-schutz-os aktuell halten“.

## Updates (Kirby, PHP) – erst auf Staging

1. Lokal oder auf einem Branch: `composer update getkirby/cms`, dann
   `composer test` und die Browser-Prüfungen (README, „Tests“).
2. Auf einem **Staging-Server** (eigene `site.env` mit `BRANCH=staging`,
   eigene Domain, Phase `preview`) mit einer Kopie der Daten testen:
   Panel-Anmeldung, Termin anlegen und veröffentlichen, Anfrage senden.
3. Erst dann auf `main` mergen. Vorher eine Sicherung ziehen
   (`sudo kneipe-backup`).

Kirby-Versionshinweise lesen: https://getkirby.com/releases

## Livegang

1. Impressum, Datenschutz und Barrierefreiheit ohne Platzhalter und mit
   Prüfhinweis „Nein, Text ist geprüft“; Stammdaten vollständig.
2. Kirby-Lizenz im Panel aktivieren.
3. DNS von `<DOMAIN>` und `<LIVE_HOST>` auf den Server (MX/SPF/DKIM nicht
   anfassen).
4. `SITE_PHASE="live"` in `deploy/site.env`, pushen, dann
   `sudo /srv/kneipe/repo/deploy/server-setup.sh` (setzt `KIRBY_URL` und
   `KIRBY_NOINDEX=false` im PHP-Pool, Caddy live).
5. Prüfen: `curl -sI https://<LIVE_HOST>/` ohne `X-Robots-Tag`,
   `/robots.txt` mit Sitemap.
6. HSTS erst aktivieren, wenn HTTPS dauerhaft steht
   (`deploy/caddy/Caddyfile`).

## Sicherheits-Header und CSP

Gesetzt in `deploy/caddy/Caddyfile`:

| Header | Wert |
| --- | --- |
| Content-Security-Policy (Website) | `default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; frame-src 'none'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'` |
| Content-Security-Policy (Panel/API) | zusätzlich `'unsafe-inline' 'unsafe-eval'` für Skripte und Styles, `blob:` für Bilder (Kirby-Panel/Vue) |
| X-Content-Type-Options | `nosniff` |
| X-Frame-Options | `SAMEORIGIN` |
| Referrer-Policy | `strict-origin-when-cross-origin` |
| Permissions-Policy | Kamera, Mikrofon, Standort, Zahlung, USB aus |
| Cross-Origin-Opener-Policy | `same-origin` |
| Strict-Transport-Security | vorbereitet, auskommentiert |
| X-Robots-Tag | `noindex, nofollow` in der Vorschau |

Die Website kommt ohne Inline-Skripte und Inline-Styles aus (automatisch
geprüft in `tests/http/html.php`).

## Anderes Hosting (Apache/Shared Hosting)

Die mitgelieferte `.htaccess` (Kirby-Standard) sperrt `content/`, `site/`,
`kirby/` und versteckte Dateien. Dann Plainkit-Struktur im Webroot, PHP ≥ 8.2
mit gd/intl, HTTPS beim Hoster aktivieren, Umgebungsvariablen per `.env`
im Projektverzeichnis (nicht im Webroot erreichbar, `.htaccess` sperrt
Punktdateien). Security-Header dann per `.htaccess` ergänzen.

## Referenz

| Datei | Zweck |
| --- | --- |
| `deploy/site.env` | Projekt, Domain, Adressen, Phase, Benutzer, Sicherung |
| `deploy/basis-schutz.env`, `basis-schutz.sh`, `basis-schutz-os/` | Serverhärtung |
| `deploy/server-setup.sh` | Einrichtung (idempotent) |
| `deploy/site-update.sh` | → `/usr/local/bin/site-update` |
| `deploy/kneipe-cli.sh` | → `/usr/local/bin/kneipe-cli` (Benutzer anlegen, Löschfrist) |
| `deploy/backup.sh` | → `/usr/local/bin/kneipe-backup` |
| `deploy/php/kirby-pool.conf` | PHP-FPM-Pool |
| `deploy/caddy/*` | Caddyfile-Vorlage und Site-Blöcke je Phase |
| `deploy/systemd/*` | Service und Timer |
| `deploy/logrotate-caddy` | Zugriffsprotokolle nach 14 Tagen löschen |

| Benutzer | Aufgabe |
| --- | --- |
| `<ADMIN_USER>` | SSH, sudo, besitzt `app/` und `config/` |
| `<BUILD_USER>` | holt Code, `composer install`, Tests – ohne Login und sudo |
| `www-data` | PHP-FPM; liest `app/`, schreibt nur `data/` |
| `caddy` | liest `app/assets` und `data/media` über die Gruppe `www-data` |

## Fehlersuche

| Symptom | Lösung |
| --- | --- |
| 502 Bad Gateway | PHP-FPM-Pool läuft nicht: `systemctl status php*-fpm`, `ls /run/php/`, `/var/log/php-fpm-kirby-*.log` |
| „Seite zeigt alten Stand“ | `journalctl -u site-update` – Test oder composer fehlgeschlagen; `sudo FORCE=1 site-update` |
| Panel meldet, dass die Installation deaktiviert ist | Richtig so – ersten Administrator mit `sudo kneipe-cli create-user …` anlegen |
| Bilder fehlen/Vorschaubilder 404 | Rechte von `data/media` (www-data), `php*-gd` installiert? |
| Formular: keine Mail | SMTP in `config/kirby.env`, `KIRBY_MAIL_FROM` passend zum Konto; Anfrage ist trotzdem im Panel gespeichert |
| Upload im Panel scheitert | Datei > 10 MB oder falscher Typ (Blueprint `files/image.yml`) |
| Weitere Punkte | siehe Astro-Vorlage, `Deployment.md`, „Fehlersuche“ (SSH, fail2ban, DNS, Zertifikate) |
