<?php

namespace App\ClubUnio\Models;

use App\ClubUnio\Models\Concerns\UsesClubUnioSchema;
use Illuminate\Database\Eloquent\Model;

class SponsorRunEvent extends Model
{
    use UsesClubUnioSchema;

    protected $connection = 'clubunio';

    protected $dateFormat = 'Y-m-d H:i:sP';

    protected $guarded = ['*'];

    protected $casts = ['event_date' => 'immutable_date', 'registration_deadline' => 'immutable_date', 'revision' => 'integer'];
}
