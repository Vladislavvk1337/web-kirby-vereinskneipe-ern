#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Lädt das offizielle Grav-Paket (Core + Admin2/API) in der festgelegten
# Version, prüft die SHA-256-Prüfsumme und baut daraus einen Grav-Baum ohne
# Beispielinhalte. Wird vom Dockerfile und von scripts/dev-server.sh genutzt.
#
#   scripts/grav-dist.sh <zielverzeichnis>
#
# Version und Prüfsumme stehen nur hier (eine Quelle). Beim Update beide
# Werte anpassen: sha256sum grav-admin-v<version>.zip
# ---------------------------------------------------------------------------
set -euo pipefail

GRAV_VERSION="${GRAV_VERSION:-2.2.0}"
GRAV_SHA256="${GRAV_SHA256:-d635b060cddb1d232896020bcf2a780254c93ab11e8f0445f33c06b23a171fee}"
GRAV_URL="${GRAV_URL:-https://github.com/getgrav/grav/releases/download/${GRAV_VERSION}/grav-admin-v${GRAV_VERSION}.zip}"

# Mitgelieferte Plugins, die das Projekt braucht (alle MIT, siehe Docker.md)
KEEP_PLUGINS="admin2 api email error flex-objects form login shortcode-core"

target="${1:?Zielverzeichnis fehlt}"
cache="${GRAV_DIST_CACHE:-${TMPDIR:-/tmp}}"
zip="$cache/grav-admin-v${GRAV_VERSION}.zip"

mkdir -p "$cache"

if [[ ! -f "$zip" ]] || ! echo "$GRAV_SHA256  $zip" | sha256sum -c --status; then
  echo "Lade Grav ${GRAV_VERSION} …" >&2
  curl -fsSL --retry 3 -o "$zip.part" "$GRAV_URL"
  mv "$zip.part" "$zip"
fi

echo "$GRAV_SHA256  $zip" | sha256sum -c --quiet

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
unzip -q "$zip" -d "$work"

src="$work/grav-admin"
[[ -f "$src/index.php" && -d "$src/system" ]] || { echo "Unerwarteter Inhalt im Grav-Paket." >&2; exit 1; }

# Beispielinhalte, Demo-Theme, nicht benötigte Plugins und Webserver-
# Beispiele entfernen. robots.txt liefert das Plugin dynamisch aus.
rm -rf "$src/user/pages" "$src/user/accounts" "$src/user/data" "$src/user/themes"/* \
       "$src/user/config" "$src/webserver-configs" "$src/robots.txt" "$src/now.json" \
       "$src/images" "$src/assets" "$src/backup" "$src/cache" "$src/logs" "$src/tmp"

for plugin in "$src/user/plugins"/*; do
  name="$(basename "$plugin")"
  case " $KEEP_PLUGINS " in
    *" $name "*) ;;
    *) rm -rf "$plugin" ;;
  esac
done

for name in $KEEP_PLUGINS; do
  [[ -d "$src/user/plugins/$name" ]] || { echo "Plugin $name fehlt im Grav-Paket." >&2; exit 1; }
done

# .htaccess-Dateien werden nicht gebraucht: Apache liest sie nicht
# (AllowOverride None), die Regeln stehen in docker/apache-grav.conf.
find "$src" -name .htaccess -delete

mkdir -p "$target"
cp -a "$src/." "$target/"
echo "Grav ${GRAV_VERSION} nach $target entpackt." >&2
