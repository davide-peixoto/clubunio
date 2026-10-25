<?php

namespace App\ClubUnio\Models;

use Laravel\Sanctum\PersonalAccessToken;

class ApiAccessToken extends PersonalAccessToken
{
    protected $connection = 'clubunio';

    protected $table = 'clubora.api_access_tokens';

    protected $hidden = ['token'];

    protected $dateFormat = 'Y-m-d H:i:sP';

    protected function casts(): array
    {
        return ['abilities' => 'json', 'last_used_at' => 'datetime', 'expires_at' => 'datetime', 'revoked_at' => 'datetime'];
    }
}
