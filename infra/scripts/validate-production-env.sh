#!/bin/sh
set -eu

fail() {
    printf 'validate-production-env: %s\n' "$1" >&2
    exit 1
}

required_variables='APP_ENV APP_KEY APP_URL DB_CONNECTION DB_HOST DB_DATABASE DB_USERNAME DB_PASSWORD REDIS_HOST REDIS_PASSWORD CACHE_STORE QUEUE_CONNECTION SESSION_DRIVER SESSION_COOKIE CORS_ALLOWED_ORIGINS OIDC_ISSUER OIDC_CLIENT_ID OIDC_CLIENT_SECRET OIDC_REDIRECT_URI OIDC_FRONTEND_URL OIDC_POST_LOGOUT_REDIRECT_URI TRUSTED_HOSTS TRUSTED_PROXIES'

for variable_name in ${required_variables}; do
    eval "variable_value=\${${variable_name}:-}"
    [ -n "${variable_value}" ] || fail "${variable_name} is required."
    case "${variable_value}" in
        *CHANGE_ME*|*INJECT_FROM_*|*placeholder*|*example.test*)
            fail "${variable_name} still contains a placeholder."
            ;;
    esac
done

[ "${APP_ENV}" = 'production' ] || fail 'APP_ENV must be production.'
[ "${APP_DEBUG:-false}" = 'false' ] || fail 'APP_DEBUG must be false.'
[ "${APP_URL#https://}" != "${APP_URL}" ] || fail 'APP_URL must use HTTPS.'
[ "${OIDC_ISSUER#https://}" != "${OIDC_ISSUER}" ] || fail 'OIDC_ISSUER must use HTTPS.'
[ "${OIDC_REDIRECT_URI#https://}" != "${OIDC_REDIRECT_URI}" ] || fail 'OIDC_REDIRECT_URI must use HTTPS.'
[ "${OIDC_FRONTEND_URL#https://}" != "${OIDC_FRONTEND_URL}" ] || fail 'OIDC_FRONTEND_URL must use HTTPS.'
[ "${OIDC_POST_LOGOUT_REDIRECT_URI#https://}" != "${OIDC_POST_LOGOUT_REDIRECT_URI}" ] || fail 'OIDC_POST_LOGOUT_REDIRECT_URI must use HTTPS.'
[ "${OIDC_ALLOW_INSECURE_HTTP:-false}" = 'false' ] || fail 'OIDC_ALLOW_INSECURE_HTTP must be false.'
[ "${SESSION_DRIVER}" = 'redis' ] || fail 'SESSION_DRIVER must be redis.'
[ "${SESSION_COOKIE}" = '__Host-nexasalud_session' ] || fail 'SESSION_COOKIE must use the __Host- prefix.'
[ "${SESSION_SECURE_COOKIE:-false}" = 'true' ] || fail 'SESSION_SECURE_COOKIE must be true.'
[ "${SESSION_HTTP_ONLY:-false}" = 'true' ] || fail 'SESSION_HTTP_ONLY must be true.'
[ "${SESSION_SAME_SITE:-}" = 'lax' ] || fail 'SESSION_SAME_SITE must be lax.'
[ "${CACHE_STORE}" = 'redis' ] || fail 'CACHE_STORE must be redis.'
[ "${QUEUE_CONNECTION}" = 'redis' ] || fail 'QUEUE_CONNECTION must be redis.'
[ "${DEMO_PORTAL_SIMULATIONS:-false}" = 'false' ] || fail 'DEMO_PORTAL_SIMULATIONS must be false.'
[ "${CORS_ALLOWED_ORIGINS}" != '*' ] || fail 'CORS_ALLOWED_ORIGINS cannot be wildcard.'
[ -n "${TRUSTED_HOSTS}" ] || fail 'TRUSTED_HOSTS cannot be empty.'
[ -n "${TRUSTED_PROXIES}" ] || fail 'TRUSTED_PROXIES cannot be empty.'

printf 'validate-production-env: secure production environment accepted.\n'
