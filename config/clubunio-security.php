<?php

return [
    'enabled' => (bool) env('CLUBUNIO_STRONG_LOGIN', false),
    'origin' => env('CLUBUNIO_SECURITY_ORIGIN'),
    'enroll_since' => env('CLUBUNIO_SECURITY_ENROLL_SINCE'),
];
