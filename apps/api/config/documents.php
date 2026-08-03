<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Clinical document generation
    |--------------------------------------------------------------------------
    |
    | PDF rendering is deliberately asynchronous in production. Local and
    | testing environments can opt into the synchronous path so fixtures and
    | the synthetic demo remain deterministic.
    |
    */

    'async_generation' => filter_var(
        env('CLINICAL_PDF_ASYNC', env('APP_ENV') === 'production'),
        FILTER_VALIDATE_BOOL,
    ),

    'queue' => (string) env('CLINICAL_PDF_QUEUE', 'clinical-pdf'),

    // The worker must reserve a little more time than this value.
    'timeout_seconds' => max(15, (int) env('CLINICAL_PDF_TIMEOUT_SECONDS', 60)),

    'retry_after_seconds' => max(75, (int) env('CLINICAL_PDF_RETRY_AFTER_SECONDS', 90)),

    'max_attempts' => max(1, (int) env('CLINICAL_PDF_MAX_ATTEMPTS', 3)),

    'max_pending_per_actor' => max(1, (int) env('CLINICAL_PDF_MAX_PENDING_PER_ACTOR', 3)),

    // Prevent a tenant's PDF workload from starving clinical requests.
    'max_concurrent_per_clinic' => max(1, (int) env('CLINICAL_PDF_MAX_CONCURRENT_PER_CLINIC', 1)),

    'poll_after_seconds' => max(1, (int) env('CLINICAL_PDF_POLL_AFTER_SECONDS', 2)),

    'stale_after_minutes' => max(5, (int) env('CLINICAL_PDF_STALE_AFTER_MINUTES', 10)),
];
