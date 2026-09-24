# CLAUDE.md

Arbeitsanweisungen für Claude Code in diesem Repository. Deployment nach dem
Muster von [astro-web-basic-template](https://github.com/Vladislavvk1337/astro-web-basic-template).

## Projekt

- Website einer ehrenamtlich betriebenen Begegnungskneipe in Erndtebrück
  (Arbeitstitel „Ehrenamtskneipe Erndtebrück“ – kein endgültiger Name).
- Zielgruppen: Gäste aller Generationen, Vereine, Initiativen, Unternehmen,
  private Gruppen, Ehrenamtliche, Redaktion (Administration, Moderation).
- Tonalität: freundlich, direkt, regional, ohne Werbephrasen, ohne
  Behördensprache, ohne Gender-Sonderzeichen; Leserinnen und Leser werden
  mit „ihr“ angesprochen.

## Stack und Grundsätze

- **Kirby 5** (Plainkit, Composer), PHP ≥ 8.2, keine fremden Plugins.
- Eigene Logik im Plugin `site/plugins/kneipe/`: reine Klassen in `src/`
  (ohne Kirby testbar), Kirby-Anbindung in `hooks.php`, `routes.php`,
  `blueprints.php`. Seitenmodelle in `site/models/`.
- **Eine Quelle je Sache:**
  - Stammdaten, Einstellungen → Panel (`content/site.txt`)
  - Farben, Schriften, Abstände → `assets/css/tokens.css` (Präfix `--ui-`)
  - Server-Namen, Domain, Phase → `deploy/site.env`
  - Container-Konfiguration → Umgebungsvariablen (`Dockerfile`, `Docker.md`)
  - Geheimnisse → Umgebung (`.env` lokal, `config/kirby.env` auf dem Server)
- Feldnamen klein, ohne Bindestrich; wiederverwendbare Blueprint-Teile in
  `fields/`, `sections/`, `tabs/`, `options/`.
- **Nie echte Daten erfinden** (Namen, Adressen, Telefonnummern, Zeiten,
  Rechtliches). Fehlendes als markierter Platzhalter `[Platzhalter: …]` und
  in `docs/project-data-needed.md` eintragen.
- **Datenschutz:** keine externen Schriften, Skripte, Karten, Tracker. Nur
  `kirby_session` auf dem Formular und im Panel. Interne Felder nie im
  Frontend ausgeben; Anfragen nie veröffentlichen.
- **Sicherheit:** Ausgaben mit `esc()`, Writer-Inhalte mit `toSafeHtml()`,
  KirbyText nur für Rechtstexte. Keine Inline-Skripte/-Styles (CSP).
- **Barrierefreiheit (WCAG 2.2 AA):** genau eine H1, keine übersprungenen
  Überschriftenebenen, Labels, sichtbarer Fokus, 44 px Ziele, Status nie
  nur über Farbe, `prefers-reduced-motion`. Alles muss ohne JavaScript gehen.
- Deutsche Texte und Kommentare, technische Bezeichner englisch.

## Prüfen vor jedem Commit

```bash
composer lint
composer test                          # Unit, Integration, HTTP
shellcheck -x -S warning deploy/*.sh docker/*.sh   # wenn deploy/ oder docker/ geändert wurde
```

Bei Änderungen am Frontend zusätzlich die Browser-Prüfung (`docs/testing.md`):
kein horizontales Scrollen bei 375–1920 px, keine Konsolenfehler.

## Nicht anfassen

- `deploy/basis-schutz-os/` – wird per Workflow gespiegelt; Anpassungen
  gehören nach `deploy/basis-schutz.env`.
- `kirby/`, `vendor/` – kommen von Composer.
- Geheimnisse und Laufzeitdaten (`.env`, `site/accounts/`, Anfragen unter
  `content/anfragen/_drafts/`) gehören nie ins Repository.
