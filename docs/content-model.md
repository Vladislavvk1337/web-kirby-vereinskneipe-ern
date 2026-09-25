# Inhaltsmodell

Jede Seite ist ein Ordner mit einer Markdown-Datei `<template>.md`
(Frontmatter + Text) und ihren Bildern. Die Formulare im Admin beschreiben
die Blueprints unter `user/themes/kneipe/blueprints/`. Bildmetadaten
(Alternativtext, Bildunterschrift, Bildnachweis) stehen in
`<bild>.meta.yaml` und werden im Admin bei den Medien gepflegt.

## Einstellungen (`settings`, Seite `/einstellungen`, nie öffentlich)

| Feld | Bedeutung |
| --- | --- |
| `name`, `claim`, `description` | Name, Leitzeile, Kurzbeschreibung |
| `venue`, `street`, `postalcode`, `city`, `latitude`, `longitude` | Ort und Anschrift, Koordinaten für den Kartenlink |
| `email`, `phone` | Kontakt |
| `openinghours` (Liste: `days`, `time`, `note`), `openingnote` | Öffnungszeiten |
| `social` (Liste: `platform`, `url`) | soziale Netzwerke |
| `operator`, `legalrepresentative`, `legalregister`, `legalresponsible`, `legalhoster`, `legalmailprovider` | Angaben für Impressum und Datenschutz |
| `approvalmode` | Freigabemodus (Standard an) |
| `requestrecipient`, `requestreceipt`, `retentiondays` | Empfänger der Anfragen, Eingangsbestätigung, Löschfrist in Tagen |

Rechtstexte holen Werte mit `(stammdaten: street)` usw.; fehlende Angaben
erscheinen markiert.

## Termin (`event`)

| Feld | Bedeutung | öffentlich |
| --- | --- | --- |
| `title` | Titel (bei freien Terminen optional) | ja (privat: „Geschlossene Gesellschaft“) |
| `category` | kneipenabend, thekenteam, sonder, senioren, kultur, privat, geschlossen | Art ja |
| `orgstatus` | frei, angefragt, reserviert, freigabe, bestaetigt, veroeffentlicht, abgesagt, geschlossen, archiviert | als öffentlicher Status |
| `start`, `end` (`Y-m-d H:i`), `allday`, `doors` (`HH:MM`) | Zeiten; ohne Ende 4 Stunden | ja |
| `team` | Route des Thekenteams | nur mit Einwilligung des Teams |
| `teaser`, Text (Markdown) | Beschreibung | ja (nicht bei privat) |
| `location`, `address` | abweichender Ort | ja |
| `audience`, `admission`, `publiccontact` | Für wen, Eintritt, Kontakt | ja (nicht bei privat) |
| `cover`, `gallery` | Bilder | ja (nicht bei privat) |
| `seotitle`, `seodescription` | Suchmaschinen | ja |
| `responsible`, `internalnote` | verantwortliche Person, interne Notiz | **nie** |
| `conflictaccepted` | Überschneidung zugelassen (nur Administration) | nie |
| `createdby/at`, `modifiedby/at`, `changelog`, `uuid` | automatisch | nie |
| `changenote` | Notiz zur Änderung, wandert ins Protokoll | nie |
| `published` | Grav-Veröffentlichung | – |

## Thekenteam (`team`)

`title`, `teamtype` (verein, unternehmen, initiative, privat), `consent`
(Einwilligung), `teaser`, Text, `website`, `social`, `logo`, `cover`,
`gallery` sind öffentlich (nur mit `consent` und veröffentlicht).
`consentnote`, `contactperson`, `contactemail`, `contactphone`,
`internalnote` sind **nie** öffentlich.

## Meldung/Rückblick (`article`)

`title`, `kind` (meldung, rueckblick), `date`, `teaser`, Text, `event`
(Route des zugehörigen Termins), `cover`, `gallery`, SEO-Felder.

## Anfrage (`request`, unter `/anfragen`, nie öffentlich)

Aus dem Formular (nur lesbar): `slotlabel`, `slotpage`, `wishdate`,
`altdate`, `groupname`, `grouptype`, `contactname`, `email`, `phone`,
`intro`, `message`, `privacy`, `submittedat`. Bearbeitung durch die
Redaktion: `processing` (neu, inbearbeitung, terminangelegt, abgelehnt,
erledigt), `handledby`, `processingnote`.

## Weitere Seiten

| Template | Felder |
| --- | --- |
| `home` | `intro`, `modelheadline`, `modelsteps`, `photo`, `photocaption`, `joinheadline`, `jointext` |
| `join` | `intro`, `steps`, `helpers` (Markdown), `faq` |
| `about` | `intro`, Text, `facts`, `cover` |
| `contact` | `intro`, Text (Anfahrt), `accessibility` |
| `events`, `teams`, `news` | `intro` |
| `requestform` | `intro`, `privacynote` |
| `confirmation`, `error` | `text` |
| `legal` | Text (Markdown mit einfachen Zeilenumbrüchen), `draftnote`, `reviewed` |
| `default` | Text |
