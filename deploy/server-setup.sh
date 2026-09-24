#!/usr/bin/env bash
# ==========================================================================
# Kirby-Website einrichten: PHP-Erweiterungen, PHP-FPM-Pool, Caddy,
# automatische Aktualisierung, tägliche Löschfrist und Datensicherung.
#
#   sudo /srv/<PROJECT>/repo/deploy/server-setup.sh
#
# Voraussetzung: deploy/basis-schutz.sh wurde ausgeführt (Admin-Benutzer,
# Firewall, gehärtetes PHP-FPM, /var/www/<DOMAIN>/). Anleitung:
# docs/deployment.md
#
# Idempotent – nach jeder Änderung in deploy/ erneut ausführen. Das
# Repository ist die Quelle für Pool, Caddyfile, Skripte und Units.
# Geheimnisse in /var/www/<DOMAIN>/config/kirby.env bleiben erhalten.
# ==========================================================================
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LOCAL_ENV="/etc/site.local.env"
ENV_FILE="/etc/default/site-update"

ok()   { printf '  ✔ %s\n' "$*"; }
step() { printf '==> %s\n' "$*"; }
warn() { printf '  ! %s\n' "$*"; }
die()  { printf '  ✘ %s\n' "$*" >&2; exit 1; }

[[ $EUID -eq 0 ]] || die "Bitte als root bzw. mit sudo ausführen."

# Platzhalter {{NAME}} in einer Vorlage ersetzen
render() {
  local content="$1" v value
  shift
  for v in "$@"; do
    value="${!v:-}"
    content="${content//"{{$v}}"/"$value"}"
  done
  if grep -q '{{' <<<"$content"; then
    die "Unbekannter Platzhalter: $(grep -o '{{[A-Z_]*}}' <<<"$content" | sort -u | tr '\n' ' ')"
  fi
  printf '%s' "$content"
}

# --------------------------------------------------------------------------
step "Konfiguration aus deploy/site.env"
# shellcheck source=site.env
source "$HERE/site.env"
if [[ -f "$LOCAL_ENV" ]]; then
  # shellcheck source=/dev/null
  source "$LOCAL_ENV"
fi

for v in PROJECT BRANCH DOMAIN LIVE_HOST SITE_PHASE ACME_EMAIL ADMIN_USER BUILD_USER; do
  [[ -n "${!v:-}" ]] || die "$v ist in deploy/site.env nicht gesetzt."
done
[[ "$PROJECT" =~ ^[a-z0-9][a-z0-9-]*$ ]] || die "PROJECT darf nur Kleinbuchstaben, Ziffern und - enthalten."
[[ "$DOMAIN" != "example.de" ]] || die "deploy/site.env ist noch nicht angepasst (DOMAIN=example.de)."

# NOINDEX wird indirekt über render() in den PHP-Pool geschrieben
# shellcheck disable=SC2034
case "$SITE_PHASE" in
  preview)
    [[ -n "${PREVIEW_HOST:-}" ]] || die "SITE_PHASE=preview braucht PREVIEW_HOST."
    SITE_URL="${SITE_URL:-https://$PREVIEW_HOST}"
    NOINDEX="true" ;;
  live)
    SITE_URL="${SITE_URL:-https://$LIVE_HOST}"
    NOINDEX="false" ;;
  *) die "SITE_PHASE muss preview oder live sein (ist: $SITE_PHASE)." ;;
esac

SITE_ID="$(printf '%s' "$DOMAIN" | tr -c 'A-Za-z0-9\n' '-')"
REPO_DIR="${REPO_DIR:-/srv/$PROJECT/repo}"
STATE_DIR="${STATE_DIR:-/srv/$PROJECT/state}"
WWW_DIR="${WWW_DIR:-/var/www/$DOMAIN}"
WWW_OWNER="${WWW_OWNER:-$ADMIN_USER}"
APP_DIR="$WWW_DIR/app"
DATA_DIR="$WWW_DIR/data"
CONFIG_DIR="$WWW_DIR/config"
KIRBY_SOCKET="/run/php/php-fpm-kirby-$SITE_ID.sock"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/$PROJECT}"
BACKUP_KEEP_DAYS="${BACKUP_KEEP_DAYS:-14}"

ok "$PROJECT · Phase $SITE_PHASE · $SITE_URL"

# --------------------------------------------------------------------------
step "Voraussetzungen aus basis-schutz-os prüfen"
id "$WWW_OWNER" >/dev/null 2>&1 || die "Admin-Benutzer $WWW_OWNER fehlt – zuerst: sudo $HERE/basis-schutz.sh"
[[ -d "$WWW_DIR" ]] || die "$WWW_DIR fehlt – Modul php: sudo $HERE/basis-schutz.sh php"
command -v php >/dev/null || die "PHP fehlt – Modul php: sudo $HERE/basis-schutz.sh php"
id "$BUILD_USER" >/dev/null 2>&1 || die "Benutzer $BUILD_USER fehlt – siehe docs/deployment.md, Schritt 3"
[[ -d "$REPO_DIR/.git" ]] || die "$REPO_DIR ist kein Git-Repository – siehe docs/deployment.md, Schritt 3"
PHP_VERSION="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
PHP_SERVICE="php$PHP_VERSION-fpm"
ok "basis-schutz-os eingerichtet, PHP $PHP_VERSION"

if systemctl is-active --quiet nginx 2>/dev/null; then
  systemctl disable --now nginx >/dev/null 2>&1
  ok "nginx gestoppt und deaktiviert (Port 80/443 gehören Caddy)"
fi

# --------------------------------------------------------------------------
step "Pakete"
export DEBIAN_FRONTEND=noninteractive
apt-get install -y -q git rsync curl ca-certificates gnupg logrotate unzip composer \
  debian-keyring debian-archive-keyring apt-transport-https \
  "php$PHP_VERSION-gd" "php$PHP_VERSION-intl" "php$PHP_VERSION-mbstring" "php$PHP_VERSION-xml" \
  "php$PHP_VERSION-curl" "php$PHP_VERSION-zip" >/dev/null
ok "git, rsync, composer, PHP-Erweiterungen für Kirby (gd, intl, mbstring, xml, curl, zip)"

if ! command -v caddy >/dev/null; then
  curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' \
    | gpg --dearmor --yes -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg
  curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' \
    > /etc/apt/sources.list.d/caddy-stable.list
  apt-get update -q >/dev/null
  apt-get install -y -q caddy >/dev/null
fi
ok "Caddy $(caddy version | cut -d' ' -f1)"

# --------------------------------------------------------------------------
step "Verzeichnisse und Rechte"
usermod -aG www-data caddy
# Programmcode: Admin schreibt (per site-update), PHP und Caddy lesen
install -d -m 2750 -o "$WWW_OWNER" -g www-data "$APP_DIR"
# Daten: nur PHP (www-data) schreibt; Caddy liest Medien über die Gruppe
install -d -m 2750 -o www-data -g www-data "$DATA_DIR"
install -d -m 2750 -o www-data -g www-data "$DATA_DIR/content" "$DATA_DIR/media"
install -d -m 2700 -o www-data -g www-data "$DATA_DIR/storage"
install -d -m 2700 -o www-data -g www-data \
  "$DATA_DIR/storage/accounts" "$DATA_DIR/storage/sessions" "$DATA_DIR/storage/cache" "$DATA_DIR/storage/logs"
install -d -m 2750 -o "$WWW_OWNER" -g www-data "$CONFIG_DIR"
install -d -m 750 -o "$BUILD_USER" -g "$BUILD_USER" "$STATE_DIR"
install -d -m 750 -o caddy -g caddy /var/log/caddy
install -d -m 700 -o root -g root "$BACKUP_DIR"
install -m 644 "$HERE/logrotate-caddy" /etc/logrotate.d/site-caddy
touch "/var/log/php-fpm-kirby-$SITE_ID.log"
chown www-data:www-data "/var/log/php-fpm-kirby-$SITE_ID.log"
chmod 640 "/var/log/php-fpm-kirby-$SITE_ID.log"
ok "$APP_DIR, $DATA_DIR, $CONFIG_DIR, $BACKUP_DIR"

# --------------------------------------------------------------------------
step "Geheimnisse ($CONFIG_DIR/kirby.env)"
SECRETS="$CONFIG_DIR/kirby.env"
if [[ ! -f "$SECRETS" ]]; then
  {
    echo "# Geheimnisse für Kirby – nur auf diesem Server, nie ins Repository."
    echo "# Angelegt von deploy/server-setup.sh am $(date -I). Siehe .env.example."
    echo "KIRBY_DEBUG=false"
    echo "KIRBY_CONTENT_SALT=$(openssl rand -hex 32)"
    echo "KIRBY_COOKIE_KEY=$(openssl rand -hex 32)"
    echo
    echo "# E-Mail (SMTP) – eintragen, dann: sudo systemctl reload $PHP_SERVICE"
    echo "KIRBY_MAIL_TRANSPORT=smtp"
    echo "KIRBY_SMTP_HOST="
    echo "KIRBY_SMTP_PORT=587"
    echo "KIRBY_SMTP_SECURITY=tls"
    echo "KIRBY_SMTP_USER="
    echo "KIRBY_SMTP_PASSWORD="
    echo "KIRBY_MAIL_FROM=noreply@$DOMAIN"
    echo "KIRBY_MAIL_FROM_NAME=\"Website $DOMAIN\""
  } > "$SECRETS"
  warn "SMTP-Zugang eintragen: sudo -u $WWW_OWNER nano $SECRETS"
else
  for key in KIRBY_CONTENT_SALT KIRBY_COOKIE_KEY; do
    if ! grep -q "^$key=." "$SECRETS"; then
      echo "$key=$(openssl rand -hex 32)" >> "$SECRETS"
      ok "$key ergänzt"
    fi
  done
fi
chown "$WWW_OWNER:www-data" "$SECRETS"
chmod 640 "$SECRETS"
ok "vorhanden (640, $WWW_OWNER:www-data)"

# --------------------------------------------------------------------------
step "PHP-FPM-Pool für Kirby"
FPM_DIR="/etc/php/$PHP_VERSION/fpm"
[[ -d "$FPM_DIR/pool.d" ]] || die "$FPM_DIR/pool.d fehlt – ist PHP-FPM installiert?"
render "$(cat "$HERE/php/kirby-pool.conf")" \
  DOMAIN SITE_ID KIRBY_SOCKET CONFIG_DIR DATA_DIR APP_DIR SITE_URL NOINDEX \
  > "$FPM_DIR/pool.d/kirby-$SITE_ID.conf"
chmod 644 "$FPM_DIR/pool.d/kirby-$SITE_ID.conf"
"php-fpm$PHP_VERSION" -t >/dev/null 2>&1 || { "php-fpm$PHP_VERSION" -t || true; die "PHP-FPM-Konfiguration ungültig"; }
systemctl restart "$PHP_SERVICE"
ok "Pool kirby-$SITE_ID → $KIRBY_SOCKET"

# --------------------------------------------------------------------------
step "Automatische Aktualisierung, Löschfrist, Datensicherung"
{
  echo "# Erzeugt von deploy/server-setup.sh aus deploy/site.env – nicht von Hand ändern."
  for v in PROJECT BRANCH SITE_URL REPO_DIR STATE_DIR BUILD_USER WWW_DIR WWW_OWNER \
           APP_DIR DATA_DIR CONFIG_DIR PHP_SERVICE BACKUP_DIR BACKUP_KEEP_DAYS; do
    printf '%s=%q\n' "$v" "${!v}"
  done
} > "$ENV_FILE.neu"
chmod 644 "$ENV_FILE.neu"
mv "$ENV_FILE.neu" "$ENV_FILE"

install -m 755 "$HERE/site-update.sh" /usr/local/bin/site-update
install -m 755 "$HERE/kneipe-cli.sh"  /usr/local/bin/kneipe-cli
install -m 755 "$HERE/backup.sh"      /usr/local/bin/kneipe-backup
for unit in site-update.service site-update.timer kneipe-cleanup.service kneipe-cleanup.timer \
            kneipe-backup.service kneipe-backup.timer; do
  install -m 644 "$HERE/systemd/$unit" "/etc/systemd/system/$unit"
done
systemctl daemon-reload
ok "site-update, kneipe-cli, kneipe-backup und Timer installiert"

# --------------------------------------------------------------------------
step "Caddy"
parts=("$HERE/caddy/Caddyfile")
if [[ "$SITE_PHASE" == "preview" ]]; then
  parts+=("$HERE/caddy/preview.caddy")
else
  parts+=("$HERE/caddy/live.caddy")
  [[ -n "${PREVIEW_HOST:-}" ]] && parts+=("$HERE/caddy/preview-redirect.caddy")
fi

caddyfile=""
for part in "${parts[@]}"; do
  caddyfile+="$(cat "$part")"$'\n\n'
done
caddyfile="$(render "${caddyfile%$'\n'}" ACME_EMAIL APP_DIR DATA_DIR KIRBY_SOCKET DOMAIN LIVE_HOST PREVIEW_HOST)"

if [[ -f /etc/caddy/Caddyfile && ! -f /etc/caddy/Caddyfile.orig ]]; then
  cp -a /etc/caddy/Caddyfile /etc/caddy/Caddyfile.orig
fi
printf '%s\n' "$caddyfile" > /etc/caddy/Caddyfile.neu
chmod 644 /etc/caddy/Caddyfile.neu
if ! runuser -u caddy -- caddy validate --config /etc/caddy/Caddyfile.neu --adapter caddyfile >/dev/null 2>&1; then
  runuser -u caddy -- caddy validate --config /etc/caddy/Caddyfile.neu --adapter caddyfile || true
  rm -f /etc/caddy/Caddyfile.neu
  die "Caddyfile ungültig – nichts geändert."
fi
mv /etc/caddy/Caddyfile.neu /etc/caddy/Caddyfile
systemctl enable caddy >/dev/null 2>&1
systemctl restart caddy || die "Caddy startet nicht – journalctl -u caddy -n 50"
ok "Caddyfile übernommen (Phase $SITE_PHASE), Caddy läuft"

# --------------------------------------------------------------------------
step "Erste Veröffentlichung"
systemctl start site-update.service || true
journalctl -u site-update -n 8 --no-pager -o cat || true
systemctl enable --now site-update.timer kneipe-cleanup.timer kneipe-backup.timer >/dev/null 2>&1
ok "Timer aktiv: Aktualisierung alle 5 Minuten, Löschfrist täglich, Sicherung täglich"

if [[ -z "$(ls -A "$DATA_DIR/storage/accounts" 2>/dev/null)" ]]; then
  echo
  warn "Noch kein Benutzerkonto. Ersten Administrator anlegen:"
  warn "  sudo kneipe-cli create-user --email=… --name=\"…\" --role=admin"
fi

echo
echo "Fertig. Prüfen: docs/deployment.md, Schritt 8."
