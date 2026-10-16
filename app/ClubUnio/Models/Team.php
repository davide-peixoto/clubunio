<?php

namespace App\ClubUnio\Models;

class Team extends CatalogModel
{
    public function expenses()
    {
        return $this->hasMany(Expense::class, 'tenant_id', 'tenant_id')->whereIn('team_season_id',
            TeamSeason::where('tenant_id', $this->tenant_id)->where('team_id', $this->id)->select('id'));
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class, 'tenant_id', 'tenant_id')->whereHas('lines', fn ($q) => $q->whereIn('id',
            TeamPlayer::where('tenant_id', $this->tenant_id)->whereHas('teamSeason', fn ($q) => $q->where('team_id', $this->id))->select('invoice_line_id')));
    }

    public function teamSeasons()
    {
        return $this->hasMany(TeamSeason::class);
    }
}
