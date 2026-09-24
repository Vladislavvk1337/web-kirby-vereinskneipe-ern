# shellcheck shell=bash
# Modul: user – Admin-Benutzer mit sudo, übernimmt die SSH-Keys von root.

module_user() {
  local home ssh_dir ak

  # --- Benutzer anlegen ------------------------------------------------------
  if id "$ADMIN_USER" &>/dev/null; then
    ok "Benutzer '$ADMIN_USER' existiert bereits"
  else
    adduser --disabled-password --gecos "" "$ADMIN_USER" >/dev/null
    ok "Benutzer '$ADMIN_USER' angelegt"
  fi
  usermod -aG sudo "$ADMIN_USER"
  # Mitglied in www-data, damit Deploys (rsync) Gruppenrechte setzen dürfen
  getent group www-data >/dev/null && usermod -aG www-data "$ADMIN_USER"
  home="$(admin_home)"
  ssh_dir="$home/.ssh"
  ak="$ssh_dir/authorized_keys"

  install -d -m 700 -o "$ADMIN_USER" -g "$ADMIN_USER" "$ssh_dir"

  # --- authorized_keys von root übernehmen -----------------------------------
  # Cloud-Images (z. B. Ubuntu) schreiben vor root-Keys teils ein
  # command="echo 'Please login as the user …'" – diese Präfixe werden entfernt.
  if [[ -s /root/.ssh/authorized_keys ]]; then
    local merged
    merged="$(mktemp)"
    {
      [[ -f "$ak" ]] && cat "$ak"
      sed -E '/Please login as/ s/^.*[[:space:]]((ssh-(ed25519|rsa)|ecdsa-sha2-nistp(256|384|521)|sk-(ssh-ed25519|ecdsa-sha2-nistp256)@openssh\.com)[[:space:]])/\1/' \
        /root/.ssh/authorized_keys
    } | { grep -Ev '^[[:space:]]*(#|$)' || true; } | awk '!seen[$0]++' > "$merged"
    install -m 600 -o "$ADMIN_USER" -g "$ADMIN_USER" "$merged" "$ak"
    rm -f "$merged"
    ok "authorized_keys von root übernommen ($(wc -l < "$ak") Schlüssel)"
  else
    warn "/root/.ssh/authorized_keys ist leer oder fehlt"
  fi

  # --- Schlüsselpaare (privat + öffentlich) von root übernehmen --------------
  if is_true "$COPY_ROOT_KEYPAIRS"; then
    local f name copied=0
    shopt -s nullglob
    for f in /root/.ssh/id_*; do
      name="$(basename "$f")"
      if [[ "$name" == *.pub ]]; then
        install -m 644 -o "$ADMIN_USER" -g "$ADMIN_USER" "$f" "$ssh_dir/$name"
      else
        install -m 600 -o "$ADMIN_USER" -g "$ADMIN_USER" "$f" "$ssh_dir/$name"
      fi
      copied=$((copied + 1))
    done
    shopt -u nullglob
    if [[ $copied -gt 0 ]]; then
      ok "$copied Schlüsseldatei(en) aus /root/.ssh/id_* kopiert"
    else
      warn "Keine Schlüsselpaare unter /root/.ssh/id_* gefunden"
    fi
  fi

  # Ohne Key kein Login – dann hier abbrechen, bevor SSH gehärtet wird.
  if ! grep -qE '(^|[[:space:]])(ssh-|ecdsa-|sk-)[A-Za-z0-9@.-]+[[:space:]]+AAAA' "$ak" 2>/dev/null; then
    die "Kein gültiger Public Key in $ak – Abbruch, sonst droht Aussperren. Key hinterlegen und erneut starten."
  fi

  # --- sudo ------------------------------------------------------------------
  local sudoers="/etc/sudoers.d/90-$ADMIN_USER" tmp
  tmp="$(mktemp)"
  if is_true "$SUDO_NOPASSWD"; then
    printf '%s ALL=(ALL:ALL) NOPASSWD: ALL\n' "$ADMIN_USER" > "$tmp"
  else
    ensure_admin_password
    printf '%s ALL=(ALL:ALL) ALL\n' "$ADMIN_USER" > "$tmp"
  fi
  printf 'Defaults:%s timestamp_timeout=15\n' "$ADMIN_USER" >> "$tmp"
  visudo -cqf "$tmp" || { rm -f "$tmp"; die "sudoers-Datei ungültig"; }
  install -m 440 -o root -g root "$tmp" "$sudoers"
  rm -f "$tmp"
  ok "sudo für '$ADMIN_USER' eingerichtet ($(is_true "$SUDO_NOPASSWD" && echo 'ohne' || echo 'mit') Passwort)"

  # --- root absichern --------------------------------------------------------
  if is_true "$LOCK_ROOT_PASSWORD"; then
    passwd -l root >/dev/null
    ok "Root-Passwort gesperrt"
  fi
  if is_true "$CLEAR_ROOT_AUTHORIZED_KEYS" && [[ -s /root/.ssh/authorized_keys ]]; then
    backup_file /root/.ssh/authorized_keys
    : > /root/.ssh/authorized_keys
    ok "/root/.ssh/authorized_keys geleert (Backup: .basis-schutz.bak)"
  fi
}

# Sorgt dafür, dass der Admin ein Passwort für sudo hat.
ensure_admin_password() {
  if [[ -n "$ADMIN_PASSWORD_HASH" ]]; then
    usermod -p "$ADMIN_PASSWORD_HASH" "$ADMIN_USER"
    ok "Passwort für '$ADMIN_USER' aus ADMIN_PASSWORD_HASH gesetzt"
    return
  fi
  # Status "P" = nutzbares Passwort vorhanden
  if [[ "$(passwd -S "$ADMIN_USER" | awk '{print $2}')" == "P" ]]; then
    ok "'$ADMIN_USER' hat bereits ein Passwort"
    return
  fi
  if [[ -t 0 ]]; then
    log "Bitte ein sudo-Passwort für '$ADMIN_USER' festlegen:"
    until passwd "$ADMIN_USER"; do warn "Nochmal versuchen …"; done
  else
    die "Kein Passwort für '$ADMIN_USER': ADMIN_PASSWORD_HASH setzen, interaktiv ausführen oder SUDO_NOPASSWD=\"true\"."
  fi
}
