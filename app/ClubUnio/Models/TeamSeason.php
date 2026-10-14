<?php

namespace App\ClubUnio\Models;

class TeamSeason extends CatalogModel
{
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
