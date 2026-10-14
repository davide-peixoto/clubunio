<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Models\MemberType;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Table;

class MemberTypeResource extends CatalogResource
{
    protected static ?string $model = MemberType::class;

    protected static ?string $modelLabel = 'Mitgliederart';

    protected static ?string $pluralModelLabel = 'Mitgliederart';

    protected static ?string $tenantRelationshipName = 'memberTypes';

    public static function form(Schema $schema): Schema
    {
        $schema = parent::form($schema);

        return $schema->components([...$schema->getComponents(),
            Toggle::make('team_required')->label('Mannschaftszuordnung vorgesehen')->default(false),
            Toggle::make('passive_membership')->label('Passivmitgliedschaft')->default(false),
        ]);
    }

    public static function table(Table $table): Table
    {
        $table = parent::table($table);

        return $table->columns([...array_values($table->getColumns()),
            IconColumn::make('team_required')->label('Mannschaft vorgesehen')->boolean(),
            IconColumn::make('passive_membership')->label('Passivmitgliedschaft')->boolean(),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => MemberTypeResource\Pages\ManageMemberTypes::route('/')];
    }
}
