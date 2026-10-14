<?php

namespace App\ClubUnio\Models;

class MemberFunction extends CatalogModel
{
    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    protected $hidden = ['legacy_qualifications'];

    public function clubFunction()
    {
        return $this->belongsTo(ClubFunction::class);
    }

    public function qualifications()
    {
        return $this->belongsToMany(Qualification::class, 'clubora.member_function_qualifications');
    }
}
