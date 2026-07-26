#!/bin/sh
set -eu

umask 077

SCRIPT_DIRECTORY=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
INFRA_DIRECTORY=$(CDPATH='' cd -- "${SCRIPT_DIRECTORY}/.." && pwd)
ENVIRONMENT_FILE="${INFRA_DIRECTORY}/.env"
REALM_TEMPLATE="${INFRA_DIRECTORY}/keycloak/nexasalud-dev-realm.template.json"
REALM_DIRECTORY="${INFRA_DIRECTORY}/data/keycloak"
REALM_FILE="${REALM_DIRECTORY}/nexasalud-dev-realm.json"
START_STACK=false

fail() {
    printf 'init: %s\n' "$1" >&2
    exit 1
}

require_command() {
    command -v "$1" >/dev/null 2>&1 || fail "Required command not found: $1"
}

read_environment_value() {
    awk -F= -v requested_key="$1" '$1 == requested_key { sub(/^[^=]*=/, ""); print; exit }' "${ENVIRONMENT_FILE}"
}

random_hex() {
    openssl rand -hex "$1"
}

create_environment() {
    temporary_file=$(mktemp "${ENVIRONMENT_FILE}.tmp.XXXXXX")
    app_key="base64:$(openssl rand -base64 32 | tr -d '\n')"
    postgres_superuser_password=$(random_hex 32)
    app_database_password=$(random_hex 32)
    redis_password=$(random_hex 32)
    keycloak_database_password=$(random_hex 32)
    keycloak_admin_password=$(random_hex 32)
    keycloak_client_secret=$(random_hex 32)
    keycloak_demo_clinician_password="NexaDemo!8$(random_hex 16)"
    keycloak_demo_receptionist_password="NexaDemo!8$(random_hex 16)"
    keycloak_demo_auditor_password="NexaDemo!8$(random_hex 16)"
    keycloak_demo_patient_password="NexaDemo!8$(random_hex 16)"

    {
        printf '%s\n' 'COMPOSE_PROJECT_NAME=nexasalud-local'
        printf '%s\n' 'API_HTTP_PORT=8000'
        printf '%s\n' 'KEYCLOAK_HTTP_PORT=8080'
        printf '%s\n' 'MAILPIT_HTTP_PORT=8025'
        printf '%s\n' 'MAILPIT_SMTP_PORT=1025'
        printf '%s\n' 'POSTGRES_HOST_PORT=5432'
        printf '%s\n' 'REDIS_HOST_PORT=6379'
        printf 'APP_KEY=%s\n' "${app_key}"
        printf '%s\n' 'APP_DEBUG=false'
        printf '%s\n' 'RUN_MIGRATIONS=true'
        printf '%s\n' 'POSTGRES_SUPERUSER=postgres'
        printf 'POSTGRES_SUPERUSER_PASSWORD=%s\n' "${postgres_superuser_password}"
        printf '%s\n' 'POSTGRES_DB=nexasalud'
        printf '%s\n' 'APP_DB_USERNAME=nexasalud_app'
        printf 'APP_DB_PASSWORD=%s\n' "${app_database_password}"
        printf 'REDIS_PASSWORD=%s\n' "${redis_password}"
        printf '%s\n' 'KEYCLOAK_DB_NAME=keycloak'
        printf '%s\n' 'KEYCLOAK_DB_USERNAME=keycloak_app'
        printf 'KEYCLOAK_DB_PASSWORD=%s\n' "${keycloak_database_password}"
        printf '%s\n' 'KEYCLOAK_BOOTSTRAP_ADMIN_USERNAME=local_admin'
        printf 'KEYCLOAK_BOOTSTRAP_ADMIN_PASSWORD=%s\n' "${keycloak_admin_password}"
        printf '%s\n' 'KEYCLOAK_CLIENT_ID=nexasalud-bff'
        printf 'KEYCLOAK_CLIENT_SECRET=%s\n' "${keycloak_client_secret}"
        printf 'KEYCLOAK_DEMO_CLINICIAN_PASSWORD=%s\n' "${keycloak_demo_clinician_password}"
        printf 'KEYCLOAK_DEMO_RECEPTIONIST_PASSWORD=%s\n' "${keycloak_demo_receptionist_password}"
        printf 'KEYCLOAK_DEMO_AUDITOR_PASSWORD=%s\n' "${keycloak_demo_auditor_password}"
        printf 'KEYCLOAK_DEMO_PATIENT_PASSWORD=%s\n' "${keycloak_demo_patient_password}"
    } >"${temporary_file}"

    chmod 600 "${temporary_file}"
    mv "${temporary_file}" "${ENVIRONMENT_FILE}"
    printf 'init: generated ignored local secrets in infra/.env.\n'
}

ensure_demo_initial_passwords() {
    keycloak_demo_clinician_password=$(read_environment_value KEYCLOAK_DEMO_CLINICIAN_PASSWORD)
    keycloak_demo_receptionist_password=$(read_environment_value KEYCLOAK_DEMO_RECEPTIONIST_PASSWORD)
    keycloak_demo_auditor_password=$(read_environment_value KEYCLOAK_DEMO_AUDITOR_PASSWORD)
    keycloak_demo_patient_password=$(read_environment_value KEYCLOAK_DEMO_PATIENT_PASSWORD)
    legacy_demo_password=$(read_environment_value KEYCLOAK_DEMO_INITIAL_PASSWORD)

    if [ -n "${keycloak_demo_clinician_password}" ] \
        && [ -n "${keycloak_demo_receptionist_password}" ] \
        && [ -n "${keycloak_demo_auditor_password}" ] \
        && [ -n "${keycloak_demo_patient_password}" ] \
        && [ -z "${legacy_demo_password}" ]; then
        return
    fi

    [ -n "${keycloak_demo_clinician_password}" ] || keycloak_demo_clinician_password="NexaDemo!8$(random_hex 16)"
    [ -n "${keycloak_demo_receptionist_password}" ] || keycloak_demo_receptionist_password="NexaDemo!8$(random_hex 16)"
    [ -n "${keycloak_demo_auditor_password}" ] || keycloak_demo_auditor_password="NexaDemo!8$(random_hex 16)"
    [ -n "${keycloak_demo_patient_password}" ] || keycloak_demo_patient_password="NexaDemo!8$(random_hex 16)"
    temporary_file=$(mktemp "${ENVIRONMENT_FILE}.tmp.XXXXXX")
    awk '$1 !~ /^KEYCLOAK_DEMO_(INITIAL|CLINICIAN|RECEPTIONIST|AUDITOR|PATIENT)_PASSWORD=/' "${ENVIRONMENT_FILE}" >"${temporary_file}"
    printf 'KEYCLOAK_DEMO_CLINICIAN_PASSWORD=%s\n' "${keycloak_demo_clinician_password}" >>"${temporary_file}"
    printf 'KEYCLOAK_DEMO_RECEPTIONIST_PASSWORD=%s\n' "${keycloak_demo_receptionist_password}" >>"${temporary_file}"
    printf 'KEYCLOAK_DEMO_AUDITOR_PASSWORD=%s\n' "${keycloak_demo_auditor_password}" >>"${temporary_file}"
    printf 'KEYCLOAK_DEMO_PATIENT_PASSWORD=%s\n' "${keycloak_demo_patient_password}" >>"${temporary_file}"
    chmod 600 "${temporary_file}"
    mv "${temporary_file}" "${ENVIRONMENT_FILE}"
    printf 'init: provisioned distinct random demo passwords in infra/.env.\n'
}

render_realm() {
    keycloak_client_secret=$(read_environment_value KEYCLOAK_CLIENT_SECRET)
    keycloak_demo_clinician_password=$(read_environment_value KEYCLOAK_DEMO_CLINICIAN_PASSWORD)
    keycloak_demo_receptionist_password=$(read_environment_value KEYCLOAK_DEMO_RECEPTIONIST_PASSWORD)
    keycloak_demo_auditor_password=$(read_environment_value KEYCLOAK_DEMO_AUDITOR_PASSWORD)
    keycloak_demo_patient_password=$(read_environment_value KEYCLOAK_DEMO_PATIENT_PASSWORD)
    [ -n "${keycloak_client_secret}" ] || fail 'KEYCLOAK_CLIENT_SECRET is missing from infra/.env.'
    [ -n "${keycloak_demo_clinician_password}" ] || fail 'KEYCLOAK_DEMO_CLINICIAN_PASSWORD is missing from infra/.env.'
    [ -n "${keycloak_demo_receptionist_password}" ] || fail 'KEYCLOAK_DEMO_RECEPTIONIST_PASSWORD is missing from infra/.env.'
    [ -n "${keycloak_demo_auditor_password}" ] || fail 'KEYCLOAK_DEMO_AUDITOR_PASSWORD is missing from infra/.env.'
    [ -n "${keycloak_demo_patient_password}" ] || fail 'KEYCLOAK_DEMO_PATIENT_PASSWORD is missing from infra/.env.'

    case "${keycloak_client_secret}" in
        *[!a-fA-F0-9]*) fail 'KEYCLOAK_CLIENT_SECRET must be hexadecimal for safe realm rendering.' ;;
    esac

    for demo_password in \
        "${keycloak_demo_clinician_password}" \
        "${keycloak_demo_receptionist_password}" \
        "${keycloak_demo_auditor_password}" \
        "${keycloak_demo_patient_password}"
    do
        case "${demo_password}" in
            *[!a-zA-Z0-9!_-]*) fail 'A KEYCLOAK_DEMO_*_PASSWORD value contains unsupported rendering characters.' ;;
        esac
    done

    mkdir -p "${REALM_DIRECTORY}"
    temporary_realm=$(mktemp "${REALM_DIRECTORY}/realm.tmp.XXXXXX")
    sed \
        -e "s/__KEYCLOAK_CLIENT_SECRET__/${keycloak_client_secret}/g" \
        -e "s/__KEYCLOAK_DEMO_CLINICIAN_PASSWORD__/${keycloak_demo_clinician_password}/g" \
        -e "s/__KEYCLOAK_DEMO_RECEPTIONIST_PASSWORD__/${keycloak_demo_receptionist_password}/g" \
        -e "s/__KEYCLOAK_DEMO_AUDITOR_PASSWORD__/${keycloak_demo_auditor_password}/g" \
        -e "s/__KEYCLOAK_DEMO_PATIENT_PASSWORD__/${keycloak_demo_patient_password}/g" \
        "${REALM_TEMPLATE}" >"${temporary_realm}"
    chmod 600 "${temporary_realm}"
    mv "${temporary_realm}" "${REALM_FILE}"
    printf 'init: rendered the ignored Keycloak realm with a local client secret.\n'
}

case "${1:-}" in
    '') ;;
    --start) START_STACK=true ;;
    *) fail 'Usage: ./scripts/init.sh [--start]' ;;
esac

require_command docker
require_command openssl
require_command awk
require_command sed

docker compose version >/dev/null 2>&1 || fail 'Docker Compose v2 is required.'

if [ ! -f "${ENVIRONMENT_FILE}" ]; then
    create_environment
else
    chmod 600 "${ENVIRONMENT_FILE}"
    printf 'init: preserving the existing infra/.env.\n'
fi

ensure_demo_initial_passwords
render_realm

cd "${INFRA_DIRECTORY}"
docker compose config --quiet
printf 'init: Compose configuration is valid.\n'

if [ "${START_STACK}" = 'true' ]; then
    docker compose up --detach --build --wait --wait-timeout 300
    "${SCRIPT_DIRECTORY}/verify.sh"
fi
