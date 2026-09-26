# Tests und Prüfprotokoll

Testplan für Betrieb und Kubernetes (20 Punkte, automatisch und manuell):
[Docker.md](../Docker.md), Kapitel 28.

## Automatisierte Tests

```bash
scripts/dev-server.sh --prepare-only   # einmalig: Grav-Kern nach .grav/ laden
php tests/run.php                      # alle: Unit und HTTP
php tests/run.php --unit               # nur reine Logik
php tests/run.php --http               # eigene Grav-Instanz, echte HTTP-Anfragen
php tests/run.php --filter=csrf        # einzelne Tests (Teil des Namens)
```

- **Unit-Tests** brauchen nur PHP (einige zusätzlich `symfony/yaml` aus dem
  Grav-Kern unter `.grav/`).
- **HTTP-Tests** bauen je Lauf eine eigene Grav-Instanz unter `tests/tmp/`
  (Umgebung `production`, Beispielinhalte aus `seed/pages`, Konten für
  Administration und Moderation über `bin/plugin kneipe user`), starten den
  eingebauten PHP-Server und einen Test-SMTP-Empfänger
  (`tests/support/smtp-sink.php`). Das Projekt und `.grav/data` bleiben
  unverändert.
- Warnungen und Hinweise zählen als Fehler.
- `tests/http/workflow.php` spricht die echte REST-API an (wie Admin2) und
  läuft als letzte Datei, weil es Inhalte ändert.

| Anforderung | Test |
| --- | --- |
| Sortierung kommender Termine, vergangene Termine | `unit/calendar.php` |
| Statusfilter, interne Status nie öffentlich | `unit/status.php`, `unit/calendar.php`, `http/site.php` |
| Überschneidungsprüfung | `unit/status.php`, `http/workflow.php` |
| Rollen und Freigabe (einzeln, Stapel, Kopie, neuer Termin) | `http/workflow.php` |
| Anmeldung, API ohne Anmeldung 401 | `http/workflow.php` |
| Formularvalidierung | `unit/form.php`, `http/form.php` |
| CSRF, Honeypot, Zeitfalle, Herkunft, Rate-Limit | `http/form.php`, `unit/form.php` |
| E-Mail an die Redaktion, Eingangsbestätigung | `http/form.php` (Test-SMTP-Empfänger) |
| Anfragen nie öffentlich, Ablehnung veröffentlicht nichts | `http/site.php`, `http/form.php`, `http/workflow.php` |
| iCalendar-Ausgabe | `unit/ics.php`, `http/site.php` |
| Interne Felder nie öffentlich | `http/site.php` |
| 404-Verhalten, robots.txt, Sitemap, Cookies | `http/site.php` |
| Health-Checks (`/healthz`, `/readyz`) | `http/site.php` |
| HTML-Grundvalidität | `http/html.php` (Parser, eine H1, Überschriftenfolge, IDs, ARIA-Verweise, alt, Labels, keine Inline-Skripte/-Styles, JSON-LD) |
| Sicherheitskonfiguration (Webserver, Grav, Container, Kubernetes) | `unit/security.php` |
| Migration aus Kirby | `unit/migration.php` |

### Browser-Prüfungen (Playwright/Chromium)

```bash
scripts/dev-server.sh &                            # http://localhost:8000
export NODE_PATH="$(npm root -g)"                  # Playwright global installiert
node tests/browser/check.cjs --screenshots         # Breiten, Tastatur, Menü, Zoom, Bewegung
node tests/browser/weight.cjs /                    # Übertragungsgröße
ADMIN=admin:<passwort> MOD=moderation:<passwort> \
  node tests/browser/admin.cjs --screenshots       # Admin2 aus Sicht beider Rollen
```

Konten für den Dev-Server anlegen (im Grav-Verzeichnis ausführen):

```bash
cd .grav/grav
printf '%s' '<passwort>' | GRAV_DATA_DIR=../data php bin/plugin kneipe user \
  --username=admin --email=admin@example.org --group=administration \
  --name='Administration' --password-stdin
```

`check.cjs` prüft 18 Seiten bei 375, 390, 768, 1024, 1280 und 1920 px auf
horizontalen Überlauf, Konsolenfehler, fehlgeschlagene Anfragen und Bilder
ohne `alt`; dazu Sprunglink als erster Tab-Stopp mit sichtbarem Fokus,
mobiles Menü (öffnen, Escape), Formular per Tab bis zum Absende-Knopf,
200 % Zoom und `prefers-reduced-motion`.

`admin.cjs` meldet sich als Administration und Moderation an, öffnet die
Redaktionsübersicht, ein Terminformular und die Einstellungen und schlägt
bei Skriptfehlern (auch durch die CSP) oder Serverfehlern der API fehl.

### Container und Manifeste

Die CI (`.github/workflows/`) prüft zusätzlich:

- `ci.yml`: Tests mit PHP 8.3 und 8.4; `shellcheck`, `hadolint`,
  `kustomize build` aller Overlays mit `kubeconform -strict` und
  `kube-linter`, keine Secrets in den Manifesten.
- `docker.yml`: Image bauen, Rauchtest mit `--read-only` und
  `--cap-drop ALL` (Routen, Sperren, Header, API-Anmeldung, PHP-Erweiterungen,
  Wartung, Neustart ohne erneutes Befüllen, Fehlstarts ohne Pflichtwerte),
  Trivy-Scan; Push nur bei Push-Ereignissen.

## Prüfprotokoll (Stand der Umsetzung)

Geprüft am 25.09.2026 lokal mit PHP 8.4, Grav 2.2.0, Admin2 2.1.23,
Chromium (Playwright 1.56).

| Prüfung | Ergebnis |
| --- | --- |
| `php tests/run.php` | 69 Tests bestanden (1035 Prüfungen) |
| Horizontaler Überlauf 375–1920 px | keiner (18 Seiten × 6 Breiten) |
| Konsolenfehler | keine |
| Startseite, Übertragung | 11 Anfragen, ca. 270 KB unkomprimiert, 1,2 KB JavaScript |
| Tastatur: Sprunglink, Fokus, Menü, Formular | automatisch geprüft ✔ |
| 200 % Zoom | kein Überlauf, Navigation klappt ein |
| Reduzierte Bewegung | Übergänge abgeschaltet |
| Mobile Navigation | Menüknopf mit `aria-expanded`, ohne JavaScript alle Links sichtbar |
| Anfrageprozess | Fehler → 422, Fokus auf Fehlerübersicht, Werte erhalten; Erfolg → 303 → Danke-Seite; Anfrage im Admin; E-Mail an die Redaktion |
| Admin2 aus Sicht der Moderation | Redaktionsübersicht, Terminformular; Veröffentlichen im Freigabemodus serverseitig abgelehnt |
| Admin2 aus Sicht der Administration | Redaktionsübersicht, Termine, Einstellungen, Konten |
| Admin2 unter der CSP | keine Skriptfehler (`admin.cjs`) |
| Container (Variante ohne kompilierte PHP-Erweiterungen, siehe Docker.md, Kapitel 32) | Start als UID 33, schreibgeschützt, ohne Capabilities; Sperren, Header, Neustart |
| Manifeste | kubeconform und kube-linter ohne Befunde |
| Farbkontraste | alle Text-/Hintergrundpaare ≥ 4,5:1 (siehe `user/themes/kneipe/css/tokens.css`) |
| Status nicht nur über Farbe | Text + Symbol, abgesagt zusätzlich durchgestrichen, frei gestrichelt |
| Dark Mode | nicht umgesetzt (bewusste Entscheidung) |

### Noch von Menschen zu prüfen

Automatische Prüfungen ersetzen keinen Test mit echten Hilfsmitteln:

- Screenreader (NVDA/Firefox, VoiceOver/Safari auf iPhone): Startseite,
  Kalender (Tabelle und Liste), Formular mit Fehlern.
- Echte Geräte: iPhone SE/kleines Android, Tablet.
- Admin2-Bedienung mit echten Redakteurinnen und Redakteuren (Verständlichkeit).
- Mailversand über den echten SMTP-Server (Zustellung, SPF/DMARC).
- Betrieb im echten Cluster: TLS, Neustart und Rollout mit Daten, Rollback,
  Restore eines Backups (Docker.md, Kapitel 28, Punkte 13–19).
- Rechtliche Prüfung von Impressum und Datenschutz.
