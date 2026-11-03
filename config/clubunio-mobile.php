<?php

return [
    // Explicit activation after additive migration and review. No external delivery by default.
    'enabled' => (bool) env('CLUBUNIO_MOBILE_ENABLED', false),
    // Enable only after migrations84-87 and check-mobile-planning --enabled.
    'native_planning_enabled' => (bool) env('CLUBUNIO_MOBILE_NATIVE_PLANNING_ENABLED', false),
    'native_expenses_enabled' => (bool) env('CLUBUNIO_MOBILE_NATIVE_EXPENSES_ENABLED', false),
    'native_my_teams_enabled' => (bool) env('CLUBUNIO_MOBILE_NATIVE_MY_TEAMS_ENABLED', false),
    'native_facilities_enabled' => (bool) env('CLUBUNIO_MOBILE_NATIVE_FACILITIES_ENABLED', false),
    'native_bus_enabled' => (bool) env('CLUBUNIO_MOBILE_NATIVE_BUS_ENABLED', false),
    'native_documents_enabled' => (bool) env('CLUBUNIO_MOBILE_NATIVE_DOCUMENTS_ENABLED', false),
    'native_sponsor_enabled' => (bool) env('CLUBUNIO_MOBILE_NATIVE_SPONSOR_ENABLED', false),
    'session_days' => 30,
    'login_limit' => 5,
    'ai' => [
        'enabled' => (bool) env('CLUBUNIO_MOBILE_AI_ENABLED', false),
        'translation_provider' => env('CLUBUNIO_MOBILE_TRANSLATION_PROVIDER', 'openai'),
        'writing_provider' => env('CLUBUNIO_MOBILE_WRITING_PROVIDER', 'openai'),
        'monthly_limit_microusd' => 20_000_000,
        'max_input_chars' => 4000,
        'max_output_tokens' => 1000,
        // Set a reviewed worst-case cost per request before enabling an Azure provider.
        'azure_reservation_microusd' => (int) env('CLUBUNIO_MOBILE_AZURE_RESERVATION_MICROUSD', 0),
        'openai' => ['key' => env('OPENAI_API_KEY'), 'model' => 'gpt-5.4-mini'],
        'azure_openai' => ['key' => env('AZURE_OPENAI_API_KEY'), 'endpoint' => env('AZURE_OPENAI_ENDPOINT'), 'deployment' => env('AZURE_OPENAI_DEPLOYMENT')],
        'azure_translator' => ['key' => env('AZURE_TRANSLATOR_KEY'), 'region' => env('AZURE_TRANSLATOR_REGION'), 'endpoint' => 'https://api.cognitive.microsofttranslator.com'],
    ],
    'push_enabled' => false,
    'uploads_enabled' => (bool) env('CLUBUNIO_MOBILE_UPLOADS_ENABLED', false),
    'attachments' => ['clamd_socket' => env('CLUBUNIO_CLAMD_SOCKET')],
];
