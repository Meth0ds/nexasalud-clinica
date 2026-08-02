#!/bin/sh
set -eu

umask 077

fail() {
    printf 'restore-postgres: %s\n' "$1" >&2
    exit 1
}

command -v docker >/dev/null 2>&1 || fail 'Docker is required.'
[ "${CONFIRM_RESTORE:-}" = 'YES' ] || fail 'Set CONFIRM_RESTORE=YES explicitly before restoring.'
[ -n "${BACKUP_FILE:-}" ] || fail 'BACKUP_FILE is required.'
[ -f "${BACKUP_FILE}" ] || fail "Backup file not found: ${BACKUP_FILE}"
[ -n "${DB_HOST:-}" ] || fail 'DB_HOST is required.'
[ -n "${DB_PORT:-}" ] || fail 'DB_PORT is required.'
[ -n "${RESTORE_TARGET_DB:-}" ] || fail 'RESTORE_TARGET_DB is required.'
[ -n "${DB_USERNAME:-}" ] || fail 'DB_USERNAME is required.'
[ -n "${DB_PASSWORD:-}" ] || fail 'DB_PASSWORD is required.'
[ -n "${BACKUP_PASSPHRASE:-}" ] || fail 'BACKUP_PASSPHRASE must be supplied by the secret manager.'

case "${RESTORE_TARGET_DB}" in
    *prod*|*production*)
        [ "${ALLOW_PRODUCTION_RESTORE:-}" = 'YES' ] || fail 'Production restore requires ALLOW_PRODUCTION_RESTORE=YES.'
        ;;
esac

if [ -f "${BACKUP_FILE}.sha256" ]; then
    sha256sum --check "${BACKUP_FILE}.sha256" || fail 'Backup digest validation failed.'
fi

openssl enc -d -aes-256-cbc -pbkdf2 -iter 600000 \
    -pass env:BACKUP_PASSPHRASE <"${BACKUP_FILE}" \
    | docker run --rm -i --network "${BACKUP_DOCKER_NETWORK:-bridge}" \
        -e PGPASSWORD="${DB_PASSWORD}" \
        "${POSTGRES_CLIENT_IMAGE:-postgres:17.5-alpine}" \
        pg_restore --exit-on-error --no-owner --no-acl \
            --host "${DB_HOST}" --port "${DB_PORT}" \
            --username "${DB_USERNAME}" --dbname "${RESTORE_TARGET_DB}"

printf 'restore-postgres: restore completed in database %s.\n' "${RESTORE_TARGET_DB}"
