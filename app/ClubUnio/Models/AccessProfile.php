<?php

namespace App\ClubUnio\Models;

class AccessProfile extends CatalogModel
{
    protected $casts = ['active' => 'boolean', 'permissions' => 'array', 'revision' => 'integer'];
}
