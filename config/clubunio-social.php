<?php

return [
    'enabled' => (bool) env('CLUBUNIO_SOCIAL_ENABLED', false),
    // Kept separate from every existing feed credential. DEV only; no configurable endpoint.
    'test_enabled' => (bool) env('CLUBUNIO_SOCIAL_META_TEST_ENABLED', false),
    'jobs_enabled' => (bool) env('CLUBUNIO_SOCIAL_JOBS_ENABLED', false),
    'dev_origin' => 'https://dev.clubunio.ch',
    'tenant_id' => env('CLUBUNIO_SOCIAL_META_TEST_TENANT'),
    'version' => 'v27.0',
    'instagram' => [
        'id' => env('CLUBUNIO_SOCIAL_META_TEST_IG_ID'),
        'token' => env('CLUBUNIO_SOCIAL_META_TEST_IG_TOKEN'),
    ],
    'facebook' => [
        'id' => env('CLUBUNIO_SOCIAL_META_TEST_FB_ID'),
        'token' => env('CLUBUNIO_SOCIAL_META_TEST_FB_TOKEN'),
    ],
];
