<?php

namespace App\ClubUnio\Models;

class Registration extends CatalogModel
{
    protected $hidden = ['applicant_encrypted'];

    protected $casts = ['birth_date' => 'date', 'submitted_at' => 'datetime', 'reviewed_at' => 'datetime', 'revision' => 'integer'];

    public function technicalLead()
    {
        return $this->belongsTo(Member::class, 'technical_lead_member_id');
    }

    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    public function status()
    {
        return $this->belongsTo(RegistrationStatus::class, 'status_id');
    }

    public function teamSeason()
    {
        return $this->belongsTo(TeamSeason::class);
    }
}
