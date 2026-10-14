<?php

namespace App\ClubUnio\Models;

use App\ClubUnio\Models\Concerns\UsesClubUnioSchema;
use Illuminate\Database\Eloquent\Model;

abstract class CatalogModel extends Model
{
    use UsesClubUnioSchema;

    protected $connection = 'clubunio';

    protected $guarded = ['*'];

    protected $casts = ['active' => 'boolean'];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
