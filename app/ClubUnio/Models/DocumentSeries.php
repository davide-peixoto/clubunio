<?php

namespace App\ClubUnio\Models;

class DocumentSeries extends CatalogModel
{
    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $table = 'document_series';

    protected $casts = ['snapshot' => 'encrypted:array'];

    public function recipients()
    {
        return $this->hasMany(DocumentSeriesRecipient::class, 'series_id');
    }
}
