<?php

namespace App\ClubUnio\Models;

class TenantAccount extends CatalogModel
{
    public $timestamps = false;

    protected $casts = ['portal_enabled' => 'boolean'];

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function roleDefinition()
    {
        return $this->belongsTo(AccountRole::class, 'role', 'code');
    }
}
