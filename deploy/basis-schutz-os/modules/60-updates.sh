# shellcheck shell=bash
# Modul: updates – automatische Sicherheitsupdates.

module_updates() {
  apt_install unattended-upgrades apt-listchanges

  cat > /etc/apt/apt.conf.d/20auto-upgrades <<CONF
// Verwaltet von basis-schutz-os
APT::Periodic::Update-Package-Lists "1";
APT::Periodic::Unattended-Upgrade "1";
APT::Periodic::AutocleanInterval "7";
CONF

  cat > /etc/apt/apt.conf.d/52basis-schutz-unattended <<CONF
// Verwaltet von basis-schutz-os
Unattended-Upgrade::Remove-Unused-Kernel-Packages "true";
Unattended-Upgrade::Remove-Unused-Dependencies "true";
Unattended-Upgrade::Automatic-Reboot "$(is_true "$AUTO_REBOOT" && echo true || echo false)";
Unattended-Upgrade::Automatic-Reboot-Time "${AUTO_REBOOT_TIME}";
CONF

  systemctl enable --now unattended-upgrades >/dev/null 2>&1 || true
  ok "Automatische Sicherheitsupdates aktiv (Auto-Reboot: $AUTO_REBOOT)"
}
