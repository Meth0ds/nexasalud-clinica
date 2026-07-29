<?php

declare(strict_types=1);

use Dedoc\Scramble\Http\Middleware\RestrictedDocsAccess;

/**
 * Configure the locally available, generated OpenAPI reference.
 *
 * Production deployments must not expose API discovery metadata. The
 * RestrictedDocsAccess middleware keeps the interactive reference local-only,
 * while the exported artifact is stored in Laravel's writable storage path.
 */
return [
    'api_path' => 'api/v1',

    'api_domain' => null,

    'export_path' => 'storage/app/openapi/nexasalud-v1.json',

    'cache' => [
        'key' => 'nexasalud.openapi.v1',
        'store' => env('SCRAMBLE_CACHE_STORE', 'redis'),
    ],

    'info' => [
        'version' => env('API_VERSION', '1.0.0'),
        'description' => 'NexaSalud clinical scheduling BFF API. This reference is generated from the application contract. Endpoints use a secure first-party session cookie; state-changing requests also require CSRF protection.',
    ],

    'ui' => [
        'title' => 'NexaSalud API Reference',
    ],

    'renderer' => 'elements',

    'renderers' => [
        'elements' => [
            'view' => 'scramble::docs',
            'theme' => 'light',
            'hideTryIt' => true,
            'hideSchemas' => false,
            'logo' => '',
            'tryItCredentialsPolicy' => 'include',
            'layout' => 'responsive',
            'router' => 'hash',
        ],
    ],

    'servers' => null,

    'enum_cases_description_strategy' => 'description',

    'enum_cases_names_strategy' => false,

    'flatten_deep_query_parameters' => true,

    'middleware' => [
        'web',
        RestrictedDocsAccess::class,
    ],

    'extensions' => [],

    'security_strategy' => null,
];
