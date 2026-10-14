<?php

namespace App\ClubUnio\Resources\RegistrationResource\Pages;

use App\ClubUnio\Resources\Concerns\HasSavedViews;
use App\ClubUnio\Resources\Concerns\HasStatusTabs;
use App\ClubUnio\Resources\RegistrationResource;
use Filament\Resources\Pages\ListRecords;

class ListRegistrations extends ListRecords
{
    use HasSavedViews;
    use HasStatusTabs;

    protected static string $resource = RegistrationResource::class;
}
