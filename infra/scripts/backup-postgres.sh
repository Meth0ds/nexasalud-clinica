#!/bin/sh
set -eu

umask 077

fail() {
    printf 'backup-postgres: %s\n' "$1" >&2
    exit 1
}

command -v docker >/dev/null 2>&1 || fail 'Docker is required.'
[ -n "${DB_HOST:-}" ] || fail 'DB_HOST is required.'
[ -n "${DB_PORT:-}" ] || fail 'DB_PORT is required.'
[ -n "${DB_DATABASE:-}" ] || fail 'DB_DATABASE is required.'
[ -n "${DB_USERNAME:-}" ] || fail 'DB_USERNAME is required.'
[ -n "${DB_PASSWORD:-}" ] || fail 'DB_PASSWORD is required.'
[ -n "${BACKUP_PASSPHRASE:-}" ] || fail 'BACKUP_PASSPHRASE must be supplied by the secret manager.'

backup_directory=${BACKUP_DIRECTORY:-backups/postgres}
timestamp=$(date -u '+%Y%m%dT%H%M%SZ')
backup_file="${backup_directory}/nexasalud-${timestamp}.dump.enc"
digest_file="${backup_file}.sha256"

mkdir -p "${backup_directory}"
chmod 700 "${backup_directory}"

docker run --rm --network "${BACKUP_DOCKER_NETWORK:-bridge}" \
    -e PGPASSWORD="${DB_PASSWORD}" \
    "${POSTGRES_CLIENT_IMAGE:-postgres:17.5-alpine}" \
    pg_dump --format=custom --no-owner --no-acl \
        --host "${DB_HOST}" --port "${DB_PORT}" \
        --username "${DB_USERNAME}" --dbname "${DB_DATABASE}" \
    | openssl enc -aes-256-cbc -pbkdf2 -iter 600000 -salt \
        -pass env:BACKUP_PASSPHRASE >"${backup_file}"

sha256sum "${backup_file}" >"${digest_file}"
chmod 600 "${backup_file}" "${digest_file}"
printf 'backup-postgres: encrypted backup written to %s\n' "${backup_file}"
