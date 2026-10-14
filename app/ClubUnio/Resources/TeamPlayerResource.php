<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Models\TeamPlayer;

class TeamPlayerResource extends TeamAssignmentResource
{
    protected static ?string $model = TeamPlayer::class;

    protected static ?string $modelLabel = 'Spielerzuordnungen';

    protected static ?string $pluralModelLabel = 'Spielerzuordnungen';

    protected static ?string $tenantRelationshipName = 'teamPlayers';

    public static function getPages(): array
    {
        return ['index' => TeamPlayerResource\Pages\ManageTeamPlayers::route('/')];
    }
}
