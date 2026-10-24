# syntax=docker/dockerfile:1.7
#
# Production image for medico-backend (Laravel 13 + Filament 5).
# Single container: nginx + php-fpm + queue worker + scheduler (via supervisor).
# Built for Railway (reads $PORT at runtime).

##############################
# Stage 1 — front-end assets #
##############################
FROM node:20-bookworm-slim AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
# Compiles the Filament/admin assets into public/build
RUN npm run build

##############################
# Stage 2 — PHP dependencies #
##############################
FROM composer:2 AS vendor
WORKDIR /app
COPY . .
# Runtime image carries the real extensions, so skip the platform check here.
RUN composer install \
        --no-dev \
        --no-interaction \
        --no-progress \
        --prefer-dist \
        --optimize-autoloader \
        --no-scripts \
        --ignore-platform-reqs

#####################
# Stage 3 — runtime #
#####################
# PHP 8.4: composer.lock resolves packages (e.g. symfony/http-foundation v8)
# that require php >= 8.4 and call 8.4-only functions such as request_parse_body().
# The runtime MUST match the locked deps, or PUT/PATCH/DELETE requests (form or
# JSON body) fatal in Request::createFromGlobals() before Laravel even boots.
FROM php:8.4-fpm-bookworm AS runtime

# System packages + PHP extensions required by the app:
#   pdo_pgsql/pgsql -> PostgreSQL   bcmath -> money (billing)
#   gd/exif         -> dompdf + QR  intl  -> locale/formatting
#   zip             -> composer     pcntl -> queue:work signals
#   opcache         -> performance
#   imagick + webp  -> dompdf draws transparent PNGs natively (without
#                      Imagick it loops over every pixel in PHP and the
#                      letterhead preview timed out) and reads WebP uploads.
RUN apt-get update && apt-get install -y --no-install-recommends \
        nginx \
        supervisor \
        libpq-dev \
        libicu-dev \
        libzip-dev \
        libonig-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libwebp-dev \
        libmagickwand-dev \
        unzip \
        curl \
        ca-certificates \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql \
        pgsql \
        bcmath \
        intl \
        zip \
        gd \
        exif \
        pcntl \
        opcache \
    && pecl install imagick \
    && docker-php-ext-enable imagick \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# pg_dump for the nightly off-site backup (elosar:backup), from PostgreSQL's own
# repository rather than Debian's.
#
# Bookworm ships postgresql-client 15, and pg_dump REFUSES to dump a newer server
# — "aborting because of server version mismatch". The database is PostgreSQL 18,
# so Debian's package produces no backup at all. The Sail image used in
# development already carries the 18 client, which is why this only ever failed
# in production.
#
# Pinned to 18 to match the server. If the database is ever upgraded past it, the
# backup command fails loudly (never silently) and this number has to move too.
RUN install -d /usr/share/postgresql-common/pgdg \
    && curl -fsSL https://www.postgresql.org/media/keys/ACCC4CF8.asc \
        -o /usr/share/postgresql-common/pgdg/apt.postgresql.org.asc \
    && echo "deb [signed-by=/usr/share/postgresql-common/pgdg/apt.postgresql.org.asc] https://apt.postgresql.org/pub/repos/apt bookworm-pgdg main" \
        > /etc/apt/sources.list.d/pgdg.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends postgresql-client-18 \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Production PHP config
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-app.ini

# OpenSSL 3 legacy provider — required to read real SRI signing certificates.
#
# The .p12 bundles issued by the Ecuadorian CAs (BCE, Security Data, ANFAC) are
# encrypted with RC2-40, which OpenSSL 3 moved into the legacy provider and does
# NOT load by default. Without this, openssl_pkcs12_read() fails with
# "error:0308010C:digital envelope routines::unsupported" and NO real certificate
# can be uploaded or used to sign a voucher — the whole SRI pipeline is dead in
# production while working fine against self-signed test certificates.
RUN printf '%s\n' \
        '' \
        'openssl_conf = openssl_init' \
        '' \
        '[openssl_init]' \
        'providers = provider_sect' \
        '' \
        '[provider_sect]' \
        'default = default_sect' \
        'legacy = legacy_sect' \
        '' \
        '[default_sect]' \
        'activate = 1' \
        '' \
        '[legacy_sect]' \
        'activate = 1' \
    >> "$(openssl version -d | sed 's/OPENSSLDIR: //;s/"//g')/openssl.cnf"

WORKDIR /var/www/html

# Application source
COPY . .
# Optimized autoloader + vendor from the composer stage
COPY --from=vendor /app/vendor ./vendor
# Compiled front-end assets from the node stage
COPY --from=assets /app/public/build ./public/build

# Drop any stale cached manifests that slipped in (regenerated at runtime).
RUN rm -f bootstrap/cache/*.php

# Writable dirs for Laravel
RUN mkdir -p storage/framework/{cache,sessions,views} storage/logs storage/app/public storage/app/private \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# nginx vhost template (entrypoint substitutes the Railway $PORT) + supervisor + entrypoint
COPY docker/nginx.conf /etc/nginx/sites-available/default.template
COPY docker/supervisord.conf /etc/supervisor/conf.d/app.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

ENV PORT=8080
EXPOSE 8080

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
