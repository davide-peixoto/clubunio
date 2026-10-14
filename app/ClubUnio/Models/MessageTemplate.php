<?php

namespace App\ClubUnio\Models;

class MessageTemplate extends CatalogModel
{
    protected $casts = ['active' => 'boolean', 'revision' => 'integer'];

    public function revisions()
    {
        return $this->hasMany(MessageTemplateRevision::class, 'template_id')->orderByDesc('revision');
    }
}
