<?php

namespace App\ClubUnio\Resources\Relations;

use App\ClubUnio\Resources\RegistrationResource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

class RegistrationsRelationManager extends DossierRelationManager
{
    protected static string $relationship = 'registrations';

    protected static string $kind = 'registrations';

    protected static ?string $title = 'Anmeldungen';

    public function infolist(Schema $schema): Schema
    {
        return RegistrationResource::infolist($schema);
    }

    public function table(Table $table): Table
    {
        return RegistrationResource::table(parent::table($table))->headerActions([])->recordUrl(null);
    }
}
