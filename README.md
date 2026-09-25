# Website der Ehrenamtskneipe Erndtebrück

Website einer ehrenamtlich betriebenen Begegnungskneipe in Erndtebrück
(Arbeitstitel „Ehrenamtskneipe Erndtebrück“): öffentlicher Terminkalender,
freie Termine, Terminanfragen mit manueller Freigabe, Thekenteams,
Aktuelles und Rechtstexte. Gebaut mit **Grav CMS 2.2** (Flat-File, keine
Datenbank), betrieben als Container in **Kubernetes**.

Die frühere Fassung mit Kirby CMS liegt im Branch `kirby-legacy`.

## Schnellstart

Lokal ohne Container (PHP ≥ 8.3 mit gd, intl, mbstring, curl, zip, dom):

```bash
scripts/dev-server.sh            # lädt Grav nach .grav/, startet http://localhost:8000
```

Konto anlegen (zweites Terminal; Grav-CLI immer im Grav-Verzeichnis):

```bash
cd .grav/grav
printf '%s' 'ein-langes-passwort' | GRAV_DATA_DIR=../data GRAV_CACHE_PATH=../cache \
  GRAV_LOG_PATH=../logs GRAV_TMP_PATH=../tmp GRAV_ENVIRONMENT=dev \
  bin/plugin kneipe user --username=admin --email=admin@example.org --group=administration --password-stdin
```

Admin: <http://localhost:8000/admin>

Mit Docker:

```bash
docker build -t kneipe-web .
docker run --rm -p 8080:8080 -v kneipe-data:/data -e GRAV_ENVIRONMENT=dev kneipe-web
```

Kubernetes (Secret vorher anlegen, siehe [Docker.md](Docker.md)):

```bash
kubectl apply -k k8s/overlays/staging
kubectl rollout status deployment/kneipe-web -n kneipe-staging
```

## Aufbau

| Pfad | Inhalt |
| --- | --- |
| `user/plugins/kneipe/` | Projekt-Plugin: Termine, Freigabeworkflow, Anfrageformular, iCalendar, Sitemap, Health-Checks, Redaktionsübersicht, CLI |
| `user/themes/kneipe/` | Theme: Twig-Templates, CSS (`css/tokens.css` für Farben, Schriften, Abstände), Blueprints für den Admin |
| `user/config/`, `user/env/` | Grav-Konfiguration, je Umgebung (dev, staging, production) |
| `seed/pages/` | Beispielinhalte für den ersten Start |
| `tools/migrate-kirby-to-grav.php` | Migration der Kirby-Inhalte (liest nur) |
| `Dockerfile`, `docker/`, `scripts/`, `setup.php` | Container |
| `k8s/` | Kubernetes (Kustomize-Basis und Overlays) |
| `tests/` | Unit-, HTTP- und Browser-Tests |

## Dokumentation

| Dokument | Für |
| --- | --- |
| [Docker.md](Docker.md) | Betrieb: Container, Kubernetes, Konfiguration, Backup, Updates, Migration, Testplan, Lizenzen |
| [docs/architecture.md](docs/architecture.md) | Aufbau der Anwendung, Freigabeworkflow, Entscheidungen |
| [docs/content-model.md](docs/content-model.md) | Seitenarten und Felder |
| [docs/editor-guide.md](docs/editor-guide.md) | Redaktion: Admin, Rollen, Ablauf von der Anfrage zum Termin |
| [docs/security.md](docs/security.md) | Sicherheit und Datenschutz |
| [docs/testing.md](docs/testing.md) | Tests und Prüfprotokoll |
| [docs/project-data-needed.md](docs/project-data-needed.md) | noch fehlende Angaben (Platzhalter) |

## Prüfen

```bash
scripts/dev-server.sh --prepare-only   # einmalig: Grav-Kern für Tests
php tests/run.php                      # Unit- und HTTP-Tests
shellcheck -x -S warning scripts/*.sh
```
