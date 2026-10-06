# Ticket Worker, self-contained: the code, its PHP packages and its built assets are in
# the image. compose.yml in the repository root runs it; INSTALL.md explains how.
#
# Three runnable stages: "app" is php-fpm, and runs the queue and the schedule too; "web"
# is nginx with the public files; "agent" adds Claude Code, for the container that runs
# agent turns and nothing else.
ARG PHP_VERSION=8.4

FROM composer:2 AS composer_bin

# The browser side, built once here: no Node at runtime.
FROM node:22-alpine AS assets
WORKDIR /src
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY resources ./resources
COPY public ./public
COPY vite.config.js ./
RUN npm run build

FROM php:${PHP_VERSION}-fpm AS app
# The user the app runs as. Match it to the owner of ./data on the host (`id -u`, `id -g`)
# so that what you push into data/systems is readable, and what the app writes is yours.
ARG UID=1000
ARG GID=1000

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends git unzip libzip-dev libonig-dev; \
    docker-php-ext-install -j"$(nproc)" pdo_mysql zip bcmath opcache pcntl; \
    rm -rf /var/lib/apt/lists/*; \
    groupmod -o -g "${GID}" www-data; \
    usermod -o -u "${UID}" -g "${GID}" www-data

RUN { \
      echo 'opcache.enable=1'; \
      echo 'opcache.validate_timestamps=0'; \
      echo 'memory_limit=512M'; \
      echo 'upload_max_filesize=32M'; \
      echo 'post_max_size=32M'; \
      echo 'expose_php=Off'; \
    } > /usr/local/etc/php/conf.d/app.ini

COPY --from=composer_bin /usr/bin/composer /usr/bin/composer
WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist

COPY . .
COPY --from=assets /src/public/build ./public/build
RUN set -eux; \
    rm -rf docker/data .env; \
    composer dump-autoload --optimize --no-dev; \
    php artisan package:discover --ansi; \
    chown -R www-data:www-data storage bootstrap/cache

COPY docker/entrypoint.sh /usr/local/bin/ticket-worker
RUN chmod 0755 /usr/local/bin/ticket-worker

USER www-data
ENTRYPOINT ["ticket-worker"]
CMD ["php-fpm"]

# Claude Code, pinned: it never updates itself. A new version arrives by changing
# CLAUDE_CODE_VERSION and rebuilding (`docker compose build agent`).
FROM app AS agent
ARG CLAUDE_CODE_VERSION=2.1.282
USER root
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends ripgrep curl ca-certificates; \
    rm -rf /var/lib/apt/lists/*; \
    curl -fsSL https://claude.ai/install.sh | bash -s "${CLAUDE_CODE_VERSION}"; \
    install -m 0755 "$(readlink -f /root/.local/bin/claude)" /usr/local/bin/claude; \
    /usr/local/bin/claude --version; \
    rm -rf /root/.local /root/.claude /root/.claude.json /root/.cache
USER www-data

FROM nginx:alpine AS web
COPY docker/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=app /app/public /app/public
