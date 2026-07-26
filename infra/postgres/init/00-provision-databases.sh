#!/bin/sh
set -eu

umask 077

fail() {
    printf 'postgres-init: %s\n' "$1" >&2
    exit 1
}

for variable_name in \
    POSTGRES_USER \
    POSTGRES_PASSWORD \
    POSTGRES_DB \
    APP_DB_USERNAME \
    APP_DB_PASSWORD \
    KEYCLOAK_DB_NAME \
    KEYCLOAK_DB_USERNAME \
    KEYCLOAK_DB_PASSWORD
do
    [ -n "$(printenv "${variable_name}" 2>/dev/null || true)" ] || fail "${variable_name} is required."
done

for identifier in "${POSTGRES_DB}" "${APP_DB_USERNAME}" "${KEYCLOAK_DB_NAME}" "${KEYCLOAK_DB_USERNAME}"
do
    case "${identifier}" in
        *[!a-zA-Z0-9_]*) fail "Database identifiers may contain only letters, numbers and underscores." ;;
    esac
done

export PGPASSWORD="${POSTGRES_PASSWORD}"

psql -X --username "${POSTGRES_USER}" --dbname postgres --set ON_ERROR_STOP=1 \
    --set app_database="${POSTGRES_DB}" \
    --set app_username="${APP_DB_USERNAME}" \
    --set app_password="${APP_DB_PASSWORD}" \
    --set keycloak_database="${KEYCLOAK_DB_NAME}" \
    --set keycloak_username="${KEYCLOAK_DB_USERNAME}" \
    --set keycloak_password="${KEYCLOAK_DB_PASSWORD}" <<'SQL'
SELECT format(
    'CREATE ROLE %I LOGIN PASSWORD %L NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOREPLICATION NOBYPASSRLS',
    :'app_username',
    :'app_password'
)
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = :'app_username') \gexec

SELECT format('ALTER ROLE %I PASSWORD %L', :'app_username', :'app_password') \gexec
SELECT format('ALTER ROLE %I SET statement_timeout = %L', :'app_username', '30s') \gexec
SELECT format('ALTER ROLE %I SET lock_timeout = %L', :'app_username', '5s') \gexec
SELECT format('ALTER DATABASE %I OWNER TO %I', :'app_database', :'app_username') \gexec
SELECT format('REVOKE CONNECT ON DATABASE %I FROM PUBLIC', :'app_database') \gexec
SELECT format('GRANT CONNECT, TEMPORARY ON DATABASE %I TO %I', :'app_database', :'app_username') \gexec
SELECT format('ALTER DATABASE %I SET timezone TO %L', :'app_database', 'UTC') \gexec

SELECT format(
    'CREATE ROLE %I LOGIN PASSWORD %L NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOREPLICATION NOBYPASSRLS',
    :'keycloak_username',
    :'keycloak_password'
)
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = :'keycloak_username') \gexec

SELECT format('ALTER ROLE %I PASSWORD %L', :'keycloak_username', :'keycloak_password') \gexec
SELECT format(
    'CREATE DATABASE %I OWNER %I ENCODING %L TEMPLATE template0',
    :'keycloak_database',
    :'keycloak_username',
    'UTF8'
)
WHERE NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = :'keycloak_database') \gexec
SELECT format('ALTER DATABASE %I OWNER TO %I', :'keycloak_database', :'keycloak_username') \gexec
SELECT format('REVOKE CONNECT ON DATABASE %I FROM PUBLIC', :'keycloak_database') \gexec
SELECT format('GRANT CONNECT, TEMPORARY ON DATABASE %I TO %I', :'keycloak_database', :'keycloak_username') \gexec
SELECT format('ALTER DATABASE %I SET timezone TO %L', :'keycloak_database', 'UTC') \gexec
SQL

psql -X --username "${POSTGRES_USER}" --dbname "${POSTGRES_DB}" --set ON_ERROR_STOP=1 \
    --set app_username="${APP_DB_USERNAME}" <<'SQL'
SELECT format('ALTER SCHEMA public OWNER TO %I', :'app_username') \gexec
SELECT format('GRANT ALL ON SCHEMA public TO %I', :'app_username') \gexec
REVOKE CREATE ON SCHEMA public FROM PUBLIC;
SQL

printf 'postgres-init: application and identity databases are provisioned.\n'
