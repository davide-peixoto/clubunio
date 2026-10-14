<?php

namespace App\ClubUnio\Models;

class MessageTemplateRevision extends CatalogModel
{
    public $timestamps = false;

    protected $casts = ['active' => 'boolean', 'revision' => 'integer', 'created_at' => 'datetime'];

    public function actor()
    {
        return $this->belongsTo(Account::class, 'actor_id');
    }
}
