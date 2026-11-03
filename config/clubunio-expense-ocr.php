<?php

return [
    'enabled' => (bool) env('CLUBUNIO_MOBILE_EXPENSE_OCR_ENABLED', false),
    'tenant_slugs' => array_values(array_filter(array_map('trim', explode(',', (string) env('CLUBUNIO_MOBILE_EXPENSE_OCR_TENANTS', ''))))),
    'endpoint' => env('AZURE_DOCUMENT_INTELLIGENCE_ENDPOINT', ''),
    'key' => env('AZURE_DOCUMENT_INTELLIGENCE_KEY', ''),
    // Require a reviewed worst-case price for exactly one prebuilt-receipt page.
    'cost_reviewed' => (bool) env('CLUBUNIO_EXPENSE_OCR_COST_REVIEWED', false),
    'reservation_microusd' => (int) env('CLUBUNIO_EXPENSE_OCR_RESERVATION_MICROUSD', 0),
    'monthly_limit_microusd' => (int) env('CLUBUNIO_EXPENSE_OCR_MONTHLY_LIMIT_MICROUSD', 5_000_000),
    'daily_requests_per_account' => 10,
    'max_bytes' => 4 * 1024 * 1024,
    'min_confidence' => 0.75,
    'poll_interval_seconds' => 3,
];
