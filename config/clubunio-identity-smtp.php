<?php

// Dedicated identity proof channel. Never inherits the general test transport.
$scheme = (string) env('CLUBUNIO_IDENTITY_SMTP_SCHEME', 'smtps');
$port = filter_var(env('CLUBUNIO_IDENTITY_SMTP_PORT', 465), FILTER_VALIDATE_INT);
$host = env('CLUBUNIO_IDENTITY_SMTP_HOST');
if (! in_array($scheme, ['smtp', 'smtps'], true) || $port === false || $port < 1 || $port > 65535
    || ! is_string($host) || $host === '' || str_contains($host, '://') || preg_match('/[\s\x00-\x1f\x7f\/\\\\]/', $host)) $host = null;
return [
    'transport' => 'smtp', 'scheme' => in_array($scheme, ['smtp', 'smtps'], true) ? $scheme : 'smtps',
    'host' => $host, 'port' => $port === false ? 465 : $port,
    'username' => env('CLUBUNIO_IDENTITY_SMTP_USERNAME'),
    'password' => env('CLUBUNIO_IDENTITY_SMTP_PASSWORD'),
    'require_tls' => true, 'timeout' => 15,
];
