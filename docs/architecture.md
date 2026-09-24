# Architektur

Kurzüberblick über Aufbau, Datenfluss und die wichtigsten Entscheidungen.
Details zu Feldern: [content-model.md](content-model.md).

## Grundlage

| Baustein | Entscheidung | Begründung |
| --- | --- | --- |
| CMS | **Kirby 5.6** (aktuelle stabile Version), Grundlage **Plainkit** | Dateibasiert, keine Datenbank, Panel für die Redaktion, Rollen und Hooks im Kern |
| Installation | Kirby über **Composer** (`getkirby/cms`), nicht im Repository | Updates über `composer update`, reproduzierbar über `composer.lock` |
| Plugins | **keine fremden Plugins** – ein eigenes Projekt-Plugin `site/plugins/kneipe` | Weniger Abhängigkeiten, alles nachvollziehbar |
| Frontend | Serverseitige Kirby-Templates, eigenes CSS, 1 kleines Skript (1,2 KB) | Keine SPA, kein Framework, funktioniert ohne JavaScript |
| Schriften | Fraunces (Überschriften) + Atkinson Hyperlegible Next (Text), lokal | Datenschutz, Lesbarkeit, SIL Open Font License |
| Server | Debian + basis-schutz-os + Caddy + PHP-FPM | Wie `astro-web-basic-template`, erweitert um Kirby |

## Verzeichnisse

```
index.php                 Einstieg; Roots aus Umgebungsvariablen (site/bootstrap.php)
assets/
  css/                    tokens.css (einzige Quelle für Farben/Schrift/Abstände),
                          site.css, calendar.css, form.css, print.css, panel.css
  js/site.js              Menü einklappen, Fokus auf Fehlerübersicht
  fonts/                  WOFF2 + Lizenzen
  brand/                  Logo, Wortmarke, Monochrom, Favicon, Social-Bild
content/                  Startinhalt und Demo-Inhalte (auf dem Server außerhalb des Codes)
site/
  blueprints/             pages/, users/, files/, fields/, sections/, tabs/, options/, site.yml
  config/                 config.php (Produktion), config.localhost.php (lokal), panel-menu.php
  controllers/            Logik je Seitentyp (Kalender, Formular, Termin)
  models/                 EventPage, EventsPage, TeamPage
  plugins/kneipe/         Projekt-Plugin
    src/                  reine PHP-Klassen – ohne Kirby testbar
    hooks.php             Freigabeworkflow, Überschneidung, Protokoll, Rechte
    routes.php            robots.txt, sitemap.xml, Sperre für /anfragen
    blueprints.php        rollenabhängige Reiter im Dashboard
  snippets/, templates/   Ausgabe (inkl. *.ics.php für iCalendar)
bin/                      CLI: Benutzer anlegen, Löschfrist anwenden
deploy/                   Server: site.env, Caddy, PHP-Pool, Skripte, systemd
tests/                    unit/, integration/, http/, browser/
docs/                     diese Dokumentation
```

## Datenmodell (Kurzfassung)

```
site (Stammdaten, Einstellungen, Dashboard)
├── home                     Startseite
├── termine        (events)  ─┬─ event  ×n   Termin (Entwurf oder veröffentlicht)
├── thekenteams    (teams)   ─┴─ team   ×n   Thekenteam  ◄── event.team
├── mitmachen      (join)
├── ueber-uns      (about)
├── kontakt        (contact)
├── aktuelles      (news)    ─── article ×n  Meldung/Rückblick ──► event (optional)
├── termin-anfragen (requestform) ── danke (confirmation)
├── anfragen       (requests) ── request ×n  nur Entwürfe, nie öffentlich
├── impressum, datenschutz, barrierefreiheit  (legal)
├── bausteine      (styleguide, noindex)
└── error
```

### Zwei unabhängige Status je Termin

1. **Kirby-Status** (Veröffentlichung): *Entwurf* oder *veröffentlicht*.
2. **Organisatorischer Status** (Feld `orgstatus`): frei, angefragt,
   reserviert, zur Freigabe, bestätigt, veröffentlicht, abgesagt,
   geschlossen, archiviert.

Öffentlich sichtbar ist ein Termin nur, wenn **beides** passt
(`Kneipe\EventStatus::publicKey()`):

| orgstatus | Terminart | öffentlich als |
| --- | --- | --- |
| frei | beliebig | Termin frei |
| bestätigt, veröffentlicht, archiviert | beliebig außer privat/geschlossen | Termin bestätigt |
| abgesagt | beliebig | Veranstaltung abgesagt |
| geschlossen oder Art „geschlossen“/„privat“ | – | Geschlossen (private Feiern ohne Titel und Details) |
| angefragt, reserviert, zur Freigabe | – | **nicht öffentlich** (404) |

Vergangene Termine (Ende vor jetzt) wechseln automatisch in den Rückblick
und werden nicht gelöscht.

## Freigabeworkflow

```
Formular ──► Anfrage (Entwurf unter /anfragen, nie öffentlich)
               │ Moderation prüft
               ▼
            Termin anlegen/ergänzen (Entwurf) ── orgstatus „zur Freigabe“
               │ Administration prüft
               ▼
            Veröffentlichen ──► orgstatus wird „veröffentlicht“
               │
               ▼
     Startseite · Kalender · Teamseite · iCalendar-Feed (automatisch)
```

- **Freigabemodus** (Einstellungen, Standard: an): Moderatoren dürfen den
  Kirby-Status nicht ändern und `bestätigt`/`veröffentlicht` nicht setzen.
- Durchgesetzt doppelt: Rollenrechte/Blueprint-Optionen **und** Hooks in
  `site/plugins/kneipe/hooks.php` (greifen auch bei direkten API-Aufrufen).
- **Doppelbelegung:** Bestätigen/Veröffentlichen ist gesperrt, solange sich
  der Termin mit einem anderen aktiven Termin überschneidet – außer die
  Administration lässt die Überschneidung ausdrücklich zu. Das Panel zeigt
  Überschneidungen am Termin und im Dashboard.
- **Nachvollziehbarkeit:** Ersteller, letzter Bearbeiter, Zeitpunkte,
  Änderungsnotiz und ein Änderungsprotokoll (Person, Zeit, geänderte
  Felder, Veröffentlichung) je Termin.

### Hinweis zu Kirby-Hooks

Wirft ein `:before`-Hook eine Ausnahme, setzt Kirby 5.6 seinen internen
Schutz vor Hook-Endlosschleifen nicht zurück; im selben PHP-Prozess würde
derselbe Hook danach übersprungen. Das Plugin setzt diesen Zustand vor
jeder Ablehnung zurück (`$deny` in `hooks.php`). Ein Test prüft
wiederholte Versuche.

## Anfragen: Datenschutzabwägung (Variante A)

Anfragen werden als **unveröffentlichte Kirby-Seiten** unter `anfragen`
gespeichert (Variante A) und zusätzlich knapp per E-Mail gemeldet.

- Zugriff nur mit persönlichem Panel-Konto (Administration, Moderation).
  Die Moderation braucht sie laut Workflow zur Prüfung, darf sie aber
  weder bearbeiten (Formularfelder schreibgeschützt) noch löschen.
- Keine öffentliche Ansicht: Route `anfragen/*` liefert 404, Templates
  werfen 404, Vorschau im Panel ist abgeschaltet, Status „veröffentlicht“
  existiert für Anfragen nicht und wird per Hook verhindert.
- Automatische Löschung nach der Löschfrist (Standard 180 Tage).
- Die E-Mail an die Redaktion enthält nur Gruppe, Wunschtermin und den
  Panel-Link – Vorstellung und Nachricht bleiben im Panel.
- Nicht im Repository (`.gitignore`), auf dem Server außerhalb des Codes.

Warum nicht nur E-Mail (Variante B)? Anfragen müssten dann in Postfächern
verwaltet werden, die Löschfrist wäre nicht durchsetzbar und der Status
der Bearbeitung nicht für alle sichtbar.

## Laufzeitdaten auf dem Server

```
/var/www/<DOMAIN>/
├── app/        Programmcode aus Git (nur lesbar für PHP)
├── data/
│   ├── content/     Inhalte – gehören dem Panel, werden nie überschrieben
│   ├── media/       von Kirby erzeugte Bildgrößen
│   └── storage/     accounts/, sessions/, cache/, logs/, .license
└── config/kirby.env Geheimnisse (Salt, Cookie-Schlüssel, SMTP)
```

Die Pfade stellt `site/bootstrap.php` über `KIRBY_CONTENT_ROOT`,
`KIRBY_MEDIA_ROOT` und `KIRBY_STORAGE_ROOT` ein; lokal gilt die
Plainkit-Struktur. `content/` aus dem Repository wird nur beim ersten
Deployment als Startinhalt übernommen.

## Standardentscheidungen

| Frage | Entscheidung |
| --- | --- |
| Kalender-Standardansicht | Liste der kommenden Termine; Monatsraster ab 56em Breite zusätzlich |
| Freie Termine im iCalendar-Feed | nein – der Feed zeigt, wann geöffnet ist bzw. was abgesagt wurde |
| Private Veranstaltungen | öffentlich als „Geschlossene Gesellschaft“ ohne Details |
| Zeitzone iCalendar | UTC (…Z) – kein VTIMEZONE nötig |
| Rich Text der Redaktion | Writer-Feld (bereinigt beim Speichern) + zweite Bereinigung bei der Ausgabe |
| KirbyText | nur in Rechtstexten (nur Administration) |
| Dark Mode | bewusst nicht umgesetzt – warme Markenfarben, weniger Prüfaufwand |
| Cookies | nur `kirby_session` auf dem Formular (CSRF) und im Panel |
