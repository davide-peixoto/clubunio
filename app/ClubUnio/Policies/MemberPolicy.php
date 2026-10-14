<?php

namespace App\ClubUnio\Policies;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Member;
use Illuminate\Database\Eloquent\Model;

class MemberPolicy extends CatalogPolicy
{
    public function create(Account $actor): bool
    {
        return $this->viewAny($actor);
    }

    public function update(Account $actor, Model $record): bool
    {
        return $record instanceof Member && ! $record->archived_at && $this->view($actor, $record);
    }
}
