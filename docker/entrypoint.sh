#!/bin/sh
# Every container starts here. The app container is the one that prepares: it waits for
# the database, migrates it and caches the config, then reports healthy, which is what the
# queue containers wait for (compose.yml).
set -e

mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs \
    "${AGENT_WORKSPACES_PATH:-/data/agent/tickets}" "${SYSTEMS_PATH:-/data/systems}" 2>/dev/null || true

if [ "$1" = "php-fpm" ]; then
    rm -f storage/framework/migrated
    if [ -z "$APP_KEY" ]; then
        echo "APP_KEY is empty. Run: docker compose run --rm app php artisan key:generate --show" >&2
        echo "and put the line it prints into .env as APP_KEY=…" >&2
        exit 1
    fi
    tries=0
    until php artisan db:show >/dev/null 2>&1; do
        tries=$((tries + 1))
        [ "$tries" -ge 30 ] && { echo "The database does not answer." >&2; exit 1; }
        sleep 2
    done
    php artisan migrate --force --no-interaction
    php artisan optimize
    touch storage/framework/migrated
fi

exec "$@"
