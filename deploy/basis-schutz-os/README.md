# basis-schutz-os

Basisschutz für einen frischen **Debian 12+/Ubuntu 22.04+** Server, der eine
**statische Astro-Webseite** ausliefert und optional **PHP für den E-Mail-Versand**
(z. B. Kontaktformular mit PHPMailer oder Symfony Mailer) bereitstellt.

## Was wird eingerichtet?

| Modul      | Inhalt |
|------------|--------|
| `base`     | System-Upgrade, Grundpakete, Zeitzone |
| `user`     | Admin-Benutzer **`keiladm`** mit sudo; übernimmt `authorized_keys` **und** die Schlüsselpaare (`/root/.ssh/id_*`, privat + öffentlich) von root; Root-Passwort wird gesperrt |
| `ssh`      | **Kein Root-Login**, kein Passwort-Login, nur Public-Key, nur `keiladm` (`AllowUsers`), schwache DH-Moduli entfernt |
| `firewall` | UFW: eingehend alles zu außer **22/tcp** (mit Rate-Limit), **80/tcp**, **443/tcp** – IPv4 + IPv6 |
| `fail2ban` | Sperrt IPs nach 3 fehlgeschlagenen SSH-Logins (1 h, bei Wiederholung steigend bis 1 Woche) |
| `updates`  | Automatische Sicherheitsupdates (unattended-upgrades) |
| `sysctl`   | Kernel-/Netzwerk-Härtung (Anti-Spoofing, SYN-Cookies, keine Redirects, …) |
| `php`      | PHP-FPM mit eigenem Pool, `open_basedir`, gesperrten Funktionen (`exec`, `system`, …), Composer + Mail-Pakete |
| `nginx`    | Webserver für Astro, Let's Encrypt (automatische Verlängerung), HTTP→HTTPS, Security-Header, Rate-Limit für die API |

## Schnellstart

Voraussetzung: Der Server wurde mit deinem SSH-Key erstellt (der Hoster legt ihn in
`/root/.ssh/authorized_keys` ab). Für TLS muss der DNS-Eintrag der Domain bereits
auf den Server zeigen.

```bash
# 1. Als root auf den neuen Server
ssh root@<server-ip>

# 2. Repository holen
apt-get update && apt-get install -y git
git clone https://github.com/vladislavvk1337/basis-schutz-os.git
cd basis-schutz-os

# 3. Konfiguration anpassen (mindestens DOMAIN und LETSENCRYPT_EMAIL)
cp config.env.example config.env
nano config.env

# 4. Ausführen – fragt nach einem sudo-Passwort für keiladm
./setup.sh
```

> ⚠️ **Die Root-Sitzung offen lassen**, bis in einem zweiten Terminal
> `ssh keiladm@<server-ip>` und `sudo -v` funktionieren. Danach ist
> `ssh root@…` gesperrt.

Anschließend das Repository z. B. nach `/home/keiladm/basis-schutz-os` verschieben
oder löschen und bei Bedarf neu klonen.

Einzelne Module lassen sich gezielt (erneut) ausführen – das Skript ist idempotent:

```bash
sudo ./setup.sh --list          # Übersicht
sudo ./setup.sh nginx           # z. B. nach DNS-Umstellung Zertifikat nachholen
sudo ./setup.sh ssh firewall
```

### Unbeaufsichtigt (z. B. per cloud-init)

Ohne Terminal kann das Skript kein Passwort abfragen. Dann entweder einen Hash
hinterlegen …

```bash
openssl passwd -6          # Ausgabe in config.env: ADMIN_PASSWORD_HASH='$6$…'
```

… oder `SUDO_NOPASSWD="true"` setzen (bequemer, aber weniger sicher).

## Prüfen

```bash
sudo ./scripts/check.sh
```

Zeigt SSH-Einstellungen, Firewall-Regeln, laufende Dienste und alle lauschenden Ports.

## Verzeichnisstruktur auf dem Server

```
/var/www/<domain>/
├── site/              ← Astro-Build (dist/)            → https://<domain>/
└── api/
    ├── public/        ← PHP-Endpunkte                  → https://<domain>/api/<name>.php
    │   └── contact.php
    ├── vendor/        ← Composer-Pakete (nicht öffentlich)
    ├── composer.json
    └── config.php     ← SMTP-Zugangsdaten (nicht öffentlich, chmod 640)
```

- Nur Dateien **direkt** in `api/public/` sind als `/api/<name>.php` erreichbar.
- Außerhalb von `/api/` wird **nie** PHP ausgeführt; `.php`-Dateien in `site/` liefern 404.
- Versteckte Dateien (`.git`, `.env`, …) liefern 404.
- `/api/*.php` ist auf 5 Anfragen/Minute pro IP begrenzt (`API_RATE`), Wiederholungstäter sperrt fail2ban.
- Aufrufe über die nackte IP oder fremde Hostnamen werden verworfen.

## E-Mail-Versand mit PHP

Standardmäßig wird **PHPMailer** installiert und ein fertiger Endpunkt
[`contact.php`](examples/php/contact.php) angelegt (Honeypot, Origin-Prüfung,
Validierung, Versand per SMTP). Danach nur noch die SMTP-Daten eintragen:

```bash
sudo -u keiladm nano /var/www/<domain>/api/config.php
```

Andere Pakete über `PHP_COMPOSER_PACKAGES` in `config.env`, z. B.:

| Paket                  | Hinweis |
|------------------------|---------|
| `phpmailer/phpmailer`  | Klassiker, sehr einfach, SMTP/TLS – Beispiel liegt bei |
| `symfony/mailer`       | Modern, DSN-basiert (`smtp://user:pass@host:587`), gut für Twig-Templates |

Der Versand läuft über **SMTP** (Port 587/465 ausgehend). Ein lokaler Mailserver
wird bewusst **nicht** installiert – das hält Port 25 zu und vermeidet Spam-Probleme.
Für gute Zustellbarkeit SPF/DKIM/DMARC der Absender-Domain beim Mail-Anbieter einrichten.

### Astro-Formular

[`examples/astro/ContactForm.astro`](examples/astro/ContactForm.astro) in das
Astro-Projekt kopieren (z. B. `src/components/`) und einbinden. Das Formular
funktioniert mit JavaScript (fetch/JSON) und ohne (POST + Weiterleitung auf `/danke/`).

## Deploy der Astro-Seite

Lokal (nicht auf dem Server) ausführen:

```bash
./scripts/deploy.sh keiladm@<server-ip> example.de ~/projekte/meine-astro-seite
# mit eigenen PHP-Endpunkten aus ./api/public:
API_DIR=./api ./scripts/deploy.sh keiladm@<server-ip> example.de .
```

Baut die Seite (`npm run build`) und synchronisiert `dist/` per rsync.

## Content-Security-Policy

Die CSP steht in `config.env` (`CSP=…`). Sie erlaubt standardmäßig nur Ressourcen
der eigenen Domain. Wer externe Fonts, Analytics, Karten o. Ä. einbindet, muss die
Domains dort ergänzen und `sudo ./setup.sh nginx` erneut ausführen.

## Hinweise

- **SSH-Port**: bleibt wie gewünscht 22. Ein anderer Port (`SSH_PORT`) wird überall
  berücksichtigt (sshd, UFW, fail2ban), bringt aber nur etwas weniger Log-Rauschen.
- **Hoster-Firewall**: Viele Anbieter (Hetzner, IONOS, …) bieten zusätzlich eine
  Cloud-Firewall. Dort dieselben Ports (22, 80, 443) freigeben – doppelt hält besser.
- **Private Keys auf dem Server**: Die Schlüsselpaare aus `/root/.ssh/id_*` werden wie
  gewünscht zu `keiladm` kopiert (z. B. für Git-Zugriff). Private Keys auf einem
  Server sollten möglichst eine Passphrase haben oder nur als Deploy-Key mit
  Leserechten verwendet werden. Abschaltbar über `COPY_ROOT_KEYPAIRS="false"`.
- Geänderte Systemdateien werden einmalig als `*.basis-schutz.bak` gesichert.
