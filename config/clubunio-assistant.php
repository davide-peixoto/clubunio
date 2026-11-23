<?php

return [
    'enabled' => env('CLUBUNIO_ASSISTANT_ENABLED', true),
    // Both gates require a reviewed provider contract, retention and processing location.
    'provider_enabled' => env('CLUBUNIO_ASSISTANT_PROVIDER_ENABLED', false),
    'privacy_reviewed' => env('CLUBUNIO_ASSISTANT_PRIVACY_REVIEWED', false),
    'key' => env('CLUBUNIO_ASSISTANT_OPENAI_KEY'),
    'model' => \App\ClubUnio\Services\OpenAiModels::upgrade(env('CLUBUNIO_ASSISTANT_OPENAI_MODEL'), \App\ClubUnio\Services\OpenAiModels::BACKOFFICE),
    'monthly_limit_microusd' => 30_000_000,
    'request_reservation_microusd' => 1_200_000,
    'max_rounds' => 4,
    'max_payload_bytes' => 60_000,
    'max_output_tokens' => 2000,
    'retention_days' => 30,
    'confirmation_minutes' => 10,
];
