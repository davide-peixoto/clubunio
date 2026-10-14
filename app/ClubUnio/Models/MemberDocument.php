<?php

namespace App\ClubUnio\Models;

class MemberDocument extends CatalogModel
{
    protected $hidden = ['storage_path', 'filename'];

    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    public function documentType()
    {
        return $this->belongsTo(DocumentType::class);
    }
}
