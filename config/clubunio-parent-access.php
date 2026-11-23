<?php

return [
    'enabled' => filter_var(env('CLUBUNIO_PARENT_ACCESS_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
];
