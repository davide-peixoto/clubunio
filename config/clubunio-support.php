<?php

return [
    'enabled' => (bool) env('CLUBUNIO_SUPPORT_ENABLED', false),
    'openai_key' => env('CLUBUNIO_SUPPORT_OPENAI_KEY'),
    'openai_project' => env('CLUBUNIO_SUPPORT_OPENAI_PROJECT'),
    'model' => env('CLUBUNIO_SUPPORT_OPENAI_MODEL'),
];
