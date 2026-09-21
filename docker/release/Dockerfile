# Production container image (1.2 M12, docs/CONTAINER.md, docs/RELEASE-PROTOCOL.md).
#
# Built from a published release ZIP - never from the working tree, so the image ships
# exactly the same file set the ZIP and Composer channels do ("one archive, many
# wrappers", docs/RELEASE-PROTOCOL.md principle 2). A single php:8.3-apache base,
# running non-root on port 8080.
#
# Build from the repository root (the working tree only supplies docker/release/* and
# .dockerignore; RELEASE_ZIP is the actual application payload, downloaded from the
# published release, not the working tree - see .github/workflows/container-image.yml):
#   mkdir -p build/release && cp /path/to/phpledger-1.2.1.zip build/release/
#   docker build -f docker/release/Dockerfile \
#     --build-arg RELEASE_ZIP=build/release/phpledger-1.2.1.zip \
#     --build-arg RELEASE_SHA256=<the archive's published sha256> \
#     -t phpledger:1.2.1 .
#
# The image never runs `composer install`: the release ZIP already carries a
# production-only, --no-dev vendor tree (tools/build-package.py), so this Dockerfile
# only extracts it.

ARG PHP_VERSION=8.3.33

# ---- extract: unpack and verify the release archive, discarded from the final image ----
FROM php:${PHP_VERSION}-apache-bookworm AS extract
ARG RELEASE_ZIP=release.zip
ARG RELEASE_SHA256=""
RUN apt-get update -qq && apt-get install -y --no-install-recommends unzip \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /tmp/release
COPY ${RELEASE_ZIP} /tmp/release.zip
RUN set -eu; \
    if [ -n "$RELEASE_SHA256" ]; then \
        echo "$RELEASE_SHA256  /tmp/release.zip" | sha256sum -c -; \
    else \
        echo 'RELEASE_SHA256 was not supplied; building without checksum verification.' >&2; \
    fi; \
    mkdir /tmp/release-extract; \
    unzip -q /tmp/release.zip -d /tmp/release-extract; \
    entries=$(find /tmp/release-extract -mindepth 1 -maxdepth 1); \
    count=$(printf '%s\n' "$entries" | sed '/^$/d' | wc -l); \
    if [ "$count" -ne 1 ] || [ ! -d "$entries" ]; then \
        echo "Unexpected release archive layout: expected exactly one top-level folder." >&2; \
        exit 1; \
    fi; \
    mv "$entries" /tmp/release/app

# ---- release: the shipped image ----
FROM php:${PHP_VERSION}-apache-bookworm AS release
RUN apt-get update -qq && apt-get install -y --no-install-recommends libzip-dev \
    && docker-php-ext-install pdo_mysql bcmath zip \
    && rm -rf /var/lib/apt/lists/* \
    && a2enmod rewrite headers \
    && printf 'ServerName localhost\n' > /etc/apache2/conf-available/servername.conf \
    && a2enconf servername \
    && sed -i 's/^Listen 80$/Listen 8080/' /etc/apache2/ports.conf \
    && mkdir -p /var/run/apache2 /var/lock/apache2 /var/log/apache2 /var/lib/phpledger \
    && chown -R www-data:www-data /var/run/apache2 /var/lock/apache2 /var/log/apache2 /var/lib/phpledger

WORKDIR /var/www/phpledger
COPY --from=extract /tmp/release/app ./
# Read-only application code: world-readable regardless of what the archive stored,
# never writable by the non-root process that serves it.
RUN chmod -R a+rX /var/www/phpledger

COPY docker/release/apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker/release/php.ini /usr/local/etc/php/conf.d/phpledger.ini
COPY docker/release/entrypoint.sh /usr/local/bin/phpledger-entrypoint
COPY docker/release/healthcheck.php /usr/local/bin/phpledger-healthcheck.php
RUN chmod 755 /usr/local/bin/phpledger-entrypoint

ENV PL_UPDATE_MODE=container \
    PL_DB_PORT=3306 \
    PL_INSTALL_DIRECTORY=/var/lib/phpledger/installation \
    PL_OAUTH_KEY_DIRECTORY=/var/lib/phpledger/oauth \
    PL_INSTALL_CONFIG_PATH=/var/lib/phpledger/config.local.php

VOLUME ["/var/lib/phpledger"]
EXPOSE 8080

# Apache's prefork MPM only tries to drop root privileges when it starts as root; a
# master process that is already www-data runs the whole server as that one
# unprivileged user, which is what makes an unprivileged, port-8080 Apache work here.
USER www-data

HEALTHCHECK --interval=10s --timeout=5s --start-period=30s --retries=6 \
    CMD ["php", "/usr/local/bin/phpledger-healthcheck.php"]

ENTRYPOINT ["/usr/local/bin/phpledger-entrypoint"]
CMD ["apache2-foreground"]
