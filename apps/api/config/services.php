<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'keycloak' => [
        'issuer' => env('OIDC_ISSUER'),
        'client_id' => env('OIDC_CLIENT_ID'),
        'client_secret' => env('OIDC_CLIENT_SECRET'),
        'redirect' => env('OIDC_REDIRECT_URI'),
        'frontend_url' => env('OIDC_FRONTEND_URL', 'http://localhost:4200'),
        'post_logout_redirect_uri' => env('OIDC_POST_LOGOUT_REDIRECT_URI', 'http://localhost:4200/login'),
        'upstream_logout' => filter_var(env('OIDC_UPSTREAM_LOGOUT', false), FILTER_VALIDATE_BOOL),
        'scopes' => array_values(array_filter(array_map(
            'trim',
            explode(' ', (string) env('OIDC_SCOPES', 'openid profile email')),
        ))),
        'allowed_algorithms' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('OIDC_ALLOWED_ALGORITHMS', 'RS256')),
        ))),
        'required_acr_values' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('OIDC_REQUIRED_ACR_VALUES', '2')),
        ))),
        'required_amr_values' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('OIDC_REQUIRED_AMR_VALUES', 'otp')),
        ))),
        'transaction_ttl_seconds' => (int) env('OIDC_TRANSACTION_TTL_SECONDS', 300),
        'discovery_cache_seconds' => (int) env('OIDC_DISCOVERY_CACHE_SECONDS', 300),
        'jwks_cache_seconds' => (int) env('OIDC_JWKS_CACHE_SECONDS', 600),
        'connect_timeout_seconds' => (int) env('OIDC_CONNECT_TIMEOUT_SECONDS', 2),
        'timeout_seconds' => (int) env('OIDC_TIMEOUT_SECONDS', 5),
        'clock_skew_seconds' => (int) env('OIDC_CLOCK_SKEW_SECONDS', 60),
        'allow_insecure_http' => filter_var(env('OIDC_ALLOW_INSECURE_HTTP', false), FILTER_VALIDATE_BOOL),
        'admin_base_url' => env('KEYCLOAK_BASE_URL'),
        'realm' => env('KEYCLOAK_REALM', 'nexasalud-dev'),
        'admin_username' => env('KEYCLOAK_ADMIN_USERNAME'),
        'admin_password' => env('KEYCLOAK_ADMIN_PASSWORD'),
    ],

];
