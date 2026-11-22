<?php

return [
    'enabled' => filter_var(env('CLUBUNIO_EVENT_CHECKIN_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    'public_origin' => env('CLUBUNIO_EVENT_CHECKIN_PUBLIC_ORIGIN', env('APP_URL')),
    // Explicitly reviewed tenant-ID => HTTPS-origin map, never a request-host override.
    'tenant_origins' => json_decode(env('CLUBUNIO_EVENT_CHECKIN_TENANT_ORIGINS', '{}'), true) ?: [],
];
