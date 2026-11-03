<?php

namespace App\ClubUnio\Models;

class AccessProfile extends CatalogModel
{
    protected $casts = ['active' => 'boolean', 'permissions' => 'array', 'mobile_modules' => 'array', 'revision' => 'integer'];

    public function tenantAccounts()
    {
        return $this->hasMany(TenantAccount::class);
    }
}
