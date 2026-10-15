<?php

namespace App\ClubUnio\Models;

class MemberDocument extends CatalogModel
{
    protected $hidden = ['storage_path', 'filename'];

    protected $casts = ['active' => 'boolean', 'portal_visible' => 'boolean', 'revision' => 'integer'];

    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    public function documentType()
    {
        return $this->belongsTo(DocumentType::class);
    }
}
