<?php

return [
    'enabled' => filter_var(env('CLUBUNIO_WALLET_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    'pass_type_identifier' => env('CLUBUNIO_WALLET_PASS_TYPE_IDENTIFIER'),
    'team_identifier' => env('CLUBUNIO_WALLET_TEAM_IDENTIFIER'),
    'certificate_path' => env('CLUBUNIO_WALLET_CERTIFICATE_PATH'),
    'private_key_path' => env('CLUBUNIO_WALLET_PRIVATE_KEY_PATH'),
    'private_key_password' => env('CLUBUNIO_WALLET_PRIVATE_KEY_PASSWORD'),
    'wwdr_certificate_path' => env('CLUBUNIO_WALLET_WWDR_CERTIFICATE_PATH'),
    'web_service_origin' => env('CLUBUNIO_WALLET_WEB_SERVICE_ORIGIN'),
];
