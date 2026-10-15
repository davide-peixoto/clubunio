<?php

namespace App\ClubUnio\Policies;

use App\ClubUnio\Models\Account;
use Illuminate\Database\Eloquent\Model;

class DossierMaintenancePolicy extends CatalogPolicy
{
    public function update(Account $actor, Model $record): bool
    {
        return $this->view($actor, $record);
    }
}
