# Fehlende Projektdaten

Diese Angaben lagen bei der Umsetzung nicht vor. Die Website funktioniert
trotzdem; fehlende Werte erscheinen als deutlich markierter Platzhalter
(gelb gestrichelt, z. B. **[Straße und Hausnummer]**). Nichts davon wurde
erfunden.

## Im Panel pflegen (Übersicht → Stammdaten / Einstellungen)

| Angabe | Feld | Stand |
| --- | --- | --- |
| Endgültiger Name | Seitentitel | Arbeitstitel „Ehrenamtskneipe Erndtebrück“ |
| Leitzeile | `claim` | Vorschlag „Von Erndtebrück. Für Erndtebrück. Zusammen.“ |
| Name des Veranstaltungsorts | `venue` | fehlt |
| Straße, Hausnummer, PLZ | `street`, `postalcode` | fehlt (Ort: Erndtebrück) |
| Koordinaten | `latitude`, `longitude` | fehlt – Kartenlink sucht bis dahin nach der Adresse |
| Allgemeine E-Mail-Adresse | `email` | Demo: `kontakt@example.org` |
| Telefonnummer | `phone` | fehlt |
| Reguläre Öffnungszeiten | `openinghours` | Platzhalter |
| Social-Media-Links | `social` | keine |
| Betreiber/Träger mit Rechtsform | `operator` | fehlt |
| Vertretungsberechtigte | `legalrepresentative` | fehlt |
| Registereintrag | `legalregister` | fehlt |
| Verantwortlich nach § 18 Abs. 2 MStV | `legalresponsible` | fehlt |
| Hosting-Anbieter | `legalhoster` | fehlt |
| E-Mail-Anbieter | `legalmailprovider` | fehlt |
| Empfänger der Terminanfragen | `requestrecipient` | Demo: `redaktion@example.org` |
| Löschfrist für Anfragen | `retentiondays` | Vorschlag 180 Tage – mit dem Träger abstimmen |

Wenn Anschrift, PLZ und Ort vollständig sind, gibt die Startseite
automatisch strukturierte Daten (schema.org `BarOrPub`) aus.

## Texte mit Platzhaltern

| Seite | Was fehlt |
| --- | --- |
| Impressum | Pflichtangaben je nach Rechtsform; rechtliche Prüfung |
| Datenschutz | Verantwortliche Stelle, Hoster, Mailanbieter, Aufsichtsbehörde, Stand; rechtliche Prüfung der Rechtsgrundlagen |
| Barrierefreiheit | Ergebnis und Datum einer Prüfung (falls durchgeführt) |
| Über uns | Wer die Kneipe trägt |
| Mitmachen, häufige Fragen | Einweisung, Anzahl Helfer, Einnahmen/Abrechnung, Hygiene und Jugendschutz |
| Kontakt | Anfahrt, Parken, Zugang/Barrieren vor Ort |
| Termine | Eintrittsinformation beim Demo-Liederabend |

Rechtstexte zeigen bis zur Prüfung den Hinweis „Entwurf – noch nicht
rechtlich geprüft“ (Schalter „Prüfhinweis anzeigen“).

## Bilder und Marke

| Was | Stand |
| --- | --- |
| Fotos aus der Kneipe | gezeichnete Platzhalterbilder mit Aufschrift „Platzhalterbild – Foto folgt“ |
| Logo | eigenes, reduziertes Zeichen (Brücke, Theke, Begegnung) in `assets/brand/` – kein Wappen; Freigabe durch den Träger nötig |
| Social-Sharing-Bild | `assets/brand/og-default.png` aus `og-template.svg` mit Arbeitstitel |
| Logos der Thekenteams | Demo-Logos „BV“/„BI“ |

## Technik

| Was | Wo |
| --- | --- |
| Domain, Vorschau- und Live-Adresse, Benutzer | `deploy/site.env` (enthält `example.de`) |
| SMTP-Zugang | Server: `config/kirby.env` |
| Kirby-Lizenz | vor dem Livegang kaufen und im Panel aktivieren |
| Token für den basis-schutz-os-Spiegel | GitHub-Secret `BASIS_SCHUTZ_TOKEN` |
