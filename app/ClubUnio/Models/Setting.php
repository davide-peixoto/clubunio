<?php

namespace App\ClubUnio\Models;

use App\ClubUnio\Models\Concerns\UsesClubUnioSchema;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    use UsesClubUnioSchema;

    protected $connection = 'clubunio';

    protected $table = 'tenant_settings';

    protected $guarded = ['*'];

    protected $hidden = ['encrypted_value'];

    protected $casts = ['is_secret' => 'boolean', 'enabled' => 'boolean'];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
