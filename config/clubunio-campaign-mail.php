<?php

return [
    'enabled' => env('CLUBUNIO_CAMPAIGN_TEST_QUEUE', false),
    'per_minute' => (int) env('CLUBUNIO_CAMPAIGN_MAIL_PER_MINUTE', 5),
    'attempts' => (int) env('CLUBUNIO_CAMPAIGN_MAIL_ATTEMPTS', 3),
];
