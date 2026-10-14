<?php

namespace App\ClubUnio\Models;

class ObjectChange extends CatalogModel
{
    protected $casts = ['before_value' => 'array', 'after_value' => 'array', 'created_at' => 'datetime'];

    public function actor()
    {
        return $this->belongsTo(Account::class, 'actor_id');
    }

    public function getBeforeTextAttribute(): string
    {
        return json_encode($this->before_value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function getAfterTextAttribute(): string
    {
        return json_encode($this->after_value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
