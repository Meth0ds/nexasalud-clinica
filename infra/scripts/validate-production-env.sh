#!/bin/sh
set -eu

fail() {
    printf 'validate-production-env: %s\n' "$1" >&2
    exit 1
}

required_variables='APP_ENV APP_KEY APP_URL DB_CONNECTION DB_HOST DB_DATABASE DB_USERNAME DB_PASSWORD DB_SSLMODE DB_SSLROOTCERT REDIS_SCHEME REDIS_HOST REDIS_PASSWORD CACHE_STORE QUEUE_CONNECTION SESSION_DRIVER SESSION_COOKIE CORS_ALLOWED_ORIGINS OIDC_ISSUER OIDC_CLIENT_ID OIDC_CLIENT_SECRET OIDC_REDIRECT_URI OIDC_FRONTEND_URL OIDC_POST_LOGOUT_REDIRECT_URI OIDC_UPSTREAM_LOGOUT TRUSTED_HOSTS TRUSTED_PROXIES CLINICAL_FILESYSTEM_DRIVER AWS_DEFAULT_REGION AWS_BUCKET OIDC_REQUIRED_ACR_VALUES OIDC_REQUIRED_AMR_VALUES'

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
[ "${OIDC_UPSTREAM_LOGOUT:-false}" = 'true' ] || fail 'OIDC_UPSTREAM_LOGOUT must be true (local logout must also end the IdP SSO session).'
[ "${OIDC_ALLOWED_ALGORITHMS:-}" = 'RS256' ] || fail 'OIDC_ALLOWED_ALGORITHMS must be the approved signing algorithm.'
[ "${DB_CONNECTION}" = 'pgsql' ] || fail 'DB_CONNECTION must be pgsql.'
[ "${DB_SSLMODE}" = 'verify-full' ] || fail 'DB_SSLMODE must be verify-full.'
[ -f "${DB_SSLROOTCERT}" ] || fail 'DB_SSLROOTCERT must point to an injected CA file.'
[ "${REDIS_SCHEME}" = 'tls' ] || fail 'REDIS_SCHEME must be tls.'
[ "${CLINICAL_FILESYSTEM_DRIVER}" = 's3' ] || fail 'CLINICAL_FILESYSTEM_DRIVER must be s3.'
[ "${AWS_USE_PATH_STYLE_ENDPOINT:-false}" = 'false' ] || fail 'AWS_USE_PATH_STYLE_ENDPOINT must be false.'
[ -n "${AWS_DEFAULT_REGION}" ] || fail 'AWS_DEFAULT_REGION cannot be empty.'
[ -n "${AWS_BUCKET}" ] || fail 'AWS_BUCKET cannot be empty.'
[ -n "${OIDC_REQUIRED_ACR_VALUES}" ] || fail 'OIDC_REQUIRED_ACR_VALUES cannot be empty.'
case ",${OIDC_REQUIRED_AMR_VALUES}," in
    *,webauthn,*|*,fido,*|*,passkey,*|*,hwk,*) : ;;
    *) fail 'OIDC_REQUIRED_AMR_VALUES must require phishing-resistant MFA.' ;;
esac
[ "${SESSION_DRIVER}" = 'redis' ] || fail 'SESSION_DRIVER must be redis.'
[ "${SESSION_COOKIE}" = '__Host-nexasalud_session' ] || fail 'SESSION_COOKIE must use the __Host- prefix.'
[ "${SESSION_SECURE_COOKIE:-false}" = 'true' ] || fail 'SESSION_SECURE_COOKIE must be true.'
[ "${SESSION_HTTP_ONLY:-false}" = 'true' ] || fail 'SESSION_HTTP_ONLY must be true.'
[ "${SESSION_SAME_SITE:-}" = 'lax' ] || fail 'SESSION_SAME_SITE must be lax.'
[ "${CACHE_STORE}" = 'redis' ] || fail 'CACHE_STORE must be redis.'
[ "${QUEUE_CONNECTION}" = 'redis' ] || fail 'QUEUE_CONNECTION must be redis.'
[ "${DEMO_PORTAL_SIMULATIONS:-false}" = 'false' ] || fail 'DEMO_PORTAL_SIMULATIONS must be false.'
case "${CORS_ALLOWED_ORIGINS}" in
    *\**)
        fail 'CORS_ALLOWED_ORIGINS cannot contain wildcard origins.'
        ;;
esac
case "${TRUSTED_PROXIES}" in
    *\**)
        fail 'TRUSTED_PROXIES cannot be wildcard.'
        ;;
esac
[ -n "${TRUSTED_HOSTS}" ] || fail 'TRUSTED_HOSTS cannot be empty.'
[ -n "${TRUSTED_PROXIES}" ] || fail 'TRUSTED_PROXIES cannot be empty.'

printf 'validate-production-env: secure production environment accepted.\n'
