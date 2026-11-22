<?php

namespace App\ClubUnio\Models;

class ClubEvent extends CatalogModel
{
    protected $casts = ['active' => 'boolean', 'checkin_enabled' => 'boolean',
        'checkin_opens_at' => 'immutable_datetime', 'checkin_closes_at' => 'immutable_datetime'];

    public function checkins()
    {
        // Event IDs are global primary keys; the composite DB foreign key also
        // guarantees each attendance row belongs to the event's tenant.
        return $this->hasMany(EventCheckin::class, 'event_id');
    }

    public function season()
    {
        return $this->belongsTo(Season::class);
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function guests()
    {
        return $this->hasMany(EventGuest::class);
    }
}
