# Inhaltsmodell

Alle Feldnamen sind klein geschrieben und ohne Bindestrich. Wiederverwendbare
Teile liegen in `site/blueprints/fields/`, `sections/`, `tabs/` und
`options/`.

## Wiederverwendbare Teile

| Datei | Inhalt | verwendet in |
| --- | --- | --- |
| `fields/seo.yml` | `seotitle`, `seodescription`, `ogimage`, `noindex` | alle Seiten |
| `fields/meta.yml` | `createdby`, `createdat`, `modifiedby`, `modifiedat` (per Hook, schreibgeschützt), `changenote` | Termin, Team, Beitrag |
| `fields/changelog.yml` | `changelog` – Protokoll (Zeitpunkt, Person, Zustand, Änderung, Notiz) | Termin |
| `fields/cover.yml`, `fields/gallery.yml` | Titelbild, Bildergalerie | Termin, Team, Beitrag, Über uns |
| `fields/richtext.yml` | Writer-Feld mit wenigen Formaten | Texte der Redaktion |
| `sections/images.yml` | Bilderliste mit Warnung bei fehlendem Alternativtext | viele |
| `tabs/seo.yml`, `tabs/history.yml` | Reiter „SEO & Teilen“, „Bearbeitung“ | viele |
| `options/admin-only.yml` | Seite nur für Administration (Moderation sieht sie nicht) | Start, Über uns, Mitmachen, Kontakt, Rechtstexte … |
| `options/container.yml` | Übersichtsseite: Moderation legt darunter an, ändert sie aber nicht | Termine, Thekenteams, Aktuelles |

## Site (Stammdaten und Einstellungen)

| Feld | Zweck |
| --- | --- |
| `title` | Name der Kneipe (Arbeitstitel „Ehrenamtskneipe Erndtebrück“) |
| `claim` | Leitzeile |
| `description` | Kurzbeschreibung (Start, SEO) |
| `ogimage` | Standardbild zum Teilen |
| `venue`, `street`, `postalcode`, `city` | Veranstaltungsort und Anschrift |
| `latitude`, `longitude` | Koordinaten für den OpenStreetMap-Link |
| `email`, `phone` | öffentliche Kontaktdaten |
| `openinghours` (Struktur: `days`, `time`, `note`), `openingnote` | reguläre Öffnungszeiten |
| `social` (Struktur: `platform`, `url`) | Social-Media-Links |
| `operator`, `legalrepresentative`, `legalregister`, `legalresponsible`, `legalhoster`, `legalmailprovider` | Angaben für Impressum/Datenschutz |
| `approvalmode` | Freigabemodus an/aus |
| `requestrecipient` | Empfänger der Terminanfragen (leer = `email`) |
| `requestreceipt` | Eingangsbestätigung an Anfragende |
| `retentiondays` | Löschfrist für Anfragen in Tagen (30–730, Standard 180) |

In Rechtstexten stehen diese Werte als KirbyTag, z. B. `(stammdaten: street)`.
Fehlt ein Wert, erscheint ein markierter Platzhalter.

## Termin (`event`)

Ordner `termine/<JJJJMMTT>_<slug>/event.txt`, Entwürfe unter `termine/_drafts/`.

| Feld | Typ | Pflicht | Hinweis |
| --- | --- | --- | --- |
| `title` | Text | ja | Titel |
| `teaser` | Text (300) | – | Kurzbeschreibung |
| `text` | Writer | – | ausführliche Beschreibung |
| `category` | Auswahl | ja | kneipenabend, thekenteam, sonder, senioren, kultur, privat, geschlossen |
| `orgstatus` | Auswahl | ja | frei, angefragt, reserviert, freigabe, bestaetigt, veroeffentlicht, abgesagt, geschlossen, archiviert |
| `start` | Datum + Zeit | ja | Beginn |
| `end` | Datum + Zeit | – | Ende; leer = Beginn + 4 h |
| `allday` | Schalter | – | ganztägig |
| `doors` | Uhrzeit | – | Einlass |
| `location`, `address` | Text | – | leer = Stammdaten |
| `team` | Seite | – | Thekenteam |
| `cover`, `gallery` | Dateien | – | Titelbild, Bildergalerie |
| `audience` | Text | – | Zielgruppe |
| `admission` | Text | – | Eintrittsinformation (keine Preise erfinden) |
| `publiccontact` | Text | – | öffentliche Kontaktinformation |
| `internalnote` | Text | – | **intern** |
| `responsible` | Benutzer | – | verantwortliche redaktionelle Person, **intern** |
| `conflictaccepted` | Schalter | – | Überschneidung bewusst zulassen (nur Administration) |
| `seotitle`, `seodescription`, `ogimage`, `noindex` | | – | SEO |
| `createdby`, `createdat`, `modifiedby`, `modifiedat`, `changenote`, `changelog` | | – | Bearbeitung (per Hook) |

Anzeigen im Panel: Überschneidungen (`conflictInfo`) und Vollständigkeit
(`missingInfoText`). Modell: `site/models/event.php`.

## Thekenteam (`team`)

| Feld | Typ | öffentlich? |
| --- | --- | --- |
| `title` | Teamname | ja |
| `teamtype` | verein, unternehmen, initiative, privat | ja |
| `teaser`, `text` | Kurzbeschreibung, ausführliche Vorstellung | ja |
| `logo`, `cover`, `gallery` | Bilder | ja |
| `website`, `social` | öffentliche Links (nur http/https) | ja |
| `consent` | Einwilligung zur Veröffentlichung | Voraussetzung für jede öffentliche Anzeige |
| `consentnote` | Nachweis der Einwilligung | **intern** |
| `contactperson`, `contactemail`, `contactphone`, `internalnote` | interne Kontaktdaten | **nie** |

Kommende und vergangene Termine des Teams ermittelt das Modell
(`site/models/team.php`) automatisch – im Panel (Reiter „Termine“) und auf
der Teamseite.

## Meldung/Rückblick (`article`)

`kind` (meldung, rueckblick), `date`, `teaser`, `text`, `event` (optional),
`cover`, `gallery`, SEO, Bearbeitung.

## Anfrage (`request`)

Nur als Entwurf unter `anfragen/_drafts/`. Formularfelder schreibgeschützt:
`slotlabel`, `slotpage`, `wishdate`, `altdate`, `groupname`, `grouptype`,
`contactname`, `email`, `phone`, `intro`, `message`, `privacy`
(Zeitpunkt der Zustimmung), `submittedat`. Bearbeitbar: `processing` (neu,
in Bearbeitung, Termin angelegt, abgelehnt, erledigt), `handledby`,
`processingnote`.

## Weitere Seiten

| Seite | Template | Felder |
| --- | --- | --- |
| Start | `home` | `intro`, `modelheadline`, `modelsteps`, `photo`, `photocaption`, `joinheadline`, `jointext` |
| Über uns | `about` | `intro`, `text`, `facts`, `cover` |
| Mitmachen | `join` | `intro`, `steps`, `helpers`, `faq`, `cover` |
| Kontakt | `contact` | `intro`, `directions`, `accessibility` |
| Termin anfragen | `requestform` | `intro`, `privacynote` |
| Danke | `confirmation` | `text` |
| Rechtstexte | `legal` | `draftnote`, `text` (KirbyText), `reviewed` |
| Bausteine | `styleguide` | – |

## Dateien

| Vorlage | erlaubt | Felder |
| --- | --- | --- |
| `image` | JPEG, PNG, WebP, AVIF bis 10 MB, max. 8000 px | `alt`, `decorative`, `caption`, `credit`, `consent` (keine Personen / liegt vor / fehlt), `consentnote` |
| `logo` | JPEG, PNG, WebP, SVG bis 2 MB | `alt` |

SVG ist nur für Logos erlaubt; Kirby prüft SVG-Dateien beim Hochladen und
lehnt Dateien mit Skripten oder externen Verweisen ab.
