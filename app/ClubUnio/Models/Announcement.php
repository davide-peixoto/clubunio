<?php

namespace App\ClubUnio\Models;

class Announcement extends CatalogModel
{
    protected $casts = ['target_ids' => 'array', 'revision' => 'integer', 'recipient_count' => 'integer',
        'published_at' => 'datetime', 'withdrawn_at' => 'datetime', 'preview_expires_at' => 'datetime'];

    protected $hidden = ['preview_hash', 'preview_token'];

    protected $dateFormat = 'Y-m-d H:i:s.uP';
}
