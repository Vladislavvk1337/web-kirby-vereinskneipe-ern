# shellcheck shell=bash
# Modul: sysctl – Kernel- und Netzwerk-Härtung.

module_sysctl() {
  install -m 644 "$TEMPLATE_DIR/sysctl.conf" /etc/sysctl.d/99-basis-schutz.conf
  # Einzelne Schlüssel können in Containern/VMs fehlen – nicht fatal
  sysctl --system >/dev/null 2>&1 || warn "Nicht alle sysctl-Werte konnten gesetzt werden"
  ok "Kernel-/Netzwerk-Härtung aktiv (/etc/sysctl.d/99-basis-schutz.conf)"
}
