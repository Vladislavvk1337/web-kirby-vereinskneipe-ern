#!/usr/bin/env bash
# ==========================================================================
# Tägliche Datensicherung: Inhalte (inkl. Bilder), Benutzerkonten,
# Lizenz und Konfiguration mit Geheimnissen.
#
#   sudo kneipe-backup            # sofort sichern
#
# Ziel: $BACKUP_DIR (root, 700), Dateien 600. Aufbewahrung BACKUP_KEEP_DAYS.
# Nicht gesichert: media/ (wird aus content/ neu erzeugt), Sitzungen, Cache.
# Wiederherstellen: docs/backup-restore.md
# Installiert von deploy/server-setup.sh nach /usr/local/bin/kneipe-backup.
# ==========================================================================
set -euo pipefail

# shellcheck source=/dev/null
source /etc/default/site-update

: "${BACKUP_DIR:?}" "${BACKUP_KEEP_DAYS:?}" "${DATA_DIR:?}" "${CONFIG_DIR:?}" "${PROJECT:?}"

[[ $EUID -eq 0 ]] || { echo "Bitte als root ausführen." >&2; exit 1; }

umask 077
install -d -m 700 "$BACKUP_DIR"

stamp="$(date '+%Y%m%d-%H%M')"
target="$BACKUP_DIR/$PROJECT-$stamp.tar.gz"
www="$(dirname "$DATA_DIR")"

paths=(data/content data/storage/accounts config)
[[ -f "$DATA_DIR/storage/.license" ]] && paths+=(data/storage/.license)
[[ -f /etc/site.local.env ]] && cp /etc/site.local.env "$CONFIG_DIR/.site.local.env.backup"

tar --create --gzip --file "$target.tmp" --directory "$www" "${paths[@]}"
mv "$target.tmp" "$target"
chmod 600 "$target"
rm -f "$CONFIG_DIR/.site.local.env.backup"

find "$BACKUP_DIR" -maxdepth 1 -name "$PROJECT-*.tar.gz" -mtime +"$BACKUP_KEEP_DAYS" -delete

printf '%s Sicherung: %s (%s)\n' "$(date '+%F %T')" "$target" "$(du -h "$target" | cut -f1)"
