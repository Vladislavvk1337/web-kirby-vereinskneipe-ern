#!/usr/bin/env bash
# ==========================================================================
# Kirby-Website aus GitHub aktualisieren
#
# Läuft alle 5 Minuten über den systemd-Timer site-update.timer:
#   1. Neuesten Stand des Branches holen               (als BUILD_USER)
#   2. Nur bei Änderungen: composer install, PHP-Syntax, Unit-Tests
#   3. Nur wenn alles klappt: Programmcode nach $APP_DIR (als root)
#
# Inhalte, Benutzerkonten, Sitzungen und Medien liegen in $DATA_DIR und
# werden NIE überschrieben – sie gehören dem Panel. content/ aus dem
# Repository wird nur beim allerersten Deployment als Startinhalt kopiert.
#
# Installation:  deploy/server-setup.sh (→ /usr/local/bin/site-update)
# Einstellungen: /etc/default/site-update (von server-setup.sh, root-eigen)
# Neu veröffentlichen erzwingen: sudo FORCE=1 site-update
# ==========================================================================
set -euo pipefail

ENV_FILE="/etc/default/site-update"
FORCE="${FORCE:-0}"

log() { printf '%s %s\n' "$(date '+%F %T')" "$*"; }

if [[ "${1:-}" != "--build" ]]; then
  [[ -r "$ENV_FILE" ]] || { log "$ENV_FILE fehlt – deploy/server-setup.sh ausführen."; exit 1; }
  # shellcheck source=/dev/null
  source "$ENV_FILE"
fi

: "${BRANCH:?}" "${REPO_DIR:?}" "${STATE_DIR:?}" "${BUILD_USER:?}"

# --------------------------------------------------------------------------
# Phase 1 – holen und prüfen (läuft als BUILD_USER)
# --------------------------------------------------------------------------
build() {
  cd "$REPO_DIR"

  git fetch --quiet --prune origin "$BRANCH"
  local remote_sha deployed_sha failed_sha
  remote_sha="$(git rev-parse "origin/$BRANCH")"
  deployed_sha="$(cat "$STATE_DIR/deployed-sha" 2>/dev/null || true)"
  failed_sha="$(cat "$STATE_DIR/failed-sha" 2>/dev/null || true)"

  if [[ "$FORCE" != "1" ]]; then
    [[ "$remote_sha" == "$deployed_sha" ]] && return 0
    [[ "$remote_sha" == "$failed_sha" ]] && return 0
  fi

  log "Neuer Stand ${remote_sha:0:7} (bisher ${deployed_sha:0:7}) – prüfe …"

  git reset --quiet --hard "$remote_sha"
  git clean --quiet -fdx -e kirby -e vendor

  # Abhängigkeiten nur neu installieren, wenn sich composer.lock geändert hat
  local lock_hash
  lock_hash="$(sha256sum composer.lock | cut -d' ' -f1)"
  if [[ ! -f kirby/bootstrap.php || "$lock_hash" != "$(cat "$STATE_DIR/lock-hash" 2>/dev/null || true)" ]]; then
    log "composer.lock geändert – composer install"
    if ! composer install --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader; then
      echo "$remote_sha" > "$STATE_DIR/failed-sha"
      log "composer install fehlgeschlagen – bisherige Version bleibt online."
      return 1
    fi
    echo "$lock_hash" > "$STATE_DIR/lock-hash"
  fi

  # Syntax aller PHP-Dateien und die schnellen Unit-Tests als Sicherung
  if ! find index.php site bin -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null \
    || ! php tests/run.php --unit >/dev/null; then
    echo "$remote_sha" > "$STATE_DIR/failed-sha"
    log "Prüfung fehlgeschlagen (php -l oder Unit-Tests) – bisherige Version bleibt online."
    return 1
  fi

  if [[ -n "$deployed_sha" ]] && ! git diff --quiet "$deployed_sha" "$remote_sha" -- deploy/ 2>/dev/null; then
    log "HINWEIS: deploy/ hat sich geändert (site.env, Caddy, PHP-Pool, Skripte oder basis-schutz-os)."
    log "         Übernehmen mit: sudo $REPO_DIR/deploy/server-setup.sh"
    if ! git diff --quiet "$deployed_sha" "$remote_sha" -- deploy/basis-schutz-os/ deploy/basis-schutz.env 2>/dev/null; then
      log "         basis-schutz-os ist neu: sudo $REPO_DIR/deploy/basis-schutz.sh"
    fi
  fi

  echo "$remote_sha" > "$STATE_DIR/ready-sha"
}

# --------------------------------------------------------------------------
# Phase 2 – veröffentlichen (läuft als root)
# --------------------------------------------------------------------------
publish() {
  local ready_sha
  ready_sha="$(cat "$STATE_DIR/ready-sha" 2>/dev/null || true)"
  [[ -n "$ready_sha" ]] || return 0

  [[ -f "$REPO_DIR/kirby/bootstrap.php" ]] || { log "kirby/ fehlt – nichts veröffentlicht."; return 1; }

  # Nur Programmcode. Laufzeitverzeichnisse unter site/ bleiben außen vor.
  rsync -rlpt --delete --delay-updates \
    --chown="$WWW_OWNER:www-data" --chmod=D2750,F640 \
    --exclude='.git/' --exclude='/site/accounts/' --exclude='/site/sessions/' --exclude='/site/cache/' \
    --exclude='/site/logs/' --exclude='/site/config/.license' \
    --include='/index.php' --include='/kirby/***' --include='/vendor/***' \
    --include='/site/***' --include='/assets/***' --include='/bin/***' \
    --exclude='*' \
    "$REPO_DIR/" "$APP_DIR/"

  # Startinhalt nur beim ersten Mal – danach gehört content/ dem Panel
  if [[ ! -f "$DATA_DIR/content/site.txt" ]]; then
    rsync -rlpt --chown=www-data:www-data --chmod=D2750,F640 \
      --exclude='/anfragen/_drafts/' "$REPO_DIR/content/" "$DATA_DIR/content/"
    log "Startinhalt (Demo-Inhalte) nach $DATA_DIR/content übernommen."
  fi

  # Kirby-Cache leeren (UUID-Zuordnungen u. ä.), Rate-Limit-Zähler behalten
  find "$DATA_DIR/storage/cache" -mindepth 1 -maxdepth 1 ! -name 'kneipe-ratelimit' -exec rm -rf {} +

  # OPcache leeren, damit der neue Code sofort gilt
  systemctl reload "$PHP_SERVICE" 2>/dev/null || true

  mv "$STATE_DIR/ready-sha" "$STATE_DIR/deployed-sha"
  chown "$BUILD_USER:$BUILD_USER" "$STATE_DIR/deployed-sha"
  rm -f "$STATE_DIR/failed-sha"
  log "Veröffentlicht: ${ready_sha:0:7} → $SITE_URL"
}

# --------------------------------------------------------------------------
if [[ "${1:-}" == "--build" ]]; then
  build
  exit
fi

: "${WWW_OWNER:?}" "${APP_DIR:?}" "${DATA_DIR:?}" "${PHP_SERVICE:?}" "${SITE_URL:?}"

if [[ $EUID -ne 0 ]]; then
  echo "Bitte als root ausführen (holt und prüft selbst als $BUILD_USER)." >&2
  exit 1
fi

for cmd in git php composer rsync flock runuser sha256sum; do
  command -v "$cmd" >/dev/null || { log "Programm fehlt: $cmd"; exit 1; }
done
for dir in "$REPO_DIR/.git" "$APP_DIR" "$DATA_DIR/content" "$DATA_DIR/storage"; do
  [[ -d "$dir" ]] || { log "Verzeichnis fehlt: $dir – deploy/server-setup.sh ausführen."; exit 1; }
done

install -d -m 750 -o "$BUILD_USER" -g "$BUILD_USER" "$STATE_DIR"

exec 9>"$STATE_DIR/update.lock"
if ! flock -n 9; then
  log "Aktualisierung läuft bereits – übersprungen."
  exit 0
fi

rm -f "$STATE_DIR/ready-sha"

build_env=(
  HOME="$(getent passwd "$BUILD_USER" | cut -d: -f6)"
  PATH="/usr/local/bin:/usr/bin:/bin"
  LANG="C.UTF-8"
  COMPOSER_NO_INTERACTION=1
  BRANCH="$BRANCH" REPO_DIR="$REPO_DIR" STATE_DIR="$STATE_DIR"
  BUILD_USER="$BUILD_USER" FORCE="$FORCE"
)
for v in http_proxy https_proxy no_proxy HTTP_PROXY HTTPS_PROXY NO_PROXY; do
  [[ -n "${!v:-}" ]] && build_env+=("$v=${!v}")
done
runuser -u "$BUILD_USER" -- env -i "${build_env[@]}" "$(readlink -f "$0")" --build

publish
