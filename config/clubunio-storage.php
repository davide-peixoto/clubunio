<?php

return [
    'profiles_enabled' => env('CLUBUNIO_STORAGE_PROFILES_ENABLED', false),
    'managed_enabled' => env('CLUBUNIO_MANAGED_STORAGE_ENABLED', false),
    's3_enabled' => env('CLUBUNIO_S3_STORAGE_ENABLED', false),
    'google_enabled' => env('CLUBUNIO_GOOGLE_STORAGE_ENABLED', false),
    'graph_enabled' => env('CLUBUNIO_GRAPH_STORAGE_ENABLED', false),
];
