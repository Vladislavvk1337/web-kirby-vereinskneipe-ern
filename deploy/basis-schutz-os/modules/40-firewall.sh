# shellcheck shell=bash
# Modul: firewall – UFW, eingehend nur SSH, HTTP, HTTPS.

module_firewall() {
  apt_install ufw

  # IPv6 mitfiltern
  sed -i 's/^IPV6=.*/IPV6=yes/' /etc/default/ufw

  ufw default deny incoming  >/dev/null
  ufw default allow outgoing >/dev/null
  ufw default deny routed    >/dev/null

  # SSH ZUERST freigeben (vor dem Aktivieren) – "limit" bremst Brute-Force:
  # max. 6 Verbindungen in 30 Sekunden pro IP
  ufw limit "${SSH_PORT}/tcp" comment 'SSH' >/dev/null
  ufw allow 80/tcp  comment 'HTTP'  >/dev/null
  ufw allow 443/tcp comment 'HTTPS' >/dev/null

  # Alte SSH-Regel entfernen, falls der Port geändert wurde
  if [[ "$SSH_PORT" != "22" ]]; then
    ufw delete limit 22/tcp >/dev/null 2>&1 || true
  fi

  ufw logging low >/dev/null
  ufw --force enable >/dev/null
  systemctl enable ufw >/dev/null 2>&1 || true

  ok "Firewall aktiv – eingehend offen: ${SSH_PORT}/tcp (limit), 80/tcp, 443/tcp"
}
