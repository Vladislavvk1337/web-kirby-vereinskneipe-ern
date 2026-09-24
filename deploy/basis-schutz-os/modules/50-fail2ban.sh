# shellcheck shell=bash
# Modul: fail2ban – sperrt IPs nach fehlgeschlagenen Logins.

module_fail2ban() {
  apt_install fail2ban python3-systemd nftables
  render_template "$TEMPLATE_DIR/fail2ban/basis-schutz.local" \
    /etc/fail2ban/jail.d/basis-schutz.local 644 SSH_PORT FAIL2BAN_IGNOREIP
  systemctl enable fail2ban >/dev/null 2>&1
  systemctl restart fail2ban
  ok "fail2ban aktiv (Jail: sshd, 3 Fehlversuche -> Sperre ab 1h, steigend)"
}
