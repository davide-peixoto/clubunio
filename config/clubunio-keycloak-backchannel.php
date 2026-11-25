<?php

return [
    // Enable only after configuring and testing each environment's Keycloak client.
    'enabled' => (bool) env('CLUBUNIO_KEYCLOAK_BACKCHANNEL_ENABLED', false),
    // Keep true when pausing the receiver; never forget already delivered revocations.
    'state_enforced' => (bool) env('CLUBUNIO_KEYCLOAK_LOGOUT_STATE_ENFORCED', false),
    'origin' => env('CLUBUNIO_KEYCLOAK_BACKCHANNEL_ORIGIN'),
    'cache_store' => env('CLUBUNIO_KEYCLOAK_BACKCHANNEL_CACHE_STORE'),
    // Exact additional client IDs (e.g. approved custom-domain clients), no wildcard.
    'clients' => array_values(array_filter(array_map('trim', explode(',', (string) env('CLUBUNIO_KEYCLOAK_BACKCHANNEL_CLIENTS', ''))))),
];
