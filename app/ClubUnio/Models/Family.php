<?php

namespace App\ClubUnio\Models;

class Family extends CatalogModel
{
    public function country()
    {
        return $this->belongsTo(Country::class);
    }

    public function memberships()
    {
        return $this->hasMany(FamilyMember::class);
    }
}
