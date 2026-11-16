<?php

return [
    'enabled' => env('CLUBUNIO_KEYCLOAK_MIGRATION_ENABLED', false),
    'client' => env('CLUBUNIO_KEYCLOAK_MIGRATION_CLIENT'),
    'secret' => env('CLUBUNIO_KEYCLOAK_MIGRATION_SECRET'),
    'legacy_disabled_accounts' => env('CLUBUNIO_KEYCLOAK_LEGACY_DISABLED_ACCOUNTS', ''),
];
