<?php

namespace App\ClubUnio\Resources\KeyReportResource\Pages;

use App\ClubUnio\Resources\Concerns\HasSavedViews;
use App\ClubUnio\Resources\KeyReportResource;
use Filament\Resources\Pages\ListRecords;

class ListKeys extends ListRecords
{
    use HasSavedViews;

    protected static string $resource = KeyReportResource::class;
}
