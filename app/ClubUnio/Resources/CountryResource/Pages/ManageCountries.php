<?php

namespace App\ClubUnio\Resources\CountryResource\Pages;

use App\ClubUnio\Resources\CountryResource;
use Filament\Resources\Pages\ManageRecords;

class ManageCountries extends ManageRecords
{
    protected static string $resource = CountryResource::class;
}
