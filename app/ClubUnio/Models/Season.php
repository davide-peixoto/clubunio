<?php

namespace App\ClubUnio\Models;

class Season extends CatalogModel
{
    public function contributionRules()
    {
        return $this->hasMany(ContributionRule::class);
    }

    public function teamSeasons()
    {
        return $this->hasMany(TeamSeason::class);
    }

    public function expenses()
    {
        return $this->hasMany(Expense::class);
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }
}
