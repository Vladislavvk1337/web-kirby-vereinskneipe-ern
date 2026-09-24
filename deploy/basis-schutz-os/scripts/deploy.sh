#!/usr/bin/env bash
# -----------------------------------------------------------------------------
# Astro-Build auf den Server übertragen (LOKAL ausführen, nicht auf dem Server).
#
#   ./deploy.sh <ssh-ziel> <domain> [astro-projektordner]
#   ./deploy.sh keiladm@203.0.113.10 example.de ~/projekte/meine-seite
#
# Optional: API_DIR=./api ./deploy.sh …  überträgt zusätzlich PHP-Endpunkte
# aus ./api/public nach /var/www/<domain>/api/public.
# -----------------------------------------------------------------------------
set -euo pipefail

TARGET="${1:?SSH-Ziel fehlt, z. B. keiladm@server}"
DOMAIN="${2:?Domain fehlt, z. B. example.de}"
PROJECT="${3:-.}"

cd "$PROJECT"
echo "==> Build"
npm run build

echo "==> Upload dist/ -> $TARGET:/var/www/$DOMAIN/site/"
# Rechte: Admin schreibt, www-data (nginx) liest, alle anderen nichts
rsync -rlptz --delete --chmod=D2750,F640 dist/ "$TARGET:/var/www/$DOMAIN/site/"

if [[ -n "${API_DIR:-}" ]]; then
  echo "==> Upload $API_DIR/public/ -> $TARGET:/var/www/$DOMAIN/api/public/"
  rsync -rlptz --chmod=D2750,F640 "$API_DIR/public/" "$TARGET:/var/www/$DOMAIN/api/public/"
fi

echo "==> Fertig: https://$DOMAIN"
