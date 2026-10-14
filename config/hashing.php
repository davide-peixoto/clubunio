<?php

return [
    'driver' => 'bcrypt',
    'bcrypt' => ['rounds' => 12, 'verify' => false],
    'rehash_on_login' => false,
];
