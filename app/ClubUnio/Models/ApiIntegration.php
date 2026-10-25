<?php

namespace App\ClubUnio\Models;

use App\ClubUnio\Models\Concerns\UsesClubUnioSchema;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class ApiIntegration extends Authenticatable
{
    use HasApiTokens, UsesClubUnioSchema;

    protected $connection = 'clubunio';

    protected $guarded = ['*'];

    protected $dateFormat = 'Y-m-d H:i:sP';

    protected $casts = ['active' => 'boolean', 'scopes' => 'array'];

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function accessProfile()
    {
        return $this->belongsTo(AccessProfile::class);
    }
}
