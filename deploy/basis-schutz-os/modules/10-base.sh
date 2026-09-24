# shellcheck shell=bash
# Modul: base – Systemaktualisierung, Grundpakete, Zeitzone

module_base() {
  log "Paketquellen aktualisieren und System upgraden …"
  apt-get update -q >/dev/null
  DEBIAN_FRONTEND=noninteractive apt-get -y -q \
    -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold \
    full-upgrade >/dev/null
  ok "System aktualisiert"

  apt_install sudo ca-certificates curl gnupg rsync unzip gettext-base \
    openssh-server nftables
  ok "Grundpakete installiert"

  if [[ -n "$TIMEZONE" ]] && command -v timedatectl >/dev/null; then
    if timedatectl set-timezone "$TIMEZONE" 2>/dev/null; then
      ok "Zeitzone: $TIMEZONE"
    else
      warn "Zeitzone konnte nicht gesetzt werden"
    fi
  fi
}
