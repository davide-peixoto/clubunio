<?php

namespace App\ClubUnio\Models;

class FootballClub extends CatalogModel
{
    protected $casts = ['active' => 'boolean', 'logo_enabled' => 'boolean', 'club_number' => 'integer'];

    public function getLogoUrlAttribute(): ?string
    {
        // Never accept arbitrary URLs or paths from imported data or form input.
        return $this->active && $this->logo_enabled && $this->club_number > 0
            ? 'https://asanisdatapublicprd01.blob.core.windows.net/logos/Verein/'.$this->club_number.'.gif'
            : null;
    }
}
