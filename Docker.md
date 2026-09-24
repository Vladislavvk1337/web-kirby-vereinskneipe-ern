# Container und Kubernetes

Das [`Dockerfile`](Dockerfile) baut ein schlankes Image der Kirby-Website,
das sich ohne Anpassungen in Kubernetes betreiben lässt: **Konfiguration nur
über Umgebungsvariablen**, **Daten nur in einem Volume**, **nicht als root**,
**schreibgeschütztes Dateisystem möglich**, **Health-Checks** für die Probes.

| | |
| --- | --- |
| Basis | `php:8.4-apache` (Debian) mit gd (WebP/AVIF), intl, zip, exif, OPcache |
| Webserver | Apache mit mod_php, Port **8080**, TLS am Ingress |
| Benutzer | `www-data` (UID/GID **33**) |
| Daten | Volume **`/data`** (Inhalte, Medien, Konten, Sitzungen, Cache) |
| Programmcode | `/var/www/html`, gehört root, schreibgeschützt |
| Health-Checks | `GET /healthz` (Liveness), `GET /readyz` (Readiness) |
| Image | `ghcr.io/vladislavvk1337/web-kirby-vereinskneipe-ern:latest` (aus `main`) |

Der Server-Betrieb ohne Container (basis-schutz-os, Caddy, systemd) ist
weiterhin in [docs/deployment.md](docs/deployment.md) beschrieben.

---

## Inhalt

- [Schnellstart mit Docker](#schnellstart-mit-docker)
- [Image bauen](#image-bauen)
- [Umgebungsvariablen](#umgebungsvariablen)
- [Volumes](#volumes)
- [Abhängigkeiten](#abhängigkeiten)
- [Kubernetes](#kubernetes)
- [Betrieb](#betrieb)
- [Fehlersuche](#fehlersuche)

---

## Schnellstart mit Docker

```bash
docker build -t kneipe-web .

docker run -d --name kneipe -p 8080:8080 \
  -v kneipe-data:/data \
  -e KIRBY_URL=http://localhost:8080 \
  -e KIRBY_CONTENT_SALT="$(openssl rand -hex 32)" \
  -e KIRBY_COOKIE_KEY="$(openssl rand -hex 32)" \
  -e KIRBY_MAIL_TRANSPORT=none \
  -e KNEIPE_ADMIN_EMAIL=admin@example.org \
  -e KNEIPE_ADMIN_PASSWORD='mindestens-12-zeichen' \
  kneipe-web
```

Danach `http://localhost:8080` und `http://localhost:8080/panel`. Beim
ersten Start kopiert der Container den Startinhalt (mit Demo-Inhalten) nach
`/data/content` und legt den Administrator an.

> Salt und Cookie-Schlüssel in echten Umgebungen **einmal** erzeugen und
> fest hinterlegen (Kubernetes-Secret) – nicht bei jedem Start neu.

## Image bauen

```bash
docker build -t kneipe-web .
docker build --build-arg PHP_VERSION=8.3 -t kneipe-web:php83 .
```

| Build-Argument | Standard | Zweck |
| --- | --- | --- |
| `PHP_VERSION` | `8.4` | PHP-Version des Basis-Images (8.2–8.5, Kirby 5.6) |
| `COMPOSER_IMAGE` | `composer:2` | Quelle des Composer-Programms (z. B. aus eigener Registry spiegeln) |

Aufbau in drei Stufen:

1. **composer** – nur das Composer-Programm.
2. **vendor** – `composer install --no-dev` installiert Kirby nach `kirby/`
   (Version aus `composer.lock`).
3. **runtime** – PHP-Erweiterungen kompilieren, Apache einrichten,
   Programmcode kopieren, Startinhalt nach `/opt/kneipe/content`.

Nicht im Image: Tests, Dokumentation, `deploy/`, Werkzeuge, `.env`,
Laufzeitdaten und Anfragen (`.dockerignore`).

**GitHub Actions** (`.github/workflows/docker.yml`) baut das Image bei jedem
Push, startet es mit Volume und prüft Seiten, Sperren, Header, Vorschaubilder
und Health-Checks. Nur wenn alles passt, wird es auf `main` nach
`ghcr.io/<owner>/<repo>` veröffentlicht – Tags `latest` und
`sha-<commit>`. Für den Cluster das Tag `sha-<commit>` verwenden, nicht
`latest`. Ist das Paket privat, braucht der Cluster ein `imagePullSecret`
(GitHub-Token mit `read:packages`).

---

## Umgebungsvariablen

**Pflicht** sind nur `KIRBY_URL`, `KIRBY_CONTENT_SALT` und `KIRBY_COOKIE_KEY`
– ohne sie startet der Container nicht (Ausnahme: `KIRBY_DEBUG=true`).
Geheimnisse gehören in ein **Secret**, alles andere in eine **ConfigMap**.

### Website

| Variable | Standard | Secret | Beschreibung |
| --- | --- | :---: | --- |
| `KIRBY_URL` | – (**Pflicht**) | | Öffentliche Adresse ohne `/` am Ende, z. B. `https://www.example.org`. Grundlage für Links, Canonical, Sitemap, Mails – und für sichere Cookies hinter dem TLS-terminierenden Ingress |
| `KIRBY_CONTENT_SALT` | – (**Pflicht**) | ✔ | Zufälliger Wert, mind. 32 Zeichen (`openssl rand -hex 32`). Für Medien-URLs und Vorschau-Links; nach dem Livegang nicht mehr ändern |
| `KIRBY_COOKIE_KEY` | – (**Pflicht**) | ✔ | Zufälliger Wert, mind. 32 Zeichen. Signiert Sitzungs-Cookies; Ändern meldet alle ab |
| `KIRBY_DEBUG` | `false` | | Fehlerdetails anzeigen – **nie** im Livebetrieb |
| `KIRBY_NOINDEX` | `false` | | `true` für Vorschau/Staging: `robots.txt` sperrt alles, `noindex`-Meta-Tag und `X-Robots-Tag` |
| `KIRBY_TIMEZONE` | `Europe/Berlin` | | Zeitzone für Termine, Kalender und PHP |

### E-Mail (Terminanfragen)

| Variable | Standard | Secret | Beschreibung |
| --- | --- | :---: | --- |
| `KIRBY_MAIL_TRANSPORT` | `smtp` | | `smtp`, `mail` (sendmail – im Image nicht vorhanden) oder `none` (nichts versenden, Anfragen nur im Panel) |
| `KIRBY_SMTP_HOST` | – | | SMTP-Server, z. B. `smtp.example.org` |
| `KIRBY_SMTP_PORT` | `587` | | Port |
| `KIRBY_SMTP_SECURITY` | `tls` | | `tls` (STARTTLS, 587) oder `ssl` (465) |
| `KIRBY_SMTP_USER` | – | ✔ | Benutzername; leer = ohne Anmeldung |
| `KIRBY_SMTP_PASSWORD` | – | ✔ | Passwort |
| `KIRBY_MAIL_FROM` | `noreply@<host>` | | Absender – muss zum SMTP-Konto passen (SPF/DMARC) |
| `KIRBY_MAIL_FROM_NAME` | Name der Website | | Anzeigename des Absenders |

Empfänger der Anfragen, Eingangsbestätigung und Löschfrist stellt die
Administration im Panel ein (Übersicht → Einstellungen).

### Erster Administrator

| Variable | Standard | Secret | Beschreibung |
| --- | --- | :---: | --- |
| `KNEIPE_ADMIN_EMAIL` | – | | Legt beim Start ein Administrator-Konto an – **nur wenn noch kein Konto existiert** |
| `KNEIPE_ADMIN_PASSWORD` | – | ✔ | Passwort dafür (mind. 12 Zeichen) |
| `KNEIPE_ADMIN_NAME` | `Administration` | | Anzeigename |

Nach der ersten Anmeldung das Passwort im Panel ändern und
`KNEIPE_ADMIN_PASSWORD` aus dem Secret entfernen. Weitere Konten – für jede
Person ein eigenes – legt die Administration im Panel unter **Accounts** an.

### Formular und Betrieb

| Variable | Standard | Beschreibung |
| --- | --- | --- |
| `KNEIPE_RATE_LIMIT` | `5` | Höchstzahl vollständiger Anfragen je IP-Adresse und Stunde |
| `KNEIPE_MIN_SECONDS` | `3` | Zeitfalle: Mindestzeit zwischen Aufruf und Absenden |
| `KNEIPE_SEED_CONTENT` | `true` | Ist `/data/content` leer, beim Start den Startinhalt (mit Demo-Inhalten) kopieren. `false` = leer lassen und eigene Daten einspielen |
| `KNEIPE_TRUSTED_PROXIES` | `10.0.0.0/8 172.16.0.0/12 192.168.0.0/16 127.0.0.1` | Proxys (Ingress), deren `X-Forwarded-For` als echte Client-IP gilt – wichtig für Rate-Limit und Anmeldesperre. Auf die Pod-/Ingress-Netze des Clusters einschränken |
| `KNEIPE_ACCESS_LOG` | `false` | Zugriffsprotokoll auf stdout (ohne Query-String und Cookies). Meist protokolliert bereits der Ingress |

### Pfade (nur ändern, wenn nötig)

| Variable | Standard | Beschreibung |
| --- | --- | --- |
| `KIRBY_CONTENT_ROOT` | `/data/content` | Inhalte (Seiten, Bilder, Anfragen) |
| `KIRBY_MEDIA_ROOT` | `/data/media` | Von Kirby erzeugte Bildgrößen; Apache liefert `/media` aus diesem Ordner aus – deshalb nicht ändern |
| `KIRBY_STORAGE_ROOT` | `/data/storage` | Konten, Sitzungen, Cache, Protokolle, Lizenz |
| `KIRBY_ENV_FILE` | `/dev/null` | Optionale `.env`-Datei (z. B. als Secret-Datei eingehängt). Umgebungsvariablen haben Vorrang |
| `KNEIPE_OPEN_BASEDIR` | `/var/www/html/:/data/:/tmp/` | PHP darf nur diese Pfade lesen. Bei geänderten Pfaden anpassen |

### PHP und Apache

| Variable | Standard | Beschreibung |
| --- | --- | --- |
| `APACHE_PORT` | `8080` | Port im Container (unprivilegiert) |
| `PHP_MEMORY_LIMIT` | `256M` | Speicher je Anfrage (Bildverarbeitung) |
| `PHP_UPLOAD_MAX_FILESIZE` | `10M` | Größte Upload-Datei im Panel (Blueprints erlauben höchstens 10 MB) |
| `PHP_POST_MAX_SIZE` | `12M` | Größte Anfrage (Apache begrenzt zusätzlich auf 13 MB) |
| `PHP_MAX_EXECUTION_TIME` | `60` | Sekunden je Anfrage |
| `PHP_OPCACHE_VALIDATE_TIMESTAMPS` | `0` | `1` nur beim Entwickeln mit eingehängtem Code |

---

## Volumes

| Pfad | Art | Inhalt | Sichern? |
| --- | --- | --- | --- |
| **`/data`** | PersistentVolumeClaim, **Pflicht** | `content/` (alle Inhalte inkl. Bilder und Anfragen), `storage/accounts/` (Benutzerkonten), `storage/.license`, `storage/sessions/`, `storage/cache/`, `media/` | `content/`, `storage/accounts/`, `storage/.license` |
| `/tmp` | `emptyDir` | PHP-Uploads, Apache-PID und -Sperren | nein |

- Größe: Inhalte plus Bilder; **1–5 GiB** reichen für den Anfang.
- Zugriffsmodus: **ReadWriteOnce** genügt bei einer Replik (empfohlen).
- Rechte: Der Container läuft als UID/GID 33 – in Kubernetes
  `securityContext.fsGroup: 33` setzen, damit das Volume beschreibbar ist.
- `media/` wird bei Bedarf neu erzeugt und muss nicht gesichert werden.
- Mit `readOnlyRootFilesystem: true` muss `/tmp` als `emptyDir`
  eingehängt sein; sonst ist nichts weiter nötig.

---

## Abhängigkeiten

### Zur Laufzeit

| Abhängigkeit | Pflicht | Hinweis |
| --- | :---: | --- |
| **Persistenter Speicher** für `/data` | ✔ | StorageClass mit ReadWriteOnce |
| **Ingress/Load-Balancer mit TLS** | ✔ | HTTPS ist Voraussetzung für Panel und Formular. Upload-Grenze ≥ 12 MB (z. B. `nginx.ingress.kubernetes.io/proxy-body-size: 12m`) |
| **SMTP-Server** | empfohlen | Benachrichtigung über Terminanfragen; ohne SMTP werden Anfragen nur im Panel gespeichert |
| **Kirby-Lizenz** | für den Livebetrieb | https://getkirby.com/buy – Aktivierung im Panel, gespeichert in `/data/storage/.license` |
| Ausgehend HTTPS zu `hub.getkirby.com` | optional | nur für die Lizenzaktivierung im Panel |

Keine Datenbank, kein Redis, kein Node.js, keine externen Dienste im
Frontend (Schriften, Karten, Tracker).

### Im Image

| Komponente | Version | Zweck |
| --- | --- | --- |
| PHP | 8.4 (`PHP_VERSION`) | Kirby 5.6 unterstützt 8.2–8.5 |
| PHP-Erweiterungen | gd (JPEG, PNG, WebP, AVIF, FreeType), intl, zip, exif, OPcache, mbstring, curl, dom, xml | Kirby, Vorschaubilder, deutsche Datumsformate |
| Apache 2.4 | aus dem Basis-Image | mod_php, rewrite, headers, remoteip, expires |
| Kirby | aus `composer.lock` (5.6.x) | CMS, bringt PHPMailer mit |

### Beim Bauen

| Quelle | Zweck |
| --- | --- |
| Docker Hub: `php:8.4-apache`, `composer:2` | Basis-Images |
| Debian-Paketquellen | Bibliotheken für gd, intl, zip |
| Packagist/GitHub | Kirby und seine Composer-Pakete |

---

## Kubernetes

Beispiel für einen Namespace `kneipe`. Werte in `<…>` ersetzen.

### Secret

```yaml
apiVersion: v1
kind: Secret
metadata:
  name: kneipe-secrets
  namespace: kneipe
type: Opaque
stringData:
  KIRBY_CONTENT_SALT: "<openssl rand -hex 32>"
  KIRBY_COOKIE_KEY: "<openssl rand -hex 32>"
  KIRBY_SMTP_USER: "noreply@<domain>"
  KIRBY_SMTP_PASSWORD: "<smtp-passwort>"
  # nur für den ersten Start, danach entfernen:
  KNEIPE_ADMIN_PASSWORD: "<mindestens-12-zeichen>"
```

### ConfigMap

```yaml
apiVersion: v1
kind: ConfigMap
metadata:
  name: kneipe-config
  namespace: kneipe
data:
  KIRBY_URL: "https://www.<domain>"
  KIRBY_NOINDEX: "false"          # Staging: "true"
  KIRBY_SMTP_HOST: "smtp.<anbieter>"
  KIRBY_SMTP_PORT: "587"
  KIRBY_SMTP_SECURITY: "tls"
  KIRBY_MAIL_FROM: "noreply@<domain>"
  KIRBY_MAIL_FROM_NAME: "Ehrenamtskneipe Erndtebrück"
  KNEIPE_ADMIN_EMAIL: "<vorname@domain>"
  KNEIPE_ADMIN_NAME: "<Vorname Nachname>"
  # Pod-/Ingress-Netze des Clusters, z. B.:
  KNEIPE_TRUSTED_PROXIES: "10.0.0.0/8"
```

### PersistentVolumeClaim

```yaml
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: kneipe-data
  namespace: kneipe
spec:
  accessModes: [ReadWriteOnce]
  resources:
    requests:
      storage: 5Gi
  # storageClassName: <klasse>
```

### Deployment

```yaml
apiVersion: apps/v1
kind: Deployment
metadata:
  name: kneipe-web
  namespace: kneipe
spec:
  replicas: 1
  strategy:
    type: Recreate            # RWO-Volume: alter Pod zuerst beenden
  selector:
    matchLabels:
      app: kneipe-web
  template:
    metadata:
      labels:
        app: kneipe-web
    spec:
      securityContext:
        runAsNonRoot: true
        runAsUser: 33
        runAsGroup: 33
        fsGroup: 33
        fsGroupChangePolicy: OnRootMismatch
        seccompProfile:
          type: RuntimeDefault
      # imagePullSecrets: [{ name: ghcr-pull }]   # bei privatem Paket
      containers:
        - name: web
          image: ghcr.io/vladislavvk1337/web-kirby-vereinskneipe-ern:sha-<commit>
          ports:
            - name: http
              containerPort: 8080
          envFrom:
            - configMapRef:
                name: kneipe-config
            - secretRef:
                name: kneipe-secrets
          volumeMounts:
            - name: data
              mountPath: /data
            - name: tmp
              mountPath: /tmp
          securityContext:
            allowPrivilegeEscalation: false
            readOnlyRootFilesystem: true
            capabilities:
              drop: [ALL]
          startupProbe:
            httpGet: { path: /healthz, port: http }
            periodSeconds: 2
            failureThreshold: 30
          livenessProbe:
            httpGet: { path: /healthz, port: http }
            periodSeconds: 20
            timeoutSeconds: 5
          readinessProbe:
            httpGet: { path: /readyz, port: http }
            periodSeconds: 10
            timeoutSeconds: 5
          resources:
            requests:
              cpu: 50m
              memory: 128Mi
            limits:
              memory: 512Mi
      volumes:
        - name: data
          persistentVolumeClaim:
            claimName: kneipe-data
        - name: tmp
          emptyDir:
            sizeLimit: 256Mi
```

### Service und Ingress

```yaml
apiVersion: v1
kind: Service
metadata:
  name: kneipe-web
  namespace: kneipe
spec:
  selector:
    app: kneipe-web
  ports:
    - name: http
      port: 80
      targetPort: http
---
apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: kneipe-web
  namespace: kneipe
  annotations:
    cert-manager.io/cluster-issuer: letsencrypt        # falls cert-manager genutzt wird
    nginx.ingress.kubernetes.io/proxy-body-size: 12m    # Uploads im Panel
    nginx.ingress.kubernetes.io/ssl-redirect: "true"
spec:
  ingressClassName: nginx
  tls:
    - hosts: [www.<domain>]
      secretName: kneipe-tls
  rules:
    - host: www.<domain>
      http:
        paths:
          - path: /
            pathType: Prefix
            backend:
              service:
                name: kneipe-web
                port:
                  name: http
```

HSTS am Ingress aktivieren, sobald HTTPS dauerhaft steht. Die übrigen
Sicherheits-Header und die Content-Security-Policy setzt der Container selbst.

### Skalierung

Kirby speichert alles in Dateien. **Empfohlen: eine Replik** mit
`strategy: Recreate` – für eine Vereinskneipe reicht das bei Weitem (die
Startseite ist ca. 170 KB groß, OPcache ist aktiv). Mehrere Repliken nur mit
einem **ReadWriteMany**-Volume (z. B. NFS/CephFS) für `/data`; Sitzungen und
Inhaltssperren liegen dann gemeinsam dort. Kurze Ausfallzeiten beim Update
(Recreate) sind der Preis für die einfache Speicherung.

### Wartung ohne CronJob

Die Löschfrist für Terminanfragen wird automatisch angewendet – bei jeder
neuen Anfrage und höchstens einmal täglich über den Readiness-Check
(`/readyz`). Ein eigener CronJob ist nicht nötig. Wer es trotzdem getrennt
möchte (nur mit ReadWriteMany-Volume oder im selben Pod):

```bash
kubectl -n kneipe exec deploy/kneipe-web -- php bin/cleanup-requests.php
```

---

## Betrieb

### Weitere Benutzer auf der Kommandozeile

```bash
kubectl -n kneipe exec -it deploy/kneipe-web -- \
  php bin/create-user.php --email=vorname@<domain> --name="Vorname Nachname" --role=moderator
```

### Update

Neues Image-Tag im Deployment setzen (`sha-<commit>` aus GitHub Actions).
Die Inhalte in `/data` bleiben unberührt; beim Start wird nur Kirbys Cache
geleert. Kirby-Updates vorher auf Staging testen (eigener Namespace mit
Kopie der Daten, `KIRBY_NOINDEX=true`).

### Sicherung

`/data/content`, `/data/storage/accounts` und `/data/storage/.license`
regelmäßig sichern – per Volume-Snapshot der StorageClass (Velero o. Ä.)
oder als Archiv:

```bash
kubectl -n kneipe exec deploy/kneipe-web -- \
  tar czf - --ignore-failed-read -C /data content storage/accounts storage/.license > kneipe-$(date +%F).tar.gz
```

Die Archive enthalten Passwort-Hashes und personenbezogene Anfragen:
verschlüsselt aufbewahren, alte Stände löschen. Dazu die Geheimnisse
(Secret) getrennt sichern. Mehr in [docs/backup-restore.md](docs/backup-restore.md).

### Daten übernehmen

Von einem bestehenden Server (`/var/www/<domain>/data`) oder aus einer
Sicherung – `KNEIPE_SEED_CONTENT=false`, Deployment starten, dann:

```bash
POD=$(kubectl -n kneipe get pod -l app=kneipe-web -o name | head -1)
kubectl -n kneipe exec -i "$POD" -- tar xzf - -C /data < kneipe-sicherung.tar.gz
kubectl -n kneipe rollout restart deploy/kneipe-web
```

Salt und Cookie-Schlüssel des alten Servers übernehmen, sonst werden
Vorschau-Links und Anmeldungen ungültig.

### Protokolle

`kubectl -n kneipe logs deploy/kneipe-web` – Start-Hinweise mit Präfix
`[kneipe]`, PHP- und Apache-Fehler, auf Wunsch Zugriffe
(`KNEIPE_ACCESS_LOG=true`). Formularinhalte werden nie protokolliert.

---

## Fehlersuche

| Meldung / Symptom | Ursache und Lösung |
| --- | --- |
| `FEHLER: KIRBY_CONTENT_SALT fehlt` (bzw. `KIRBY_COOKIE_KEY`, `KIRBY_URL`) | Secret/ConfigMap nicht eingebunden (`envFrom`) oder Wert zu kurz |
| `… ist nicht beschreibbar für UID 33` | `securityContext.fsGroup: 33` fehlt oder die StorageClass unterstützt fsGroup nicht (dann Init-Container mit `chown -R 33:33 /data`) |
| `/tmp ist nicht beschreibbar` | Bei `readOnlyRootFilesystem` ein `emptyDir` auf `/tmp` einhängen |
| Readiness bleibt „nicht bereit“ | `/data/content/site.txt` fehlt (`KNEIPE_SEED_CONTENT=false` ohne eingespielte Daten) oder Volume nicht beschreibbar |
| Links zeigen auf `http://` oder falschen Host | `KIRBY_URL` setzen |
| Panel-Anmeldung klappt nicht / Cookie fehlt | Seite nicht über HTTPS aufgerufen oder `KIRBY_URL` beginnt nicht mit `https://` |
| Alle Formularanfragen scheitern mit „zu viele Anfragen“ | Client-IP ist die des Ingress: `KNEIPE_TRUSTED_PROXIES` auf das Netz des Ingress setzen, Ingress muss `X-Forwarded-For` senden |
| Upload im Panel scheitert (413) | Upload-Grenze des Ingress erhöhen (`proxy-body-size: 12m`) |
| Keine E-Mails | `KIRBY_SMTP_*`, `KIRBY_MAIL_FROM` prüfen; Anfragen stehen trotzdem im Panel. Logs zeigen `[kneipe] … nicht versendet` |
| Bilder/Vorschaubilder fehlen | `/data/media` nicht beschreibbar oder `KIRBY_MEDIA_ROOT` geändert |
