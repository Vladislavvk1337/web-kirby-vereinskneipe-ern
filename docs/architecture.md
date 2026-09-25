# Architektur

Überblick über den Aufbau der Anwendung. Betrieb, Container und Kubernetes:
[Docker.md](../Docker.md).

## Grundlage

- **Grav CMS 2.2** (Flat-File, keine Datenbank) mit **Admin2** (SvelteKit-
  Oberfläche) und dem **API-Plugin** (REST-API, über die Admin2 arbeitet).
  Beides stammt unverändert aus dem offiziellen Paket `grav-admin-v2.2.0.zip`.
- Weitere mitgelieferte Plugins: `login`, `form`, `email`, `error`,
  `flex-objects`, `shortcode-core` (Abhängigkeiten von Admin2/API).
- Projekt-Plugin `kneipe` und Theme `kneipe` – beide im Repository.

## Verzeichnisse

| Pfad | Inhalt |
| --- | --- |
| `user/plugins/kneipe/kneipe.php` | Anbindung an Grav: Ereignisse, Routen (ICS, Sitemap, robots.txt, Health), Sichtbarkeit, Formular, Admin2-Erweiterungen |
| `user/plugins/kneipe/classes/` | `Calendar`, `EventStatus`, `Overlap`, `Ics`, `RequestValidator`, `FormTimer`, `RateLimiter`, `Richtext`, `Changelog`, `Workflow` (ohne Grav testbar); `Service`, `EventEntry`, `TeamEntry`, `CalendarView`, `Feeds`, `RequestForm`, `Retention`, `Health`, `Guard`, `OverviewController`, `TwigHelpers` (Grav-Anbindung) |
| `user/plugins/kneipe/cli/` | `bin/plugin kneipe user` (Konten), `bin/plugin kneipe maintenance` (Löschfrist) |
| `user/plugins/kneipe/admin-next/pages/kneipe.js` | Redaktionsübersicht im Admin (Web Component, ohne Framework) |
| `user/themes/kneipe/templates/` | Twig-Templates je Seitenart, `partials/` |
| `user/themes/kneipe/blueprints/` | Formulare der Seitenarten für Admin2 |
| `user/themes/kneipe/css`, `js`, `fonts`, `icons`, `images/brand` | Gestaltung (Tokens in `css/tokens.css`) |
| `user/config/`, `user/env/<env>/config/` | Konfiguration, Gruppen (`groups.yaml`) |
| `setup.php` | beschreibbare Konfigurationsebene `$GRAV_DATA_DIR/config` für Schlüsseldateien |
| `seed/pages/` | Beispielinhalte (aus der Kirby-Migration erzeugt) |

## Seiten

```
home/                     Startseite (home)
01.termine/               Terminübersicht (events), darunter je Termin (event)
02.thekenteams/           Übersicht (teams), darunter je Team (team)
03.mitmachen/  04.ueber-uns/  05.kontakt/
aktuelles/                Meldungen und Rückblicke (news → article)
termin-anfragen/          Anfrageformular (requestform), danke/ (confirmation)
anfragen/                 Anfragen (requests → request) – nie öffentlich
einstellungen/            Stammdaten und Einstellungen (settings) – nie öffentlich
impressum/ datenschutz/ barrierefreiheit/   Rechtstexte (legal)
bausteine/                Design-System (styleguide), nicht verlinkt
error/                    Fehlerseite
```

Nummerierte Ordner erscheinen in der Hauptnavigation. Einzelheiten zu den
Feldern: [content-model.md](content-model.md).

## Zwei unabhängige Status je Termin

- **Veröffentlicht** (`published`, Grav): Ist die Seite freigeschaltet?
- **Organisatorischer Status** (`orgstatus`): frei, angefragt, reserviert,
  zur Freigabe, bestätigt, veröffentlicht, abgesagt, geschlossen, archiviert.

Öffentlich erscheint ein Termin nur, wenn er veröffentlicht ist **und** sein
Status eine öffentliche Entsprechung hat (`EventStatus::publicKey`). Interne
Status (angefragt, reserviert, zur Freigabe) sind nie öffentlich; private
Veranstaltungen erscheinen nur als „Geschlossene Gesellschaft“. Nicht
öffentliche Termine und Teams beantwortet das Plugin mit 404; die
Admin-Vorschau (signiertes Vorschau-Token von Admin2) zeigt sie gekennzeichnet.

## Freigabeworkflow

Rollen sind Grav-Gruppen (`user/config/groups.yaml`):

- **administration**: `api.pages`, `api.media`, `api.users`, `api.system.read`,
  `kneipe.admin` – kein technischer Superuser.
- **moderation**: `api.pages`, `api.media`.

`Guard.php` prüft **serverseitig** jede Änderung, die über Admin2 bzw. die
REST-API läuft – unabhängig davon, was die Oberfläche anbietet:

| Ereignis | Regel |
| --- | --- |
| `onAdminSave` (Anlegen, Ändern, Stapel-Veröffentlichen) | Freigabemodus: Moderation darf `published` nicht umschalten (neue Termine starten unveröffentlicht), `orgstatus` nicht auf bestätigt/veröffentlicht setzen, Überschneidungen nicht zulassen; Doppelbelegung blockiert Bestätigen/Veröffentlichen für alle; Seitenart und Seitenrechte ändert nur die Administration; Seiten außer Termin/Team/Meldung ändert nur die Administration; Anfragen bleiben unveröffentlicht und ihre Formularangaben unverändert; Metadaten und Änderungsprotokoll werden gesetzt |
| `onApiBeforePageDelete` | Moderation löscht nur unveröffentlichte Termine sowie Teams und Meldungen; Anfragen und übrige Seiten nur die Administration |
| `onApiPageCreated` (Kopie) | Kopien der Moderation starten unveröffentlicht und ohne Freigabestatus; andere Seitenarten darf sie nicht kopieren |
| `onApiPageMoved` | Termine, Teams, Meldungen bleiben in ihrem Bereich (sonst Rückverschiebung) |
| `onApiBeforePagesReorder`, `…Reorganize`, `…PageTranslate` | nur Administration |

Beim Veröffentlichen eines Termins mit Status „zur Freigabe“ oder
„bestätigt“ wird der Status automatisch „veröffentlicht“. Zusätzlich tragen
Rechtstexte, Startseite und weitere Seiten der Administration eine
Seitenregel (`permissions` im Frontmatter), damit Admin2 dort keine
Bearbeitung anbietet. Übersichtsseiten (Termine, Teams, Aktuelles) tragen
bewusst keine Seitenregel: Grav vererbt Regeln an Unterseiten ohne eigene
Regel.

## Anfragen

Das Formular verarbeitet das Plugin selbst (nicht das Form-Plugin): Nonce
der Grav-Sitzung, Honeypot, signierte Zeitfalle, Herkunftsprüfung,
Validierung (`RequestValidator`), Rate-Limit je HMAC der IP-Adresse (keine
IP im Klartext). Gespeichert wird eine Seite unter `anfragen/` mit
`published: false`, `routable: false`; der Webserver sperrt zusätzlich
`/user/pages/anfragen`. Danach E-Mail an die Redaktion (Reply-To = anfragende
Person, Link in den Admin, keine Formulartexte) und optional eine
Eingangsbestätigung. Die Löschfrist entfernt Anfragen nach der eingestellten
Zahl von Tagen.

## Standardentscheidungen

| Frage | Entscheidung | Grund |
| --- | --- | --- |
| Grav 1.7 oder 2.2 | 2.2 mit Admin2 | aktuelle stabile Version; klassischer Admin nur für 1.7 |
| Anfragen als Flex-Objekte oder Seiten | Seiten | Admin2 bearbeitet Seiten mit Blueprints; keine eigene Oberfläche nötig |
| Stammdaten in der Konfiguration oder als Seite | Seite „Einstellungen“ | Konfiguration ist im Container schreibgeschützt; Seiten liegen auf dem Volume |
| Mehrere Pods | nein | Flat Files ohne Sperren über Pods hinweg (Docker.md, Kapitel 17) |
| Twig in Inhalten | aus | Schutz vor Template-Injection durch Redaktionskonten |
