<?php

return [
    // Explicit activation after additive migration and review. No external delivery by default.
    'enabled' => (bool) env('CLUBUNIO_MOBILE_ENABLED', false),
    'session_days' => 30,
    'login_limit' => 5,
    'ai' => [
        'enabled' => false,
        'translation_provider' => 'openai',
        'writing_provider' => 'openai',
        'monthly_limit_microusd' => 20_000_000,
        'max_input_chars' => 4000,
        'max_output_tokens' => 1000,
        // Set a reviewed worst-case cost per request before enabling an Azure provider.
        'azure_reservation_microusd' => null,
        'openai' => ['key' => env('OPENAI_API_KEY'), 'model' => 'gpt-5.4-mini'],
        'azure_openai' => ['key' => env('AZURE_OPENAI_API_KEY'), 'endpoint' => env('AZURE_OPENAI_ENDPOINT'), 'deployment' => env('AZURE_OPENAI_DEPLOYMENT')],
        'azure_translator' => ['key' => env('AZURE_TRANSLATOR_KEY'), 'region' => env('AZURE_TRANSLATOR_REGION'), 'endpoint' => 'https://api.cognitive.microsofttranslator.com'],
    ],
    'push_enabled' => false,
    'uploads_enabled' => false,
];
