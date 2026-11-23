<?php

return [
    'enabled' => (bool) env('CLUBUNIO_SUPPORT_ENABLED', false),
    'openai_key' => env('CLUBUNIO_SUPPORT_OPENAI_KEY'),
    'openai_project' => env('CLUBUNIO_SUPPORT_OPENAI_PROJECT'),
    'model' => \App\ClubUnio\Services\OpenAiModels::upgrade(env('CLUBUNIO_SUPPORT_OPENAI_MODEL'), \App\ClubUnio\Services\OpenAiModels::TEXT),
    'triage_enabled' => (bool) env('CLUBUNIO_SUPPORT_TRIAGE_ENABLED', false),
    'triage_connection' => env('CLUBUNIO_SUPPORT_TRIAGE_CONNECTION', 'database'),
    'zammad' => [
        'enabled' => (bool) env('CLUBUNIO_SUPPORT_ZAMMAD_ENABLED', false),
        'url' => env('CLUBUNIO_SUPPORT_ZAMMAD_URL'),
        'token' => env('CLUBUNIO_SUPPORT_ZAMMAD_API_TOKEN'),
        'group_id' => env('CLUBUNIO_SUPPORT_ZAMMAD_GROUP_ID'),
        'customer_id' => env('CLUBUNIO_SUPPORT_ZAMMAD_CUSTOMER_ID'),
    ],
];
