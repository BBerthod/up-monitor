<?php

return [
    'retention_days' => env('WARMING_RETENTION_DAYS', 180),

    /*
    |--------------------------------------------------------------------------
    | Circuit Breaker Threshold
    |--------------------------------------------------------------------------
    |
    | Number of consecutive FAILED runs after which a WarmSite is automatically
    | disabled (is_active = false) and a single "auto-disabled" notification is
    | sent. Set to 0 to disable the circuit breaker entirely.
    |
    */
    'circuit_breaker_threshold' => env('WARMING_CIRCUIT_BREAKER_THRESHOLD', 5),
];
