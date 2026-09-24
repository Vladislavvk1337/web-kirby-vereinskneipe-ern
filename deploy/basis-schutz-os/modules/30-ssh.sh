# shellcheck shell=bash
# Modul: ssh – Root-Login und Passwort-Login deaktivieren, nur Public-Key.

module_ssh() {
  local dropin=/etc/ssh/sshd_config.d/00-basis-schutz.conf
  local home
  home="$(admin_home)"

  # Sicherheitsnetz: nie härten, solange der Admin sich nicht anmelden kann.
  id "$ADMIN_USER" &>/dev/null || die "Benutzer '$ADMIN_USER' fehlt – zuerst Modul 'user' ausführen."
  grep -qE '(^|[[:space:]])(ssh-|ecdsa-|sk-)[A-Za-z0-9@.-]+[[:space:]]+AAAA' "$home/.ssh/authorized_keys" 2>/dev/null \
    || die "Kein Public Key für '$ADMIN_USER' hinterlegt – SSH wird NICHT gehärtet."

  # Include des Drop-in-Verzeichnisses sicherstellen (Debian 11+/Ubuntu 20.04+ haben das)
  if ! grep -qE '^[[:space:]]*Include[[:space:]]+/etc/ssh/sshd_config\.d/\*\.conf' /etc/ssh/sshd_config; then
    backup_file /etc/ssh/sshd_config
    sed -i '1i Include /etc/ssh/sshd_config.d/*.conf' /etc/ssh/sshd_config
    ok "Include für sshd_config.d ergänzt"
  fi

  install -d -m 755 /etc/ssh/sshd_config.d
  render_template "$TEMPLATE_DIR/ssh/00-basis-schutz.conf" "$dropin" 644 SSH_PORT ADMIN_USER

  # Schwache Diffie-Hellman-Moduli (< 3072 Bit) entfernen
  if [[ -f /etc/ssh/moduli ]] && awk '!/^#/ && $5 < 3071' /etc/ssh/moduli | grep -q .; then
    backup_file /etc/ssh/moduli
    awk '/^#/ || $5 >= 3071' /etc/ssh/moduli > /etc/ssh/moduli.tmp && mv /etc/ssh/moduli.tmp /etc/ssh/moduli
    ok "Schwache DH-Moduli entfernt"
  fi

  mkdir -p /run/sshd
  if ! sshd -t; then
    rm -f "$dropin"
    die "sshd-Konfiguration ungültig – Drop-in wieder entfernt, nichts geändert."
  fi

  # Effektive Werte prüfen
  local eff
  eff="$(sshd -T -C "user=$ADMIN_USER,host=localhost,addr=127.0.0.1" 2>/dev/null)"
  grep -q '^permitrootlogin no' <<<"$eff" || warn "PermitRootLogin ist effektiv nicht 'no' – sshd_config prüfen!"
  grep -q '^passwordauthentication no' <<<"$eff" || warn "PasswordAuthentication ist effektiv nicht 'no' – sshd_config prüfen!"

  # Ubuntu 24.04+: Socket-Aktivierung – Port wird aus sshd_config generiert
  if systemctl is-enabled ssh.socket &>/dev/null; then
    systemctl daemon-reload
    systemctl restart ssh.socket
  fi

  # Reload lässt bestehende Sitzungen offen
  systemctl try-reload-or-restart ssh.service 2>/dev/null \
    || systemctl try-reload-or-restart sshd.service 2>/dev/null || true
  ok "SSH gehärtet: kein Root-Login, kein Passwort, nur '$ADMIN_USER' mit Key (Port $SSH_PORT)"
}
