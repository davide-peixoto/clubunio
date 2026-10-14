<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Models\GameType;

class GameTypeResource extends CatalogResource
{
    protected static ?string $model = GameType::class;

    protected static ?string $modelLabel = 'Spieltypen';

    protected static ?string $pluralModelLabel = 'Spieltypen';

    protected static ?string $tenantRelationshipName = 'gameTypes';

    public static function getPages(): array
    {
        return ['index' => GameTypeResource\Pages\ManageRecords::route('/')];
    }
}
