<?php

namespace App\ClubUnio\Models;

class TeamSeason extends CatalogModel
{
    public function invoices()
    {
        return $this->hasMany(Invoice::class, 'season_id', 'season_id')->whereHas('lines', fn ($q) => $q->whereIn('id',
            TeamPlayer::where('tenant_id', $this->tenant_id)->where('team_season_id', $this->id)->select('invoice_line_id')));
    }

    public function trainingSlots()
    {
        return $this->hasMany(TrainingSlot::class);
    }

    public function teamPlayers()
    {
        return $this->hasMany(TeamPlayer::class);
    }

    public function teamStaff()
    {
        return $this->hasMany(TeamStaff::class);
    }

    public function technicalLeads()
    {
        return $this->hasMany(TechnicalLead::class);
    }

    public function registrations()
    {
        return $this->hasMany(Registration::class);
    }

    public function expenses()
    {
        return $this->hasMany(Expense::class);
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function season()
    {
        return $this->belongsTo(Season::class);
    }
}
