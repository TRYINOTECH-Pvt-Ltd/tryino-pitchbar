# syntax=docker/dockerfile:1.7
#
# Production image for Pitchbar on a VPS (Portainer / docker compose).
# One image, several process roles: web | horizon | scheduler | reverb | ssr
# See docker-compose.yml and infra/docker/README.md.

# ---------------------------------------------------------------------------
# 1. Composer vendor (no scripts — Laravel is not bootable yet)
# ---------------------------------------------------------------------------
FROM composer:2 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./

RUN composer install \
    --no-dev \
    --no-scripts \
    --no-autoloader \
    --no-interaction \
    --prefer-dist \
    --ignore-platform-reqs

# ---------------------------------------------------------------------------
# 2. Front-end + widget + SSR bundles (Wayfinder needs a bootable Laravel)
# ---------------------------------------------------------------------------
FROM dunglas/frankenphp:1-php8.4 AS assets

WORKDIR /app

RUN install-php-extensions \
        bcmath \
        exif \
        gd \
        intl \
        opcache \
        pcntl \
        pdo_mysql \
        pdo_pgsql \
        posix \
        redis \
        zip \
        sockets

COPY --from=node:22-bookworm-slim /usr/local/bin/node /usr/local/bin/node
COPY --from=node:22-bookworm-slim /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -sf /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -sf /usr/local/lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx

COPY --from=vendor /app/vendor ./vendor
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY . .
COPY infra/docker/php.ini $PHP_INI_DIR/conf.d/zz-pitchbar.ini

# Dummy env so Wayfinder / Vite can boot artisan without Redis/Postgres.
# Vendor was installed with --no-autoloader — dump-autoload must run
# before any artisan command.
ENV APP_ENV=production \
    APP_DEBUG=false \
    SESSION_DRIVER=array \
    CACHE_STORE=array \
    QUEUE_CONNECTION=sync \
    BROADCAST_CONNECTION=log \
    DB_CONNECTION=sqlite \
    DB_DATABASE=/tmp/build.sqlite \
    LOG_CHANNEL=stderr \
    NODE_OPTIONS=--max-old-space-size=2048

RUN cp .env.example .env \
    && touch /tmp/build.sqlite \
    && composer dump-autoload --optimize --classmap-authoritative --no-dev \
    && php artisan key:generate --force \
    && php artisan package:discover --ansi \
    && npm ci \
    && npm run build \
    && npx vite build --ssr \
    && npm run build:widget \
    && rm -rf node_modules .env /usr/bin/composer

# ---------------------------------------------------------------------------
# 3. Runtime
# ---------------------------------------------------------------------------
FROM dunglas/frankenphp:1-php8.4

WORKDIR /app

RUN install-php-extensions \
        bcmath \
        exif \
        gd \
        intl \
        opcache \
        pcntl \
        pdo_mysql \
        pdo_pgsql \
        posix \
        redis \
        zip \
        sockets \
    && apt-get update \
    && apt-get install -y --no-install-recommends curl \
    && rm -rf /var/lib/apt/lists/*

COPY --from=node:22-bookworm-slim /usr/local/bin/node /usr/local/bin/node

COPY infra/docker/php.ini $PHP_INI_DIR/conf.d/zz-pitchbar.ini
COPY infra/docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN sed -i 's/\r$//' /usr/local/bin/entrypoint.sh \
    && chmod +x /usr/local/bin/entrypoint.sh

COPY --from=assets /app /app

RUN mkdir -p \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        storage/app/public \
        storage/app/private \
        bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    OCTANE_SERVER=frankenphp \
    OCTANE_HTTPS=false \
    SERVER_NAME=:80

EXPOSE 80 8080 13714

# Per-role healthchecks live in docker-compose.yml (only `web` serves /up).

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["web"]
