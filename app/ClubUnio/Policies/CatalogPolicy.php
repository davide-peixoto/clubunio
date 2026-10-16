<?php

namespace App\ClubUnio\Policies;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Bank;
use App\ClubUnio\Models\BillingRecipient;
use App\ClubUnio\Models\ContributionPrice;
use App\ClubUnio\Models\ContributionRule;
use App\ClubUnio\Models\Country;
use App\ClubUnio\Models\DocumentType;
use App\ClubUnio\Models\ExpenseRound;
use App\ClubUnio\Models\ExpenseStatus;
use App\ClubUnio\Models\ExpenseType;
use App\ClubUnio\Models\Family;
use App\ClubUnio\Models\FamilyMember;
use App\ClubUnio\Models\GameType;
use App\ClubUnio\Models\Gender;
use App\ClubUnio\Models\InvoiceLineStatus;
use App\ClubUnio\Models\InvoiceStatus;
use App\ClubUnio\Models\MemberStatus;
use App\ClubUnio\Models\MemberType;
use App\ClubUnio\Models\RegistrationStatus;
use App\ClubUnio\Models\Season;
use App\ClubUnio\Models\StaffFunction;
use App\ClubUnio\Models\Team;
use App\ClubUnio\Models\TrainingOption;
use App\ClubUnio\Models\TrainingSlot;
use Illuminate\Database\Eloquent\Model;

class CatalogPolicy
{
    public function viewAny(Account $actor): bool
    {
        return $actor->hasBackofficeTenant();
    }

    public function view(Account $actor, Model $record): bool
    {
        return $record->tenant && $actor->canAccessTenant($record->tenant);
    }

    public function create(Account $actor): bool
    {
        return $this->viewAny($actor);
    }

    public function update(Account $actor, Model $record): bool
    {
        if (in_array($record::class, [InvoiceStatus::class, InvoiceLineStatus::class, ContributionPrice::class, ContributionRule::class, TrainingSlot::class, TrainingOption::class], true)) {
            return $this->view($actor, $record);
        }

        return in_array($record::class, [Bank::class, ExpenseType::class, ExpenseRound::class, ExpenseStatus::class, GameType::class, Team::class, Season::class, MemberStatus::class, MemberType::class, RegistrationStatus::class, StaffFunction::class, Country::class, Gender::class, BillingRecipient::class, DocumentType::class, Family::class, FamilyMember::class], true) && $this->view($actor, $record);
    }

    public function delete(Account $actor, Model $record): bool
    {
        return false;
    }

    public function deleteAny(Account $actor): bool
    {
        return false;
    }

    public function restore(Account $actor, Model $record): bool
    {
        return false;
    }

    public function forceDelete(Account $actor, Model $record): bool
    {
        return false;
    }
}
