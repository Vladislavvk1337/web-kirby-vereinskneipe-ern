#!/usr/bin/env bash
# -----------------------------------------------------------------------------
# basis-schutz-os – Basisschutz für einen Debian/Ubuntu-Server mit statischer
# Astro-Webseite und optionalem PHP für den E-Mail-Versand.
#
# Aufruf (als root bzw. mit sudo):
#   ./setup.sh                 # alle Module
#   ./setup.sh ssh firewall    # nur ausgewählte Module
#   ./setup.sh --list          # Module anzeigen
#
# Das Skript ist idempotent und kann gefahrlos mehrfach ausgeführt werden.
# -----------------------------------------------------------------------------
set -Eeuo pipefail

BASE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TEMPLATE_DIR="$BASE_DIR/templates"
export BASE_DIR TEMPLATE_DIR

# shellcheck source=lib/common.sh
source "$BASE_DIR/lib/common.sh"

trap 'die "Fehler in Zeile $LINENO (Modul: ${CURRENT_MODULE:-setup}). Abbruch."' ERR

# --- Standardwerte (werden von config.env überschrieben) ---------------------
ADMIN_USER="keiladm"
ADMIN_PASSWORD_HASH=""
SUDO_NOPASSWD="false"
COPY_ROOT_KEYPAIRS="true"
LOCK_ROOT_PASSWORD="true"
CLEAR_ROOT_AUTHORIZED_KEYS="false"
TIMEZONE="Europe/Berlin"
SSH_PORT="22"
AUTO_REBOOT="false"
AUTO_REBOOT_TIME="04:00"
FAIL2BAN_IGNOREIP=""
DOMAIN=""
DOMAIN_ALIASES=""
ENABLE_TLS="true"
LETSENCRYPT_EMAIL=""
CSP=""
ENABLE_PHP="true"
PHP_COMPOSER_PACKAGES="phpmailer/phpmailer"
API_RATE="5r/m"
API_BURST="5"

CONFIG_FILE="${CONFIG_FILE:-$BASE_DIR/config.env}"
if [[ -f "$CONFIG_FILE" ]]; then
  # shellcheck source=/dev/null
  source "$CONFIG_FILE"
else
  warn "Keine config.env gefunden – es werden Standardwerte verwendet."
fi

# --- Module ------------------------------------------------------------------
ALL_MODULES=(base user ssh firewall fail2ban updates sysctl php nginx)

for f in "$BASE_DIR"/modules/*.sh; do
  # shellcheck source=/dev/null
  source "$f"
done

usage() {
  cat <<EOF
Aufruf: sudo ./setup.sh [--list] [MODUL ...]

Module (Reihenfolge):
  base      Pakete aktualisieren, Grundpakete, Zeitzone
  user      Admin-Benutzer '$ADMIN_USER' mit sudo + SSH-Keys von root
  ssh       Root-Login & Passwort-Login deaktivieren, nur Public-Key
  firewall  UFW: eingehend nur ${SSH_PORT}/tcp, 80/tcp, 443/tcp
  fail2ban  Brute-Force-Schutz für SSH (und nginx)
  updates   Automatische Sicherheitsupdates (unattended-upgrades)
  sysctl    Kernel-/Netzwerk-Härtung
  php       PHP-FPM (gehärtet) + Composer + Mail-Pakete
  nginx     nginx für Astro (statisch) + Let's Encrypt + Security-Header
EOF
}

preflight() {
  [[ $EUID -eq 0 ]] || die "Bitte als root oder mit sudo ausführen."
  [[ -r /etc/os-release ]] || die "/etc/os-release nicht gefunden."
  # shellcheck source=/dev/null
  . /etc/os-release
  case "${ID:-}" in
    debian|ubuntu) ;;
    *) die "Nicht unterstütztes System: ${PRETTY_NAME:-unbekannt} (unterstützt: Debian 12+/Ubuntu 22.04+)." ;;
  esac
  ok "System: $PRETTY_NAME"
  [[ "$ADMIN_USER" =~ ^[a-z_][a-z0-9_-]{0,31}$ ]] || die "Ungültiger Benutzername: $ADMIN_USER"
  [[ "$ADMIN_USER" != "root" ]] || die "ADMIN_USER darf nicht root sein."
}

run_module() {
  local m="$1"
  declare -F "module_$m" >/dev/null || die "Unbekanntes Modul: $m (siehe --list)"
  CURRENT_MODULE="$m"
  log "Modul: $m"
  "module_$m"
}

main() {
  local modules=()
  while [[ $# -gt 0 ]]; do
    case "$1" in
      -h|--help|--list) usage; exit 0 ;;
      *) modules+=("$1") ;;
    esac
    shift
  done
  [[ ${#modules[@]} -gt 0 ]] || modules=("${ALL_MODULES[@]}")

  preflight
  for m in "${modules[@]}"; do run_module "$m"; done
  CURRENT_MODULE=""

  echo
  ok "Fertig."
  if [[ " ${modules[*]} " == *" ssh "* ]]; then
    cat <<EOF

${C_YELLOW}WICHTIG:${C_RESET} Diese Sitzung NICHT schließen, bevor der neue Login getestet ist!
In einem ZWEITEN Terminal prüfen:

    ssh ${ADMIN_USER}@<server-ip>
    sudo -v

Erst wenn beides klappt, die Root-Sitzung beenden.
EOF
  fi
}

main "$@"
