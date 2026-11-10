<?php

use Dotenv\Dotenv;

$file = base_path('.env.clubunio-integrations.local');
if (!is_file($file)) {
    $file = base_path('.env.clubora-integrations.local');
}
$values = is_file($file) ? Dotenv::parse(file_get_contents($file)) : [];
$stage = env('APP_ENV') === 'staging' && rtrim((string) env('APP_URL'), '/') === 'https://stage.clubunio.com';

return [
    'test_mail_enabled' => ($values['TEST_MAIL_ENABLED'] ?? '0') === '1',
    'pitch_planning_test_mail_enabled' => ($values['PITCH_PLANNING_TEST_MAIL_ENABLED'] ?? '0') === '1',
    'smtp' => [
        'transport' => 'smtp', 'scheme' => $values['SMTP_SCHEME'] ?? 'smtp',
        'host' => $values['SMTP_HOST'] ?? '', 'port' => (int) ($values['SMTP_PORT'] ?? 587),
        'username' => $values['SMTP_USERNAME'] ?? '', 'password' => $values['SMTP_PASSWORD'] ?? '',
        'timeout' => 20, 'require_tls' => true,
    ],
    'from' => $values['SMTP_FROM'] ?? '',
    // The source is the immutable SV Sissach snapshot. Never expose this adapter to other tenants.
    'azure' => [
        'enabled' => ($values['AZURE_READ_ENABLED'] ?? '0') === '1',
        'tenant_slug' => 'sv-sissach',
        'account' => $values['AZURE_ACCOUNT'] ?? '', 'key' => $stage ? '' : ($values['AZURE_KEY'] ?? ''),
        'container' => $stage ? 'svs-stage-private' : 'svs-prod-private',
        'read_sas' => $stage ? ($values['AZURE_STAGE_READ_SAS'] ?? '') : '',
    ],
];
