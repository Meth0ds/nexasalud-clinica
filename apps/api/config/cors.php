<?php

$allowedOrigins = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'http://localhost:4200')),
)));

return [
    'paths' => ['api/*', 'auth/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => $allowedOrigins,

    'allowed_origins_patterns' => [],

    'allowed_headers' => [
        'Accept',
        'Accept-Language',
        'Content-Language',
        'Content-Type',
        'Idempotency-Key',
        'Origin',
        'X-CSRF-TOKEN',
        'X-Request-ID',
        'X-Requested-With',
        'X-XSRF-TOKEN',
    ],

    'exposed_headers' => ['X-Trace-Id'],

    'max_age' => 600,

    'supports_credentials' => true,
];
