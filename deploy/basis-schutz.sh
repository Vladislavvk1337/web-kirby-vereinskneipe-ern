#!/usr/bin/env bash
# -----------------------------------------------------------------------------
# basis-schutz-os mit der Konfiguration dieses Projekts ausführen.
#
#   sudo deploy/basis-schutz.sh               # alle Module außer nginx
#   sudo deploy/basis-schutz.sh ssh firewall  # nur ausgewählte Module
#
# Kopiert deploy/basis-schutz-os/ nach /opt/basis-schutz-os (root-eigen, damit
# nichts aus dem Build-Verzeichnis mit root-Rechten läuft) und legt daneben
# config.env an – zusammengesetzt aus deploy/site.env, deploy/basis-schutz.env
# und /etc/site.local.env. Danach startet setup.sh.
# Idempotent – nach jeder Aktualisierung von basis-schutz-os erneut ausführen.
# -----------------------------------------------------------------------------
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TARGET="/opt/basis-schutz-os"
LOCAL_ENV="/etc/site.local.env"
DEFAULT_MODULES=(base user ssh firewall fail2ban updates sysctl php)

[[ $EUID -eq 0 ]] || { echo "Bitte als root bzw. mit sudo ausführen." >&2; exit 1; }
[[ -x "$HERE/basis-schutz-os/setup.sh" ]] || { echo "deploy/basis-schutz-os/ fehlt." >&2; exit 1; }
[[ -f "$HERE/site.env" ]] || { echo "deploy/site.env fehlt." >&2; exit 1; }

modules=("$@")
[[ ${#modules[@]} -gt 0 ]] || modules=("${DEFAULT_MODULES[@]}")
for m in "${modules[@]}"; do
  if [[ "$m" == "nginx" ]]; then
    echo "Das Modul nginx wird nicht verwendet – Webserver ist Caddy (deploy/caddy/)." >&2
    exit 1
  fi
done

# Platzhalter aus der Vorlage nicht auf einen echten Server loslassen
# shellcheck source=site.env
source "$HERE/site.env"
if [[ "${DOMAIN:-}" == "example.de" || -z "${DOMAIN:-}" ]]; then
  echo "deploy/site.env ist noch nicht angepasst (DOMAIN=\"${DOMAIN:-}\")." >&2
  exit 1
fi

rm -rf "$TARGET.neu"
cp -a "$HERE/basis-schutz-os" "$TARGET.neu"
{
  echo "# Erzeugt von deploy/basis-schutz.sh – nicht von Hand ändern."
  echo "# --- deploy/site.env";         cat "$HERE/site.env"
  echo; echo "# --- deploy/basis-schutz.env"; cat "$HERE/basis-schutz.env"
  if [[ -f "$LOCAL_ENV" ]]; then
    echo; echo "# --- $LOCAL_ENV"; cat "$LOCAL_ENV"
  fi
} > "$TARGET.neu/config.env"
chmod 600 "$TARGET.neu/config.env"
chown -R root:root "$TARGET.neu"
rm -rf "$TARGET"
mv "$TARGET.neu" "$TARGET"

echo "==> basis-schutz-os $(sed -n 's/^commit=//p' "$HERE/basis-schutz-os.version" | cut -c1-7) – Module: ${modules[*]}"
cd "$TARGET"
exec ./setup.sh "${modules[@]}"
