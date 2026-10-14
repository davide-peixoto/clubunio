<?php

namespace App\ClubUnio\Models;

class SignageNotice extends CatalogModel
{
    protected $casts = ['active' => 'boolean', 'shows_on' => 'date'];
}
