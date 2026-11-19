<?php

return [
    'enabled' => (bool) env('CLUBUNIO_SOCIAL_READ_ENABLED', false),
    'app_id' => env('CLUBUNIO_SOCIAL_READ_APP_ID'),
    'app_secret' => env('CLUBUNIO_SOCIAL_READ_APP_SECRET'),
    'version' => 'v27.0',
    'test_tenant' => env('CLUBUNIO_SOCIAL_READ_TEST_TENANT'),
    'facebook_ids' => array_filter(explode(',', env('CLUBUNIO_SOCIAL_READ_FACEBOOK_IDS', ''))),
    'instagram_ids' => array_filter(explode(',', env('CLUBUNIO_SOCIAL_READ_INSTAGRAM_IDS', ''))),
];
