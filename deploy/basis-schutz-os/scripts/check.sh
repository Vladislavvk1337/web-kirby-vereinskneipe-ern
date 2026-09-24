#!/usr/bin/env bash
# -----------------------------------------------------------------------------
# Schnell-Check des Basisschutzes (auf dem Server ausführen):
#   sudo ./scripts/check.sh
# -----------------------------------------------------------------------------
set -uo pipefail

ADMIN_USER="${ADMIN_USER:-keiladm}"
BASE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# shellcheck source=/dev/null
[[ -f "$BASE_DIR/config.env" ]] && source "$BASE_DIR/config.env"

[[ $EUID -eq 0 ]] || { echo "Bitte mit sudo ausführen."; exit 1; }

pass=0; fail=0
check() {
  local desc="$1"; shift
  if "$@" &>/dev/null; then
    printf '  \e[32m✔\e[0m %s\n' "$desc"; pass=$((pass + 1))
  else
    printf '  \e[31m✘\e[0m %s\n' "$desc"; fail=$((fail + 1))
  fi
}

sshd_eff="$(sshd -T -C "user=$ADMIN_USER,host=localhost,addr=127.0.0.1" 2>/dev/null)"
ssh_opt() { grep -qx "$1" <<<"$sshd_eff"; }

echo "Benutzer"
check "$ADMIN_USER existiert"                 id "$ADMIN_USER"
check "$ADMIN_USER ist in Gruppe sudo"        bash -c "id -nG '$ADMIN_USER' | grep -qw sudo"
check "$ADMIN_USER hat authorized_keys"       test -s "$(getent passwd "$ADMIN_USER" | cut -d: -f6)/.ssh/authorized_keys"
check "Root-Passwort gesperrt"                bash -c "passwd -S root | awk '{exit (\$2==\"L\")?0:1}'"

echo "SSH"
check "PermitRootLogin no"                    ssh_opt "permitrootlogin no"
check "PasswordAuthentication no"             ssh_opt "passwordauthentication no"
check "KbdInteractiveAuthentication no"       ssh_opt "kbdinteractiveauthentication no"
check "AllowUsers $ADMIN_USER"                ssh_opt "allowusers $ADMIN_USER"

echo "Firewall"
check "UFW aktiv"                             bash -c "ufw status | grep -q 'Status: active'"
check "Standard: eingehend verweigert"        bash -c "ufw status verbose | grep -q 'deny (incoming)'"
for p in "${SSH_PORT:-22}" 80 443; do
  check "Port $p/tcp freigegeben"             bash -c "ufw status | grep -qE '^$p/tcp +(ALLOW|LIMIT)'"
done

echo "Dienste"
check "fail2ban läuft"                        systemctl is-active fail2ban
check "fail2ban-Jail sshd aktiv"              fail2ban-client status sshd
check "unattended-upgrades aktiv"             systemctl is-enabled unattended-upgrades
check "nginx läuft"                           systemctl is-active nginx
check "nginx-Konfiguration gültig"            nginx -t

echo
echo "Offene Ports (lauschend):"
ss -tulpnH | awk '{print "  " $1 " " $5 "  " $7}' | sort -u
echo
printf 'Ergebnis: %d ok, %d fehlgeschlagen\n' "$pass" "$fail"
[[ $fail -eq 0 ]]
