<?php

return [
    'enabled' => (bool) env('CLUBUNIO_PLATFORM_OIDC_ENABLED', false),
    'legacy_allowed' => (bool) env('CLUBUNIO_PLATFORM_OIDC_LEGACY_ALLOWED', true),
    'issuer' => 'https://auth.clubunio.com/realms/clubunio',
    'origin' => env('CLUBUNIO_PLATFORM_OIDC_ORIGIN'),
    'migrated_subjects' => json_decode((string) env('CLUBUNIO_PLATFORM_OIDC_MIGRATED_SUBJECTS', '{}'), true),
    'invitations_enabled' => (bool) env('CLUBUNIO_PLATFORM_OIDC_INVITATIONS_ENABLED', false),
    'client' => env('CLUBUNIO_PLATFORM_OIDC_CLIENT'),
    'secret' => env('CLUBUNIO_PLATFORM_OIDC_SECRET'),
    'subjects' => json_decode((string) env('CLUBUNIO_PLATFORM_OIDC_SUBJECTS', '{}'), true),
];
