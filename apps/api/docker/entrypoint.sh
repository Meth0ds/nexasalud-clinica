#!/bin/sh
set -eu

umask 027

fail() {
    printf 'nexasalud-entrypoint: %s\n' "$1" >&2
    exit 1
}

[ -n "${APP_KEY:-}" ] || fail 'APP_KEY is required.'
[ -n "${DB_PASSWORD:-}" ] || fail 'DB_PASSWORD is required.'
[ -n "${REDIS_PASSWORD:-}" ] || fail 'REDIS_PASSWORD is required.'

case "${APP_KEY}" in
    *CHANGE_ME*|*change-me*|*DEV_ONLY*) fail 'APP_KEY still contains a placeholder.' ;;
esac

mkdir -p storage/app/openapi

if [ "${PREPARE_APPLICATION:-true}" = 'true' ]; then
    php artisan package:discover --ansi --no-interaction

    if [ "${RUN_MIGRATIONS:-false}" = 'true' ]; then
        php artisan migrate --force --no-interaction
    fi

    php artisan config:cache --no-interaction
    php artisan route:cache --no-interaction
fi

exec "$@"
