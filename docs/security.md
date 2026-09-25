# Sicherheit und Datenschutz

Kurzfassung der Schutzmaßnahmen. Container und Cluster im Detail:
[Docker.md](../Docker.md), Kapitel 24.

## Überblick

| Bereich | Maßnahme |
| --- | --- |
| Betrieb | Container als UID 33, schreibgeschütztes Dateisystem, keine Capabilities, Pod Security `restricted`, NetworkPolicy (nur Ingress rein, nur DNS/SMTP raus) |
| Geheimnisse | nur im Kubernetes-Secret bzw. lokal in `.env`; Nonce- und JWT-Schlüssel optional aus dem Secret, sonst von Grav auf dem Volume erzeugt (0600); Passwörter per STDIN |
| Webserver | feste Sperren für Konfiguration, Konten, Daten, Anfragen, Einstellungen, Quelltext und versteckte Dateien; nur `index.php` ausführbar; `open_basedir`, `disable_functions` |
| Formular | CSRF-Nonce, Honeypot, signierte Zeitfalle, Herkunftsprüfung, serverseitige Validierung, Rate-Limit je IP-Hash, Post/Redirect/Get |
| Inhalte | HTML im Markdown wird maskiert, Ausgabe zusätzlich bereinigt (`Richtext`), kein Twig in Inhalten, Twig-Autoescape |
| Admin | Anmeldung mit Sperre nach Fehlversuchen, Zwei-Faktor-Anmeldung verfügbar, API-Rate-Limit, kein offener Einrichtungsassistent, keine Registrierung, keine API-Schlüssel |
| Rollen | Administration ohne technischen Superuser; Freigaberegeln serverseitig für jede API-Operation (`Guard.php`) |
| Updates | Grav-Version mit Prüfsumme, Image-Scan mit Trivy in der CI, regelmäßiger Neubau |

## Security-Header und CSP

Gesetzt in `docker/apache-grav.conf`:

- Website: `Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; frame-src 'none'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'`
- Admin (`/admin`, `/api`): eigene CSP mit `'unsafe-inline'` und `blob:` für
  Skripte (Admin2 lädt eine Startkonfiguration inline und Plugin-Seiten als
  Modul), `Cache-Control: no-store`, `X-Robots-Tag: noindex`.
- Immer: `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`,
  `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy`,
  `Cross-Origin-Opener-Policy: same-origin`.
- HSTS setzt der Ingress.

## Rollen und Freigabe

Siehe [architecture.md](architecture.md#freigabeworkflow). Jede Regel ist
durch HTTP-Tests über die echte API abgesichert (`tests/http/workflow.php`),
einschließlich Stapel-Veröffentlichung, Kopie, Verschieben und direktem
Setzen von `published`.

## Interne Daten

- Termine: `responsible`, `internalnote`, `conflictaccepted`, Protokoll –
  nie im Frontend, nicht im iCalendar, nicht in der Sitemap.
- Thekenteams: Kontaktdaten, Einwilligungsnachweis, interne Notiz – nie
  öffentlich; Teams ohne Einwilligung sind 404.
- Anfragen: nie routbar, im Webserver gesperrt, nicht in Sitemap oder
  Kalender, nicht in der Redaktionsübersicht (dort nur Titel und Stand), nicht
  in E-Mails an die Redaktion (dort nur Gruppe, Termin und Link), Löschfrist.
- Rate-Limit speichert nur HMAC-Hashes der IP-Adresse.
- Protokolle enthalten keine Formularinhalte.

## Datenschutz

- Keine externen Schriften, Skripte, Karten oder Tracker; OpenStreetMap nur
  als Link. Avatare im Admin lokal (kein Gravatar).
- Cookies: Die Website setzt nur auf dem Anfrageformular ein Sitzungs-Cookie
  (technisch notwendig für den CSRF-Schutz) sowie im Admin.
- Keine Besucherstatistik (API-Plugin: `popularity` aus).
- Admin2 versucht, Neuigkeiten von getgrav.org zu laden; die NetworkPolicy
  blockiert das. Wer Egress freigibt, sollte das in der Datenschutzerklärung
  bedenken (betrifft nur angemeldete Konten, keine Besucher).

## Updates

- Grav, Admin2 und API: Version und Prüfsumme in `scripts/grav-dist.sh`;
  Changelogs lesen, Tests laufen lassen, Image neu bauen.
- Basis-Image monatlich neu bauen; Trivy-Befunde prüfen, Ausnahmen nur mit
  Begründung und Ablaufdatum in `.trivyignore`.

## Sicherheitsproblem melden

Bitte vertraulich an die Administration der Website (Adresse im Impressum),
nicht als öffentliches Issue.
