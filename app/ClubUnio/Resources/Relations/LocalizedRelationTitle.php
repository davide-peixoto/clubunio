<?php

namespace App\ClubUnio\Resources\Relations;

trait LocalizedRelationTitle
{
    public static function getTitle(\Illuminate\Database\Eloquent\Model $ownerRecord, string $pageClass): string
    {
        return __(parent::getTitle($ownerRecord,$pageClass));
    }
}
