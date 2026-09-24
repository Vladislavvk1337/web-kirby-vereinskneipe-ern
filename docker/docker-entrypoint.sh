#!/usr/bin/env bash
# ==========================================================================
# Start des Containers
#
#   1. Umgebungsvariablen prüfen (Geheimnisse, öffentliche URL)
#   2. Datenverzeichnisse unter /data anlegen und auf Schreibrechte prüfen
#   3. Startinhalt beim allerersten Start kopieren (KNEIPE_SEED_CONTENT)
#   4. Optional: ersten Administrator anlegen (KNEIPE_ADMIN_*)
#   5. Apache starten – oder einen anderen Befehl ausführen, z. B.
#      "php bin/cleanup-requests.php" in einem Kubernetes-CronJob
#
# Dokumentation: Docker.md
# ==========================================================================
set -euo pipefail

log()  { printf '[kneipe] %s\n' "$*"; }
fail() { printf '[kneipe] FEHLER: %s\n' "$*" >&2; exit 1; }
is_true() { [[ "${1,,}" =~ ^(1|true|yes|on|ja)$ ]]; }

APP_DIR=/var/www/html
SEED_DIR=/opt/kneipe/content
server=false
[[ "${1:-}" == "apache2-foreground" ]] && server=true

# --------------------------------------------------------------------------
# 1. Konfiguration prüfen
# --------------------------------------------------------------------------
if ! is_true "${KIRBY_DEBUG:-false}"; then
  for key in KIRBY_CONTENT_SALT KIRBY_COOKIE_KEY; do
    value="${!key:-}"
    [[ -n "$value" ]] || fail "$key fehlt. Zufälligen Wert erzeugen: openssl rand -hex 32 (als Kubernetes-Secret hinterlegen)."
    (( ${#value} >= 32 )) || fail "$key ist zu kurz (mindestens 32 Zeichen)."
  done

  [[ -n "${KIRBY_URL:-}" ]] || fail "KIRBY_URL fehlt, z. B. https://www.example.org (öffentliche Adresse ohne / am Ende)."
  [[ "$KIRBY_URL" =~ ^https?://[^/]+(/.*)?$ ]] || fail "KIRBY_URL ist keine gültige Adresse: $KIRBY_URL"
  [[ "$KIRBY_URL" == */ ]] && fail "KIRBY_URL ohne / am Ende angeben: $KIRBY_URL"
  [[ "$KIRBY_URL" == https://* ]] || log "WARNUNG: KIRBY_URL ohne https – Panel-Anmeldung und Formular nur über HTTPS betreiben."
else
  log "WARNUNG: KIRBY_DEBUG=true – nur für lokale Tests, nie im Livebetrieb."
fi

case "${KIRBY_MAIL_TRANSPORT:-smtp}" in
  smtp)
    [[ -n "${KIRBY_SMTP_HOST:-}" ]] || log "WARNUNG: KIRBY_SMTP_HOST fehlt – Anfragen werden gespeichert, aber nicht per E-Mail gemeldet."
    [[ -n "${KIRBY_MAIL_FROM:-}" ]] || log "WARNUNG: KIRBY_MAIL_FROM fehlt – Absender wird noreply@<host>." ;;
  mail|none) ;;
  *) fail "KIRBY_MAIL_TRANSPORT muss smtp, mail oder none sein." ;;
esac

# --------------------------------------------------------------------------
# 2. Datenverzeichnisse
# --------------------------------------------------------------------------
CONTENT="${KIRBY_CONTENT_ROOT:?}"
MEDIA="${KIRBY_MEDIA_ROOT:?}"
STORAGE="${KIRBY_STORAGE_ROOT:?}"

for dir in "$CONTENT" "$MEDIA" "$STORAGE" "$STORAGE/accounts" "$STORAGE/sessions" "$STORAGE/cache" "$STORAGE/logs"; do
  mkdir -p "$dir" 2>/dev/null || fail "Kann $dir nicht anlegen. Volume beschreibbar für UID $(id -u)? In Kubernetes: securityContext.fsGroup: 33"
  [[ -w "$dir" ]] || fail "$dir ist nicht beschreibbar für UID $(id -u). In Kubernetes: securityContext.fsGroup: 33"
done
[[ -w /tmp ]] || fail "/tmp ist nicht beschreibbar (bei readOnlyRootFilesystem ein emptyDir einhängen)."

if [[ "$MEDIA" != "/data/media" ]]; then
  log "WARNUNG: KIRBY_MEDIA_ROOT ist nicht /data/media – der Webserver liefert Medien nur aus /data/media aus."
fi

# --------------------------------------------------------------------------
# 3. Startinhalt (nur beim allerersten Start)
# --------------------------------------------------------------------------
if $server && [[ ! -f "$CONTENT/site.txt" ]]; then
  if is_true "${KNEIPE_SEED_CONTENT:-true}"; then
    cp -R "$SEED_DIR"/. "$CONTENT"/
    log "Startinhalt (inkl. Demo-Inhalte) nach $CONTENT kopiert."
  else
    log "WARNUNG: $CONTENT ist leer und KNEIPE_SEED_CONTENT=false – Inhalte einspielen (Docker.md, Abschnitt Daten übernehmen)."
  fi
fi

# Kirby-Cache nach neuem Image leeren (UUID-Zuordnungen), Rate-Limit behalten
if $server; then
  find "$STORAGE/cache" -mindepth 1 -maxdepth 1 ! -name 'kneipe-ratelimit' -exec rm -rf {} + 2>/dev/null || true
fi

# --------------------------------------------------------------------------
# 4. Erster Administrator (nur wenn noch kein Konto existiert)
# --------------------------------------------------------------------------
if $server && [[ -n "${KNEIPE_ADMIN_EMAIL:-}" ]]; then
  if [[ -z "$(ls -A "$STORAGE/accounts" 2>/dev/null)" ]]; then
    [[ -n "${KNEIPE_ADMIN_PASSWORD:-}" ]] || fail "KNEIPE_ADMIN_EMAIL ist gesetzt, aber KNEIPE_ADMIN_PASSWORD fehlt."
    KIRBY_NEW_PASSWORD="$KNEIPE_ADMIN_PASSWORD" php "$APP_DIR/bin/create-user.php" \
      --email="$KNEIPE_ADMIN_EMAIL" --name="${KNEIPE_ADMIN_NAME:-Administration}" --role=admin \
      || fail "Erster Administrator konnte nicht angelegt werden."
    log "Erster Administrator angelegt: $KNEIPE_ADMIN_EMAIL – Passwort nach der ersten Anmeldung ändern und KNEIPE_ADMIN_PASSWORD entfernen."
  fi
fi

# --------------------------------------------------------------------------
# 5. Start
# --------------------------------------------------------------------------
if $server; then
  shift
  args=()
  is_true "${KNEIPE_ACCESS_LOG:-false}" && args+=(-DKNEIPE_ACCESS_LOG)
  is_true "${KIRBY_NOINDEX:-false}" && args+=(-DKNEIPE_NOINDEX)
  log "Start auf Port ${APACHE_PORT} · ${KIRBY_URL:-ohne feste URL} · Daten in /data"
  exec apache2-foreground "${args[@]}" "$@"
fi

exec "$@"
