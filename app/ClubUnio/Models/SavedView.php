<?php

namespace App\ClubUnio\Models;

class SavedView extends CatalogModel
{
    protected $hidden = ['state_encrypted'];

    protected $casts = ['state_encrypted' => 'encrypted:array', 'is_default' => 'boolean', 'revision' => 'integer'];
}
