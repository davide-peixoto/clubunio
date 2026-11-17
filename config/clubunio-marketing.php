<?php

$limits = require __DIR__.'/clubunio-licenses.php';

return [
    'mail_enabled' => env('CLUBUNIO_MARKETING_MAIL_ENABLED', false),
    'mail_from' => env('CLUBUNIO_MARKETING_MAIL_FROM'),
    'contact' => 'info@clubunio.com',
    // Proposed annual-billing prices. No checkout, provisioning or billing.
    'plans' => [
        ['key' => 'start', 'members' => $limits['start'], 'price' => 15],
        ['key' => 'verein', 'members' => $limits['verein'], 'price' => 29],
        ['key' => 'club', 'members' => $limits['club'], 'price' => 49],
        ['key' => 'large', 'members' => $limits['large'], 'price' => 79],
    ],
];
