<?php

return [
    // Separate source/device/provider activation. No credential discovery or legacy fallback.
    'enabled' => false,
    'delivery_enabled' => false,
    // Operator-reviewed entries: native app ID => platform/origin/fixed_tenant_id/project_id/credentials_file.
    // Platform entry has fixed_tenant_id=null; custom entry must have a verified custom tenant domain.
    'apps' => [],
];
