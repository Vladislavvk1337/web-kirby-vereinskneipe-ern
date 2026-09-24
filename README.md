# Ehrenamtskneipe Erndtebrück – Website (Kirby CMS)

Website für eine ehrenamtlich betriebene Begegnungskneipe in Erndtebrück:
Vereine, Unternehmen, Initiativen und private Gruppen übernehmen
abwechselnd die Theke. Die Seite zeigt kommende Abende und freie Termine,
nimmt Terminanfragen entgegen und gibt der Redaktion ein Panel mit
Freigabeworkflow.

> „Ehrenamtskneipe Erndtebrück“ ist ein **Arbeitstitel**. Name und
> Leitzeile lassen sich im Panel ändern. Alle Kontaktdaten sind Platzhalter
> oder `example.org`-Adressen – siehe [docs/project-data-needed.md](docs/project-data-needed.md).

**Technik:** Kirby 5.6 (Plainkit) · PHP 8.2–8.5 · keine Datenbank · keine
fremden Plugins · kein JavaScript-Framework · lokale Schriften · keine
Tracker, keine Cookies außer der technisch notwendigen Kirby-Sitzung.

---

## Inhalt

- [Voraussetzungen](#voraussetzungen)
- [Lokale Installation](#lokale-installation)
- [Composer-Befehle](#composer-befehle)
- [Erster Administrator und Moderatoren](#erster-administrator-und-moderatoren)
- [Konfiguration](#konfiguration)
- [E-Mail](#e-mail)
- [Tests](#tests)
- [Deployment](#deployment)
- [Demo-Inhalte](#demo-inhalte)
- [Offene rechtliche Platzhalter](#offene-rechtliche-platzhalter)
- [Lizenzen](#lizenzen)
- [Weitere Dokumentation](#weitere-dokumentation)

## Voraussetzungen

- **PHP 8.2, 8.3, 8.4 oder 8.5** mit den Erweiterungen `ctype`, `curl`,
  `dom`, `filter`, `gd`, `hash`, `iconv`, `intl`, `json`, `mbstring`,
  `openssl`, `SimpleXML` (für Kirby) – `php -m` zeigt sie an.
- **Composer 2**
- Für die optionalen Browser-Prüfungen: Node.js mit Playwright und Chromium.

## Lokale Installation

```bash
git clone git@github.com:Vladislavvk1337/web-kirby-vereinskneipe-ern.git
cd web-kirby-vereinskneipe-ern
composer install                 # installiert Kirby nach kirby/
cp .env.example .env             # lokale Einstellungen, siehe unten
composer start                   # http://localhost:8000
```

Für die lokale Entwicklung in `.env`:

```bash
KIRBY_DEBUG=true
KIRBY_MAIL_TRANSPORT=none        # Mails nicht versenden
KIRBY_CONTENT_SALT=lokal-beliebig
KIRBY_COOKIE_KEY=lokal-beliebig
```

`http://localhost:8000` lädt zusätzlich `site/config/config.localhost.php`
(Debug an, Panel-Installation im Browser erlaubt).

| Adresse | Inhalt |
| --- | --- |
| `/` | Startseite |
| `/termine`, `/termine/<termin>`, `/termine.ics`, `/termine/<termin>.ics` | Kalender, Termin, iCalendar |
| `/termin-anfragen`, `/termin-anfragen/danke` | Anfrageformular, Bestätigung |
| `/thekenteams`, `/thekenteams/<team>` | Thekenteams |
| `/mitmachen`, `/ueber-uns`, `/kontakt`, `/aktuelles` | Inhaltsseiten |
| `/impressum`, `/datenschutz`, `/barrierefreiheit` | Rechtstexte |
| `/bausteine` | Design-System (nicht verlinkt, `noindex`) |
| `/sitemap.xml`, `/robots.txt` | automatisch |
| `/panel` | Redaktionsbereich |

## Composer-Befehle

```bash
composer install            # Abhängigkeiten (Kirby) installieren
composer start              # Entwicklungsserver auf http://localhost:8000
composer test               # alle Tests
composer lint               # PHP-Syntax prüfen
composer update getkirby/cms   # Kirby aktualisieren (danach testen, Staging!)
```

## Erster Administrator und Moderatoren

**Für jede Person ein eigenes Konto** – keine gemeinsamen Zugänge.

Lokal geht es im Browser: `http://localhost:8000/panel` bietet beim ersten
Aufruf die Installation an. Oder auf der Kommandozeile:

```bash
php bin/create-user.php --email=vorname@example.org --name="Vorname Nachname" --role=admin
php bin/create-user.php --email=… --name="…" --role=moderator
```

Auf dem Server (Installation im Browser ist dort gesperrt):

```bash
sudo kneipe-cli create-user --email=… --name="…" --role=admin
```

Weitere Konten legt die Administration im Panel unter **Accounts** an.
Rollen: **Administration** (alles) und **Moderation** (Termine, Teams,
Aktuelles, Anfragen lesen; kein Veröffentlichen im Freigabemodus, keine
Benutzer, keine Einstellungen, keine Rechtstexte). Details:
[docs/editor-guide.md](docs/editor-guide.md).

## Konfiguration

Umgebungsvariablen (lokal `.env`, Server `config/kirby.env` bzw. PHP-FPM-Pool),
vollständige Liste in [`.env.example`](.env.example):

| Variable | Zweck |
| --- | --- |
| `KIRBY_DEBUG` | Fehlerdetails – nur lokal `true` |
| `KIRBY_URL` | feste öffentliche Adresse (Produktion) |
| `KIRBY_CONTENT_SALT`, `KIRBY_COOKIE_KEY` | zufällige Geheimnisse (64 Hex-Zeichen) |
| `KIRBY_NOINDEX` | Vorschau/Staging für Suchmaschinen sperren |
| `KIRBY_CONTENT_ROOT`, `KIRBY_MEDIA_ROOT`, `KIRBY_STORAGE_ROOT` | Daten außerhalb des Programmcodes (Server) |
| `KIRBY_MAIL_TRANSPORT` | `smtp`, `mail` oder `none` |
| `KIRBY_SMTP_*`, `KIRBY_MAIL_FROM`, `KIRBY_MAIL_FROM_NAME` | Mailversand |
| `KNEIPE_RATE_LIMIT`, `KNEIPE_MIN_SECONDS` | Missbrauchsschutz des Formulars |
| `KIRBY_TIMEZONE` | Standard `Europe/Berlin` |

Inhaltliche Einstellungen pflegt die Administration im Panel
(**Übersicht → Stammdaten / Einstellungen**): Name, Leitzeile, Adresse,
Koordinaten, E-Mail, Telefon, Öffnungszeiten, Social-Media-Links,
Freigabemodus, Empfänger der Anfragen, Eingangsbestätigung, Löschfrist.

Gestaltung: Farben, Schriften und Abstände ausschließlich in
[`assets/css/tokens.css`](assets/css/tokens.css) (Präfix `--ui-`).

## E-Mail

Kirby verschickt über den eingebauten PHPMailer:

- **Benachrichtigung** an den Empfänger aus den Einstellungen (leer =
  allgemeine E-Mail-Adresse): Gruppe, Wunschtermin, Link ins Panel.
  „Antworten“ geht direkt an die anfragende Person.
- **Eingangsbestätigung** an die anfragende Person (abschaltbar), bewusst
  ohne Formularinhalte – so lässt sich das Formular nicht zum Versand
  fremder Texte missbrauchen.

SMTP-Zugang über `KIRBY_SMTP_HOST`, `_PORT` (587), `_SECURITY` (`tls`/`ssl`),
`_USER`, `_PASSWORD`. Absender (`KIRBY_MAIL_FROM`) muss zum SMTP-Konto passen
(SPF/DMARC). Scheitert der Versand, bleibt die Anfrage im Panel gespeichert;
ins Protokoll kommt nur die technische Ursache, keine Formulardaten.

## Tests

```bash
composer test          # 74 Tests: Unit, Integration (Kirby), HTTP (PHP-Server)
```

Zusätzlich Browser-Prüfungen mit Playwright (Breiten 375–1920 px,
Tastatur, 200 % Zoom, reduzierte Bewegung, Panel aus Sicht beider Rollen).
Übersicht, Befehle und Prüfprotokoll: [docs/testing.md](docs/testing.md).
GitHub Actions führt Tests, ShellCheck und die Caddyfile-Prüfung bei jedem
Push aus (`.github/workflows/ci.yml`).

## Deployment

Eigener Debian-Server nach dem Muster von
[astro-web-basic-template](https://github.com/Vladislavvk1337/astro-web-basic-template):
basis-schutz-os, Caddy mit automatischem TLS, eigener PHP-FPM-Pool,
Aktualisierung alle 5 Minuten per systemd-Timer, tägliche Löschfrist und
Datensicherung. Inhalte aus dem Panel liegen außerhalb des Programmcodes
und werden bei Deployments nie überschrieben.

```bash
# einmalig, als root auf dem Server (Details in docs/deployment.md)
/srv/kneipe/repo/deploy/basis-schutz.sh
sudo /srv/kneipe/repo/deploy/server-setup.sh
sudo kneipe-cli create-user --email=… --name="…" --role=admin
```

Anleitung: [docs/deployment.md](docs/deployment.md) · Sicherung:
[docs/backup-restore.md](docs/backup-restore.md) · Sicherheit:
[docs/security.md](docs/security.md)

## Demo-Inhalte

`content/` enthält **klar gekennzeichnete Beispielinhalte** („Demo-…“,
„Beispiel-…“, „Beispieltermin – noch frei“): Termine im Herbst 2026 in
allen Status (vergangen, bestätigt, frei, abgesagt, privat, geschlossen,
Entwürfe zur Freigabe und mit Überschneidung), drei Thekenteams (eines ohne
Einwilligung), zwei Meldungen, gezeichnete Platzhalterbilder. Interne
Demo-Felder tragen die Markierung `XINTERNX`, damit Tests prüfen können,
dass sie nie öffentlich erscheinen.

Vor dem Livegang im Panel löschen bzw. ersetzen: alle Termine und Teams mit
„Demo“/„Beispiel“ im Namen, die Meldungen unter „Aktuelles“, die
Platzhalterbilder (Startseite, Termin, Team, Rückblick). Die Demo-Termine
liegen im Herbst 2026; danach zeigt die Startseite ohne neue Termine einen
gestalteten Leerzustand.

## Offene rechtliche Platzhalter

Impressum, Datenschutzerklärung und Barrierefreiheitsseite sind Vorlagen mit
markierten Platzhaltern und dem Hinweis „Entwurf – noch nicht rechtlich
geprüft“. Vor dem Livegang:

- Betreiber/Träger, Rechtsform, Vertretung, Registereintrag, Verantwortliche
  nach § 18 Abs. 2 MStV (Stammdaten)
- Hosting- und E-Mail-Anbieter (Stammdaten)
- Rechtsgrundlagen, Aufsichtsbehörde, Stand (Datenschutz)
- Ergebnis einer Barrierefreiheitsprüfung, falls vorhanden
- Löschfrist mit dem Träger abstimmen und in Einstellungen und
  Datenschutzerklärung gleich halten (der Text liest den Wert aus dem Panel)

Liste aller fehlenden Angaben: [docs/project-data-needed.md](docs/project-data-needed.md).

## Lizenzen

- **Kirby** ist kommerzielle Software. Lokal und zum Testen kostenlos; für
  den **öffentlichen Betrieb ist eine Lizenz nötig** (je Website) –
  https://getkirby.com/buy. Gemeinnützige Projekte können bei Kirby nach
  Sonderkonditionen fragen. Aktivierung im Panel. Kirby wird per Composer
  installiert und liegt nicht im Repository.
- **Schriften** (lokal in `assets/fonts/`, über [Fontsource](https://fontsource.org) bezogen):
  - *Fraunces* – The Fraunces Project Authors, SIL Open Font License 1.1
    ([`LICENSE-Fraunces.txt`](assets/fonts/LICENSE-Fraunces.txt))
  - *Atkinson Hyperlegible Next* – The Atkinson Hyperlegible Next Project
    Authors (Braille Institute of America), SIL Open Font License 1.1
    ([`LICENSE-AtkinsonHyperlegibleNext.txt`](assets/fonts/LICENSE-AtkinsonHyperlegibleNext.txt))
- **Logo, Symbole, Illustrationen und Platzhalterbilder** sind für dieses
  Projekt neu gezeichnet (SVG) – keine fremden Iconbibliotheken, keine Wappen.
- **basis-schutz-os** (`deploy/basis-schutz-os/`) – Spiegel des gleichnamigen
  Repositorys.

## Weitere Dokumentation

| Datei | Inhalt |
| --- | --- |
| [docs/architecture.md](docs/architecture.md) | Aufbau, Status-Logik, Workflow, Datenschutzabwägung |
| [docs/content-model.md](docs/content-model.md) | alle Seitentypen und Felder |
| [docs/editor-guide.md](docs/editor-guide.md) | Redaktionsleitfaden |
| [docs/deployment.md](docs/deployment.md) | Server einrichten und betreiben |
| [docs/backup-restore.md](docs/backup-restore.md) | Sicherung und Wiederherstellung |
| [docs/security.md](docs/security.md) | Sicherheitsmaßnahmen, Header, CSP |
| [docs/testing.md](docs/testing.md) | Tests und Prüfprotokoll |
| [docs/project-data-needed.md](docs/project-data-needed.md) | fehlende Projektdaten |
| [CLAUDE.md](CLAUDE.md) | Arbeitsanweisungen für Claude Code |
