# syntax=docker/dockerfile:1
# ==========================================================================
# Container-Image der Kirby-Website – für Kubernetes und Docker.
#
#   docker build -t kneipe-web .
#   docker run -p 8080:8080 -e KIRBY_URL=http://localhost:8080 \
#     -e KIRBY_CONTENT_SALT=… -e KIRBY_COOKIE_KEY=… -v kneipe-data:/data kneipe-web
#
# - Apache + mod_php, lauscht als www-data (UID 33) auf Port 8080
# - Konfiguration ausschließlich über Umgebungsvariablen
# - veränderliche Daten nur unter /data (Volume), Programmcode schreibgeschützt
# - funktioniert mit readOnlyRootFilesystem (beschreibbar: /data, /tmp)
#
# Dokumentation: Docker.md
# ==========================================================================

ARG PHP_VERSION=8.4
ARG COMPOSER_IMAGE=composer:2

# --------------------------------------------------------------------------
# Composer (nur als Werkzeug)
# --------------------------------------------------------------------------
FROM ${COMPOSER_IMAGE} AS composer

# --------------------------------------------------------------------------
# Abhängigkeiten: Kirby und Autoloader ohne Entwicklungspakete
# --------------------------------------------------------------------------
FROM php:${PHP_VERSION}-apache AS vendor

COPY --from=composer /usr/bin/composer /usr/local/bin/composer

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends git unzip; \
    rm -rf /var/lib/apt/lists/*

WORKDIR /build
ENV COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_NO_INTERACTION=1

COPY composer.json composer.lock ./
# PHP-Erweiterungen (gd, intl …) gibt es erst in der Laufzeitstufe; dort
# prüft der Build sie ausdrücklich
RUN composer install --no-dev --no-progress --prefer-dist --optimize-autoloader --no-scripts \
      --ignore-platform-req='ext-*'

# --------------------------------------------------------------------------
# Laufzeit
# --------------------------------------------------------------------------
FROM php:${PHP_VERSION}-apache AS runtime

LABEL org.opencontainers.image.title="Ehrenamtskneipe Erndtebrück – Website" \
      org.opencontainers.image.description="Kirby-CMS-Website mit Terminkalender und Anfrageformular" \
      org.opencontainers.image.source="https://github.com/Vladislavvk1337/web-kirby-vereinskneipe-ern"

# PHP-Erweiterungen für Kirby: gd (Vorschaubilder inkl. WebP/AVIF), intl
# (Datumsformate), zip, exif. Build-Pakete werden danach wieder entfernt,
# nur die Laufzeitbibliotheken bleiben.
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
    for ext in gd intl zip exif ctype curl dom filter hash iconv json libxml mbstring openssl SimpleXML fileinfo; do \
      php -m | grep -qix "$ext" || { echo "PHP-Erweiterung fehlt: $ext" >&2; exit 1; }; \
    done

# Apache: Module, eigener Site-Block, Port 8080, keine Standardseite
RUN set -eux; \
    a2enmod rewrite headers remoteip expires > /dev/null; \
    a2dissite 000-default > /dev/null; \
    a2disconf other-vhosts-access-log serve-cgi-bin > /dev/null; \
    echo 'Listen ${APACHE_PORT}' > /etc/apache2/ports.conf

COPY docker/apache-kneipe.conf /etc/apache2/sites-available/kneipe.conf
COPY docker/php-kneipe.ini "$PHP_INI_DIR/conf.d/zz-kneipe.ini"
COPY --chmod=755 docker/docker-entrypoint.sh /usr/local/bin/docker-entrypoint
RUN set -eux; \
    a2ensite kneipe > /dev/null; \
    cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# Programmcode (gehört root, für www-data nur lesbar)
WORKDIR /var/www/html
COPY --from=vendor /build/kirby ./kirby
COPY --from=vendor /build/vendor ./vendor
COPY index.php composer.json ./
COPY site ./site
COPY assets ./assets
COPY bin ./bin
# Startinhalt (inkl. Demo-Inhalte) – wird beim ersten Start nach /data kopiert
COPY content /opt/kneipe/content

RUN set -eux; \
    rm -rf site/accounts site/sessions site/cache site/logs /opt/kneipe/content/anfragen/_drafts; \
    ln -s /data/media media; \
    find /var/www/html /opt/kneipe -type d -exec chmod 755 {} +; \
    find /var/www/html /opt/kneipe -type f -exec chmod 644 {} +; \
    install -d -m 775 -o www-data -g www-data /data

# Voreinstellungen – alles über Umgebungsvariablen überschreibbar (Docker.md)
ENV APACHE_PORT=8080 \
    APACHE_RUN_DIR=/tmp/apache2/run \
    APACHE_LOCK_DIR=/tmp/apache2/lock \
    APACHE_PID_FILE=/tmp/apache2/run/apache2.pid \
    KIRBY_DEBUG=false \
    KIRBY_URL="" \
    KIRBY_NOINDEX=false \
    KIRBY_TIMEZONE=Europe/Berlin \
    KIRBY_ENV_FILE=/dev/null \
    KIRBY_CONTENT_ROOT=/data/content \
    KIRBY_MEDIA_ROOT=/data/media \
    KIRBY_STORAGE_ROOT=/data/storage \
    KIRBY_MAIL_TRANSPORT=smtp \
    KIRBY_SMTP_PORT=587 \
    KIRBY_SMTP_SECURITY=tls \
    KNEIPE_RATE_LIMIT=5 \
    KNEIPE_MIN_SECONDS=3 \
    KNEIPE_SEED_CONTENT=true \
    KNEIPE_ACCESS_LOG=false \
    KNEIPE_TRUSTED_PROXIES="10.0.0.0/8 172.16.0.0/12 192.168.0.0/16 127.0.0.1" \
    KNEIPE_OPEN_BASEDIR="/var/www/html/:/data/:/tmp/" \
    PHP_MEMORY_LIMIT=256M \
    PHP_UPLOAD_MAX_FILESIZE=10M \
    PHP_POST_MAX_SIZE=12M \
    PHP_MAX_EXECUTION_TIME=60 \
    PHP_OPCACHE_VALIDATE_TIMESTAMPS=0

USER www-data
EXPOSE 8080
VOLUME ["/data"]
STOPSIGNAL SIGWINCH

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
  CMD curl -fsS -o /dev/null "http://127.0.0.1:${APACHE_PORT}/healthz" || exit 1

ENTRYPOINT ["docker-entrypoint"]
CMD ["apache2-foreground"]
