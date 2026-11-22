<?php

return [
    'enabled' => filter_var(env('GOOGLE_WALLET_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    'environment' => env('GOOGLE_WALLET_ENVIRONMENT'),
    'issuer_id' => env('GOOGLE_WALLET_ISSUER_ID'),
    'project_id' => env('GOOGLE_WALLET_PROJECT_ID'),
    'service_account_email' => env('GOOGLE_WALLET_SERVICE_ACCOUNT_EMAIL'),
    'credentials' => env('GOOGLE_WALLET_CREDENTIALS'),
    'public_origin' => env('GOOGLE_WALLET_PUBLIC_ORIGIN'),
];
