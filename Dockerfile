# syntax=docker/dockerfile:1
# ==========================================================================
# Container-Image der Website (Grav CMS) – für Kubernetes, Docker und Podman.
#
#   docker build -t kneipe-web .
#   docker run -p 8080:8080 -v kneipe-data:/data -e GRAV_ENVIRONMENT=dev kneipe-web
#
# - Grav 2.2 (Core + Admin2/API) in fester Version, Prüfsumme geprüft
# - Apache + mod_php, lauscht als www-data (UID 33) auf Port 8080
# - Konfiguration über Umgebungsvariablen (GRAV_*, KNEIPE_*, PHP_*)
# - veränderliche Daten nur unter /data (Volume), Programmcode schreibgeschützt
# - funktioniert mit readOnlyRootFilesystem (beschreibbar: /data, /tmp)
# - keine Geheimnisse im Image
#
# Dokumentation: Docker.md
# ==========================================================================

ARG PHP_VERSION=8.4

# --------------------------------------------------------------------------
# Grav-Paket laden und prüfen (Version und SHA-256 in scripts/grav-dist.sh)
# --------------------------------------------------------------------------
FROM php:${PHP_VERSION}-cli AS grav

SHELL ["/bin/bash", "-o", "pipefail", "-c"]

# Paketversionen folgen dem Basis-Image (Sicherheitsupdates beim Neubau)
# hadolint ignore=DL3008
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends ca-certificates curl unzip; \
    rm -rf /var/lib/apt/lists/*

COPY --chmod=755 scripts/grav-dist.sh /usr/local/bin/grav-dist
RUN grav-dist /opt/grav

# --------------------------------------------------------------------------
# Laufzeit
# --------------------------------------------------------------------------
FROM php:${PHP_VERSION}-apache AS runtime

SHELL ["/bin/bash", "-o", "pipefail", "-c"]

ARG VERSION=dev
ARG REVISION=unknown
ARG CREATED=unknown

LABEL org.opencontainers.image.title="Ehrenamtskneipe Erndtebrück – Website" \
      org.opencontainers.image.description="Grav-CMS-Website mit Terminkalender, Anfrageformular und Freigabeworkflow" \
      org.opencontainers.image.source="https://github.com/Vladislavvk1337/web-kirby-vereinskneipe-ern" \
      org.opencontainers.image.url="https://github.com/Vladislavvk1337/web-kirby-vereinskneipe-ern" \
      org.opencontainers.image.documentation="https://github.com/Vladislavvk1337/web-kirby-vereinskneipe-ern/blob/main/Docker.md" \
      org.opencontainers.image.version="${VERSION}" \
      org.opencontainers.image.revision="${REVISION}" \
      org.opencontainers.image.created="${CREATED}" \
      org.opencontainers.image.vendor="Ehrenamtskneipe Erndtebrück" \
      org.opencontainers.image.base.name="docker.io/library/php:${PHP_VERSION}-apache"

# PHP-Erweiterungen für Grav: gd (Bilder inkl. WebP/AVIF), intl
# (Datumsformate), zip, exif. Build-Pakete werden danach wieder entfernt,
# nur die Laufzeitbibliotheken bleiben. ($savedAptMark wird absichtlich
# in einzelne Wörter zerlegt.)
# hadolint ignore=DL3008,SC2086
RUN set -eux; \
    savedAptMark="$(apt-mark showmanual)"; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
      libfreetype6-dev libjpeg62-turbo-dev libpng-dev libwebp-dev libavif-dev \
      libicu-dev libzip-dev; \
    docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp --with-avif; \
    docker-php-ext-install -j"$(nproc)" gd intl zip exif; \
    apt-mark auto '.*' > /dev/null; \
    [ -z "$savedAptMark" ] || apt-mark manual $savedAptMark > /dev/null; \
    extDir="$(php -r 'echo ini_get("extension_dir");')"; \
    ldd "$extDir"/*.so \
      | awk '/=>/ { so = $(NF-1); if (index(so, "/usr/local/") == 1) { next }; gsub("^/(usr/)?", "", so); print so }' \
      | sort -u | xargs -r dpkg-query --search | cut -d: -f1 | sort -u | xargs -r apt-mark manual > /dev/null; \
    apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false; \
    rm -rf /var/lib/apt/lists/*; \
    for ext in gd intl zip exif ctype curl dom mbstring openssl session SimpleXML xml fileinfo Zend\ OPcache; do \
      php -m | grep -qix "$ext" || { echo "PHP-Erweiterung fehlt: $ext" >&2; exit 1; }; \
    done

# Apache: Module, eigener Site-Block, Port 8080, keine Standardseite
# (${APACHE_PORT} setzt Apache selbst ein, deshalb einfache Anführungszeichen)
# hadolint ignore=SC2016
RUN set -eux; \
    a2enmod rewrite headers remoteip expires deflate > /dev/null; \
    a2dissite 000-default > /dev/null; \
    a2disconf other-vhosts-access-log serve-cgi-bin > /dev/null; \
    echo 'Listen ${APACHE_PORT}' > /etc/apache2/ports.conf

COPY docker/apache-grav.conf /etc/apache2/sites-available/grav.conf
COPY docker/php-grav.ini "$PHP_INI_DIR/conf.d/zz-grav.ini"
COPY --chmod=755 scripts/entrypoint.sh /usr/local/bin/entrypoint
COPY --chmod=755 scripts/healthcheck.sh /usr/local/bin/healthcheck
RUN set -eux; \
    a2ensite grav > /dev/null; \
    cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# Programmcode: Grav-Kern und Projektdateien (gehören root, für www-data nur lesbar)
WORKDIR /var/www/grav
COPY --from=grav /opt/grav ./
COPY setup.php ./setup.php
COPY user/config ./user/config
COPY user/env ./user/env
COPY user/plugins/kneipe ./user/plugins/kneipe
COPY user/themes/kneipe ./user/themes/kneipe
# Startinhalt – wird nur beim ersten Start in ein leeres /data/pages kopiert
COPY seed/pages /opt/grav-seed/pages

# Grav einmal initialisieren (schreibt user/config/versions.yaml und prüft,
# dass Kern, Plugins und Konfiguration zusammenpassen), dann die Laufzeit-
# verzeichnisse auf /data (persistent) bzw. /tmp (flüchtig) umlenken.
RUN set -eux; \
    install -d /tmp/build/data/pages /tmp/build/data/accounts /tmp/build/data/data /tmp/build/data/config; \
    GRAV_DATA_DIR=/tmp/build/data GRAV_CACHE_PATH=/tmp/build/cache GRAV_LOG_PATH=/tmp/build/logs \
      GRAV_TMP_PATH=/tmp/build/tmp GRAV_ENVIRONMENT=production php bin/grav cache > /dev/null; \
    test -f user/config/versions.yaml; \
    rm -rf /tmp/build; \
    rm -rf user/pages user/accounts user/data user/media images assets cache logs tmp backup; \
    ln -s /data/pages user/pages; \
    ln -s /data/accounts user/accounts; \
    ln -s /data/data user/data; \
    ln -s /data/media user/media; \
    ln -s /tmp/grav/images images; \
    ln -s /tmp/grav/assets assets; \
    find /var/www/grav /opt/grav-seed -xdev -type d -exec chmod 755 {} +; \
    find /var/www/grav /opt/grav-seed -xdev -type f ! -perm -u+x -exec chmod 644 {} +; \
    chmod 755 bin/*; \
    install -d -m 775 -o www-data -g www-data /data

# Voreinstellungen – alles über Umgebungsvariablen überschreibbar (Docker.md)
ENV GRAV_ENVIRONMENT=production \
    GRAV_DATA_DIR=/data \
    GRAV_CACHE_PATH=/tmp/grav/cache \
    GRAV_LOG_PATH=/tmp/grav/logs \
    GRAV_TMP_PATH=/tmp/grav/tmp \
    GRAV_BACKUP_PATH=/tmp/grav/backup \
    GRAV_CONFIG=true \
    APACHE_PORT=8080 \
    APACHE_RUN_DIR=/tmp/apache2/run \
    APACHE_LOCK_DIR=/tmp/apache2/lock \
    APACHE_PID_FILE=/tmp/apache2/run/apache2.pid \
    KNEIPE_SEED_CONTENT=true \
    KNEIPE_ACCESS_LOG=false \
    KNEIPE_NOINDEX=false \
    KNEIPE_LOG_STDERR=true \
    KNEIPE_RATE_LIMIT=5 \
    KNEIPE_MIN_SECONDS=3 \
    KNEIPE_TRUSTED_PROXIES="10.0.0.0/8 172.16.0.0/12 192.168.0.0/16 127.0.0.1" \
    KNEIPE_OPEN_BASEDIR="/var/www/grav/:/data/:/tmp/:/opt/grav-seed/" \
    KNEIPE_REQUEST_BODY_LIMIT=13631488 \
    PHP_TIMEZONE=Europe/Berlin \
    PHP_MEMORY_LIMIT=256M \
    PHP_UPLOAD_MAX_FILESIZE=10M \
    PHP_POST_MAX_SIZE=12M \
    PHP_MAX_EXECUTION_TIME=60 \
    PHP_OPCACHE_VALIDATE_TIMESTAMPS=1

USER 33:33
EXPOSE 8080
VOLUME ["/data"]
STOPSIGNAL SIGWINCH

HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 CMD ["healthcheck"]

ENTRYPOINT ["entrypoint"]
CMD ["apache2-foreground"]
