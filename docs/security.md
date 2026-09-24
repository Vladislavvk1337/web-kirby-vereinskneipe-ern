# Sicherheit

## Überblick

| Maßnahme | Umsetzung |
| --- | --- |
| Debug in Produktion aus | `debug` aus `KIRBY_DEBUG` (Standard `false`); nur `config.localhost.php` schaltet lokal ein |
| HTTPS | Caddy mit automatischem TLS, HTTP → HTTPS; Kirby setzt Cookies dann mit `Secure` |
| Feste Basis-URL | `KIRBY_URL` (aus `SITE_URL` im PHP-Pool) – schützt vor Host-Header-Manipulation in Links und Mails |
| Salt und Cookie-Schlüssel | `KIRBY_CONTENT_SALT`, `KIRBY_COOKIE_KEY` – zufällig (64 Hex-Zeichen), von `server-setup.sh` erzeugt, nur in `config/kirby.env` |
| Sitzungen | Kirby-Sitzungen: HttpOnly, SameSite=Lax, Secure unter HTTPS; Panel 2 h (angemeldet bleiben max. 7 Tage), Leerlauf 1 h |
| Anmeldung | nur Passwort, 5 Fehlversuche je Stunde, dann Sperre (`auth.trials`) |
| Panel-Installation im Browser | aus (`panel.install = false`); erster Administrator per CLI |
| REST-API | nur für das Panel, keine Basic-Auth |
| Rollen | Administration/Moderation (`site/blueprints/users/`), zusätzlich Hooks |
| Direkter Dateizugriff | Caddy liefert nur `/assets/` und `/media/` aus; `content/`, `site/`, `kirby/`, `vendor/`, Punktdateien → 404; `.htaccess` für Apache |
| Code/Daten getrennt | Programmcode nur lesbar für PHP, Daten außerhalb, `open_basedir` im Pool |
| PHP-Härtung | basis-schutz-os (`expose_php` aus, `allow_url_fopen` aus, gefährliche Funktionen gesperrt) |
| Uploads | Bilder: JPEG/PNG/WebP/AVIF bis 10 MB; SVG nur als Logo bis 2 MB; Kirby prüft Dateiinhalte (SVG ohne Skripte/externe Verweise) |
| Eingaben | Formular: Normalisierung (Steuer- und unsichtbare Zeichen, Längen), Validierung am Server; Kalenderparameter nur als Zeichenketten |
| Ausgaben | `esc()` in allen Templates; Writer-Inhalte beim Speichern (Kirby) und bei der Ausgabe (`Kneipe\Richtext`) bereinigt; KirbyText nur für Rechtstexte der Administration |
| Formular | CSRF (Kirby-Sitzung), Herkunftsprüfung (Origin), Honeypot, Zeitfalle (signiert), Rate-Limit je IP-Hash |
| Keine Daten in URLs/Logs | POST, Weiterleitung ohne Parameter; Protokolle enthalten nur technische Ursachen |
| Zugriffsprotokolle | IP gekürzt, ohne Cookies/Authorization/Set-Cookie, 14 Tage |
| Geheimnisse | nie im Repository (`.gitignore`, `.env.example` ohne Werte) |

## Security-Header und CSP

Siehe [deployment.md](deployment.md#sicherheits-header-und-csp). Vorschlag
für andere Webserver (Website ohne Panel):

```
Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; frame-src 'none'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'
X-Content-Type-Options: nosniff
X-Frame-Options: SAMEORIGIN
Referrer-Policy: strict-origin-when-cross-origin
Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()
Strict-Transport-Security: max-age=31536000; includeSubDomains   (erst wenn HTTPS dauerhaft steht)
```

Für `/panel` und `/api` zusätzlich `'unsafe-inline' 'unsafe-eval'` in
`script-src`/`style-src` und `blob:` in `img-src`.

## Rollen und Freigabe

- Moderatoren: kein Zugriff auf Benutzer, System, Stammdaten,
  Einstellungen, Rechtstexte und statische Seiten (Blueprint-Optionen
  `access`/`list`/`update`).
- Hooks (`site/plugins/kneipe/hooks.php`) prüfen serverseitig bei jeder
  Änderung: Veröffentlichen und Bestätigen im Freigabemodus, Zulassen von
  Überschneidungen, Löschen veröffentlichter Termine, Anlegen/Veröffentlichen
  von Anfragen, Benutzer und Rollen, Stammdaten.
- Kirby setzt seinen Hook-Schutz nach einer Ablehnung nicht zurück; das
  Plugin tut es selbst, damit auch wiederholte Versuche geprüft werden
  (siehe [architecture.md](architecture.md#hinweis-zu-kirby-hooks)).

## Interne Daten

Interne Felder (Terminnotiz, verantwortliche Person, Protokoll,
Team-Kontaktdaten, Einwilligungsnachweise) werden von keinem Template
ausgegeben. `tests/http/site.php` ruft alle öffentlichen Seiten, die
Sitemap-Adressen und iCalendar-Dateien ab und prüft, dass keine der
markierten Demo-Werte (`XINTERNX`) erscheinen.

## Updates

- Kirby: `composer update getkirby/cms`, Tests, Staging, dann `main`
  (siehe [deployment.md](deployment.md#updates-kirby-php--erst-auf-staging)).
  Sicherheitsmeldungen: https://getkirby.com/security
- Server: automatische Sicherheitsupdates (basis-schutz-os, Modul `updates`).
- basis-schutz-os: Spiegel per GitHub-Workflow, Anwendung von Hand.

## Sicherheitsproblem melden

Hinweise an die allgemeine E-Mail-Adresse (Stammdaten). Optional eine
`/.well-known/security.txt` im Webroot anlegen (Caddy liefert `.well-known`
aus).
