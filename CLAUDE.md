# CLAUDE.md

Arbeitsanweisungen für Claude Code in diesem Repository. Betrieb:
[Docker.md](Docker.md) (Container und Kubernetes).

## Projekt

- Website einer ehrenamtlich betriebenen Begegnungskneipe in Erndtebrück
  (Arbeitstitel „Ehrenamtskneipe Erndtebrück“ – kein endgültiger Name).
- Zielgruppen: Gäste aller Generationen, Vereine, Initiativen, Unternehmen,
  private Gruppen, Ehrenamtliche, Redaktion (Administration, Moderation).
- Tonalität: freundlich, direkt, regional, ohne Werbephrasen, ohne
  Behördensprache, ohne Gender-Sonderzeichen; Leserinnen und Leser werden
  mit „ihr“ angesprochen.

## Stack und Grundsätze

- **Grav CMS 2.2** mit Admin2/API aus dem offiziellen Paket (Version und
  Prüfsumme nur in `scripts/grav-dist.sh`), PHP ≥ 8.3, keine weiteren
  fremden Plugins, keine Datenbank. Die Kirby-Fassung liegt in `kirby-legacy`.
- Eigene Logik im Plugin `user/plugins/kneipe/`: reine Klassen in `classes/`
  (Calendar, EventStatus, Overlap, Ics, RequestValidator, Workflow … ohne Grav
  testbar), Grav-Anbindung in `kneipe.php`, `Service.php`, `Guard.php`
  (Freigabe serverseitig für jede API-Operation). Darstellung im Theme
  `user/themes/kneipe/` (Twig, Blueprints für Admin2).
- **Eine Quelle je Sache:**
  - Stammdaten, fachliche Einstellungen → Seite „Einstellungen“ im Admin
    (`/data/pages/einstellungen`, Seed: `seed/pages/einstellungen`)
  - Farben, Schriften, Abstände → `user/themes/kneipe/css/tokens.css` (Präfix `--ui-`)
  - Grav-Konfiguration → `user/config/`, Abweichungen je Umgebung → `user/env/<env>/`
  - Container- und Cluster-Werte → Umgebungsvariablen (`Dockerfile`, `k8s/`, `Docker.md`)
  - Geheimnisse → Kubernetes-Secret bzw. `.env` (lokal), nie im Repository
- Frontmatter-Felder klein, ohne Bindestrich.
- **Nie echte Daten erfinden** (Namen, Adressen, Telefonnummern, Zeiten,
  Rechtliches). Fehlendes als markierter Platzhalter `[Platzhalter: …]` und
  in `docs/project-data-needed.md` eintragen.
- **Datenschutz:** keine externen Schriften, Skripte, Karten, Tracker. Sitzung
  nur bei Bedarf (Formular, Admin). Interne Felder nie im Frontend ausgeben;
  Anfragen nie veröffentlichen.
- **Sicherheit:** Twig-Autoescape; redaktionelles HTML nur über `safe_html()`
  bzw. `richtext()`, `|raw` nur für `kneipe.legalHtml()`. Kein Twig in
  Inhalten. Keine Inline-Skripte/-Styles (CSP).
- **Barrierefreiheit (WCAG 2.2 AA):** genau eine H1, keine übersprungenen
  Überschriftenebenen, Labels, sichtbarer Fokus, 44 px Ziele, Status nie
  nur über Farbe, `prefers-reduced-motion`. Alles muss ohne JavaScript gehen.
- **Container:** läuft als UID 33 mit schreibgeschütztem Dateisystem;
  beschreibbar nur `/data` (persistent) und `/tmp`. Grav-CLI immer im
  Grav-Verzeichnis ausführen (Grav leitet sein Wurzelverzeichnis aus dem
  Arbeitsverzeichnis ab).
- Deutsche Texte und Kommentare, technische Bezeichner englisch.

## Prüfen vor jedem Commit

```bash
scripts/dev-server.sh --prepare-only   # einmalig
php tests/run.php                      # Unit und HTTP (inkl. Freigabe über die API)
shellcheck -x -S warning scripts/*.sh  # wenn scripts/ geändert wurde
kubectl kustomize k8s/overlays/production > /dev/null   # wenn k8s/ geändert wurde
```

Bei Änderungen am Frontend zusätzlich die Browser-Prüfung (`docs/testing.md`):
kein horizontales Scrollen bei 375–1920 px, keine Konsolenfehler.

## Nicht anfassen

- `deploy/basis-schutz-os/` – wird per Workflow gespiegelt; Anpassungen
  gehören nach `deploy/basis-schutz.env`.
- `.grav/` – lokaler Grav-Kern und Daten (`scripts/dev-server.sh`).
- Geheimnisse und Laufzeitdaten (`.env`, `user/accounts/`, `user/pages/`,
  `user/data/`, `*-private.php`) gehören nie ins Repository.
