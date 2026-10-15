<?php

namespace App\ClubUnio\Models;

class MemberChangeRequest extends CatalogModel
{
    protected $hidden = ['snapshot_encrypted', 'member_version'];

    protected $casts = ['revision' => 'integer', 'created_at' => 'datetime', 'updated_at' => 'datetime', 'reviewed_at' => 'datetime'];

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    public function requester()
    {
        return $this->belongsTo(Account::class, 'requested_by');
    }

    public function status()
    {
        return $this->belongsTo(MemberChangeStatus::class, 'status_id');
    }
}
