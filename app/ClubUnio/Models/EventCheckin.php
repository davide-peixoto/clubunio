<?php

namespace App\ClubUnio\Models;

class EventCheckin extends CatalogModel
{
    protected $casts = ['checked_in_at' => 'immutable_datetime'];

    public function member() { return $this->belongsTo(Member::class); }
    public function actor() { return $this->belongsTo(Account::class, 'checked_in_by'); }
    public function clubEvent() { return $this->belongsTo(ClubEvent::class, 'event_id'); }
}
