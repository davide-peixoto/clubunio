<?php

namespace App\ClubUnio\Resources\MessageTemplateResource\Pages;

use App\ClubUnio\Resources\MessageTemplateResource;
use Filament\Resources\Pages\ListRecords;

class ListMessageTemplates extends ListRecords
{
    protected static string $resource = MessageTemplateResource::class;
}
