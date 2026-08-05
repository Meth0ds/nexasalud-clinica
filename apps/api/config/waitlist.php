<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Waitlist matching engine
    |--------------------------------------------------------------------------
    |
    | The automated engine matches waiting entries to free appointment slots
    | and creates time-bounded offers. It is intentionally conservative: a
    | bounded lookahead, a minimum lead time and a hard cap per run prevent
    | runaway offer creation in large clinics.
    |
    */

    'matching' => [

        'enabled' => filter_var(
            env('WAITLIST_MATCHING_ENABLED', true),
            FILTER_VALIDATE_BOOL,
        ),

        // How long an automatically created offer stays valid before it is expired.
        'offer_valid_minutes' => max(15, (int) env('WAITLIST_MATCHING_OFFER_VALID_MINUTES', 1440)),

        // Hard cap on offers created per scheduler run across all tenants.
        'max_offers_per_run' => max(1, (int) env('WAITLIST_MATCHING_MAX_OFFERS_PER_RUN', 25)),

        // Hard cap on waiting entries scanned per clinic to bound scheduler CPU time.
        'max_entries_per_run' => max(1, (int) env('WAITLIST_MATCHING_MAX_ENTRIES_PER_RUN', 200)),

        // Do not search beyond this horizon measured from the current moment.
        'lookahead_days' => max(1, (int) env('WAITLIST_MATCHING_LOOKAHEAD_DAYS', 30)),

        // Slots closer than this lead time are never offered automatically.
        'min_lead_hours' => max(0, (int) env('WAITLIST_MATCHING_MIN_LEAD_HOURS', 4)),

        // Candidate grid used to generate slots within the clinic's workday.
        'slot_step_minutes' => 15,

        'workday_start' => '08:00',

        'workday_end' => '18:00',
    ],
];
