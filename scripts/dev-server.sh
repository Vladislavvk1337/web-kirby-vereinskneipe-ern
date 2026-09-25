#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Lokaler Entwicklungsserver ohne Container: baut unter .grav/ denselben
# Aufbau wie im Image (Grav-Kern + Projektdateien per Symlink, Laufzeit-
# daten unter .grav/data) und startet den eingebauten PHP-Server.
#
#   scripts/dev-server.sh [port]          # Standard: 8000
#   scripts/dev-server.sh --prepare-only  # nur aufbauen (Tests, CI)
#
# Voraussetzung: PHP ≥ 8.3 mit gd, intl, mbstring, curl, zip, dom.
# ---------------------------------------------------------------------------
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
dev="${GRAV_DEV_DIR:-$root/.grav}"
grav="$dev/grav"
data="${GRAV_DATA_DIR:-$dev/data}"
port="${1:-8000}"

if [[ ! -f "$grav/index.php" ]]; then
  GRAV_DIST_CACHE="${GRAV_DIST_CACHE:-$dev/dist}" "$root/scripts/grav-dist.sh" "$grav"
fi

# Projektdateien verlinken – Änderungen im Repository wirken sofort
ln -sfn "$root/setup.php" "$grav/setup.php"
mkdir -p "$grav/user/plugins" "$grav/user/themes"
for dir in config env; do
  rm -rf "${grav:?}/user/$dir"
  ln -sfn "$root/user/$dir" "$grav/user/$dir"
done
ln -sfn "$root/user/plugins/kneipe" "$grav/user/plugins/kneipe"
ln -sfn "$root/user/themes/kneipe" "$grav/user/themes/kneipe"

# Laufzeitdaten wie im Container unter $GRAV_DATA_DIR
mkdir -p "$data"/{accounts,data,config,media} "$dev"/{cache,logs,tmp,backup,images,assets}
if [[ ! -d "$data/pages" ]]; then
  cp -a "$root/seed/pages" "$data/pages"
  echo "Beispielinhalte nach $data/pages kopiert." >&2
fi
for dir in pages accounts data media; do
  rm -rf "${grav:?}/user/$dir"
  ln -sfn "$data/$dir" "$grav/user/$dir"
done
for dir in images assets; do
  rm -rf "${grav:?}/$dir"
  ln -sfn "$dev/$dir" "$grav/$dir"
done

export GRAV_ENVIRONMENT="${GRAV_ENVIRONMENT:-dev}"
export GRAV_DATA_DIR="$data"
export GRAV_CACHE_PATH="$dev/cache" GRAV_LOG_PATH="$dev/logs" GRAV_TMP_PATH="$dev/tmp" GRAV_BACKUP_PATH="$dev/backup"

if [[ "$port" == "--prepare-only" ]]; then
  echo "$grav"
  exit 0
fi

if [[ -z "$(ls -A "$data/accounts")" ]]; then
  echo "Noch kein Konto vorhanden. Anlegen mit:" >&2
  echo "  GRAV_DATA_DIR=$data $grav/bin/plugin login new-user -u admin -e admin@example.org -P b --admin-type=api" >&2
  echo "und im Konto 'groups: [administration]' ergänzen (Docker.md)." >&2
fi

echo "Grav läuft auf http://localhost:$port (Admin: /admin), Umgebung $GRAV_ENVIRONMENT" >&2
cd "$grav"
exec php -d variables_order=EGPCS -S "localhost:$port" system/router.php
