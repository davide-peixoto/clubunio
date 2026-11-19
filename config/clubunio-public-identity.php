<?php

// Explicit operator configuration; no existing account/email or mailer discovery.
$flag = fn (string $key) => filter_var(env($key, false), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true;
$origins = array_values(array_unique(array_filter(array_map('trim', explode(',', (string) env('CLUBUNIO_MOBILE_IDENTITY_ORIGINS', ''))))));
if (count($origins) > 100) $origins = [];
foreach ($origins as $origin) {
    $parts = parse_url($origin);
    if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['path']) || isset($parts['query']) || isset($parts['fragment'])) { $origins = []; break; }
}
$slugs = array_values(array_unique(array_filter(array_map('trim', explode(',', (string) env('CLUBUNIO_MOBILE_IDENTITY_TENANT_SLUGS', ''))))));
if (count($slugs) > 100) $slugs = [];
foreach ($slugs as $slug) if (! preg_match('/^[a-z0-9][a-z0-9-]{0,79}$/D', $slug)) { $slugs = []; break; }
return [
    'enabled' => $flag('CLUBUNIO_MOBILE_IDENTITY_ENABLED'),
    'registration_enabled' => $flag('CLUBUNIO_MOBILE_IDENTITY_REGISTRATION_ENABLED'),
    'password_reset_enabled' => $flag('CLUBUNIO_MOBILE_IDENTITY_PASSWORD_RESET_ENABLED'),
    'membership_enabled' => $flag('CLUBUNIO_MOBILE_IDENTITY_MEMBERSHIP_ENABLED'),
    'membership_account_enabled' => $flag('CLUBUNIO_MOBILE_IDENTITY_MEMBERSHIP_ACCOUNT_ENABLED'),
    'email_enrollment_enabled' => $flag('CLUBUNIO_MOBILE_IDENTITY_EMAIL_ENROLLMENT_ENABLED'),
    'mail_enabled' => $flag('CLUBUNIO_MOBILE_IDENTITY_MAIL_ENABLED'),
    'origins' => $origins, 'tenant_slugs' => $slugs,
    'mailer' => 'clubunio_identity', 'from' => env('CLUBUNIO_MOBILE_IDENTITY_FROM'),
    'dispatch_after_response' => true,
];
