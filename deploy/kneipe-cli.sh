#!/usr/bin/env bash
# ==========================================================================
# Kirby-Befehle auf dem Server ausführen – als www-data, mit denselben
# Pfaden und Geheimnissen wie die Website.
#
#   sudo kneipe-cli create-user --email=name@example.org --name="Vorname Nachname" --role=admin
#   sudo kneipe-cli create-user --email=… --name="…" --role=moderator
#   sudo kneipe-cli cleanup-requests
#
# Installiert von deploy/server-setup.sh nach /usr/local/bin/kneipe-cli.
# ==========================================================================
set -euo pipefail

# shellcheck source=/dev/null
source /etc/default/site-update

command="${1:-}"
shift || true

case "$command" in
  create-user|cleanup-requests) ;;
  *)
    echo "Aufruf: kneipe-cli create-user --email=… --name=\"…\" --role=admin|moderator" >&2
    echo "        kneipe-cli cleanup-requests" >&2
    exit 2 ;;
esac

[[ $EUID -eq 0 ]] || { echo "Bitte mit sudo ausführen." >&2; exit 1; }

cd "$APP_DIR"
exec runuser -u www-data -- env -i \
  PATH="/usr/local/bin:/usr/bin:/bin" LANG="C.UTF-8" \
  KIRBY_NEW_PASSWORD="${KIRBY_NEW_PASSWORD:-}" \
  KIRBY_ENV_FILE="$CONFIG_DIR/kirby.env" \
  KIRBY_CONTENT_ROOT="$DATA_DIR/content" \
  KIRBY_MEDIA_ROOT="$DATA_DIR/media" \
  KIRBY_STORAGE_ROOT="$DATA_DIR/storage" \
  KIRBY_URL="$SITE_URL" \
  php "$APP_DIR/bin/$command.php" "$@"
