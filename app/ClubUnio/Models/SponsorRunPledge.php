<?php

namespace App\ClubUnio\Models;

use App\ClubUnio\Models\Concerns\UsesClubUnioSchema;
use Illuminate\Database\Eloquent\Model;

class SponsorRunPledge extends Model
{
    use UsesClubUnioSchema;

    protected $connection = 'clubunio';

    protected $dateFormat = 'Y-m-d H:i:sP';

    protected $guarded = ['*'];

    protected $hidden = ['recipient_encrypted', 'payload_hash', 'request_key'];

    protected $casts = ['amount_cents' => 'integer', 'cap_cents' => 'integer', 'revision' => 'integer'];
}
