#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Start des Containers (läuft als www-data, UID 33):
#
#   1. Konfiguration prüfen – im Zweifel mit klarer Meldung abbrechen
#   2. Laufzeitverzeichnisse anlegen (/data persistent, /tmp flüchtig)
#   3. Beim allerersten Start Beispielinhalte nach /data/pages kopieren
#      (nie, wenn dort schon Inhalte liegen – produktive Daten bleiben)
#   4. Geheimnisse aus der Umgebung als Grav-Schlüsseldateien ablegen
#   5. Erstes Administrationskonto anlegen, falls noch keins existiert
#   6. Webserver starten (bzw. den übergebenen Befehl ausführen)
#
# Alle Variablen: Docker.md
# ---------------------------------------------------------------------------
set -Eeuo pipefail
trap 'echo "[entrypoint] FEHLER in Zeile $LINENO (Befehl: $BASH_COMMAND)" >&2' ERR

GRAV_ROOT_DIR=/var/www/grav
: "${GRAV_DATA_DIR:=/data}"
: "${GRAV_ENVIRONMENT:=production}"
: "${GRAV_CACHE_PATH:=/tmp/grav/cache}"
: "${GRAV_LOG_PATH:=/tmp/grav/logs}"
: "${GRAV_TMP_PATH:=/tmp/grav/tmp}"
: "${GRAV_BACKUP_PATH:=/tmp/grav/backup}"
: "${KNEIPE_SEED_CONTENT:=true}"
export GRAV_DATA_DIR GRAV_ENVIRONMENT GRAV_CACHE_PATH GRAV_LOG_PATH GRAV_TMP_PATH GRAV_BACKUP_PATH

log() { echo "[entrypoint] $*" >&2; }
fail() { echo "[entrypoint] FEHLER: $*" >&2; exit 1; }
is_true() { [[ "${1,,}" =~ ^(1|true|yes|on|ja)$ ]]; }

# --- 1. Konfiguration prüfen -------------------------------------------------
case "$GRAV_ENVIRONMENT" in
  dev|staging|production) ;;
  *) fail "GRAV_ENVIRONMENT muss dev, staging oder production sein (ist: $GRAV_ENVIRONMENT)." ;;
esac

base_url="${GRAV_CONFIG__system__custom_base_url:-}"
if [[ "$GRAV_ENVIRONMENT" != "dev" ]]; then
  [[ -n "$base_url" ]] || fail "GRAV_CONFIG__system__custom_base_url (öffentliche Adresse, z. B. https://www.example.org) fehlt."
  [[ "$base_url" =~ ^https?://[^/]+ ]] || fail "GRAV_CONFIG__system__custom_base_url muss mit http:// oder https:// beginnen."
  [[ "${GRAV_CONFIG:-}" == "true" ]] || fail "GRAV_CONFIG=true fehlt – ohne sie wertet Grav keine GRAV_CONFIG__*-Variablen aus."
fi

for secret in GRAV_NONCE_KEY GRAV_API_JWT_SECRET; do
  value="${!secret:-}"
  if [[ -n "$value" && ${#value} -lt 32 ]]; then
    fail "$secret ist zu kurz (mindestens 32 Zeichen)."
  fi
done

# --- 2. Verzeichnisse ------------------------------------------------------
writable() {
  local dir="$1"
  mkdir -p "$dir" 2>/dev/null || fail "$dir lässt sich nicht anlegen (Volume eingehängt? Rechte für UID $(id -u)?)."
  [[ -w "$dir" ]] || fail "$dir ist nicht beschreibbar für UID $(id -u) – fsGroup/Rechte des Volumes prüfen."
}

writable "$GRAV_DATA_DIR"
for dir in pages accounts data config config/plugins media; do
  writable "$GRAV_DATA_DIR/$dir"
done
for dir in "$GRAV_CACHE_PATH" "$GRAV_LOG_PATH" "$GRAV_TMP_PATH" "$GRAV_BACKUP_PATH" \
           /tmp/grav/images /tmp/grav/assets /tmp/grav/sessions /tmp/apache2/run /tmp/apache2/lock; do
  writable "$dir"
done

# --- 3. Erststart: Beispielinhalte ------------------------------------------
if [[ -z "$(ls -A "$GRAV_DATA_DIR/pages" 2>/dev/null)" ]]; then
  if is_true "$KNEIPE_SEED_CONTENT"; then
    cp -R /opt/grav-seed/pages/. "$GRAV_DATA_DIR/pages/"
    log "Beispielinhalte nach $GRAV_DATA_DIR/pages kopiert (Erststart)."
  else
    log "WARNUNG: $GRAV_DATA_DIR/pages ist leer und KNEIPE_SEED_CONTENT=false – Inhalte bitte einspielen (Docker.md, Migration)."
  fi
fi

# --- 4. Geheimnisse ---------------------------------------------------------
# Grav liest seine Schlüssel aus PHP-Dateien in der ersten Konfigurations-
# ebene ($GRAV_DATA_DIR/config, siehe setup.php). Aus einem Secret gesetzt,
# gelten sie für alle Pods gleich; sonst erzeugt Grav sie beim ersten Bedarf.
write_secret() {
  local file="$1" tmp
  tmp="$(mktemp "$file.XXXXXX")"
  printf "<?php\n\n// Aus der Umgebung gesetzt (Entrypoint). Nicht bearbeiten.\nreturn %s;\n" \
    "$(php -r 'echo var_export(getenv("SECRET_VALUE"), true);')" > "$tmp"
  chmod 600 "$tmp"
  mv "$tmp" "$file"
}

if [[ -n "${GRAV_NONCE_KEY:-}" ]]; then
  SECRET_VALUE="$GRAV_NONCE_KEY" write_secret "$GRAV_DATA_DIR/config/security-private.php"
fi
if [[ -n "${GRAV_API_JWT_SECRET:-}" ]]; then
  SECRET_VALUE="$GRAV_API_JWT_SECRET" write_secret "$GRAV_DATA_DIR/config/plugins/api-private.php"
fi
unset SECRET_VALUE

# Veralteten Cache der vorigen Version entfernen (Image-Update)
rm -rf "${GRAV_CACHE_PATH:?}"/* 2>/dev/null || true

# --- 5. Erstes Konto ----------------------------------------------------------
cd "$GRAV_ROOT_DIR"

if [[ -z "$(find "$GRAV_DATA_DIR/accounts" -maxdepth 1 -name '*.yaml' -print -quit 2>/dev/null)" ]]; then
  if [[ -n "${KNEIPE_ADMIN_USERNAME:-}" && -n "${KNEIPE_ADMIN_PASSWORD:-}" && -n "${KNEIPE_ADMIN_EMAIL:-}" ]]; then
    printf '%s' "$KNEIPE_ADMIN_PASSWORD" | php bin/plugin kneipe user \
      --username="$KNEIPE_ADMIN_USERNAME" --email="$KNEIPE_ADMIN_EMAIL" \
      --group=administration --name="${KNEIPE_ADMIN_NAME:-Administration}" --password-stdin --if-none \
      || fail "Erstes Administrationskonto konnte nicht angelegt werden."
    log "Erstes Administrationskonto $KNEIPE_ADMIN_USERNAME angelegt."
  elif [[ "$GRAV_ENVIRONMENT" != "dev" ]] && ! is_true "${KNEIPE_ALLOW_WEB_SETUP:-false}"; then
    # Ohne Konto würde Admin2 die Einrichtung des ersten Superusers für
    # jede Person im Internet anbieten.
    fail "Noch kein Konto vorhanden. KNEIPE_ADMIN_USERNAME, KNEIPE_ADMIN_EMAIL und KNEIPE_ADMIN_PASSWORD setzen (Secret) – oder bewusst KNEIPE_ALLOW_WEB_SETUP=true."
  else
    log "WARNUNG: Noch kein Konto – die Einrichtung läuft über /admin. Nur in geschützter Umgebung verwenden."
  fi
fi
unset KNEIPE_ADMIN_PASSWORD

# --- 6. Start ---------------------------------------------------------------
if [[ "${1:-}" == "apache2-foreground" ]]; then
  flags=()
  is_true "${KNEIPE_ACCESS_LOG:-false}" && flags+=(-DKNEIPE_ACCESS_LOG)
  if is_true "${KNEIPE_NOINDEX:-false}" || [[ "$GRAV_ENVIRONMENT" == "staging" ]]; then
    flags+=(-DKNEIPE_NOINDEX)
  fi
  log "Starte Grav ($GRAV_ENVIRONMENT) auf Port ${APACHE_PORT:-8080}."
  exec apache2-foreground "${flags[@]}"
fi

exec "$@"
