<?php

return [
    // Enable only after the additive member_cards migration was applied.
    'verification_enabled' => filter_var(env('CLUBUNIO_MEMBER_CARD_VERIFICATION_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    'public_origin' => env('CLUBUNIO_MEMBER_CARD_PUBLIC_ORIGIN', env('APP_URL')),
    'disabled_tenant_slugs' => array_values(array_filter(array_map('trim', explode(',', (string) env('CLUBUNIO_MEMBER_CARD_DISABLED_TENANTS', ''))))),
];
