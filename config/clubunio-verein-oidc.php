<?php

return [
    'enabled' => (bool) env('CLUBUNIO_VEREIN_OIDC_ENABLED', false),
    'invites_enabled' => (bool) env('CLUBUNIO_VEREIN_OIDC_INVITES_ENABLED', false),
    'mobile_enabled' => (bool) env('CLUBUNIO_VEREIN_OIDC_MOBILE_ENABLED', false),
    'mobile_mfa_acr' => env('CLUBUNIO_VEREIN_OIDC_MOBILE_MFA_ACR'),
    'legacy_allowed' => (bool) env('CLUBUNIO_VEREIN_OIDC_LEGACY_ALLOWED', true),
    'web_mfa_acr' => env('CLUBUNIO_VEREIN_OIDC_WEB_MFA_ACR'),
    'issuer' => 'https://auth.clubunio.com/realms/clubunio',
    'origin' => env('CLUBUNIO_VEREIN_OIDC_ORIGIN'),
    'client' => env('CLUBUNIO_VEREIN_OIDC_CLIENT'),
    'secret' => env('CLUBUNIO_VEREIN_OIDC_SECRET'),
    'subjects' => json_decode((string) env('CLUBUNIO_VEREIN_OIDC_SUBJECTS', '{}'), true),
];
