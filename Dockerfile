# syntax=docker/dockerfile:1.7

# ─── Stage: dev ─────────────────────────────────────────────────────────────
# Local dev target — FrankenPHP traditional mode (no Octane worker).
# Source code is volume-mounted at runtime; this stage only bakes in PHP
# extensions and the dev Caddyfile. Run: `docker compose build --target dev`.
FROM dunglas/frankenphp:1-php8.4-alpine AS dev
WORKDIR /var/www/html

RUN install-php-extensions \
        pdo_mysql \
        redis \
        intl \
        bcmath \
        opcache \
        pcntl

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Node + npm for `npm run dev` inside the container (composer dev script).
RUN apk add --no-cache nodejs npm

# Caddy needs writable dirs for its PKI module even when auto_https is off.
RUN mkdir -p /data/caddy /config/caddy /config/psysh \
    && chown -R www-data:www-data /data/caddy /config/caddy /config/psysh

COPY docker/Caddyfile.dev /etc/frankenphp/Caddyfile
COPY docker/php.dev.ini /usr/local/etc/php/conf.d/zz-app.ini

# admin off in Caddyfile.dev disables port 2019 — silence the inherited healthcheck.
HEALTHCHECK NONE

USER www-data
EXPOSE 80

# ─── Stage 1: vendor ────────────────────────────────────────────────────────
# Composer install (no dev deps), then dump optimized autoloader. The second
# composer call also fires post-autoload-dump → `php artisan package:discover`,
# which writes bootstrap/cache/{packages,services}.php.
FROM composer:2 AS vendor
WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN --mount=type=cache,target=/tmp/composer-cache,sharing=locked \
    COMPOSER_CACHE_DIR=/tmp/composer-cache composer install \
        --no-dev --no-scripts --no-autoloader \
        --prefer-dist --no-interaction --no-progress \
        --ignore-platform-req=ext-pcntl

COPY . .
# --no-scripts skips the post-autoload-dump hook (package:discover). The
# composer:2 image has no redis ext, and a provider boot under the new
# redis-default cache would crash here. package:discover runs in the
# runtime stage instead, where every PHP extension is installed.
RUN composer dump-autoload --optimize --classmap-authoritative --no-scripts

# ─── Stage 2: assets ────────────────────────────────────────────────────────
# Build Vite bundle (tailwind via @tailwindcss/vite). Vendor dir is needed
# because some Laravel packages publish CSS/JS that Vite picks up.
FROM node:22-alpine AS assets
WORKDIR /var/www/html

COPY package.json package-lock.json vite.config.ts tsconfig.json ./
RUN --mount=type=cache,target=/root/.npm,sharing=locked \
    npm ci --no-audit --no-fund

COPY resources ./resources
COPY public ./public
COPY --from=vendor /var/www/html/vendor ./vendor
RUN npm run build

# ─── Stage 3: runtime ───────────────────────────────────────────────────────
# FrankenPHP serves on :7001 (not :80) — auto_https is disabled in the
# Caddyfile because Cloudflare terminates TLS at the edge.
FROM dunglas/frankenphp:1-php8.4-alpine
WORKDIR /var/www/html

# Concrete thread count, not `auto`: the deploy runs docker-out-of-docker and
# cgroup CPU limits don't reduce nproc, so `auto` would size off the shared
# host's full core count and over-subscribe this 2-cpu-capped container.
ENV FRANKENPHP_NUM_THREADS=4 \
    SERVER_NAME=:7001

RUN install-php-extensions \
        pdo_mysql \
        redis \
        intl \
        bcmath \
        opcache \
        pcntl

COPY --from=vendor /var/www/html /var/www/html
COPY --from=assets /var/www/html/public/build /var/www/html/public/build
# Run the package:discover hook the vendor stage deferred. Writes
# bootstrap/cache/packages.php. CACHE_STORE=array because no redis is
# reachable at build time — runtime config still resolves to redis.
# config:cache is deliberately NOT baked here: build time has no .env
# loaded, so env() falls back to the PHP defaults (e.g. DB_CONNECTION
# defaults to 'sqlite' in config/database.php) and freezes them into the
# cache. Runtime env vars from compose.prod.yaml are then ignored because
# Laravel reads from the cached config, not from env. The post-rollover
# `php artisan optimize` step in ci.yml does the caching at deploy time
# where the runtime env is actually loaded.
RUN CACHE_STORE=array php artisan package:discover --ansi
# FrankenPHP loads /etc/frankenphp/Caddyfile, NOT /etc/caddy/Caddyfile.
# Copying to the wrong path silently falls back to the image's default
# Caddyfile (no Cache-Control headers, no worker directive, etc.).
COPY docker/Caddyfile /etc/frankenphp/Caddyfile
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-app.ini

# /data/caddy and /config/caddy are Caddy's data + config dirs (used by the
# pki module even when auto_https is off). Must be writable by www-data.
RUN mkdir -p /data/caddy /config/caddy /config/psysh \
    && chown -R www-data:www-data \
        /var/www/html/storage /var/www/html/bootstrap/cache \
        /data/caddy /config/caddy /config/psysh

USER www-data
EXPOSE 7001

HEALTHCHECK --interval=30s --timeout=3s --retries=3 \
    CMD wget -qO- http://127.0.0.1:7001/up || exit 1
