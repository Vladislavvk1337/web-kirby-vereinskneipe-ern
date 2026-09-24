# shellcheck shell=bash
# Modul: nginx – Webserver für die statische Astro-Seite, TLS via Let's Encrypt.

module_nginx() {
  require_domain nginx
  php_vars
  SITE_ROOT="$WEB_ROOT/site"
  SERVER_NAMES="$(echo "$DOMAIN $DOMAIN_ALIASES" | xargs)"
  local cert="/etc/letsencrypt/live/$DOMAIN/fullchain.pem"

  apt_install nginx
  is_true "$ENABLE_TLS" && apt_install certbot

  local nginx_version
  nginx_version="$(nginx -v 2>&1 | sed -E 's|.*nginx/([0-9.]+).*|\1|')"

  # --- Verzeichnisse -----------------------------------------------------------
  install -d -m 755 -o root -g root /var/www /var/www/letsencrypt
  install -d -m 2750 -o "$ADMIN_USER" -g www-data "$WEB_ROOT" "$SITE_ROOT"
  if [[ -z "$(ls -A "$SITE_ROOT")" ]]; then
    printf '<!doctype html><meta charset="utf-8"><title>%s</title><p>Hier entsteht %s.</p>\n' \
      "$DOMAIN" "$DOMAIN" > "$SITE_ROOT/index.html"
    chown "$ADMIN_USER:www-data" "$SITE_ROOT/index.html"
    chmod 640 "$SITE_ROOT/index.html"
  fi

  # --- Globale Einstellungen + Header ----------------------------------------
  render_template "$TEMPLATE_DIR/nginx/basis-schutz.conf" /etc/nginx/conf.d/basis-schutz.conf 644 API_RATE
  install -m 644 "$TEMPLATE_DIR/nginx/tls.conf" /etc/nginx/snippets/basis-schutz-tls.conf

  CSP_HEADER=""
  [[ -n "$CSP" ]] && CSP_HEADER="add_header Content-Security-Policy \"$CSP\" always;"
  HSTS_HEADER=""
  if is_true "$ENABLE_TLS"; then
    HSTS_HEADER='add_header Strict-Transport-Security "max-age=31536000" always;'
  fi
  render_template "$TEMPLATE_DIR/nginx/security-headers.conf" \
    /etc/nginx/snippets/basis-schutz-headers.conf 644 CSP_HEADER HSTS_HEADER

  # Default-Server: unbekannte Hosts verwerfen (TLS-Handshake ab nginx 1.19.4)
  DEFAULT_TLS_SERVER=""
  if version_ge "$nginx_version" 1.19.4; then
    DEFAULT_TLS_SERVER=$'server {\n    listen 443 ssl default_server;\n    listen [::]:443 ssl default_server;\n    server_name _;\n    ssl_reject_handshake on;\n}'
  fi
  rm -f /etc/nginx/sites-enabled/default
  render_template "$TEMPLATE_DIR/nginx/default-deny.conf" \
    /etc/nginx/sites-available/000-default-deny.conf 644 DEFAULT_TLS_SERVER
  ln -sfn ../sites-available/000-default-deny.conf /etc/nginx/sites-enabled/000-default-deny.conf

  # --- Seite -----------------------------------------------------------------
  PHP_INCLUDE="# PHP deaktiviert (ENABLE_PHP=false)"
  if is_true "$ENABLE_PHP"; then
    render_template "$TEMPLATE_DIR/nginx/site-php.conf" "/etc/nginx/snippets/$SITE_ID-php.conf" 644 \
      DOMAIN API_ROOT API_BURST PHP_SOCKET
    PHP_INCLUDE="include snippets/$SITE_ID-php.conf;"
  fi
  render_template "$TEMPLATE_DIR/nginx/site-body.conf" "/etc/nginx/snippets/$SITE_ID-site.conf" 644 \
    DOMAIN SITE_ROOT SITE_ID PHP_INCLUDE

  if version_ge "$nginx_version" 1.25.1; then
    LISTEN_443=$'listen 443 ssl;\n    listen [::]:443 ssl;\n    http2 on;'
  else
    LISTEN_443=$'listen 443 ssl http2;\n    listen [::]:443 ssl http2;'
  fi

  nginx_write_site() {
    local tpl="site-http.conf"
    [[ -f "$cert" ]] && tpl="site-https.conf"
    render_template "$TEMPLATE_DIR/nginx/$tpl" "/etc/nginx/sites-available/$SITE_ID.conf" 644 \
      DOMAIN SERVER_NAMES SITE_ID LISTEN_443
    ln -sfn "../sites-available/$SITE_ID.conf" "/etc/nginx/sites-enabled/$SITE_ID.conf"
    nginx -t 2>/dev/null || { nginx -t || true; die "nginx-Konfiguration ungültig"; }
    systemctl enable nginx >/dev/null 2>&1
    systemctl reload-or-restart nginx
  }
  nginx_write_site
  ok "nginx liefert $SITE_ROOT für: $SERVER_NAMES"

  # --- Let's Encrypt ---------------------------------------------------------
  if is_true "$ENABLE_TLS"; then
    if [[ ! -f "$cert" ]]; then
      local args=(certonly --webroot -w /var/www/letsencrypt --non-interactive --agree-tos
                  --cert-name "$DOMAIN" --key-type ecdsa)
      local n
      for n in $SERVER_NAMES; do args+=(-d "$n"); done
      if [[ -n "$LETSENCRYPT_EMAIL" ]]; then
        args+=(--email "$LETSENCRYPT_EMAIL")
      else
        args+=(--register-unsafely-without-email)
      fi
      if certbot "${args[@]}"; then
        nginx_write_site
        ok "TLS-Zertifikat eingerichtet – HTTP leitet auf HTTPS um"
      else
        warn "Zertifikat konnte nicht ausgestellt werden (zeigt DNS für $SERVER_NAMES auf diesen Server?)."
        warn "Seite läuft vorerst nur über HTTP. Später erneut: sudo ./setup.sh nginx"
      fi
    else
      ok "TLS-Zertifikat vorhanden"
    fi
    install -d -m 755 /etc/letsencrypt/renewal-hooks/deploy
    printf '#!/bin/sh\nsystemctl reload nginx\n' > /etc/letsencrypt/renewal-hooks/deploy/reload-nginx.sh
    chmod 755 /etc/letsencrypt/renewal-hooks/deploy/reload-nginx.sh
  fi

  # --- fail2ban-Jail für das API-Rate-Limit ----------------------------------
  if [[ -d /etc/fail2ban/jail.d ]]; then
    touch /var/log/nginx/error.log
    install -m 644 "$TEMPLATE_DIR/fail2ban/nginx.local" /etc/fail2ban/jail.d/basis-schutz-nginx.local
    systemctl restart fail2ban 2>/dev/null || warn "fail2ban-Neustart fehlgeschlagen"
  fi
}
