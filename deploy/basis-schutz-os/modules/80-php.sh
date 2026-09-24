# shellcheck shell=bash
# Modul: php – gehärtetes PHP-FPM, Composer und Mail-Pakete für die API.
#
# Verzeichnisstruktur:
#   /var/www/<domain>/site/          Astro-Build (dist/) – öffentlich
#   /var/www/<domain>/api/public/    PHP-Endpunkte  ->  https://<domain>/api/*.php
#   /var/www/<domain>/api/vendor/    Composer-Pakete (nicht öffentlich)
#   /var/www/<domain>/api/config.php SMTP-Zugangsdaten (nicht öffentlich, 640)

php_vars() {
  SITE_ID="$(site_id)"
  WEB_ROOT="/var/www/$DOMAIN"
  API_ROOT="$WEB_ROOT/api"
  PHP_SOCKET="/run/php/php-fpm-$SITE_ID.sock"
}

module_php() {
  if ! is_true "$ENABLE_PHP"; then
    log "PHP deaktiviert (ENABLE_PHP=false) – übersprungen"
    return 0
  fi
  require_domain php
  php_vars

  apt_install php-fpm php-cli php-mbstring php-xml php-curl php-intl composer unzip
  local v
  v="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
  local fpm_dir="/etc/php/$v/fpm"
  [[ -d "$fpm_dir" ]] || die "PHP-FPM-Verzeichnis $fpm_dir nicht gefunden"

  # --- php.ini-Härtung + eigener Pool ----------------------------------------
  install -m 644 "$TEMPLATE_DIR/php/99-basis-schutz.ini" "$fpm_dir/conf.d/99-basis-schutz.ini"
  render_template "$TEMPLATE_DIR/php/pool.conf" "$fpm_dir/pool.d/$SITE_ID.conf" 644 \
    DOMAIN SITE_ID PHP_SOCKET API_ROOT
  # Standard-Pool "www" wird nicht gebraucht
  if [[ -f "$fpm_dir/pool.d/www.conf" ]]; then
    mv "$fpm_dir/pool.d/www.conf" "$fpm_dir/pool.d/www.conf.disabled"
  fi
  touch "/var/log/php-fpm-$SITE_ID.log"
  chown www-data:www-data "/var/log/php-fpm-$SITE_ID.log"
  chmod 640 "/var/log/php-fpm-$SITE_ID.log"

  # --- Verzeichnisse -----------------------------------------------------------
  install -d -m 755 -o root -g root /var/www
  install -d -m 2750 -o "$ADMIN_USER" -g www-data "$WEB_ROOT" "$API_ROOT" "$API_ROOT/public"

  # --- Composer-Pakete (als Admin, nicht als root) ---------------------------
  if [[ -n "$PHP_COMPOSER_PACKAGES" ]]; then
    local pkgs
    read -r -a pkgs <<<"$PHP_COMPOSER_PACKAGES"
    sudo -u "$ADMIN_USER" -H composer --no-interaction --quiet \
      --working-dir="$API_ROOT" require "${pkgs[@]}"
    ok "Composer-Pakete installiert: $PHP_COMPOSER_PACKAGES"
  fi

  # --- Konfiguration + Beispiel-Endpunkt (nur wenn noch nicht vorhanden) -----
  if [[ ! -f "$API_ROOT/config.php" ]]; then
    local ALLOWED_ORIGINS="" n
    for n in $DOMAIN $DOMAIN_ALIASES; do
      ALLOWED_ORIGINS+="'https://$n', "
    done
    ALLOWED_ORIGINS="${ALLOWED_ORIGINS%, }"
    render_template "$TEMPLATE_DIR/php/config.php" "$API_ROOT/config.php" 640 DOMAIN ALLOWED_ORIGINS
    chown "$ADMIN_USER:www-data" "$API_ROOT/config.php"
    warn "SMTP-Zugangsdaten eintragen: $API_ROOT/config.php"
  fi
  if [[ ! -f "$API_ROOT/public/contact.php" && " $PHP_COMPOSER_PACKAGES " == *" phpmailer/phpmailer "* ]]; then
    install -m 640 -o "$ADMIN_USER" -g www-data "$BASE_DIR/examples/php/contact.php" "$API_ROOT/public/contact.php"
    ok "Beispiel-Endpunkt angelegt: /api/contact.php"
  fi

  "php-fpm$v" -t 2>/dev/null || die "PHP-FPM-Konfiguration ungültig (php-fpm$v -t)"
  systemctl enable "php$v-fpm" >/dev/null 2>&1
  systemctl restart "php$v-fpm"
  ok "PHP $v-FPM aktiv (Pool '$SITE_ID', open_basedir, gefährliche Funktionen gesperrt)"
}
