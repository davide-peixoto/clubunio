<?php

namespace App\ClubUnio\Models;

class AccountRole extends CatalogModel
{
    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;
}
