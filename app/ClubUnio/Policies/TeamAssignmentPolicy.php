<?php

namespace App\ClubUnio\Policies;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\TeamPlayer;
use App\ClubUnio\Models\TeamSeason;
use App\ClubUnio\Models\TeamStaff;
use App\ClubUnio\Models\TechnicalLead;
use Illuminate\Database\Eloquent\Model;

class TeamAssignmentPolicy extends CatalogPolicy
{
    public function update(Account $actor, Model $record): bool
    {
        return in_array($record::class, [TeamSeason::class, TeamPlayer::class, TeamStaff::class, TechnicalLead::class], true) && $this->view($actor, $record);
    }
}
