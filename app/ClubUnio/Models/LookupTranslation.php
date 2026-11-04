<?php

namespace App\ClubUnio\Models;

final class LookupTranslation extends CatalogModel
{
    public $timestamps = false;

    protected $casts = ['revision' => 'integer', 'tenant_id' => 'integer'];
}
