<?php

namespace App\ClubUnio\Models;

class SpotsRecipient extends CatalogModel
{
    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected static function booted(): void
    {
        static::saving(fn () => throw new \LogicException('Read-only report'));
        static::deleting(fn () => throw new \LogicException('Read-only report'));
    }
}
