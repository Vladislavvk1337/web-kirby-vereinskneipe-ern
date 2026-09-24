# Tests und Prüfprotokoll

## Automatisierte Tests

```bash
composer test                      # alle: Unit, Integration, HTTP
php tests/run.php --unit           # nur reine Logik (auch ohne Kirby)
php tests/run.php --integration    # Kirby im Prozess mit Kopie von content/
php tests/run.php --http           # eingebauter PHP-Server, echte HTTP-Anfragen
php tests/run.php --filter=csrf    # einzelne Tests
composer lint                      # PHP-Syntax
```

Die Tests arbeiten mit einer Kopie von `content/` in `tests/tmp/` und
verändern das Projekt nicht. Warnungen und Hinweise zählen als Fehler.
Die Integrationstests verwenden einen festen „Jetzt“-Zeitpunkt
(24.09.2026), damit sie mit den Demo-Terminen dauerhaft funktionieren.

| Anforderung | Test |
| --- | --- |
| Sortierung kommender Termine | `unit/calendar.php`, `integration/events.php` |
| Erkennung vergangener Termine | `unit/calendar.php`, `integration/events.php` |
| Statusfilter | `unit/status.php`, `unit/calendar.php`, `integration/events.php`, `http/site.php` |
| Überschneidungsprüfung | `unit/status.php`, `integration/events.php`, `integration/permissions.php` |
| Rollenberechtigungen | `integration/permissions.php` |
| Unerlaubte Veröffentlichung durch Moderatoren | `integration/permissions.php` (inkl. wiederholter Versuche) |
| Formularvalidierung | `unit/form.php`, `http/form.php` |
| CSRF-Schutz | `http/form.php` |
| Honeypot, Zeitfalle, Herkunft, Rate-Limit | `http/form.php`, `unit/form.php` |
| iCalendar-Ausgabe | `unit/ics.php`, `http/site.php` |
| Zugriff auf interne Felder | `integration/events.php`, `http/site.php` |
| 404-Verhalten | `http/site.php` |
| HTML-Grundvalidität | `http/html.php` (Parser, eine H1, Überschriftenfolge, IDs, ARIA-Verweise, alt, Labels, keine Inline-Skripte/-Styles, JSON-LD) |
| Anfragen nie öffentlich, Löschfrist | `integration/requests.php`, `http/form.php` |
| Sicherheitskonfiguration | `unit/security.php` |

### Browser-Prüfungen (Playwright/Chromium)

```bash
composer start &                                   # http://localhost:8000
export NODE_PATH="$(npm root -g)"                  # Playwright global installiert
node tests/browser/check.cjs --screenshots         # Breiten, Tastatur, Menü, Zoom, Bewegung
node tests/browser/weight.cjs /                    # Übertragungsgröße
ADMIN=admin@example.org:<passwort> MOD=moderation@example.org:<passwort> \
  node tests/browser/panel.cjs --screenshots       # Panel aus Sicht beider Rollen
```

`check.cjs` prüft 18 Seiten bei 375, 390, 768, 1024, 1280 und 1920 px auf
horizontalen Überlauf, Konsolenfehler, fehlgeschlagene Anfragen und Bilder
ohne `alt`; dazu Sprunglink als erster Tab-Stopp mit sichtbarem Fokus,
mobiles Menü (öffnen, Escape), Formular per Tab bis zum Absende-Knopf,
200 % Zoom und `prefers-reduced-motion`.

## Prüfprotokoll (Stand der Umsetzung)

Geprüft lokal mit PHP 8.4, Kirby 5.6.0, Chromium (Playwright 1.56).

| Prüfung | Ergebnis |
| --- | --- |
| `composer test` | 74 Tests bestanden |
| Horizontaler Überlauf 375–1920 px | keiner (18 Seiten × 6 Breiten) |
| Konsolenfehler | keine |
| Startseite, Übertragung | 9 Anfragen, ca. 170 KB unkomprimiert, 1,2 KB JavaScript |
| Tastatur: Sprunglink, Fokus, Menü, Formular | automatisch geprüft ✔ |
| Sichtbarer Fokus | 3 px dunkler Rahmen mit Bernstein-Hof, auf dunklem Grund hell (15:1 bzw. 8:1) |
| 200 % Zoom | kein Überlauf, Navigation klappt ein |
| Reduzierte Bewegung | Übergänge abgeschaltet |
| Mobile Navigation | Menüknopf mit `aria-expanded`, ohne JavaScript alle Links sichtbar |
| Anfrageprozess | Fehler → Fokus auf Fehlerübersicht, Werte erhalten; Erfolg → 303 → Danke-Seite; Anfrage im Panel |
| Panel aus Sicht der Moderation | Übersicht mit allen Bereichen; kein Zugriff auf Accounts, System, Stammdaten, Einstellungen, Rechtstexte |
| Panel aus Sicht der Administration | alle Bereiche, Stammdaten und Einstellungen editierbar |
| Farbkontraste | alle Text-/Hintergrundpaare ≥ 4,5:1 (siehe `assets/css/tokens.css`) |
| Status nicht nur über Farbe | Text + Symbol, abgesagt zusätzlich durchgestrichen, frei gestrichelt |
| Dark Mode | nicht umgesetzt (bewusste Entscheidung) |

### Noch von Menschen zu prüfen

Automatische Prüfungen ersetzen keinen Test mit echten Hilfsmitteln:

- Screenreader (NVDA/Firefox, VoiceOver/Safari auf iPhone): Startseite,
  Kalender (Tabelle und Liste), Formular mit Fehlern.
- Echte Geräte: iPhone SE/kleines Android, Tablet.
- Panel-Bedienung mit echten Redakteurinnen und Redakteuren (Verständlichkeit).
- Mailversand über den echten SMTP-Server (Zustellung, SPF/DMARC).
- Rechtliche Prüfung von Impressum und Datenschutz.
