<?php

namespace App\ClubUnio\Models;

class DocumentSeriesTemplate extends CatalogModel
{
    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $casts = ['definition' => 'array', 'version' => 'integer'];
}
