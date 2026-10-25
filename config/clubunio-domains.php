<?php

return [
    // Operator-managed only, after domain ownership, DNS and TLS have been verified.
    // Exact lower-case hosts (including punycode where required), never URL/wildcards.
    'hosts' => [],
    'central_hosts' => array_values(array_filter(explode(',', (string) env('CLUBUNIO_CENTRAL_HOSTS', '')))),
];
