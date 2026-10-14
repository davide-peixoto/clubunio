<?php

namespace App\ClubUnio\Models;

class AccountMemberLink extends CatalogModel
{
    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function member()
    {
        return $this->belongsTo(Member::class);
    }
}
