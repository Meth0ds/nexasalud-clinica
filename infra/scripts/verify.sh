#!/bin/sh
set -eu

SCRIPT_DIRECTORY=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
INFRA_DIRECTORY=$(CDPATH='' cd -- "${SCRIPT_DIRECTORY}/.." && pwd)
ENVIRONMENT_FILE="${INFRA_DIRECTORY}/.env"

fail() {
    printf 'verify: %s\n' "$1" >&2
    exit 1
}

read_environment_value() {
    value=$(awk -F= -v requested_key="$1" '$1 == requested_key { sub(/^[^=]*=/, ""); print; exit }' "${ENVIRONMENT_FILE}")
    printf '%s' "${value:-$2}"
}

command -v docker >/dev/null 2>&1 || fail 'Docker is required.'
command -v curl >/dev/null 2>&1 || fail 'curl is required.'
[ -f "${ENVIRONMENT_FILE}" ] || fail 'Run ./scripts/init.sh first.'

api_port=$(read_environment_value API_HTTP_PORT 8000)
keycloak_port=$(read_environment_value KEYCLOAK_HTTP_PORT 8080)
mailpit_port=$(read_environment_value MAILPIT_HTTP_PORT 8025)

cd "${INFRA_DIRECTORY}"
docker compose config --quiet
docker compose ps --status running

worker_container=$(docker compose ps -q worker)
[ -n "${worker_container}" ] || fail 'Queue worker container is not running.'
[ "$(docker inspect --format '{{.State.Health.Status}}' "${worker_container}")" = 'healthy' ] \
    || fail 'Queue worker is not healthy.'

[ "$(docker compose port postgres 5432)" = "127.0.0.1:$(read_environment_value POSTGRES_HOST_PORT 5432)" ] \
    || fail 'PostgreSQL is not bound to the configured localhost port.'
[ "$(docker compose port redis 6379)" = "127.0.0.1:$(read_environment_value REDIS_HOST_PORT 6379)" ] \
    || fail 'Redis is not bound to the configured localhost port.'

curl --fail --silent --show-error --max-time 5 "http://127.0.0.1:${api_port}/ready" >/dev/null
oidc_document=$(curl --fail --silent --show-error --max-time 5 \
    "http://keycloak.localhost:${keycloak_port}/realms/nexasalud-dev/.well-known/openid-configuration")
normalized_oidc_document=$(printf '%s' "${oidc_document}" | tr -d '[:space:]')
printf '%s' "${normalized_oidc_document}" \
    | grep -Fq "\"issuer\":\"http://keycloak.localhost:${keycloak_port}/realms/nexasalud-dev\"" \
    || fail 'Keycloak publishes an unexpected OIDC issuer.'
curl --fail --silent --show-error --max-time 5 "http://127.0.0.1:${mailpit_port}/api/v1/info" >/dev/null

docker compose exec -T postgres sh -ec '
    PGPASSWORD="$APP_DB_PASSWORD" \
        psql -X -h 127.0.0.1 -U "$APP_DB_USERNAME" -d "$POSTGRES_DB" \
        --set ON_ERROR_STOP=1 --tuples-only --command "SELECT 1" >/dev/null
'
docker compose exec -T redis sh -ec 'redis-cli --no-auth-warning -a "$REDIS_PASSWORD" ping' | grep -q '^PONG$'
docker compose exec -T api php -r '
    $required = ["pdo_pgsql", "redis", "sodium", "intl", "mbstring", "Zend OPcache"];
    $missing = array_values(array_filter($required, static fn (string $extension): bool => ! extension_loaded($extension)));
    if ($missing !== []) {
        fwrite(STDERR, "Missing PHP extensions: ".implode(", ", $missing).PHP_EOL);
        exit(1);
    }
'

printf 'verify: API, PostgreSQL, Redis, Keycloak, Mailpit and required PHP extensions are healthy.\n'
