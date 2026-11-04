<?php

namespace App\ClubUnio\Resources;

trait LocalizedLabels
{
    public static function getModelLabel(): string { return __(parent::getModelLabel()); }
    public static function getPluralModelLabel(): string { return __(parent::getPluralModelLabel()); }
}
