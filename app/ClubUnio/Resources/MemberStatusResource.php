<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Models\MemberStatus;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class MemberStatusResource extends CatalogResource
{
    protected static ?string $model = MemberStatus::class;

    protected static ?string $modelLabel = 'Mitgliederstatus';

    protected static ?string $pluralModelLabel = 'Mitgliederstatus';

    protected static ?string $tenantRelationshipName = 'memberStatuses';

    public static function form(Schema $schema): Schema
    {
        return parent::form($schema)->components(array_merge(parent::form($schema)->getComponents(), [
            Toggle::make('ends_membership')->label('Austritt')->default(false),
            Toggle::make('restores_membership')->label('Wiedereintritt')->default(false),
            Toggle::make('spots_eligible')->label('Spots-Einzelversand zulässig')->default(true),
        ]));
    }

    public static function getPages(): array
    {
        return ['index' => MemberStatusResource\Pages\ManageMemberStatuses::route('/')];
    }
}
