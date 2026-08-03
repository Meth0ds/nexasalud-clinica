#!/bin/sh
set -eu

umask 077

fail() {
    printf 'backup-postgres: %s\n' "$1" >&2
    exit 1
}

command -v docker >/dev/null 2>&1 || fail 'Docker is required.'
command -v openssl >/dev/null 2>&1 || fail 'OpenSSL is required on the backup runner.'
command -v sha256sum >/dev/null 2>&1 || fail 'sha256sum is required on the backup runner.'
[ -n "${DB_HOST:-}" ] || fail 'DB_HOST is required.'
[ -n "${DB_PORT:-}" ] || fail 'DB_PORT is required.'
[ -n "${DB_DATABASE:-}" ] || fail 'DB_DATABASE is required.'
[ -n "${DB_USERNAME:-}" ] || fail 'DB_USERNAME is required.'
[ -n "${DB_PASSWORD:-}" ] || fail 'DB_PASSWORD is required.'
[ -n "${BACKUP_PASSPHRASE:-}" ] || fail 'BACKUP_PASSPHRASE must be supplied by the secret manager.'
[ "${#BACKUP_PASSPHRASE}" -ge 32 ] || fail 'BACKUP_PASSPHRASE must contain at least 32 characters.'
[ -n "${BACKUP_HMAC_KEY:-}" ] || fail 'BACKUP_HMAC_KEY must be supplied by the secret manager.'
[ "${#BACKUP_HMAC_KEY}" -ge 32 ] || fail 'BACKUP_HMAC_KEY must contain at least 32 characters.'
[ "${DB_SSLMODE:-prefer}" != 'verify-full' ] || [ -n "${DB_SSLROOTCERT:-}" ] || fail 'DB_SSLROOTCERT is required with DB_SSLMODE=verify-full.'
[ -z "${DB_SSLROOTCERT:-}" ] || [ -f "${DB_SSLROOTCERT}" ] || fail 'DB_SSLROOTCERT file was not found.'

backup_directory=${BACKUP_DIRECTORY:-backups/postgres}
timestamp=$(date -u '+%Y%m%dT%H%M%SZ')
backup_file="${backup_directory}/nexasalud-${timestamp}.dump.enc"
digest_file="${backup_file}.sha256"
hmac_file="${backup_file}.hmac"

mkdir -p "${backup_directory}"
chmod 700 "${backup_directory}"

if [ -n "${DB_SSLROOTCERT:-}" ]; then
    docker run --rm --network "${BACKUP_DOCKER_NETWORK:-bridge}" \
        -e "PGPASSWORD=${DB_PASSWORD}" \
        -e "PGSSLMODE=${DB_SSLMODE:-verify-full}" \
        -e "PGSSLROOTCERT=${DB_SSLROOTCERT}" \
        -v "${DB_SSLROOTCERT}:${DB_SSLROOTCERT}:ro" \
        "${POSTGRES_CLIENT_IMAGE:-postgres:17.5-alpine}" \
        pg_dump --format=custom --no-owner --no-acl \
            --host "${DB_HOST}" --port "${DB_PORT}" \
            --username "${DB_USERNAME}" --dbname "${DB_DATABASE}"
else
    docker run --rm --network "${BACKUP_DOCKER_NETWORK:-bridge}" \
        -e "PGPASSWORD=${DB_PASSWORD}" \
        -e "PGSSLMODE=${DB_SSLMODE:-prefer}" \
        "${POSTGRES_CLIENT_IMAGE:-postgres:17.5-alpine}" \
        pg_dump --format=custom --no-owner --no-acl \
            --host "${DB_HOST}" --port "${DB_PORT}" \
            --username "${DB_USERNAME}" --dbname "${DB_DATABASE}"
fi \
    | openssl enc -aes-256-cbc -pbkdf2 -iter 600000 -salt \
        -pass env:BACKUP_PASSPHRASE >"${backup_file}"

sha256sum "${backup_file}" >"${digest_file}"
printf '%s  %s\n' "$(openssl dgst -sha256 -hmac "${BACKUP_HMAC_KEY}" "${backup_file}" | awk '{print $2}')" "$(basename "${backup_file}")" >"${hmac_file}"
chmod 600 "${backup_file}" "${digest_file}" "${hmac_file}"
printf 'backup-postgres: encrypted backup written to %s\n' "${backup_file}"
