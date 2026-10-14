<?php

namespace App\ClubUnio\Policies;

use App\ClubUnio\Models\Account;
use Illuminate\Database\Eloquent\Model;

class ReadOnlyPolicy extends CatalogPolicy
{
    public function create(Account $actor): bool
    {
        return false;
    }

    public function update(Account $actor, Model $record): bool
    {
        return false;
    }
}
