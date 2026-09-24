# shellcheck shell=bash
# Gemeinsame Hilfsfunktionen für setup.sh und die Module.

if [[ -t 1 ]]; then
  C_RESET=$'\e[0m'; C_BLUE=$'\e[34m'; C_GREEN=$'\e[32m'; C_YELLOW=$'\e[33m'; C_RED=$'\e[31m'
else
  C_RESET=""; C_BLUE=""; C_GREEN=""; C_YELLOW=""; C_RED=""
fi

log()     { printf '%s==>%s %s\n' "$C_BLUE" "$C_RESET" "$*"; }
ok()      { printf '%s  ✔%s %s\n' "$C_GREEN" "$C_RESET" "$*"; }
warn()    { printf '%s  !%s %s\n' "$C_YELLOW" "$C_RESET" "$*" >&2; }
die()     { printf '%s  ✘ %s%s\n' "$C_RED" "$*" "$C_RESET" >&2; exit 1; }

is_true() { [[ "${1,,}" == "true" || "${1,,}" == "yes" || "$1" == "1" ]]; }

# Sicherungskopie einer Datei anlegen (einmalig pro Lauf und Datei).
backup_file() {
  local f="$1"
  [[ -f "$f" ]] || return 0
  [[ -f "$f.basis-schutz.bak" ]] && return 0
  cp -a "$f" "$f.basis-schutz.bak"
}

# Template rendern. Nur die explizit genannten Variablen werden ersetzt,
# damit nginx-/PHP-Variablen wie $uri unangetastet bleiben.
#   render_template <quelle> <ziel> <modus> VAR1 VAR2 ...
render_template() {
  local src="$1" dst="$2" mode="$3"; shift 3
  local vars="" v
  for v in "$@"; do vars+="\${$v} "; done
  local tmp
  tmp="$(mktemp)"
  ( for v in "$@"; do export "${v?}"; done; envsubst "$vars" < "$src" ) > "$tmp"
  install -m "$mode" "$tmp" "$dst"
  rm -f "$tmp"
}

apt_install() {
  DEBIAN_FRONTEND=noninteractive apt-get install -y -q \
    -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold "$@" >/dev/null
}

# Versionsvergleich: version_ge 1.25.1 1.22.0 -> true
version_ge() { [[ "$(printf '%s\n%s\n' "$1" "$2" | sort -V | head -n1)" == "$2" ]]; }

admin_home() { getent passwd "$ADMIN_USER" | cut -d: -f6; }

site_id() { printf '%s' "$DOMAIN" | tr -c 'A-Za-z0-9\n' '-'; }

require_domain() {
  [[ -n "${DOMAIN:-}" ]] || die "DOMAIN ist in config.env nicht gesetzt (benötigt für Modul '$1')."
}
