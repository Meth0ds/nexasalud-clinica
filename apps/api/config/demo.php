<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Demonstration capabilities
    |--------------------------------------------------------------------------
    |
    | These capabilities provide safe presentation flows only. They are
    | disabled unless an environment explicitly enables them, and must never
    | be used to represent clinical or fiscal outcomes.
    |
    */

    'portal_simulations' => env('DEMO_PORTAL_SIMULATIONS', false),
];
