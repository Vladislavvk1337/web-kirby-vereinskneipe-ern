# Betrieb mit Container und Kubernetes

Betriebsdokumentation der Website der Ehrenamtskneipe Erndtebrück (Grav CMS)
für Docker, Podman und Kubernetes. Alle Namen, Ports und Pfade entsprechen den
Dateien im Repository (`Dockerfile`, `scripts/`, `docker/`, `k8s/`). Werte, die
als **ANNAHME** oder **PLATZHALTER** markiert sind, müssen vor dem Livegang
an den tatsächlichen Cluster angepasst werden.

| Was | Wert |
| --- | --- |
| Image | `ghcr.io/vladislavvk1337/web-kirby-vereinskneipe-ern` (Tags: Kapitel 22) |
| Port im Container | `8080` (HTTP, TLS endet am Ingress) |
| Benutzer im Container | `www-data`, UID/GID `33` |
| Persistente Daten | `/data` (Kubernetes: PVC `kneipe-web-data`) |
| Flüchtige Daten | `/tmp` (Kubernetes: `emptyDir`) |
| Kubernetes-Namen | Deployment/Service/Ingress `kneipe-web`, ConfigMap `kneipe-web-config`, Secret `kneipe-web-secrets` |
| Namespaces | `kneipe-dev`, `kneipe-staging`, `kneipe` (Produktion) |
| Health-URLs | `/healthz` (lebt), `/readyz` (bereit) |

Inhalt:

1. [Bestandsaufnahme](#1-bestandsaufnahme)
2. [Zielarchitektur](#2-zielarchitektur)
3. [Voraussetzungen](#3-voraussetzungen)
4. [Repository- und Verzeichnisstruktur](#4-repository--und-verzeichnisstruktur)
5. [Build des Images mit Docker](#5-build-des-images-mit-docker)
6. [Build des Images mit Podman](#6-build-des-images-mit-podman)
7. [Lokaler Start mit Docker](#7-lokaler-start-mit-docker)
8. [Lokaler Start mit Podman](#8-lokaler-start-mit-podman)
9. [Lokale Konfiguration](#9-lokale-konfiguration)
10. [Umgebungsvariablen](#10-umgebungsvariablen)
11. [Kubernetes-Voraussetzungen](#11-kubernetes-voraussetzungen)
12. [Anlegen von Namespace und Secrets](#12-anlegen-von-namespace-und-secrets)
13. [Deployment mit kubectl](#13-deployment-mit-kubectl)
14. [Deployment mit Kustomize](#14-deployment-mit-kustomize)
15. [Konfiguration von Ingress und DNS](#15-konfiguration-von-ingress-und-dns)
16. [TLS und cert-manager](#16-tls-und-cert-manager)
17. [Persistenter Speicher](#17-persistenter-speicher)
18. [Kalender und Terminanfragen](#18-kalender-und-terminanfragen)
19. [E-Mail-Konfiguration](#19-e-mail-konfiguration)
20. [Logs und Troubleshooting](#20-logs-und-troubleshooting)
21. [Healthchecks](#21-healthchecks)
22. [Updates und Rollbacks](#22-updates-und-rollbacks)
23. [Backup und Restore](#23-backup-und-restore)
24. [Sicherheitsmaßnahmen](#24-sicherheitsmaßnahmen)
25. [Monitoring](#25-monitoring)
26. [CI/CD](#26-cicd)
27. [Migration vom alten Linux-Server](#27-migration-vom-alten-linux-server)
28. [Testplan](#28-testplan)
29. [Lizenzen](#29-lizenzen)
30. [Deinstallation](#30-deinstallation)
31. [Bekannte Einschränkungen](#31-bekannte-einschränkungen)
32. [Validierung](#32-validierung)

Ein Helm-Chart gibt es nicht; das Deployment erfolgt mit Kustomize bzw. kubectl.

---

## 1. Bestandsaufnahme

**Ausgangssystem** (Branch `kirby-legacy`): Kirby 5 auf einem Linux-Server
(Caddy, PHP-FPM, systemd-Timer für Löschfrist und Backup) bzw. als
Docker-Image. Inhalte als Textdateien unter `content/`, Konten unter
`site/accounts/`, Anfragen als Entwürfe unter `content/anfragen/_drafts/`.

**Übernommene Funktionen** (alle umgesetzt und getestet):

| Funktion | Kirby | Grav |
| --- | --- | --- |
| Öffentlicher Kalender (Liste, Monatsansicht, Filter, Rückblick) | Templates, Controller | Theme `kneipe` + `CalendarView` |
| Terminseiten, iCalendar (Feed und Einzeltermin) | Templates `*.ics.php` | Plugin-Routen `/termine.ics`, `/termine/<termin>.ics` |
| Öffentliche Terminanfrage mit serverseitiger Validierung | `RequestForm` | `RequestForm` (Grav) + unveränderter `RequestValidator` |
| Spam- und CSRF-Schutz: Nonce, Honeypot, Zeitfalle, Herkunft, Rate-Limit | ja | ja |
| Speicherung als Flat Files (Anfragen nie öffentlich) | Entwürfe | Seiten mit `published: false`, `routable: false` |
| E-Mail an die Redaktion, Eingangsbestätigung | Kirby-Mail | Grav-Plugin `email` (Symfony Mailer) |
| Rollen Administration/Moderation, Freigabeworkflow | Hooks | Gruppen + `Guard` (serverseitig für Admin2/API) |
| Doppelbelegungen blockieren Veröffentlichen | ja | ja |
| Thekenteams mit Einwilligung, Aktuelles, Rechtstexte | ja | ja |
| Löschfrist für Anfragen | systemd-Timer | `/readyz` (täglich), CLI `bin/plugin kneipe maintenance` |
| Health-Checks, Sitemap, robots.txt | ja | ja |

**Annahmen** (deutlich gekennzeichnet, wo sie in Dateien stehen):

- Ingress-Controller: ingress-nginx (`ingressClassName: nginx`), Namespace `ingress-nginx`.
- Zertifikate: cert-manager mit einem ClusterIssuer `letsencrypt` (optional, Kapitel 16).
- Speicher: eine Standard-StorageClass mit ReadWriteOnce.
- DNS: CoreDNS im Namespace `kube-system` mit Label `k8s-app: kube-dns`.
- SMTP: externer Server über Port 587 (STARTTLS) oder 465 (TLS).
- Pod-Netz des Ingress-Controllers liegt in privaten Adressbereichen (`KNEIPE_TRUSTED_PROXIES`).
- Domains `www.example.org`, `staging.kneipe.example.org`, `dev.kneipe.example.org` sind **PLATZHALTER**.

**Offene Punkte**: echte Domains, SMTP-Zugang, Stammdaten und Rechtstexte
(`docs/project-data-needed.md`), Backup-Werkzeug des Clusters (Kapitel 23),
Lizenz der Admin2-Schrift (Kapitel 29).

**Migrationsrisiken** und wie sie behandelt sind:

| Risiko | Behandlung |
| --- | --- |
| Admin2 ist neu (Grav 2, SPA über die REST-API) – Hooks des klassischen Admins greifen nicht | Freigaberegeln in `Guard.php` auf API-Ebene (`onAdminSave`, Löschen, Kopieren, Verschieben, Sortieren); HTTP-Tests prüfen jede Regel |
| Writer-HTML → Markdown | Migrationswerkzeug mit Tests; Rechtstexte bleiben Markdown |
| Grav braucht einen beschreibbaren Konfigurationsordner für Schlüssel | eigene Ebene `$GRAV_DATA_DIR/config` (`setup.php`), Schlüssel optional aus dem Secret |
| Ohne Konto bietet Admin2 jedem die Einrichtung des Superusers an | Entrypoint verweigert in staging/production den Start ohne Erstkonto |
| Flat Files und mehrere Pods | genau ein Pod (Kapitel 17) |

## 2. Zielarchitektur

```
 Internet ──HTTPS──▶ Ingress (TLS, Redirect) ──HTTP──▶ Service kneipe-web :80
                                                         │
                                                         ▼
                                          Pod kneipe-web (1 Replik, UID 33)
                                          Apache + mod_php 8.4, Port 8080
                                          Grav 2.2 (read-only im Image)
                                          ├── /data  ◀── PVC kneipe-web-data (RWO)
                                          │     pages/  accounts/  data/  media/  config/
                                          └── /tmp   ◀── emptyDir (Cache, Sitzungen, Bildvarianten)
                                                         │ Egress nur DNS + SMTP
                                                         ▼
                                                    SMTP-Server (extern)
```

**Container**: offizielles `php:8.4-apache` (Debian), Apache mit mod_php.
Begründung: ein Prozess, keine zusätzliche FPM-Konfiguration, Grav liefert
fertige Apache-Regeln (hier fest in `docker/apache-grav.conf`, keine
`.htaccess`). Nginx + PHP-FPM wäre möglich, bräuchte aber zwei Prozesse oder
zwei Container und einen gemeinsamen Socket.

**Multi-Stage-Build**: Stage `grav` lädt das offizielle Paket
`grav-admin-v2.2.0.zip` (Core + Admin2 + API und deren Plugins), prüft die
SHA-256-Summe und entfernt Beispielinhalte und nicht benötigte Plugins
(`scripts/grav-dist.sh`). Stage `runtime` enthält nur PHP, Apache, die
gebauten Erweiterungen und die Laufzeitdateien. Plugins werden nicht per GPM
zur Laufzeit installiert, sondern kommen versioniert mit dem Paket – das ist
reproduzierbar und funktioniert mit schreibgeschütztem Dateisystem. Composer
wird nicht gebraucht: Grav und seine Plugins bringen ihre `vendor/`-Ordner mit.
Die PHP-Erweiterungen werden in der Runtime-Stage kompiliert und die
Build-Pakete im selben Layer entfernt (Muster der offiziellen PHP-Images) –
eine eigene Builder-Stage dafür brächte keine kleinere Runtime.

**Persistenz**: Programmcode und Konfiguration im Image, nur redaktionelle
Daten auf dem Volume (Kapitel 17). Die Image-Verzeichnisse `user/pages`,
`user/accounts`, `user/data`, `user/media` sind per Symlink mit `/data/…`
verbunden – so bleiben die Medien-URLs (`/user/pages/…/bild.jpg`) gültig.

**Konfiguration**: drei Ebenen, die erste gewinnt (`setup.php`):

1. `/data/config` – Laufzeit: nur die Schlüsseldateien `security-private.php`
   und `plugins/api-private.php` (aus dem Secret oder von Grav erzeugt)
2. `user/env/<GRAV_ENVIRONMENT>/config` – dev, staging, production (Image)
3. `user/config` – Grundkonfiguration (Image)

Einzelwerte überschreibt Grav selbst aus Umgebungsvariablen
`GRAV_CONFIG__<bereich>__<schlüssel>` (bei `GRAV_CONFIG=true`). Stammdaten
und fachliche Einstellungen (Adresse, Öffnungszeiten, Freigabemodus,
Empfänger der Anfragen, Löschfrist) pflegt die Administration im Admin auf
der Seite „Einstellungen“ – sie liegen damit auf dem Volume.

**Ingress**: ein Ingress-Objekt für Website und `/admin`; TLS am Ingress.
Alternativen in Kapitel 15.

## 3. Voraussetzungen

| Zweck | Werkzeug |
| --- | --- |
| Image bauen und lokal starten | Docker ≥ 24 mit BuildKit **oder** Podman ≥ 4.4 (Buildah) |
| Kubernetes | Cluster ≥ 1.27, kubectl ≥ 1.27 (enthält Kustomize) |
| Optional | kustomize ≥ 5, cert-manager, ein CNI mit NetworkPolicy (Calico, Cilium, Canal in RKE2) |
| Lokale Entwicklung ohne Container | PHP ≥ 8.3 mit gd, intl, mbstring, curl, zip, dom (`scripts/dev-server.sh`) |
| Prüfwerkzeuge (CI) | shellcheck, hadolint, kubeconform, kube-linter, Trivy |

## 4. Repository- und Verzeichnisstruktur

```
Dockerfile                    Multi-Stage-Build (grav → runtime)
.dockerignore                 nur freigegebene Pfade gelangen in den Build-Kontext
.trivyignore                  begründete Ausnahmen des Image-Scans
.env.example                  Vorlage für docker/podman --env-file (ohne Werte)
setup.php                     Grav-Setup: Konfigurationsebene /data/config
docker/apache-grav.conf       Apache-Site: Sperren, Security-Header, CSP, RemoteIP
docker/php-grav.ini           PHP-Einstellungen (über Umgebungsvariablen)
scripts/grav-dist.sh          Grav-Paket laden, Prüfsumme, Beispielinhalte entfernen
scripts/entrypoint.sh         Start: prüfen, Verzeichnisse, Erststart, Schlüssel, Erstkonto
scripts/healthcheck.sh        Health-Check für docker/podman
scripts/dev-server.sh         lokaler Start ohne Container
user/config/                  Grundkonfiguration (system, site, security, groups, plugins)
user/env/{dev,staging,production}/config/   Abweichungen je Umgebung
user/plugins/kneipe/          Projekt-Plugin (Logik, Freigabe, Formular, Feeds, Health, CLI)
user/themes/kneipe/           Theme (Twig, CSS, Schriften, Symbole, Blueprints für Admin2)
seed/pages/                   Beispielinhalte für den Erststart (aus der Migration erzeugt)
tools/migrate-kirby-to-grav.php   Migration der Kirby-Inhalte (liest nur)
k8s/base/                     namespace, configmap, secret.example, pvc, deployment,
                              service, ingress, network-policy, kustomization
k8s/overlays/{dev,staging,production}/kustomization.yaml
tests/                        Unit-, HTTP- und Browser-Tests
docs/                         Architektur, Inhaltsmodell, Redaktion, Sicherheit, Tests
deploy/                       nur für den alten Linux-Server bis zur Abschaltung
                              (basis-schutz-os; vom Container nicht verwendet)
```

Abweichung von der vorgegebenen Dateiliste: Die Kustomize-Basis liegt unter
`k8s/base/` statt direkt in `k8s/`. Kustomize lehnt eine Basis ab, deren
Verzeichnis die Overlays enthält (Zykluserkennung). Die Dateinamen sind
unverändert. Zusätzlich nötig: `setup.php`, `docker/`, `scripts/grav-dist.sh`.

Im Image:

| Pfad | Inhalt | Schreibbar |
| --- | --- | --- |
| `/var/www/grav` | Grav-Kern, Plugins, Theme, Konfiguration | nein |
| `/var/www/grav/user/{pages,accounts,data,media}` | Symlinks nach `/data/…` | über `/data` |
| `/var/www/grav/{images,assets}` | Symlinks nach `/tmp/grav/…` (Bildvarianten, Asset-Cache) | über `/tmp` |
| `/opt/grav-seed/pages` | Beispielinhalte für den Erststart | nein |
| `/data` | Volume | ja |
| `/tmp` | Cache, Logs, Sitzungen, Apache-Laufzeit | ja |

## 5. Build des Images mit Docker

```bash
docker build -t ghcr.io/vladislavvk1337/web-kirby-vereinskneipe-ern:dev .

# mit OCI-Metadaten wie in der CI
docker build \
  --build-arg VERSION=1.0.0 \
  --build-arg REVISION="$(git rev-parse HEAD)" \
  --build-arg CREATED="$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
  -t ghcr.io/vladislavvk1337/web-kirby-vereinskneipe-ern:1.0.0 .
```

Build-Argumente: `PHP_VERSION` (Standard `8.4`), `VERSION`, `REVISION`,
`CREATED`. Die Grav-Version und ihre Prüfsumme stehen nur in
`scripts/grav-dist.sh` (`GRAV_VERSION`, `GRAV_SHA256`). Der Build braucht
Zugriff auf github.com (Grav-Paket) und die Debian-Paketquellen (Bibliotheken
für gd, intl, zip).

Der Build führt `bin/grav cache` einmal aus: Das prüft, dass Kern, Plugins und
Konfiguration zusammenpassen, und legt `user/config/versions.yaml` an.

## 6. Build des Images mit Podman

```bash
podman build --format docker -t ghcr.io/vladislavvk1337/web-kirby-vereinskneipe-ern:dev .
```

`--format docker` übernimmt `HEALTHCHECK` und `STOPSIGNAL` (das OCI-Format
kennt beide nicht). `COPY --chmod` benötigt Buildah ≥ 1.24 (Podman ≥ 4).

## 7. Lokaler Start mit Docker

Schnellstart in der Umgebung `dev` (Beispielinhalte, Einrichtung des ersten
Kontos über `/admin` erlaubt):

```bash
docker volume create kneipe-data
docker run --rm -p 8080:8080 -v kneipe-data:/data \
  -e GRAV_ENVIRONMENT=dev \
  ghcr.io/vladislavvk1337/web-kirby-vereinskneipe-ern:dev
# http://localhost:8080  –  Admin: http://localhost:8080/admin
```

Wie im Cluster (schreibgeschützt, ohne Capabilities, Werte aus `.env`):

```bash
cp .env.example .env    # ausfüllen, GRAV_CONFIG__system__custom_base_url=http://localhost:8080
docker run -d --name kneipe -p 8080:8080 \
  --read-only --tmpfs /tmp:uid=33,gid=33 --cap-drop ALL --security-opt no-new-privileges \
  -v kneipe-data:/data --env-file .env \
  ghcr.io/vladislavvk1337/web-kirby-vereinskneipe-ern:dev
docker logs -f kneipe
```

In staging und production setzt Grav das Sitzungs-Cookie mit `Secure`;
Browser akzeptieren das für `http://localhost`.

Konto anlegen oder Passwort setzen (Passwort über STDIN, nicht als Argument):

```bash
printf '%s' 'ein-langes-passwort' | docker exec -i kneipe php bin/plugin kneipe user \
  --username=anna --email=anna@example.org --group=moderation --name="Anna" --password-stdin
```

## 8. Lokaler Start mit Podman

```bash
podman volume create kneipe-data
podman run --rm -p 8080:8080 -v kneipe-data:/data \
  -e GRAV_ENVIRONMENT=dev \
  ghcr.io/vladislavvk1337/web-kirby-vereinskneipe-ern:dev
```

Rootless-Podman: Benannte Volumes übernehmen beim ersten Einhängen die
Besitzer aus dem Image (`/data` gehört UID 33). Für ein Host-Verzeichnis statt
eines Volumes `-v ./daten:/data:U` verwenden (`:U` passt den Besitz an die
Container-UID an). Mit `--read-only --tmpfs /tmp` läuft das Image wie im
Cluster; Konten legt man wie in Kapitel 7 mit `podman exec -i` an.

## 9. Lokale Konfiguration

- **Ohne Container**: `scripts/dev-server.sh` lädt Grav nach `.grav/`, verlinkt
  die Projektdateien und startet `php -S localhost:8000`. Daten liegen unter
  `.grav/data` (Beispielinhalte beim ersten Start). Konto anlegen – im
  Verzeichnis `.grav/grav`, weil Grav sein Wurzelverzeichnis aus dem
  Arbeitsverzeichnis ableitet:

  ```bash
  cd .grav/grav
  printf '%s' 'ein-langes-passwort' | GRAV_DATA_DIR=../data GRAV_CACHE_PATH=../cache \
    GRAV_LOG_PATH=../logs GRAV_TMP_PATH=../tmp GRAV_ENVIRONMENT=dev \
    bin/plugin kneipe user --username=admin --email=admin@example.org --group=administration --password-stdin
  ```

- **Mit Container**: `.env` aus `.env.example`, `--env-file .env`.
- `GRAV_ENVIRONMENT=dev` zeigt Fehlerdetails, lädt Templates neu und sperrt
  Suchmaschinen; es verlangt keine Basis-URL und kein Erstkonto.

## 10. Umgebungsvariablen

### Grav

| Variable | Standard (Image) | Zweck | Wohin |
| --- | --- | --- | --- |
| `GRAV_ENVIRONMENT` | `production` | `dev`, `staging` oder `production` (wählt `user/env/<name>`) | ConfigMap |
| `GRAV_CONFIG` | `true` | schaltet die Auswertung von `GRAV_CONFIG__*` ein | ConfigMap |
| `GRAV_CONFIG__system__custom_base_url` | – | öffentliche Adresse, z. B. `https://www.example.org` (staging/production Pflicht) | ConfigMap |
| `GRAV_CONFIG__plugins__login__site_host` | – | Adresse für Links in Passwort-Mails | ConfigMap |
| `GRAV_CONFIG__plugins__email__…` | Versand aus (`engine: none`) | SMTP, Kapitel 19 | ConfigMap, Passwort: Secret |
| `GRAV_CONFIG__<bereich>__<schlüssel>` | – | jeder Grav-Konfigurationswert, z. B. `GRAV_CONFIG__system__errors__display=1` | ConfigMap |
| `GRAV_NONCE_KEY` | – | Schlüssel für CSRF-Nonces (≥ 32 Zeichen); sonst erzeugt Grav ihn beim ersten Bedarf auf dem Volume | **Secret** |
| `GRAV_API_JWT_SECRET` | – | Signaturschlüssel der Admin-Anmeldung (≥ 32 Zeichen); sonst von Grav erzeugt | **Secret** |
| `GRAV_DATA_DIR` | `/data` | Wurzel der persistenten Daten | fest |
| `GRAV_CACHE_PATH`, `GRAV_LOG_PATH`, `GRAV_TMP_PATH`, `GRAV_BACKUP_PATH` | `/tmp/grav/…` | flüchtige Grav-Verzeichnisse | fest |

### Projekt

| Variable | Standard | Zweck | Wohin |
| --- | --- | --- | --- |
| `KNEIPE_ADMIN_USERNAME`, `KNEIPE_ADMIN_EMAIL` | – | erstes Administrationskonto, nur wenn noch keins existiert | Secret |
| `KNEIPE_ADMIN_PASSWORD` | – | Passwort dazu (≥ 12 Zeichen); danach aus dem Secret entfernbar | **Secret** |
| `KNEIPE_ADMIN_NAME` | `Administration` | Anzeigename des Erstkontos | Secret/ConfigMap |
| `KNEIPE_ALLOW_WEB_SETUP` | `false` | staging/production ohne Konto trotzdem starten (Einrichtung über `/admin` – nur in geschützter Umgebung) | ConfigMap |
| `KNEIPE_SEED_CONTENT` | `true` | Beispielinhalte in ein **leeres** `/data/pages` kopieren | ConfigMap |
| `KNEIPE_RATE_LIMIT` | `5` | vollständige Anfragen je IP und Stunde | ConfigMap |
| `KNEIPE_MIN_SECONDS` | `3` | Zeitfalle: frühestens nach … Sekunden absenden | ConfigMap |
| `KNEIPE_NOINDEX` | `false` | Suchmaschinen aussperren (in staging immer aktiv) | ConfigMap |
| `KNEIPE_ACCESS_LOG` | `false` | Apache-Zugriffsprotokoll auf stdout (ohne Query und Cookies) | ConfigMap |
| `KNEIPE_LOG_STDERR` | `true` | Grav-Meldungen ab Warnung nach stderr statt in eine Datei | ConfigMap |
| `KNEIPE_TRUSTED_PROXIES` | private Netze + `127.0.0.1` | Proxys, deren `X-Forwarded-For` gilt (Client-IP für Rate-Limit und Anmeldesperre) | ConfigMap |
| `KNEIPE_OPEN_BASEDIR` | `/var/www/grav/:/data/:/tmp/:/opt/grav-seed/` | `open_basedir` für PHP im Webserver | fest |
| `KNEIPE_REQUEST_BODY_LIMIT` | `13631488` | maximale Anfragegröße in Byte (Apache) | ConfigMap |

### PHP und Apache

| Variable | Standard | Zweck |
| --- | --- | --- |
| `PHP_TIMEZONE` | `Europe/Berlin` | Zeitzone (zusätzlich `system.timezone` in Grav) |
| `PHP_MEMORY_LIMIT` | `256M` | Speichergrenze je Anfrage |
| `PHP_UPLOAD_MAX_FILESIZE` / `PHP_POST_MAX_SIZE` | `10M` / `12M` | Bild-Uploads im Admin |
| `PHP_MAX_EXECUTION_TIME` | `60` | Sekunden |
| `PHP_OPCACHE_VALIDATE_TIMESTAMPS` | `1` | Grav schreibt kompilierte Caches zur Laufzeit – anlassen |
| `APACHE_PORT` | `8080` | Port im Container (Manifeste passen dazu) |

**Nicht nötig**: ein CAPTCHA (Schutz über Nonce, Honeypot, Zeitfalle und
Rate-Limit – es werden keine Schlüssel gebraucht) und Kalenderparameter (der
iCalendar-Feed enthält fest 90 Tage Rückblick und alle kommenden Termine).
Die Empfängeradresse für Anfragen („Administratoradresse“) pflegt die
Administration im Admin (Einstellungen → Freigabe und Anfragen); die
Adresse des Erstkontos kommt aus `KNEIPE_ADMIN_EMAIL`.

## 11. Kubernetes-Voraussetzungen

- Eine StorageClass mit **ReadWriteOnce** (Standard-StorageClass oder im
  Overlay gesetzt). Kein RWX nötig.
- Ein Ingress-Controller (Annahme: ingress-nginx) oder Gateway API.
- Optional cert-manager für Zertifikate; sonst ein TLS-Secret `kneipe-web-tls`.
- Ein CNI mit NetworkPolicy-Unterstützung, damit `network-policy.yaml` wirkt
  (ohne wird sie ignoriert – die Website läuft trotzdem).
- Pod Security Admission: Der Namespace erzwingt `restricted`; das
  Deployment erfüllt es.
- Keine ClusterRoles, kein ServiceAccount-Token im Pod.

## 12. Anlegen von Namespace und Secrets

Der Namespace entsteht mit dem Overlay. Das Secret wird **vorher** angelegt
und nie committet (Vorlage: `k8s/base/secret.example.yaml`):

```bash
NS=kneipe-staging
kubectl create namespace "$NS"
kubectl label namespace "$NS" pod-security.kubernetes.io/enforce=restricted

kubectl -n "$NS" create secret generic kneipe-web-secrets \
  --from-literal=GRAV_NONCE_KEY="$(openssl rand -hex 32)" \
  --from-literal=GRAV_API_JWT_SECRET="$(openssl rand -hex 32)" \
  --from-literal=GRAV_CONFIG__plugins__email__mailer__smtp__password='<smtp-passwort>' \
  --from-literal=KNEIPE_ADMIN_USERNAME='admin' \
  --from-literal=KNEIPE_ADMIN_EMAIL='<adresse>' \
  --from-literal=KNEIPE_ADMIN_PASSWORD='<mindestens-12-zeichen>'
```

`--from-literal` landet im Shell-Verlauf; besser `--from-env-file` mit einer
Datei außerhalb des Repositories. Für GitOps: Sealed Secrets, External
Secrets Operator oder SOPS – das Secret heißt immer `kneipe-web-secrets` und
enthält die Schlüssel aus der Vorlage. Das Deployment startet ohne dieses
Secret nicht (`CreateContainerConfigError`).

## 13. Deployment mit kubectl

`kubectl` enthält Kustomize; ein Overlay wird mit `-k` angewendet:

```bash
kubectl diff -k k8s/overlays/staging          # zeigt, was sich ändern würde
kubectl apply -k k8s/overlays/staging
kubectl rollout status deployment/kneipe-web -n kneipe-staging
kubectl get pods,pvc,ingress -n kneipe-staging
kubectl logs -n kneipe-staging deploy/kneipe-web
```

## 14. Deployment mit Kustomize

```bash
kustomize build k8s/overlays/production > /tmp/kneipe-production.yaml
kubectl apply -f /tmp/kneipe-production.yaml
kubectl rollout status deployment/kneipe-web -n kneipe
```

| Overlay | Namespace | Hostname (PLATZHALTER) | Image-Tag | Volume | Besonderheiten |
| --- | --- | --- | --- | --- | --- |
| `dev` | `kneipe-dev` | `dev.kneipe.example.org` | `dev` | 1 Gi | `GRAV_ENVIRONMENT=dev`, noindex, kleine Ressourcen |
| `staging` | `kneipe-staging` | `staging.kneipe.example.org` | `main` | 2 Gi | noindex |
| `production` | `kneipe` | `www.example.org` | `1.0.0` (feste Version, PLATZHALTER) | 10 Gi | – |

Anpassen im jeweiligen Overlay: Hostname (ConfigMap-URLs, Ingress-Host und
TLS-Host), Image-Tag, Volume-Größe, Ressourcen. Nach Änderungen an der
ConfigMap: `kubectl -n <namespace> rollout restart deployment/kneipe-web`.

## 15. Konfiguration von Ingress und DNS

**DNS**: Für den Hostnamen einen A/AAAA- oder CNAME-Eintrag auf die externe
Adresse des Ingress-Controllers setzen
(`kubectl -n ingress-nginx get svc ingress-nginx-controller`).

**ingress-nginx** (Standard, `k8s/base/ingress.yaml`): HTTPS-Redirect,
`proxy-body-size: 12m` für Bild-Uploads, 60 s Timeout. ingress-nginx setzt
`X-Forwarded-For`/`-Proto`; Apache übernimmt die Client-IP nur von Adressen
in `KNEIPE_TRUSTED_PROXIES`. Die öffentliche Adresse (Schema, Host) kommt aus
`GRAV_CONFIG__system__custom_base_url`, nicht aus Weiterleitungs-Headern.

**Traefik** (auch Standard in k3s): `ingressClassName: traefik`, die
nginx-Annotationen entfernen, HTTPS-Redirect über eine Middleware:

```yaml
# BEISPIEL – an die Traefik-Version anpassen
apiVersion: traefik.io/v1alpha1
kind: Middleware
metadata:
  name: https-redirect
spec:
  redirectScheme:
    scheme: https
    permanent: true
---
# im Ingress:
metadata:
  annotations:
    traefik.ingress.kubernetes.io/router.middlewares: <namespace>-https-redirect@kubernetescrd
```

**RKE2**: bringt je nach Version ingress-nginx (`ingressClassName: nginx`,
Controller im Namespace `kube-system` statt `ingress-nginx` – dann den
`namespaceSelector` in `network-policy.yaml` anpassen) oder Traefik mit.
`kubectl get ingressclass` zeigt, was installiert ist.

**Gateway API**: den Ingress im Overlay entfernen (Patch mit
`$patch: delete`) und eine HTTPRoute anlegen:

```yaml
# BEISPIEL – Gateway-Name und Namespace an den Cluster anpassen
apiVersion: gateway.networking.k8s.io/v1
kind: HTTPRoute
metadata:
  name: kneipe-web
spec:
  parentRefs:
    - name: public-gateway
      namespace: gateway-system
      sectionName: https
  hostnames:
    - www.example.org
  rules:
    - backendRefs:
        - name: kneipe-web
          port: 80
```

HTTPS-Redirect und Zertifikat liegen dann am Gateway (Listener `http` mit
`RequestRedirect`-Filter). Die NetworkPolicy muss dann den Namespace des
Gateway-Controllers statt `ingress-nginx` erlauben.

**Admin-Bereich**: `/admin` und `/api` sind durch Anmeldung, Sperre nach
Fehlversuchen (5 in 10 Minuten), ein Rate-Limit der API (120 Anfragen je
Minute) und optional Zwei-Faktor-Anmeldung geschützt und senden
`X-Robots-Tag: noindex` sowie `Cache-Control: no-store`. Wer den Admin
zusätzlich auf bekannte Netze beschränken möchte, legt für `/admin` und `/api`
einen eigenen Ingress mit
`nginx.ingress.kubernetes.io/whitelist-source-range: "<netz>/<präfix>"` an
(BEISPIEL – die Netze der Redaktion sind nicht bekannt). Der
Einrichtungsassistent für den ersten Superuser ist in staging/production
gesperrt (Kapitel 12).

## 16. TLS und cert-manager

Mit cert-manager (optional): Die Annotation
`cert-manager.io/cluster-issuer: letsencrypt` im Ingress fordert das
Zertifikat an und legt es im Secret `kneipe-web-tls` ab. Der ClusterIssuer
`letsencrypt` ist eine **ANNAHME** – vorhandenen Namen eintragen.

Ohne cert-manager: Annotation im Overlay entfernen und das Zertifikat selbst
ablegen:

```bash
kubectl -n kneipe create secret tls kneipe-web-tls --cert=fullchain.pem --key=privkey.pem
```

Prüfen: `curl -sI https://www.example.org/ | head -1` und – mit cert-manager –
`kubectl -n kneipe describe certificate kneipe-web-tls`. HSTS setzt
ingress-nginx standardmäßig für HTTPS-Antworten (ConfigMap-Option `hsts`).

## 17. Persistenter Speicher

**Auf dem Volume `/data`** (PVC `kneipe-web-data`):

| Pfad | Inhalt | Grav-Pfad |
| --- | --- | --- |
| `/data/pages` | alle Seiten, Termine, Teams, Meldungen, Bilder, **Anfragen** (`pages/anfragen`), **Einstellungen** (`pages/einstellungen`) | `user/pages` |
| `/data/accounts` | Konten (bcrypt-Hashes) | `user/accounts` |
| `/data/data` | Plugin-Daten: Rate-Limit-Zähler (nur Hashes), Wartungsmarke, Admin2-Einstellungen | `user/data` |
| `/data/media` | Dateien der Mediathek und Admin2-Branding | `user/media` |
| `/data/config` | Schlüsseldateien `security-private.php`, `plugins/api-private.php` (0600) | erste `config://`-Ebene |

**Im Image (nicht persistent, versioniert im Git)**: `user/config`,
`user/env`, `user/plugins`, `user/themes`, Theme-Bilder (die Grav-Ordner
`user/images` und `user/languages` werden nicht verwendet). Konfigurations-
änderungen erfolgen per Git und neuem Image, nicht im Admin – die
Konfigurationsseiten von Admin2 können deshalb nicht speichern.

**Flüchtig in `/tmp` (emptyDir, 1 Gi)**: `cache`, `logs`, `tmp`, `backup`,
Bildvarianten (`images`), Asset-Cache (`assets`), PHP-Sitzungen,
Apache-Laufzeit. Alles wird bei Bedarf neu erzeugt.

**Rechte**: `fsGroup: 33` macht das Volume für UID 33 beschreibbar
(`fsGroupChangePolicy: OnRootMismatch` vermeidet lange Starts). Fehlen
Schreibrechte, bricht der Entrypoint mit einer klaren Meldung ab und
`/readyz` meldet 503. Der Entrypoint ändert keine Rechte auf dem Volume und
löscht dort nichts.

**Bewertete Alternativen**:

| Variante | Bewertung |
| --- | --- |
| **ReadWriteOnce, ein Pod** (gewählt) | überall verfügbar, einfach, konsistent. Einschränkung: keine Hochverfügbarkeit; Updates mit kurzer Unterbrechung (alter Pod endet, bevor der neue startet) |
| ReadWriteMany (NFS, CephFS, Longhorn RWX) | mehrere Pods möglich, aber Grav hat keine Sperren über Pods hinweg (gleichzeitiges Speichern derselben Seite, Pages-Cache und Sitzungen je Pod). Nur mit Sticky Sessions und gemeinsamem Cache sinnvoll; NFS-Latenz verlangsamt Grav deutlich |
| Grav im Image, nur Daten persistent | umgesetzt – Updates sind reproduzierbar und rückrollbar |
| Medien in Object Storage (S3) | würde das Volume verkleinern, braucht aber ein Grav-Plugin für externe Medien und andere Medien-URLs; derzeit unverhältnismäßig |
| Git-basierter Redaktions-Workflow (Git Sync) | Historie und mehrere lesende Instanzen möglich, aber Anfragen (personenbezogen) dürfen nicht in Git – bräuchten einen getrennten Speicher |

**Späterer Ausbau zur Hochverfügbarkeit**: Anfragen in einen externen Dienst
(kleine API mit Datenbank oder ein Ticketsystem) auslagern, Inhalte per
Git oder RWX-Speicher bereitstellen, Grav-Cache auf Redis
(`GRAV_CONFIG__system__cache__driver=redis`), Sitzungen zentral oder
Sticky Sessions am Ingress. Erst dann `replicas > 1` und ein
PodDisruptionBudget – bei einem Pod würde ein PDB nur Node-Wartungen blockieren
und ist deshalb nicht enthalten.

## 18. Kalender und Terminanfragen

**Ablauf**: Formular `/termin-anfragen` → Prüfung (CSRF-Nonce der Sitzung,
Honeypot, Zeitfalle, Herkunft, Validierung, Rate-Limit je IP-Hash) →
Speichern als Seite `/data/pages/anfragen/anfrage-<datum>-<zufall>/request.md`
mit `published: false`, `routable: false` → E-Mail an die Redaktion und
Eingangsbestätigung → Weiterleitung (303) auf `/termin-anfragen/danke`.

**Freigabe**: Die Redaktion sieht offene Anfragen im Admin unter
„Redaktion“ und „Seiten → Anfragen“. Die Moderation legt Termine an (immer
unveröffentlicht) und setzt sie „zur Freigabe“; bestätigen und veröffentlichen
darf im Freigabemodus nur die Administration. Öffentlich erscheint ein
Termin erst, wenn er veröffentlicht ist **und** einen öffentlichen Status hat.
Eine abgelehnte Anfrage veröffentlicht nichts. Einzelheiten:
`docs/editor-guide.md`, Regeln: `user/plugins/kneipe/classes/Workflow.php`,
Durchsetzung: `Guard.php`.

**Auswirkungen des Kubernetes-Betriebs**:

| Thema | Umgang |
| --- | --- |
| Gleichzeitige Zugriffe auf Flat Files | ein Pod; jede Anfrage in einem eigenen Ordner mit Zufallsnamen (keine Kollision); Rate-Limit-Dateien mit `flock` |
| Dateisperren | `flock` funktioniert auf Block-Volumes (RWO); auf NFS unzuverlässig – ein Grund gegen RWX |
| Parallele Pods | nicht vorgesehen (`replicas: 1`, `maxSurge: 0`); bei zwei Pods wären Sitzungen (CSRF) und Pages-Cache getrennt |
| Backup während einer Anfrage | eine Anfrage ist eine einzelne kleine Datei; ein Dateisystem-Backup erfasst sie ganz oder gar nicht. Für konsistente Stände: CSI-Snapshot (Kapitel 23) |
| Pod- oder Node-Ausfall | das Deployment startet den Pod neu; bei Node-Ausfall hängt die Wiederanbindung des RWO-Volumes vom Speicher ab (einige Minuten). Daten auf dem Volume bleiben; Sitzungen und Cache gehen verloren (offene Formulare zeigen einmal den Sitzungshinweis und behalten die Eingaben) |
| E-Mail aus dem Cluster | über einen authentifizierten SMTP-Server (Kapitel 19); Egress in der NetworkPolicy auf 587/465 |
| Fehlende Schreibrechte | Entrypoint bricht ab, `/readyz` meldet 503; Abhilfe: `fsGroup`, StorageClass, `kubectl describe pvc` |

**Löschfrist**: Anfragen werden nach der eingestellten Frist (Standard 180
Tage) gelöscht – bei jeder neuen Anfrage, höchstens einmal täglich über
`/readyz` und bei Bedarf manuell:

```bash
kubectl -n kneipe exec deploy/kneipe-web -- php bin/plugin kneipe maintenance
```

Ein eigener CronJob ist bewusst nicht enthalten: Er müsste das
RWO-Volume parallel zum laufenden Pod einhängen.

**Kalender**: `/termine` (Liste, Monatsansicht, Filter), `/termine.ics`
(abonnierbar), `/termine/<termin>.ics`. Interne Status (angefragt,
reserviert, zur Freigabe) erscheinen nie öffentlich; private Veranstaltungen
nur als „Geschlossene Gesellschaft“.

## 19. E-Mail-Konfiguration

Grav versendet über das Plugin `email` (Symfony Mailer). Ohne Konfiguration
ist der Versand aus (`engine: none`); Anfragen werden trotzdem gespeichert
und die Redaktionsübersicht zeigt der Administration einen Hinweis.

| Variable | Beispiel | Wohin |
| --- | --- | --- |
| `GRAV_CONFIG__plugins__email__mailer__engine` | `smtp` | ConfigMap |
| `GRAV_CONFIG__plugins__email__mailer__smtp__server` | `smtp.example.org` | ConfigMap |
| `GRAV_CONFIG__plugins__email__mailer__smtp__port` | `587` | ConfigMap |
| `GRAV_CONFIG__plugins__email__mailer__smtp__encryption` | `tls` (587, STARTTLS) oder `ssl` (465) | ConfigMap |
| `GRAV_CONFIG__plugins__email__mailer__smtp__user` | `noreply@example.org` | ConfigMap |
| `GRAV_CONFIG__plugins__email__mailer__smtp__password` | – | **Secret** |
| `GRAV_CONFIG__plugins__email__from` | `noreply@example.org` (muss zum SMTP-Konto passen, SPF/DMARC) | ConfigMap |
| `GRAV_CONFIG__plugins__email__from_name` | `Ehrenamtskneipe Erndtebrück` | ConfigMap |

Empfänger der Anfragen und die Eingangsbestätigung stellt die Administration
im Admin ein (Einstellungen → Freigabe und Anfragen). Versandfehler blockieren
die Anfrage nicht; im Log steht nur die technische Ursache (kein
Formularinhalt, keine Adresse).

Test: eine Anfrage absenden und in `kubectl logs` nach
`[kneipe] … nicht versendet` suchen. Port 25 ist in vielen Clustern und bei
vielen Anbietern gesperrt – deshalb 587/465.

## 20. Logs und Troubleshooting

```bash
kubectl logs -n kneipe deploy/kneipe-web              # Entrypoint, Apache-Fehler, PHP, Grav (ab Warnung)
kubectl logs -n kneipe deploy/kneipe-web --previous   # nach einem Neustart
kubectl describe pod -n kneipe -l app.kubernetes.io/name=kneipe-web
kubectl get events -n kneipe --sort-by=.lastTimestamp
kubectl exec -n kneipe deploy/kneipe-web -- php bin/grav cache   # Cache leeren
```

Protokolle enthalten keine Formularinhalte: Das Plugin schreibt nur
technische Ursachen, das Zugriffsprotokoll (optional) nur den Pfad ohne Query.

| Symptom | Ursache | Abhilfe |
| --- | --- | --- |
| Pod startet nicht, Log „`GRAV_CONFIG__system__custom_base_url … fehlt`“ | ConfigMap unvollständig | Overlay prüfen |
| „Noch kein Konto vorhanden“ | leeres Volume, kein Erstkonto im Secret | `KNEIPE_ADMIN_*` ins Secret |
| `CreateContainerConfigError` | Secret `kneipe-web-secrets` fehlt | Kapitel 12 |
| „… ist nicht beschreibbar für UID 33“ | Volume-Rechte | `fsGroup`, StorageClass; bei hostPath `chown 33:33` |
| `/readyz` 503 | Einstellungen-Seite oder Konto fehlt, Volume nicht beschreibbar | Logs, `kubectl exec … ls -la /data` |
| Pod und PVC `Pending` | keine passende StorageClass | `kubectl get sc`, `storageClassName` im Overlay |
| Neuer Pod hängt nach Node-Ausfall | RWO-Volume noch am alten Node | warten bzw. `kubectl get volumeattachment` |
| Formular meldet „Sitzung ist abgelaufen“ | Pod neu gestartet oder Formular > 12 h offen | erneut absenden |
| Keine E-Mails | `engine: none`, falsches Passwort, Egress gesperrt | Kapitel 19, NetworkPolicy |
| 413 beim Bild-Upload | Grenze am Ingress | `proxy-body-size` und `PHP_POST_MAX_SIZE` |
| Admin-Konfiguration speichert nicht | Konfiguration ist im Image (read-only) | per Git ändern, neues Image |
| Rate-Limit greift für alle Besucher | `KNEIPE_TRUSTED_PROXIES` passt nicht zum Pod-Netz des Ingress | Netz eintragen |

## 21. Healthchecks

| Endpunkt | Bedeutung | Prüft | Antwort |
| --- | --- | --- | --- |
| `/healthz` | Liveness und Startup: PHP und Grav antworten | Grav-Initialisierung ohne Seiten | `200 ok` |
| `/readyz` | Readiness: bereit für Besucher | Seiten und Einstellungen vorhanden, mindestens ein Konto, `/data` und `/tmp` beschreibbar; führt einmal täglich die Wartung aus | `200 ok` / `503 nicht bereit` |

Beide sind ohne Anmeldung erreichbar, setzen keine Cookies, senden
`Cache-Control: no-store` und geben keine Details aus. Sie antworten vor der
Admin2-Umleitung, die ohne Konto alle Seiten nach `/admin` schickt.

Probes (`k8s/base/deployment.yaml`):

| Probe | Pfad | initialDelay | period | timeout | success | failure |
| --- | --- | --- | --- | --- | --- | --- |
| startup | `/healthz` | 5 s | 5 s | 3 s | 1 | 24 (max. 2 min) |
| liveness | `/healthz` | 0 s | 20 s | 5 s | 1 | 3 |
| readiness | `/readyz` | 0 s | 10 s | 5 s | 1 | 3 |

Docker/Podman nutzen `scripts/healthcheck.sh` (Liveness; `healthcheck ready`
prüft die Bereitschaft).

## 22. Updates und Rollbacks

**Image-Tags** (CI, `.github/workflows/docker.yml`): `main` und `latest`
für den Branch main, `dev` für den Branch dev, `sha-<commit>` für jeden Push,
`1.2.3` und `1.2` für Git-Tags `v1.2.3`. Produktion verwendet immer eine
feste Version.

**Update**:

```bash
cd k8s/overlays/production
kustomize edit set image ghcr.io/vladislavvk1337/web-kirby-vereinskneipe-ern:1.1.0   # oder newTag von Hand ändern
cd -
kubectl apply -k k8s/overlays/production
kubectl rollout status deployment/kneipe-web -n kneipe
```

Der alte Pod endet, bevor der neue startet (`maxSurge: 0`): kurze
Unterbrechung von wenigen Sekunden. Der Entrypoint leert beim Start den Cache;
Inhalte auf dem Volume bleiben unverändert, Beispielinhalte werden nur in ein
leeres Volume kopiert.

**Rollback**:

```bash
kubectl rollout history deployment/kneipe-web -n kneipe
kubectl rollout undo deployment/kneipe-web -n kneipe            # vorige Revision
kubectl rollout undo deployment/kneipe-web -n kneipe --to-revision=3
```

Danach den Tag im Overlay zurücksetzen, sonst stellt das nächste `apply` die
neue Version wieder her. Datenänderungen (Seiten) werden durch einen
Image-Rollback nicht zurückgedreht – dafür gibt es das Backup.

**Grav und Plugins aktualisieren**: `GRAV_VERSION` und `GRAV_SHA256` in
`scripts/grav-dist.sh` anpassen (neue Summe: `sha256sum grav-admin-v<version>.zip`),
Changelogs von Grav, Admin2 und API lesen, `php tests/run.php` ausführen,
`.trivyignore` prüfen. Keine Updates per GPM im Admin (schreibgeschützt).

**Regelmäßig**: Basis-Image und Grav mindestens monatlich neu bauen
(Sicherheitsupdates), Trivy-Befunde der CI lesen.

## 23. Backup und Restore

**Was gesichert wird**: das gesamte Volume `/data`:

| Pfad | Warum |
| --- | --- |
| `/data/pages` | alle Inhalte und Bilder, Einstellungen, Anfragen |
| `/data/accounts` | Konten |
| `/data/config` | Schlüssel (ohne sie werden offene Formulare und Admin-Anmeldungen ungültig – sonst unkritisch; bei Schlüsseln aus dem Secret dort ebenfalls sichern) |
| `/data/data`, `/data/media` | Plugin-Daten, Mediathek |

Nicht gesichert werden müssen: das Image (Registry und Git) und `/tmp`.

**Schutz von Anfragen und Medien**: Anfragen enthalten personenbezogene
Daten. Backups deshalb verschlüsselt ablegen, Zugriff auf wenige Personen
beschränken und nicht länger aufbewahren als nötig (Vorschlag: 30 Tage
Rotation). Beim Restore eines alten Standes können bereits gelöschte Anfragen
zurückkehren – die nächste Wartung (`bin/plugin kneipe maintenance`) entfernt
sie wieder.

**Häufigkeit (Vorschlag)**: täglich nachts, 14 Tagesstände, 4 Wochenstände;
zusätzlich vor jedem Update.

**Konsistenz**: Grav schreibt kleine Einzeldateien. Am sichersten ist ein
CSI-VolumeSnapshot (atomar für das ganze Volume). Dateibasierte Backups im
laufenden Betrieb sind in der Praxis ausreichend (jede Seite ist eine
Datei), können aber eine gerade geschriebene Anfrage verpassen. Wer ganz
sicher gehen will: kurz auf 0 skalieren, sichern, wieder auf 1.

Die folgenden Beispiele sind **nicht getestet**, weil das Speichersystem des
Clusters unbekannt ist. Snapshot-Klasse, Backup-Werkzeug, Schlüssel und Ziele
müssen angepasst werden.

*BEISPIEL A – CSI-Snapshot* (setzt einen CSI-Treiber mit Snapshot-Unterstützung
und eine `VolumeSnapshotClass` voraus):

```yaml
# BEISPIEL – volumeSnapshotClassName anpassen
apiVersion: snapshot.storage.k8s.io/v1
kind: VolumeSnapshot
metadata:
  name: kneipe-web-data-2026-09-25
  namespace: kneipe
spec:
  volumeSnapshotClassName: <snapshot-klasse>
  source:
    persistentVolumeClaimName: kneipe-web-data
```

*BEISPIEL B – Velero* (setzt eine installierte Velero-Instanz mit
Objektspeicher voraus; Verschlüsselung über den Objektspeicher bzw. das
Datei-Backup von Velero):

```bash
# BEISPIEL
velero backup create kneipe-$(date +%F) --include-namespaces kneipe --default-volumes-to-fs-backup
velero schedule create kneipe-daily --schedule "0 3 * * *" --include-namespaces kneipe --ttl 336h
```

*BEISPIEL C – Archiv aus dem Pod* (ohne Cluster-Werkzeuge; verschlüsselt mit
age):

```bash
# BEISPIEL – <age-public-key> und Ablageort anpassen
kubectl -n kneipe exec deploy/kneipe-web -- tar -C /data -czf - pages accounts config data media \
  | age -r <age-public-key> > kneipe-data-$(date +%F).tar.gz.age
```

**Restore**:

1. Deployment anhalten: `kubectl -n kneipe scale deployment/kneipe-web --replicas=0`
2. Daten zurückspielen – je nach Verfahren: neues PVC aus dem Snapshot
   (`spec.dataSource` verweist auf den VolumeSnapshot) und im Overlay
   verwenden; `velero restore create --from-backup …`; oder bei Beispiel C
   einen Hilfs-Pod mit dem PVC starten (Kapitel 27, Schritt 6) und das Archiv
   entpacken:
   `age -d -i schluessel.txt kneipe-data-<datum>.tar.gz.age | kubectl -n kneipe exec -i datenimport -- tar -C /data -xzf -`
3. Besitz prüfen (UID/GID 33).
4. `kubectl -n kneipe scale deployment/kneipe-web --replicas=1`,
   `kubectl rollout status …`, Startseite, Kalender und Admin-Anmeldung prüfen.

**Restore testen**: vierteljährlich in `kneipe-staging` einen echten Restore
durchspielen (Schritte oben), Termine, Bilder, eine Anfrage und die Anmeldung
prüfen, die Dauer notieren.

**Nach Cluster- oder Node-Ausfall**: Node-Ausfall – der Pod wird neu
geplant, das Volume wandert mit (je nach Speicher einige Minuten). Verlust
des Volumes oder des Clusters – neuen Cluster bzw. Namespace aufsetzen
(Kapitel 12–14, dieselben Secrets, insbesondere `GRAV_NONCE_KEY` und
`GRAV_API_JWT_SECRET`), Restore wie oben, DNS prüfen.

## 24. Sicherheitsmaßnahmen

- **Container**: UID 33, kein root, `readOnlyRootFilesystem`,
  `capabilities: drop ALL`, `allowPrivilegeEscalation: false`,
  `seccompProfile: RuntimeDefault`, kein ServiceAccount-Token, keine
  Service-Links, Pod Security `restricted`, kein privilegierter Container.
- **Keine Geheimnisse** in Dockerfile, Image, Git, Kustomize oder Logs. Die
  Secret-Vorlage enthält nur Platzhalter (CI und Unit-Test prüfen das).
  Passwörter werden per STDIN übergeben; der Entrypoint entfernt
  `KNEIPE_ADMIN_PASSWORD` vor dem Start von Apache aus der Umgebung.
- **Webserver**: kein `.htaccess`, feste Sperren für `user/config`,
  `user/env`, `user/accounts`, `user/data`, Anfragen, Einstellungen, `system`,
  `vendor`, `bin`, versteckte Dateien und Quellcode-Dateitypen; nur `index.php`
  darf als PHP laufen; `open_basedir`, `disable_functions`
  (`exec`, `shell_exec`, `proc_open` …).
- **Header**: CSP ohne Inline-Skripte für die Website (`script-src 'self'`),
  eigene CSP für Admin2, `X-Content-Type-Options`, `X-Frame-Options`,
  `Referrer-Policy`, `Permissions-Policy`, `Cross-Origin-Opener-Policy`.
- **Grav**: Twig in Inhalten aus, HTML im Markdown maskiert, Ausgabe
  redaktioneller Inhalte zusätzlich bereinigt, Sitzung nur bei Bedarf
  (`session.lazy`), `HttpOnly`, `SameSite=Lax`, `Secure` in staging/production,
  keine Registrierung, keine API-Schlüssel, keine Besucherstatistik,
  Avatare lokal statt Gravatar.
- **Rollen**: Administration ist kein technischer Superuser; die Moderation
  verwaltet keine Konten. Freigaberegeln werden serverseitig für jede
  API-Operation geprüft (Kapitel 18).
- **Admin-Bereich**: Anmeldung mit Sperre nach Fehlversuchen,
  Zwei-Faktor-Anmeldung verfügbar (je Konto im Admin einschalten),
  Einrichtungsassistent gesperrt, optional IP-Beschränkung (Kapitel 15).
- **Netzwerk**: NetworkPolicy – eingehend nur vom Ingress-Controller auf
  Port 8080, ausgehend nur DNS und SMTP (587/465) nach außen. Admin2
  versucht, Neuigkeiten von getgrav.org zu laden; das wird blockiert und ist
  ohne Folgen.
- **Datenschutz**: keine externen Schriften, Skripte, Karten oder Tracker;
  OpenStreetMap nur als Link; Anfragen nie öffentlich, Löschfrist.
- **Prüfungen**: Trivy (Image, CI), hadolint, shellcheck, kubeconform,
  kube-linter, Unit- und HTTP-Tests.

## 25. Monitoring

- **Verfügbarkeit**: externer Uptime-Check auf `https://<domain>/healthz`
  und eine Inhaltsseite (z. B. `/termine`).
- **Kubernetes**: Neustarts und Bereitschaft
  (`kube_pod_container_status_restarts_total`, `kube_pod_status_ready` aus
  kube-state-metrics), Belegung des Volumes (`kubelet_volume_stats_used_bytes`
  / `kubelet_volume_stats_capacity_bytes`, Warnung ab 80 %), Ablauf des
  Zertifikats (cert-manager-Metriken oder externer Check).
- **Logs**: stdout/stderr des Pods in die zentrale Log-Plattform
  (Loki, Elasticsearch …). Warnen bei `grav.ERROR`, `grav.CRITICAL`,
  `PHP Fatal` und `[kneipe] … nicht versendet`.
- **Fachlich**: Die Redaktionsübersicht im Admin zeigt offene Anfragen,
  Freigaben, Doppelbelegungen und Konfigurationshinweise.

Die Anwendung stellt keinen Prometheus-Endpunkt bereit.

## 26. CI/CD

GitHub Actions (Anmeldung an der Registry nur über `secrets.GITHUB_TOKEN`,
keine Zugangsdaten im Repository):

| Workflow | Schritte |
| --- | --- |
| `ci.yml` (Push, Pull Request) | PHP 8.3 und 8.4: Syntax, Unit- und HTTP-Tests (eigene Grav-Instanz, E-Mail über einen Test-SMTP-Empfänger, Freigabeworkflow über die API); shellcheck; hadolint; `kustomize build` aller Overlays mit kubeconform und kube-linter; Prüfung der Secret-Vorlage |
| `docker.yml` (Push, Pull Request, Tags) | Build mit BuildKit-Cache; Smoke-Test des Containers (read-only, ohne Capabilities): Seiten, Sperren, Header, Cookies, Bildvarianten, Admin-API, Wartung, Neustart ohne erneutes Seeding, Startverweigerung ohne Konfiguration; Trivy (Schwachstellen und Geheimnisse, Abbruch bei behebbaren HIGH/CRITICAL); Push nach ghcr.io |

**Deployment**: bewusst nicht automatisch. Vorschlag GitOps: Argo CD oder
Flux beobachtet `k8s/overlays/staging` (Tag `main`) automatisch und
`k8s/overlays/production` (fester Tag, Änderung per Pull Request). Secrets
kommen über Sealed Secrets oder External Secrets, nie aus dem Repository.

## 27. Migration vom alten Linux-Server

Die Originaldaten werden in keinem Schritt verändert: Das
Migrationswerkzeug liest nur und schreibt in ein getrenntes Ziel.

1. **Bestandsaufnahme**: Kirby-Version, Umfang von `content/` (Seiten,
   Bilder), Konten in `site/accounts/`, offene Anfragen in
   `content/anfragen/_drafts/`, SMTP-Zugang, Domain und DNS-TTL notieren.
2. **Export und Sicherung**: auf dem alten Server ein vollständiges Backup
   (`deploy/backup.sh` im Branch `kirby-legacy` bzw. ein Archiv von
   `content/`, `site/accounts/` und `config/`), Prüfsumme erzeugen und an zwei
   Orten ablegen. Für die Migration eine Kopie aus dem Backup entpacken – nie
   auf dem Live-System arbeiten.
3. **Migration nach Grav** (Arbeitsrechner mit PHP ≥ 8.3):

   ```bash
   scripts/dev-server.sh --prepare-only            # Grav-Kern (für symfony/yaml)
   php tools/migrate-kirby-to-grav.php --source=/pfad/zur/kirby-kopie --target=/tmp/grav-data
   # Ergebnis: /tmp/grav-data/pages, /tmp/grav-data/accounts, /tmp/grav-data/MIGRATION.txt
   ```

   Das Werkzeug wandelt Writer-HTML in Markdown, Bildmetadaten in
   `*.meta.yaml`, Team-Verweise (UUID) in Routen, Kirby-Konten in Grav-Konten
   (bcrypt-Hashes bleiben gültig, Rolle → Gruppe) und die Stammdaten in die
   Seite „Einstellungen“. `MIGRATION.txt` listet alles, was zu prüfen ist.
   Ohne offene Anfragen: `--without-requests`.
4. **Image bauen und testen**: `docker build …`, lokal mit den migrierten
   Daten starten (`-v /tmp/grav-data:/data:U` bzw. Besitz 33:33), Seiten
   stichprobenartig mit der alten Website vergleichen, `php tests/run.php`.
5. **Isolierte Kubernetes-Umgebung**: `kneipe-staging` aufsetzen
   (Kapitel 12–14) mit `KNEIPE_SEED_CONTENT: "false"` im Overlay.
6. **Import der Daten** in das PVC (Deployment auf 0, Hilfs-Pod, der die
   Anforderungen von Pod Security `restricted` erfüllt):

   ```bash
   kubectl -n kneipe-staging scale deployment/kneipe-web --replicas=0
   kubectl -n kneipe-staging apply -f - <<'EOF'
   apiVersion: v1
   kind: Pod
   metadata:
     name: datenimport
   spec:
     securityContext:
       runAsNonRoot: true
       runAsUser: 33
       runAsGroup: 33
       fsGroup: 33
       seccompProfile:
         type: RuntimeDefault
     containers:
       - name: datenimport
         image: busybox:1.37
         command: ["sleep", "3600"]
         securityContext:
           allowPrivilegeEscalation: false
           capabilities:
             drop: ["ALL"]
         volumeMounts:
           - name: data
             mountPath: /data
     volumes:
       - name: data
         persistentVolumeClaim:
           claimName: kneipe-web-data
   EOF
   kubectl -n kneipe-staging wait --for=condition=Ready pod/datenimport
   tar -C /tmp/grav-data -czf - pages accounts | kubectl -n kneipe-staging exec -i datenimport -- tar -C /data -xzf -
   kubectl -n kneipe-staging delete pod datenimport
   kubectl -n kneipe-staging scale deployment/kneipe-web --replicas=1
   ```

7. **Domain, DNS, TLS**: Hostname im Overlay, Zertifikat (Kapitel 16). Die
   DNS-TTL der Live-Domain einen Tag vorher auf 300 s senken.
8. **Funktionstest** nach dem Testplan (Kapitel 28): Seiten, Kalender,
   iCalendar, Anfrage mit E-Mail, Freigabe durch Administration und
   Moderation, Anmeldung aller migrierten Konten.
9. **Umschalten**: auf dem alten Server die Redaktion informieren und
   Änderungen einfrieren, Änderungen und Anfragen seit Schritt 2 erneut
   migrieren (Schritte 3 und 6 im Namespace `kneipe`), DNS auf den Ingress
   umstellen.
10. **Beobachtung**: 48 Stunden Logs, Bereitschaft, E-Mail-Versand und neue
    Anfragen prüfen; der alte Server bleibt unverändert lauffähig.
11. **Rollback**: DNS zurück auf den alten Server (TTL 300 s). Anfragen, die in
    der Zwischenzeit in Grav eingegangen sind, liegen als Dateien unter
    `/data/pages/anfragen/`; die Redaktion übernimmt sie von Hand (Export mit
    `kubectl -n kneipe exec deploy/kneipe-web -- tar -C /data/pages -czf - anfragen > anfragen.tar.gz`).
    Den alten Server erst nach einer stabilen Phase (Vorschlag: 4 Wochen)
    abschalten; sein letztes Backup bis zum Ende der Löschfrist aufbewahren.

## 28. Testplan

| Nr. | Test | Wie | Automatisiert |
| --- | --- | --- | --- |
| 1 | Container startet lokal | `docker run …`, `docker logs` | CI `docker.yml` |
| 2 | Startseite funktioniert | `curl /` → 200, „Nächster Öffnungstermin“ | CI, `tests/http/site.php` |
| 3 | Adminbereich erreichbar und geschützt | `/admin` → 200; API ohne Anmeldung 401; falsches Passwort abgelehnt; Moderation ohne Konten- und Einstellungsrechte | `tests/http/workflow.php`, CI |
| 4 | Öffentliche Seiten funktionieren | alle Seiten der Sitemap, HTML-Grundregeln | `tests/http/site.php`, `html.php` |
| 5 | Kalender zeigt Termine | `/termine`, Monatsansicht, Filter, iCalendar | `site.php`, Browser-Test |
| 6 | Terminanfrage kann gesendet werden | Formular → 303 → Danke-Seite, Datei gespeichert | `tests/http/form.php` |
| 7 | Ungültige Eingaben werden abgelehnt | 422 mit Fehlern am Feld; CSRF, Honeypot, Zeitfalle, Herkunft, Rate-Limit (429) | `form.php`, `tests/unit/form.php` |
| 8 | Anfrage wird nicht direkt veröffentlicht | Anfrage 404, nicht in Kalender oder Sitemap | `form.php`, `workflow.php` |
| 9 | Administrator erhält eine Benachrichtigung | E-Mail an den Empfänger aus den Einstellungen mit Reply-To und Admin-Link; Eingangsbestätigung | `form.php` (Test-SMTP-Empfänger) |
| 10 | Freigabe veröffentlicht den Termin | Administration veröffentlicht → Seite 200, Status „veröffentlicht“ | `workflow.php` |
| 11 | Ablehnung veröffentlicht nichts | Stand „abgelehnt“ → nichts öffentlich | `workflow.php` |
| 12 | Moderation kann im Freigabemodus nicht veröffentlichen oder bestätigen | einzeln, per Stapel, per Kopie, als neuer Termin | `workflow.php` |
| 13 | Daten bleiben nach Pod-Neustart erhalten | `kubectl delete pod …`, Inhalt prüfen | lokal: CI (`docker restart`); Cluster: manuell |
| 14 | Daten bleiben nach Rollout erhalten | `kubectl rollout restart`, neues Image | manuell |
| 15 | Readiness- und Liveness-Probes funktionieren | `/healthz`, `/readyz`; Volume nicht beschreibbar → 503 | `site.php`; Cluster: manuell |
| 16 | TLS funktioniert | `curl -I https://…`, Redirect von http | manuell |
| 17 | Unerlaubte Pfade sind nicht erreichbar | `user/config`, `user/accounts`, `.md`, `setup.php`, `vendor` … | CI `docker.yml` |
| 18 | Backups lassen sich wiederherstellen | Restore in staging (Kapitel 23) | manuell, vierteljährlich |
| 19 | Rollback auf eine vorige Image-Version | `kubectl rollout undo`, Version prüfen | manuell |
| 20 | Darstellung und Barrierefreiheit | 375–1920 px, Tastatur, Zoom, reduzierte Bewegung | `tests/browser/check.cjs` |

Ausführen: `php tests/run.php` (Unit und HTTP); Browser-Tests siehe
`docs/testing.md`.

## 29. Lizenzen

Geprüft anhand der Lizenzdateien im Grav-Paket 2.2.0 und eines Lizenz-Scans
des Images (Trivy). Es werden keine Lizenzbedingungen ergänzt oder
ausgelegt; bei Zweifeln rechtlich prüfen lassen.

| Bestandteil | Lizenz (laut Paket) | Anmerkung |
| --- | --- | --- |
| Grav Core 2.2.0 | MIT (`LICENSE.txt`) | der Lizenztext bleibt im Image |
| Plugins admin2, api, email, error, flex-objects, form, login, shortcode-core | MIT (je `LICENSE`) | unverändert aus dem Paket übernommen |
| PHP-Abhängigkeiten in `vendor/` | überwiegend MIT und BSD-3-Clause, außerdem Apache-2.0, BSD-2-Clause, CC0-1.0 | Scan: 117 Pakete mit erkannter Lizenz |
| `multiavatar/multiavatar-php` (Teil des Grav-Kerns) | „Multiavatar License v1.0“ (`vendor/multiavatar/multiavatar-php/LICENSE`) | eigene Lizenz des Herstellers. Laut Lizenztext ist die Nutzung in Websites und Apps erlaubt; es gelten Einschränkungen, u. a. gegen das Nachbauen eines konkurrierenden Dienstes und das Umverpacken der Designs. Genutzt wird es hier nur für Profilbilder im Admin (`accounts.avatar: multiavatar`) |
| Schrift „Google Sans“ in Admin2 (`user/plugins/admin2/app/fonts/`) | im Paket **keine** Lizenzdatei | nur im internen Admin sichtbar. Vor dem produktiven Betrieb die Lizenz klären. Admin2 bringt weitere Schriften mit (Voreinstellung über `ui.defaults.fontFamily` in `user/config/admin-next.yaml`, z. B. `inter`); auch dafür liegen im Paket keine Lizenzdateien bei |
| Theme und Plugin „kneipe“ | eigenes Projekt | – |
| Schriften der Website (Atkinson Hyperlegible Next, Fraunces) | SIL Open Font License 1.1 (`user/themes/kneipe/fonts/LICENSE-*.txt`) | selbst gehostet |
| Basis-Image `php:8.4-apache` | PHP- und Apache-Lizenz sowie die Lizenzen der Debian-Pakete | offizielles Docker-Image |

Ergebnis: Aus den mitgelieferten Lizenzdateien ergibt sich für den
vorgesehenen Betrieb (öffentliche Vereinswebsite, eigenes Image in eigener
Registry) kein Hindernis. Offen ist die Lizenz der Admin2-Schrift.

## 30. Deinstallation

**Achtung**: Das Löschen des PVC (auch über das Löschen des Namespaces)
löscht je nach `reclaimPolicy` der StorageClass die Daten. Vorher ein Backup
anlegen (Kapitel 23) oder das Volume behalten:

```bash
kubectl patch pv "$(kubectl -n kneipe-staging get pvc kneipe-web-data -o jsonpath='{.spec.volumeName}')" \
  -p '{"spec":{"persistentVolumeReclaimPolicy":"Retain"}}'
```

Danach:

```bash
kubectl delete -k k8s/overlays/staging        # Deployment, Service, Ingress, NetworkPolicy, ConfigMap, PVC, Namespace
```

Das Secret verschwindet mit dem Namespace. Lokal:
`docker rm -f kneipe && docker volume rm kneipe-data` (bzw. `podman`).

## 31. Bekannte Einschränkungen

- **Ein Pod**: keine Hochverfügbarkeit; Updates und Node-Ausfälle bedeuten
  kurze Unterbrechungen (Kapitel 17).
- **Konfiguration nur per Git**: Die Konfigurationsseiten und die
  Plugin-Verwaltung von Admin2 können im Container nicht speichern. Fachliche
  Einstellungen liegen deshalb auf der Seite „Einstellungen“.
- **Admin2** ist die neue Oberfläche von Grav 2 (SvelteKit über die REST-API);
  die mitgelieferte README bezeichnet sie noch als „Alpha“. Die für dieses
  Projekt nötigen Funktionen sind getestet; bei Updates von Admin2/API die
  HTTP-Tests laufen lassen.
- **Leserechte im Admin**: Die API prüft Leserechte einzelner Seiten nicht;
  die Moderation kann die Seite „Einstellungen“ im Admin lesen, aber nicht
  ändern. Die Stammdaten sind ohnehin öffentlich (Impressum, Kontakt).
- **Sitzungen im Pod**: Ein Neustart beendet Admin-Anmeldungen nicht (JWT),
  offene Formulare zeigen aber einmal den Sitzungshinweis.
- **Kein CronJob** für die Löschfrist (RWO-Volume); die Wartung läuft über
  `/readyz`, neue Anfragen oder manuell.
- **Scanner-Befunde** in `symfony/mailer`/`symfony/mime` des Grav-Plugins
  `email` (`.trivyignore` mit Begründung und Ablaufdatum).
- **Kein Helm-Chart**.

## 32. Validierung

Geprüft am 25.09.2026:

| Prüfung | Ergebnis |
| --- | --- |
| `kustomize build` aller Overlays + `kubeconform -strict` (Kubernetes 1.33) | je 7 Ressourcen gültig |
| `kube-linter lint` | keine Befunde |
| `hadolint Dockerfile` | keine Befunde |
| `shellcheck -S warning scripts/*.sh` | keine Befunde |
| `php tests/run.php` | alle Unit- und HTTP-Tests bestanden |
| Browser-Prüfung (18 Seiten × 6 Breiten) | kein Überlauf, keine Konsolenfehler |
| Container mit `--read-only --cap-drop ALL`, UID 33 (Variante ohne kompilierte PHP-Erweiterungen, weil die Debian-Paketquellen in der Build-Umgebung gesperrt waren) | Start, Sperren, CSP, Admin2 im Browser, Speichern über die API, Neustart; der vollständige Build läuft in der CI |
| Trivy (Image) | keine behebbaren HIGH/CRITICAL außer den begründeten Ausnahmen |

Statischer Abgleich:

| Punkt | Container | Kubernetes | Stimmt überein |
| --- | --- | --- | --- |
| Port | `APACHE_PORT=8080`, `EXPOSE 8080` | `containerPort: 8080`, Service Port 80 → `targetPort: http` | ja |
| Benutzer | `USER 33:33` | `runAsUser: 33`, `runAsGroup: 33`, `fsGroup: 33` | ja |
| Volumes | `VOLUME /data`, Schreibpfade `/data` und `/tmp` | PVC → `/data`, emptyDir → `/tmp`, `readOnlyRootFilesystem: true` | ja |
| Probes | `/healthz`, `/readyz`, Healthcheck-Skript | startup/liveness `/healthz`, readiness `/readyz` | ja |
| Umgebung | Standardwerte im Dockerfile | ConfigMap und Secret per `envFrom` | ja |
| Basis-URL | Entrypoint verlangt sie in staging/production | ConfigMap-Patch je Overlay = Ingress-Host | ja |
| Upload-Größe | `PHP_POST_MAX_SIZE=12M`, Apache 13 MB | `proxy-body-size: 12m` | ja |

**Risiken und notwendige Anpassungen vor dem Livegang**: Hostnamen,
Ingress-Klasse, ClusterIssuer, StorageClass, Namespace des
Ingress-Controllers und Pod-Netz (`KNEIPE_TRUSTED_PROXIES`) an den Cluster
anpassen; SMTP-Zugang eintragen; Secrets anlegen; Backup-Verfahren wählen und
einen Restore testen; Lizenz der Admin2-Schrift klären; Stammdaten und
Rechtstexte ergänzen (`docs/project-data-needed.md`).
