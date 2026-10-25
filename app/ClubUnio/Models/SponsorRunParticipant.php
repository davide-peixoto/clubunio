<?php

namespace App\ClubUnio\Models;

use App\ClubUnio\Models\Concerns\UsesClubUnioSchema;
use Illuminate\Database\Eloquent\Model;

class SponsorRunParticipant extends Model
{
    use UsesClubUnioSchema;

    protected $connection = 'clubunio';

    protected $dateFormat = 'Y-m-d H:i:sP';

    protected $guarded = ['*'];

    protected $casts = ['required' => 'boolean', 'draft_laps' => 'integer', 'final_laps' => 'integer', 'approved_at' => 'datetime', 'checked_in_at' => 'datetime', 'revision' => 'integer'];
}
